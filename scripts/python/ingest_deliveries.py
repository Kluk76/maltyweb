"""
ingest_deliveries.py — ingest BSF!Deliveries A2:V into inv_deliveries.

Source: BSF spreadsheet, tab Deliveries, range A2:V (22 columns, row 1 is header).
Column map (0-based):
  0  delivery_id_raw   A  formula DeliveryID (e.g. "DEL00123")
  1  date_received     B  DateReceived
  2  supplier_raw      C  raw operator-input supplier name
  3  ingredient_raw    D  raw operator-input ingredient name
  4  mi_id_raw         E  formula VLOOKUP MI_ID (may be "#N/A" or blank)
  5  category_raw      F  formula VLOOKUP category from MI
  6  lot_number        G  lot number / batch ID from supplier
  7  qty_delivered     H  QtyDelivered in pricing units
  8  pricing_unit      I  formula VLOOKUP from MI
  9  unit_price        J  per pricing unit
 10  currency          K  CHF | EUR | …
 11  eur_to_chf        L  conversion rate when currency=EUR
 12  total_original    M  formula qty × unit_price
 13  total_chf         N  formula total_orig × eur_to_chf
 14  qty_remaining     O  remaining qty (mutates via depletion tracking)
 15  status            P  Active | Pending | Consumed | Skipped
 16  invoice_ref       Q  invoice reference number
 17  notes_raw         R  formula price-validation status text
 18  source            S  Manual | Invoice-OCR | DN-OCR | PhotoNote | …
 19  submitted_at      T  form timestamp
 20  [SKIP]            U  DEPRECATED — was checkbox, now unused
 21  details           V  editable notes (de facto Notes column)

Upsert policy: ON DUPLICATE KEY UPDATE on sheet_row_index.
  imported_at set once (INSERT only); last_seen_at refreshed every run.

Deactivation pass: rows observed before but absent from current snapshot get
  status = 'Removed' (BSF rarely deletes rows, but defensive).

Snapshot: before any live write the raw BSF range is snapshotted to
  scripts/python/data/delivery-snapshots/<timestamp>.json  (last 3 kept).

row_hash covers immutable identity fields only (width=11):
  date_received, supplier_raw, ingredient_raw, lot_number, qty_delivered,
  unit_price, currency, invoice_ref, source, submitted_at, details.
Mutations to qty_remaining and status do NOT change the hash.

Usage:
  python ingest_deliveries.py                # dry-run (default)
  python ingest_deliveries.py --apply        # live write
  python ingest_deliveries.py --limit 20     # debug: first N data rows
  python ingest_deliveries.py --skip-resolution  # raw cols only, FKs as NULL
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from datetime import datetime
from pathlib import Path

from lib_config import load as load_config
from lib_coerce import d, dt, n, s
from lib_db import connect
from lib_hashing import row_hash
from lib_sheets import SheetsClient

DELIVERIES_RANGE = "Deliveries!A2:V"
DELIVERIES_WIDTH = 22  # columns A..V

# Immutable fields used for row_hash (indices into the parsed dict, not the raw row)
# Width = 11 fields: date_received, supplier_raw, ingredient_raw, lot_number,
# qty_delivered, unit_price, currency, invoice_ref, source, submitted_at, details
HASH_WIDTH = 11

# Sheets formula error patterns — treat as blank for resolution
_FORMULA_ERROR_RE = re.compile(r"^#(N/A|REF!|NAME\?|VALUE!|DIV/0!|NULL!|NUM!|ERROR!)", re.I)

SCRIPT_DIR = Path(__file__).parent
SNAPSHOT_DIR = SCRIPT_DIR / "data" / "delivery-snapshots"


# ── Snapshot ──────────────────────────────────────────────────────────────────

def save_snapshot(raw_rows: list) -> Path:
    SNAPSHOT_DIR.mkdir(parents=True, exist_ok=True)
    ts = datetime.utcnow().strftime("%Y%m%dT%H%M%S")
    dest = SNAPSHOT_DIR / f"deliveries-{ts}.json"
    dest.write_text(json.dumps(raw_rows, indent=2), encoding="utf-8")
    existing = sorted(SNAPSHOT_DIR.glob("deliveries-*.json"))
    for old in existing[:-3]:
        old.unlink()
    return dest


# ── Row parsing ───────────────────────────────────────────────────────────────

def _is_formula_error(v) -> bool:
    """Return True if cell value is a Sheets formula error (#N/A etc.)."""
    if v is None:
        return False
    return bool(_FORMULA_ERROR_RE.match(str(v).strip()))


def parse_row(row: list, sheet_row_index: int) -> dict | None:
    """
    Parse a raw Sheets row into a typed dict.
    Returns None if delivery_id_raw (col A) is empty — skip blank rows.
    """
    padded = list(row) + [""] * max(0, DELIVERIES_WIDTH - len(row))

    delivery_id_raw = s(padded[0])
    if not delivery_id_raw:
        return None

    # col E (mi_id_raw): blank out formula errors
    mi_id_raw_cell = s(padded[4])
    mi_id_raw = None if (mi_id_raw_cell is None or _is_formula_error(mi_id_raw_cell)) else mi_id_raw_cell

    # col T (submitted_at): datetime
    submitted_at_raw = s(padded[19])

    # col V (details): col index 21; col U (index 20) is skipped
    details_raw = s(padded[21])

    # Immutable identity fields for row_hash (11 fields, fixed order)
    hash_cells = [
        s(padded[1]),   # date_received (raw string — consistent pre-coercion)
        s(padded[2]),   # supplier_raw
        s(padded[3]),   # ingredient_raw
        s(padded[6]),   # lot_number
        s(padded[7]),   # qty_delivered (raw)
        s(padded[9]),   # unit_price (raw)
        s(padded[10]),  # currency
        s(padded[16]),  # invoice_ref
        s(padded[18]),  # source
        submitted_at_raw,
        details_raw,
    ]

    return {
        "sheet_row_index":  sheet_row_index,
        "row_hash":         row_hash([c or "" for c in hash_cells], HASH_WIDTH),
        "delivery_id_raw":  delivery_id_raw,
        "date_received":    d(padded[1]),
        "supplier_raw":     s(padded[2]),
        "ingredient_raw":   s(padded[3]),
        "mi_id_raw":        mi_id_raw,
        "category_raw":     s(padded[5]),
        "lot_number":       s(padded[6]),
        "qty_delivered":    n(padded[7]),
        "pricing_unit":     s(padded[8]),
        "unit_price":       n(padded[9]),
        "currency":         s(padded[10]),
        "eur_to_chf":       n(padded[11]),
        "total_original":   n(padded[12]),
        "total_chf":        n(padded[13]),
        "qty_remaining":    n(padded[14]),
        "status":           s(padded[15]),
        "invoice_ref":      s(padded[16]),
        "notes_raw":        s(padded[17]),
        "source":           s(padded[18]),
        "submitted_at":     dt(padded[19]),
        # col U (index 20) intentionally skipped
        "details":          details_raw,
    }


# ── FK resolution ─────────────────────────────────────────────────────────────

def resolve_fks(conn, parsed: dict) -> tuple[int | None, int | None, str]:
    """
    Resolve ingredient_fk and supplier_fk for one parsed row.

    Ingredient resolution (ref_mi):
      a. mi_id_raw non-empty and not a formula error → exact MI_ID lookup.
      b. ingredient_raw name exact match (case-insensitive) in ref_mi.
      c. ingredient_raw alias match in ref_mi_aliases.
      d. unresolved.

    Supplier resolution (ref_suppliers):
      a. Exact name match (case-insensitive).
      b. If 0 rows → unresolved.
      c. If 1 row → use it.
      d. If >1 rows (multi-GL): try to match on GL inferred from category_raw
         via ref_mi_categories.default_gl_account. If unique match → use it.
         Else fall back to modal_gl from ref_supplier_summary.
         If still ambiguous → pick lowest id, flag resolution='ambiguous_multi_gl'.

    Returns (ingredient_fk, supplier_fk, resolution).
    Resolution reflects the ingredient path taken; ambiguous_multi_gl overrides.
    """
    ingredient_fk: int | None = None
    supplier_fk: int | None = None
    resolution = "unresolved"

    with conn.cursor() as cur:
        # ── Ingredient ────────────────────────────────────────────────────────
        mi_id_raw = parsed.get("mi_id_raw")
        ingredient_raw = parsed.get("ingredient_raw")

        if mi_id_raw:
            cur.execute("SELECT id FROM ref_mi WHERE mi_id = %s", (mi_id_raw,))
            row = cur.fetchone()
            if row:
                ingredient_fk = row["id"]
                resolution = "mi_id_match"

        if ingredient_fk is None and ingredient_raw:
            cur.execute(
                "SELECT id FROM ref_mi WHERE LOWER(name) = LOWER(%s)",
                (ingredient_raw,),
            )
            rows = cur.fetchall()
            if len(rows) == 1:
                ingredient_fk = rows[0]["id"]
                resolution = "name_exact"
            elif len(rows) == 0:
                # Try alias table
                cur.execute(
                    "SELECT mi_id_fk AS id FROM ref_mi_aliases"
                    " WHERE LOWER(alias) = LOWER(%s)",
                    (ingredient_raw,),
                )
                alias_row = cur.fetchone()
                if alias_row:
                    ingredient_fk = alias_row["id"]
                    resolution = "alias"

        # ── Supplier ─────────────────────────────────────────────────────────
        supplier_raw = parsed.get("supplier_raw")
        if supplier_raw:
            cur.execute(
                "SELECT id, gl_account, name FROM ref_suppliers"
                " WHERE LOWER(name) = LOWER(%s) AND is_active = 1",
                (supplier_raw,),
            )
            sup_rows = cur.fetchall()

            if len(sup_rows) == 0:
                # Alias fallback — look up raw input in ref_supplier_aliases
                cur.execute(
                    "SELECT s.id, s.gl_account, s.name FROM ref_supplier_aliases a"
                    " JOIN ref_suppliers s ON s.id = a.supplier_id_fk"
                    " WHERE LOWER(a.alias) = LOWER(%s)",
                    (supplier_raw,),
                )
                sup_rows = cur.fetchall()
                alias_resolved = len(sup_rows) > 0
            else:
                alias_resolved = False

            def _disambiguate_multi_gl(rows: list) -> tuple[int | None, bool]:
                """
                Given multiple ref_suppliers rows for one supplier name, try to
                pick one via category GL then modal GL.
                Returns (matched_id, still_ambiguous).
                """
                category_raw = parsed.get("category_raw")
                matched: int | None = None
                if category_raw:
                    cur.execute(
                        "SELECT default_gl_account FROM ref_mi_categories"
                        " WHERE name = %s",
                        (category_raw,),
                    )
                    cat_row = cur.fetchone()
                    if cat_row and cat_row["default_gl_account"]:
                        gl = cat_row["default_gl_account"]
                        gl_matches = [r for r in rows if r["gl_account"] == gl]
                        if len(gl_matches) == 1:
                            matched = gl_matches[0]["id"]

                if matched is None:
                    # Fall back to modal_gl from ref_supplier_summary
                    name_for_summary = rows[0]["name"] if rows else supplier_raw
                    cur.execute(
                        "SELECT modal_gl FROM ref_supplier_summary"
                        " WHERE LOWER(name) = LOWER(%s)",
                        (name_for_summary,),
                    )
                    summary_row = cur.fetchone()
                    if summary_row and summary_row["modal_gl"]:
                        modal_gl = summary_row["modal_gl"]
                        modal_matches = [r for r in rows if r["gl_account"] == modal_gl]
                        if len(modal_matches) == 1:
                            matched = modal_matches[0]["id"]

                if matched is None:
                    matched = min(r["id"] for r in rows)
                    return matched, True  # still ambiguous

                return matched, False

            if len(sup_rows) == 1:
                supplier_fk = sup_rows[0]["id"]
                if alias_resolved:
                    resolution = "supplier_alias"
            elif len(sup_rows) > 1:
                matched_id, still_ambiguous = _disambiguate_multi_gl(sup_rows)
                supplier_fk = matched_id
                if still_ambiguous:
                    resolution = "ambiguous_multi_gl"
                elif alias_resolved:
                    resolution = "supplier_alias"

    return ingredient_fk, supplier_fk, resolution


# ── Main upsert ───────────────────────────────────────────────────────────────

def upsert_rows(conn, parsed_rows: list[dict], skip_resolution: bool) -> tuple[int, int]:
    """
    INSERT ... ON DUPLICATE KEY UPDATE for inv_deliveries.
    Returns (inserted, updated) counts.
    """
    inserted = 0
    updated = 0

    with conn.cursor() as cur:
        for r in parsed_rows:
            ingredient_fk = r.get("ingredient_fk")
            supplier_fk = r.get("supplier_fk")
            resolution = r.get("resolution", "unresolved")

            cur.execute(
                """
                INSERT INTO inv_deliveries (
                  sheet_row_index, row_hash,
                  delivery_id_raw, date_received, supplier_raw, ingredient_raw,
                  mi_id_raw, category_raw, lot_number, qty_delivered, pricing_unit,
                  unit_price, currency, eur_to_chf, total_original, total_chf,
                  qty_remaining, status, invoice_ref, notes_raw, source,
                  submitted_at, details,
                  supplier_fk, ingredient_fk, resolution,
                  imported_at, last_seen_at
                ) VALUES (
                  %s, %s,
                  %s, %s, %s, %s,
                  %s, %s, %s, %s, %s,
                  %s, %s, %s, %s, %s,
                  %s, %s, %s, %s, %s,
                  %s, %s,
                  %s, %s, %s,
                  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
                ON DUPLICATE KEY UPDATE
                  row_hash        = VALUES(row_hash),
                  delivery_id_raw = VALUES(delivery_id_raw),
                  date_received   = VALUES(date_received),
                  supplier_raw    = VALUES(supplier_raw),
                  ingredient_raw  = VALUES(ingredient_raw),
                  mi_id_raw       = VALUES(mi_id_raw),
                  category_raw    = VALUES(category_raw),
                  lot_number      = VALUES(lot_number),
                  qty_delivered   = VALUES(qty_delivered),
                  pricing_unit    = VALUES(pricing_unit),
                  unit_price      = VALUES(unit_price),
                  currency        = VALUES(currency),
                  eur_to_chf      = VALUES(eur_to_chf),
                  total_original  = VALUES(total_original),
                  total_chf       = VALUES(total_chf),
                  qty_remaining   = VALUES(qty_remaining),
                  status          = VALUES(status),
                  invoice_ref     = VALUES(invoice_ref),
                  notes_raw       = VALUES(notes_raw),
                  source          = VALUES(source),
                  submitted_at    = VALUES(submitted_at),
                  details         = VALUES(details),
                  supplier_fk     = VALUES(supplier_fk),
                  ingredient_fk   = VALUES(ingredient_fk),
                  resolution      = VALUES(resolution),
                  last_seen_at    = CURRENT_TIMESTAMP
                """,
                (
                    r["sheet_row_index"], r["row_hash"],
                    r["delivery_id_raw"], r["date_received"], r["supplier_raw"], r["ingredient_raw"],
                    r["mi_id_raw"], r["category_raw"], r["lot_number"], r["qty_delivered"], r["pricing_unit"],
                    r["unit_price"], r["currency"], r["eur_to_chf"], r["total_original"], r["total_chf"],
                    r["qty_remaining"], r["status"], r["invoice_ref"], r["notes_raw"], r["source"],
                    r["submitted_at"], r["details"],
                    supplier_fk, ingredient_fk, resolution,
                ),
            )
            if cur.rowcount == 1:
                inserted += 1
            elif cur.rowcount == 2:
                updated += 1

    conn.commit()
    return inserted, updated


# ── Deactivation pass ─────────────────────────────────────────────────────────

def deactivate_absent(conn, observed_indices: set[int]) -> int:
    """
    Soft-delete rows present in the DB but absent from the current BSF snapshot.
    Sets status = 'Removed'. Does not delete rows (FK references must survive).
    Returns count of rows affected.
    """
    if not observed_indices:
        return 0
    placeholders = ", ".join(["%s"] * len(observed_indices))
    sql = (
        f"UPDATE inv_deliveries SET status = 'Removed'"
        f"  WHERE sheet_row_index NOT IN ({placeholders})"
        f"    AND status != 'Removed'"
    )
    with conn.cursor() as cur:
        cur.execute(sql, list(observed_indices))
        count = cur.rowcount
    conn.commit()
    return count


# ── Resolution stats ──────────────────────────────────────────────────────────

def _resolution_stats(parsed_rows: list[dict], key: str) -> dict[str, int]:
    """Tally resolution values across parsed rows for 'resolution' (shared field)."""
    counts: dict[str, int] = {}
    for r in parsed_rows:
        val = r.get(key, "unresolved")
        counts[val] = counts.get(val, 0) + 1
    return counts


def _fk_stats(parsed_rows: list[dict]) -> dict[str, dict[str, int]]:
    """Count ingredient/supplier FK resolution outcomes."""
    ing: dict[str, int] = {}
    sup: dict[str, int] = {}
    for r in parsed_rows:
        res = r.get("resolution", "unresolved")
        # ingredient resolution tracks the main resolution path
        ing_resolved = r.get("ingredient_fk") is not None
        sup_resolved = r.get("supplier_fk") is not None
        if ing_resolved:
            ing[res] = ing.get(res, 0) + 1
        else:
            ing["unresolved"] = ing.get("unresolved", 0) + 1
        if sup_resolved:
            # Preserve alias / ambiguous sub-buckets for visibility
            if res in ("supplier_alias", "ambiguous_multi_gl"):
                sup[res] = sup.get(res, 0) + 1
            else:
                sup["resolved"] = sup.get("resolved", 0) + 1
        else:
            sup["unresolved"] = sup.get("unresolved", 0) + 1
    return {"ingredient": ing, "supplier": sup}


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Ingest BSF Deliveries (Deliveries!A2:V) into inv_deliveries.",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Commit writes to DB (default is dry-run).",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        metavar="N",
        help="Process only the first N data rows (debug).",
    )
    parser.add_argument(
        "--skip-resolution",
        action="store_true",
        help="Write raw columns only; leave supplier_fk/ingredient_fk as NULL.",
    )
    args = parser.parse_args()
    dry_run = not args.apply

    cfg = load_config()
    sheets = SheetsClient(cfg.service_account_path)
    conn = connect(cfg)

    try:
        print(f"\n{'[DRY-RUN] ' if dry_run else ''}Fetching {DELIVERIES_RANGE} from BSF ...")
        raw_rows = sheets.read_range(cfg.bsf_spreadsheet_id, DELIVERIES_RANGE)
        print(f"  fetched {len(raw_rows)} raw rows")

        parsed: list[dict] = []
        skipped_blank = 0
        for array_index, row in enumerate(raw_rows):
            sheet_row_index = 2 + array_index  # header is row 1, data starts at row 2
            r = parse_row(row, sheet_row_index)
            if r is None:
                skipped_blank += 1
                continue
            parsed.append(r)

        if args.limit:
            parsed = parsed[: args.limit]

        observed_indices: set[int] = {r["sheet_row_index"] for r in parsed}
        distinct_suppliers = {r["supplier_raw"] for r in parsed if r["supplier_raw"]}
        distinct_ingredients = {r["ingredient_raw"] for r in parsed if r["ingredient_raw"]}

        print(f"  data rows parsed:          {len(parsed)}")
        print(f"  skipped (blank ID):        {skipped_blank}")
        print(f"  distinct suppliers seen:   {len(distinct_suppliers)}")
        print(f"  distinct ingredients seen: {len(distinct_ingredients)}")

        # ── Resolution ────────────────────────────────────────────────────────
        if not args.skip_resolution:
            for r in parsed:
                ing_fk, sup_fk, resolution = resolve_fks(conn, r)
                r["ingredient_fk"] = ing_fk
                r["supplier_fk"] = sup_fk
                r["resolution"] = resolution
        else:
            for r in parsed:
                r["ingredient_fk"] = None
                r["supplier_fk"] = None
                r["resolution"] = "unresolved"

        fk = _fk_stats(parsed)
        ing_stats = fk["ingredient"]
        sup_stats = fk["supplier"]

        print(f"\n  ingredient FK resolution:")
        for k in ("mi_id_match", "name_exact", "alias", "ambiguous_multi_gl", "unresolved"):
            v = ing_stats.get(k, 0)
            if v:
                print(f"    {k:<22s}: {v}")
        print(f"  supplier FK resolution:")
        for k in ("resolved", "supplier_alias", "ambiguous_multi_gl", "unresolved"):
            v = sup_stats.get(k, 0)
            if v:
                print(f"    {k:<22s}: {v}")

        if dry_run:
            with conn.cursor() as cur:
                cur.execute("SELECT sheet_row_index FROM inv_deliveries")
                existing_indices: set[int] = {r["sheet_row_index"] for r in cur.fetchall()}
                cur.execute("SELECT COUNT(*) AS cnt FROM inv_deliveries")
                db_total = cur.fetchone()["cnt"]

            net_new = observed_indices - existing_indices
            to_update = observed_indices & existing_indices
            would_remove = len(
                {idx for idx in existing_indices if idx not in observed_indices}
            )

            print(f"\n  currently in DB:           {db_total}")
            print(f"  would insert (net-new):    {len(net_new)}")
            print(f"  would update (existing):   {len(to_update)}")
            print(f"  would soft-delete (absent):{would_remove}")

            # Top-20 unresolved (ingredient_raw, supplier_raw) for operator
            unresolved = [
                r for r in parsed
                if r.get("ingredient_fk") is None or r.get("supplier_fk") is None
            ]
            unresolved.sort(key=lambda r: r["sheet_row_index"])
            print(f"\n  top unresolved rows (ingredient or supplier not matched):")
            print(f"  {'row':>5}  {'date':<12}  {'ingredient_raw':<35}  {'supplier_raw'}")
            for r in unresolved[:20]:
                date_str = str(r["date_received"]) if r["date_received"] else "—"
                ing = (r["ingredient_raw"] or "—")[:34]
                sup = (r["supplier_raw"] or "—")[:40]
                print(f"  {r['sheet_row_index']:>5}  {date_str:<12}  {ing:<35}  {sup}")
            if len(unresolved) > 20:
                print(f"  ... and {len(unresolved) - 20} more unresolved rows.")

            print(f"\n[dry-run] no rows written. Re-run with --apply to commit.")
            return 0

        # ── Live writes ───────────────────────────────────────────────────────

        snap_path = save_snapshot(raw_rows)
        print(f"  snapshot saved -> {snap_path}")

        inserted, updated = upsert_rows(conn, parsed, args.skip_resolution)
        removed = deactivate_absent(conn, observed_indices)

        print(
            f"\n  inserted {inserted}, updated {updated}, soft-deleted (Removed) {removed}"
        )

    finally:
        conn.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
