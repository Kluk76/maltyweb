"""
ingest_sku_bom.py — ingest BSF!SKU_BOM A2:N into ref_skus + ref_sku_bom.

Source: BSF spreadsheet, tab SKU_BOM, range A2:N (14 columns, row 1 is header).
Column map (0-based):
  0  SKU           A  SKU code (ZEPF, ZEP4, ZEPC, …)
  1  BEER          B  recipe name (matched against ref_recipes.name)
  2  FORMAT        C  "Bot" | "Can" | "Keg" | "Cuv"
  3  UNIT_LABEL    D  "24-pack box (24 × 33cl)"
  4  HL_PER_UNIT   E  sellable unit volume in HL (e.g. 0.0792)
  5  SOURCE        F  "Brewing" | "Packaging"
  6  CATEGORY      G  "Malt" | "Hops" | "Container" | "Label" | …
  7  INGREDIENT    H  ingredient name (matched against ref_mi)
  8  ING_UNIT      I  "kg" | "L" | "pcs" | …
  9  QTY_PER_UNIT  J  qty of this ingredient per 1 sellable unit
 10  PRICING_UNIT  K  how the ingredient is priced (kg, L, HL)
 11  PRICE         L  per pricing unit
 12  CURRENCY      M
 13  COST          N  computed: qty × price (denormalized)

Two passes:
  Pass 1 — collect distinct SKUs (one row per sku_code, first-row values for
            BEER/FORMAT/UNIT_LABEL/HL_PER_UNIT).
  Pass 2 — collect BOM lines (one row per (sku, ingredient_raw, source)).

FK resolution:
  recipe_id  — case-insensitive match on ref_recipes.name. When multiple rows
               share the same name (EPH vintages), prefer vintage IS NULL or ''.
  mi_id      — a) exact name match in ref_mi, b) alias match in ref_mi_aliases,
               c) NULL / 'unresolved'.

Upsert order:
  1. Upsert ref_skus (ON DUPLICATE KEY UPDATE on sku_code).
  2. Build sku_code→id map from DB.
  3. Upsert ref_sku_bom (ON DUPLICATE KEY UPDATE on (sku_id, ingredient_raw, source)).
  4. Hard-delete BOM lines absent from current snapshot.
  5. Soft-deactivate SKUs absent from current snapshot (is_active=0).

Snapshot: before any live write the raw BSF range is snapshotted to
  scripts/python/data/sku-bom-snapshots/<timestamp>.json  (last 3 kept).

Usage:
  python ingest_sku_bom.py             # dry-run (default) — prints counts, no writes
  python ingest_sku_bom.py --apply     # live write
  python ingest_sku_bom.py --limit 20  # debug: process only first N data rows
"""
from __future__ import annotations

import argparse
import json
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path

from lib_config import load as load_config
from lib_coerce import n, s
from lib_db import connect
from lib_hashing import row_hash
from lib_sheets import SheetsClient

BOM_RANGE = "SKU_BOM!A2:N"
BOM_WIDTH = 14  # columns A..N

# Hash widths — all raw source fields, FKs excluded
SKU_HASH_FIELDS = 5   # sku_code, beer_raw, format, unit_label, hl_per_unit
BOM_HASH_FIELDS = 10  # sku_code, ingredient_raw, source, category_raw,
                      # qty_per_unit, ing_unit, pricing_unit, price, currency, cost

SCRIPT_DIR = Path(__file__).parent
SNAPSHOT_DIR = SCRIPT_DIR / "data" / "sku-bom-snapshots"


# ── Snapshot ──────────────────────────────────────────────────────────────────

def save_snapshot(raw_rows: list) -> Path:
    SNAPSHOT_DIR.mkdir(parents=True, exist_ok=True)
    ts = datetime.utcnow().strftime("%Y%m%dT%H%M%S")
    dest = SNAPSHOT_DIR / f"sku-bom-{ts}.json"
    dest.write_text(json.dumps(raw_rows, indent=2), encoding="utf-8")
    existing = sorted(SNAPSHOT_DIR.glob("sku-bom-*.json"))
    for old in existing[:-3]:
        old.unlink()
    return dest


# ── Row parsing ───────────────────────────────────────────────────────────────

def parse_row(row: list) -> dict | None:
    """
    Parse a raw Sheets row into a typed dict.
    Returns None if sku_code (col A) is empty — skip blank rows.
    """
    padded = list(row) + [""] * max(0, BOM_WIDTH - len(row))

    sku_code = s(padded[0])
    if not sku_code:
        return None

    ingredient_raw = s(padded[7])
    source = s(padded[5])
    # Skip summary/totals rows at the bottom of the tab — those have valid SKU+BEER
    # but blank SOURCE and numeric values in cols G/H/I. Real BOM lines always have
    # SOURCE in {Brewing, Packaging}.
    if source not in ("Brewing", "Packaging"):
        return None
    qty_raw = s(padded[9])
    price_raw = s(padded[11])
    cost_raw = s(padded[13])

    qty = n(qty_raw)
    price = n(price_raw)
    cost = n(cost_raw)

    # If cost col is blank but qty and price are both present, compute it.
    if cost is None and qty is not None and price is not None:
        cost = qty * price

    # SKU hash fields (raw strings, pre-coercion for stability)
    sku_hash_cells = [
        s(padded[0]) or "",  # sku_code
        s(padded[1]) or "",  # beer_raw
        s(padded[2]) or "",  # format
        s(padded[3]) or "",  # unit_label
        s(padded[4]) or "",  # hl_per_unit (raw string)
    ]

    # BOM hash fields (all BOM-line content except FK columns)
    bom_hash_cells = [
        s(padded[0]) or "",   # sku_code
        ingredient_raw or "", # ingredient_raw
        source or "",         # source
        s(padded[6]) or "",   # category_raw
        qty_raw or "",        # qty_per_unit (raw — pre-coercion)
        s(padded[8]) or "",   # ing_unit
        s(padded[10]) or "",  # pricing_unit
        price_raw or "",      # price (raw)
        s(padded[12]) or "",  # currency
        s(padded[13]) or "",  # cost (raw, not computed)
    ]

    return {
        # SKU-level fields (stable across BOM lines for the same SKU)
        "sku_code":       sku_code,
        "beer_raw":       s(padded[1]),
        "format":         s(padded[2]),
        "unit_label":     s(padded[3]),
        "hl_per_unit":    n(padded[4]),
        "sku_hash":       row_hash(sku_hash_cells, SKU_HASH_FIELDS),
        # BOM-line fields
        "source":         source,
        "category_raw":   s(padded[6]),
        "ingredient_raw": ingredient_raw,
        "ing_unit":       s(padded[8]),
        "qty_per_unit":   qty,
        "pricing_unit":   s(padded[10]),
        "price":          price,
        "currency":       s(padded[12]),
        "cost":           cost,
        "bom_hash":       row_hash(bom_hash_cells, BOM_HASH_FIELDS),
    }


# ── Pass 1: collect distinct SKUs ─────────────────────────────────────────────

def collect_skus(parsed_rows: list[dict]) -> dict[str, dict]:
    """
    Group by sku_code. For each unique SKU, keep the FIRST row's SKU-level
    fields (BEER/FORMAT/UNIT_LABEL/HL_PER_UNIT are stable across BOM lines).
    Returns {sku_code: sku_dict}.
    """
    skus: dict[str, dict] = {}
    for r in parsed_rows:
        code = r["sku_code"]
        if code not in skus:
            skus[code] = {
                "sku_code":    code,
                "beer_raw":    r["beer_raw"],
                "format":      r["format"],
                "unit_label":  r["unit_label"],
                "hl_per_unit": r["hl_per_unit"],
                "row_hash":    r["sku_hash"],
            }
    return skus


# ── FK resolution — recipe ────────────────────────────────────────────────────

def resolve_recipe_ids(conn, skus: dict[str, dict]) -> None:
    """
    Resolve recipe_id for each SKU in-place.

    Strategy:
      1. Case-insensitive match on ref_recipes.name = beer_raw.
      2. If multiple matches (EPH vintages share the same name), prefer the row
         where vintage IS NULL or vintage = '' (non-EPH, standard recipe).
         If still ambiguous (no NULL-vintage row), pick the lowest id.
      3. If 0 matches, recipe_id = None.
    """
    with conn.cursor() as cur:
        cur.execute("SELECT id, name, vintage FROM ref_recipes")
        recipes = cur.fetchall()

    # Build {lower_name: [row, ...]} index
    by_name: dict[str, list[dict]] = {}
    for r in recipes:
        key = (r["name"] or "").lower()
        by_name.setdefault(key, []).append(r)

    for sku in skus.values():
        beer_raw = sku.get("beer_raw")
        if not beer_raw:
            sku["recipe_id"] = None
            continue
        candidates = by_name.get(beer_raw.lower(), [])
        if not candidates:
            sku["recipe_id"] = None
        elif len(candidates) == 1:
            sku["recipe_id"] = candidates[0]["id"]
        else:
            # Multiple rows — prefer vintage IS NULL or vintage == ''
            null_vintage = [c for c in candidates if not c.get("vintage")]
            if len(null_vintage) == 1:
                sku["recipe_id"] = null_vintage[0]["id"]
            elif len(null_vintage) > 1:
                sku["recipe_id"] = min(c["id"] for c in null_vintage)
            else:
                # All have a vintage — pick lowest id (most stable)
                sku["recipe_id"] = min(c["id"] for c in candidates)


# ── FK resolution — ingredient ────────────────────────────────────────────────

def resolve_mi_ids(conn, parsed_rows: list[dict]) -> None:
    """
    Resolve mi_id for each BOM line in-place.

    Steps:
      a. Direct: SELECT id FROM ref_mi WHERE LOWER(name) = LOWER(ingredient_raw).
         Exactly 1 match → resolution='mi_match'.
      b. Alias: SELECT mi_id_fk FROM ref_mi_aliases WHERE LOWER(alias) = LOWER(ingredient_raw).
         Found → resolution='alias'.
      c. Else → mi_id=None, resolution='unresolved'.
    """
    # Pre-load all MI names and aliases in one round-trip for efficiency
    with conn.cursor() as cur:
        cur.execute("SELECT id, LOWER(name) AS lname FROM ref_mi WHERE is_active = 1")
        mi_rows = cur.fetchall()
        cur.execute("SELECT mi_id_fk, LOWER(alias) AS lalias FROM ref_mi_aliases")
        alias_rows = cur.fetchall()

    # Build lookup maps
    name_map: dict[str, list[int]] = {}
    for r in mi_rows:
        name_map.setdefault(r["lname"], []).append(r["id"])

    alias_map: dict[str, int] = {}
    for r in alias_rows:
        alias_map[r["lalias"]] = r["mi_id_fk"]

    for r in parsed_rows:
        raw = r.get("ingredient_raw")
        if not raw:
            r["mi_id"] = None
            r["resolution"] = "unresolved"
            continue

        key = raw.strip().lower()

        ids = name_map.get(key, [])
        if len(ids) == 1:
            r["mi_id"] = ids[0]
            r["resolution"] = "mi_match"
        elif len(ids) > 1:
            # Multiple MI rows with same name — ambiguous; take lowest id
            r["mi_id"] = min(ids)
            r["resolution"] = "mi_match"
        else:
            alias_id = alias_map.get(key)
            if alias_id is not None:
                r["mi_id"] = alias_id
                r["resolution"] = "alias"
            else:
                r["mi_id"] = None
                r["resolution"] = "unresolved"


# ── Upsert ref_skus ───────────────────────────────────────────────────────────

def upsert_skus(conn, skus: dict[str, dict]) -> tuple[int, int]:
    """
    INSERT ... ON DUPLICATE KEY UPDATE for ref_skus.
    Returns (inserted, updated).
    """
    inserted = 0
    updated = 0
    with conn.cursor() as cur:
        for sku in skus.values():
            cur.execute(
                """
                INSERT INTO ref_skus
                  (sku_code, recipe_id, beer_raw, format, unit_label,
                   hl_per_unit, is_active, row_hash,
                   last_seen_at, imported_at, last_modified_by)
                VALUES
                  (%s, %s, %s, %s, %s,
                   %s, 1, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'ingest')
                ON DUPLICATE KEY UPDATE
                  -- Emancipation: web-pinned rows preserve every column.
                  recipe_id    = IF(last_modified_by = 'web', recipe_id,   VALUES(recipe_id)),
                  beer_raw     = IF(last_modified_by = 'web', beer_raw,    VALUES(beer_raw)),
                  format       = IF(last_modified_by = 'web', format,      VALUES(format)),
                  unit_label   = IF(last_modified_by = 'web', unit_label,  VALUES(unit_label)),
                  hl_per_unit  = IF(last_modified_by = 'web', hl_per_unit, VALUES(hl_per_unit)),
                  is_active    = IF(last_modified_by = 'web', is_active,   1),
                  row_hash     = IF(last_modified_by = 'web', row_hash,    VALUES(row_hash)),
                  last_seen_at = CURRENT_TIMESTAMP
                """,
                (
                    sku["sku_code"], sku.get("recipe_id"), sku["beer_raw"],
                    sku["format"], sku["unit_label"], sku["hl_per_unit"],
                    sku["row_hash"],
                ),
            )
            if cur.rowcount == 1:
                inserted += 1
            elif cur.rowcount == 2:
                updated += 1
    conn.commit()
    return inserted, updated


def load_sku_id_map(conn, sku_codes: list[str]) -> dict[str, int]:
    """SELECT id FROM ref_skus for each code in list. Returns {sku_code: id}."""
    if not sku_codes:
        return {}
    placeholders = ", ".join(["%s"] * len(sku_codes))
    with conn.cursor() as cur:
        cur.execute(
            f"SELECT id, sku_code FROM ref_skus WHERE sku_code IN ({placeholders})",
            sku_codes,
        )
        return {r["sku_code"]: r["id"] for r in cur.fetchall()}


# ── Upsert ref_sku_bom ────────────────────────────────────────────────────────

def upsert_bom_lines(
    conn, parsed_rows: list[dict], sku_id_map: dict[str, int]
) -> tuple[int, int, list[int]]:
    """
    INSERT ... ON DUPLICATE KEY UPDATE for ref_sku_bom.
    Returns (inserted, updated, list_of_observed_ids).
    """
    inserted = 0
    updated = 0
    observed_ids: list[int] = []

    with conn.cursor() as cur:
        for r in parsed_rows:
            sku_id = sku_id_map.get(r["sku_code"])
            if sku_id is None:
                # Should not happen; sku was just upserted
                continue
            ingredient_raw = r.get("ingredient_raw")
            if not ingredient_raw:
                continue

            cur.execute(
                """
                INSERT INTO ref_sku_bom
                  (sku_id, mi_id, ingredient_raw, source, category_raw,
                   qty_per_unit, ing_unit, pricing_unit, price, currency, cost,
                   resolution, row_hash, last_seen_at, imported_at)
                VALUES
                  (%s, %s, %s, %s, %s,
                   %s, %s, %s, %s, %s, %s,
                   %s, %s, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                  mi_id        = VALUES(mi_id),
                  category_raw = VALUES(category_raw),
                  qty_per_unit = VALUES(qty_per_unit),
                  ing_unit     = VALUES(ing_unit),
                  pricing_unit = VALUES(pricing_unit),
                  price        = VALUES(price),
                  currency     = VALUES(currency),
                  cost         = VALUES(cost),
                  resolution   = VALUES(resolution),
                  row_hash     = VALUES(row_hash),
                  last_seen_at = CURRENT_TIMESTAMP
                """,
                (
                    sku_id, r.get("mi_id"), ingredient_raw, r.get("source"),
                    r.get("category_raw"), r.get("qty_per_unit"), r.get("ing_unit"),
                    r.get("pricing_unit"), r.get("price"), r.get("currency"),
                    r.get("cost"), r.get("resolution", "unresolved"), r["bom_hash"],
                ),
            )
            if cur.rowcount == 1:
                inserted += 1
                # Retrieve the auto-increment id just inserted
                cur.execute(
                    "SELECT id FROM ref_sku_bom"
                    " WHERE sku_id = %s AND ingredient_raw = %s AND source <=> %s",
                    (sku_id, ingredient_raw, r.get("source")),
                )
                row = cur.fetchone()
                if row:
                    observed_ids.append(row["id"])
            elif cur.rowcount == 2:
                updated += 1
                cur.execute(
                    "SELECT id FROM ref_sku_bom"
                    " WHERE sku_id = %s AND ingredient_raw = %s AND source <=> %s",
                    (sku_id, ingredient_raw, r.get("source")),
                )
                row = cur.fetchone()
                if row:
                    observed_ids.append(row["id"])
            else:
                # rowcount == 0 means no change — row existed with same data
                cur.execute(
                    "SELECT id FROM ref_sku_bom"
                    " WHERE sku_id = %s AND ingredient_raw = %s AND source <=> %s",
                    (sku_id, ingredient_raw, r.get("source")),
                )
                row = cur.fetchone()
                if row:
                    observed_ids.append(row["id"])

    conn.commit()
    return inserted, updated, observed_ids


# ── Hard-delete absent BOM lines ──────────────────────────────────────────────

def delete_absent_bom_lines(conn, observed_ids: list[int]) -> int:
    """
    Hard-delete any ref_sku_bom rows NOT in the current snapshot.
    BOM is entirely derived — stale lines must be removed.
    Returns count deleted.
    """
    if not observed_ids:
        return 0
    placeholders = ", ".join(["%s"] * len(observed_ids))
    with conn.cursor() as cur:
        cur.execute(
            f"DELETE FROM ref_sku_bom WHERE id NOT IN ({placeholders})",
            observed_ids,
        )
        count = cur.rowcount
    conn.commit()
    return count


# ── Soft-deactivate absent SKUs ───────────────────────────────────────────────

def deactivate_absent_skus(conn, observed_codes: set[str]) -> int:
    """
    Set is_active=0 for any ref_skus row whose sku_code was NOT observed.
    Does not delete (FK references from ref_sku_bom survive via ON DELETE CASCADE,
    but other future tables may reference ref_skus).
    Returns count deactivated.
    """
    if not observed_codes:
        return 0
    placeholders = ", ".join(["%s"] * len(observed_codes))
    # Emancipation: web-pinned rows are exempt from auto-deactivation.
    sql = (
        f"UPDATE ref_skus SET is_active = 0"
        f"  WHERE sku_code NOT IN ({placeholders})"
        f"    AND is_active = 1"
        f"    AND last_modified_by != 'web'"
    )
    with conn.cursor() as cur:
        cur.execute(sql, list(observed_codes))
        count = cur.rowcount
    conn.commit()
    return count


# ── Summary helpers ───────────────────────────────────────────────────────────

def _top_unresolved(parsed_rows: list[dict], n_top: int = 10) -> list[tuple[str, int]]:
    """Return top N (ingredient_raw, count) pairs that are unresolved."""
    counter: Counter = Counter()
    for r in parsed_rows:
        if r.get("resolution") == "unresolved" and r.get("ingredient_raw"):
            counter[r["ingredient_raw"]] += 1
    return counter.most_common(n_top)


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Ingest BSF SKU_BOM (SKU_BOM!A2:N) into ref_skus + ref_sku_bom.",
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
    args = parser.parse_args()
    dry_run = not args.apply

    cfg = load_config()
    sheets = SheetsClient(cfg.service_account_path)
    conn = connect(cfg)

    try:
        print(f"\n{'[DRY-RUN] ' if dry_run else ''}Fetching {BOM_RANGE} from BSF ...")
        raw_rows = sheets.read_range(cfg.bsf_spreadsheet_id, BOM_RANGE)
        print(f"  fetched {len(raw_rows)} raw rows")

        # Parse
        parsed: list[dict] = []
        skipped_blank = 0
        for row in raw_rows:
            r = parse_row(row)
            if r is None:
                skipped_blank += 1
                continue
            # Strip leading/trailing whitespace from ingredient_raw
            if r.get("ingredient_raw"):
                r["ingredient_raw"] = r["ingredient_raw"].strip()
            parsed.append(r)

        if args.limit:
            parsed = parsed[: args.limit]

        # Pass 1: distinct SKUs
        skus = collect_skus(parsed)
        observed_sku_codes: set[str] = set(skus.keys())

        # Resolution
        resolve_recipe_ids(conn, skus)
        resolve_mi_ids(conn, parsed)

        # Summary stats
        recipe_match = sum(1 for s in skus.values() if s.get("recipe_id") is not None)
        recipe_miss = len(skus) - recipe_match

        res_counts: dict[str, int] = {}
        for r in parsed:
            k = r.get("resolution", "unresolved")
            res_counts[k] = res_counts.get(k, 0) + 1

        print(f"  data rows parsed:          {len(parsed)}")
        print(f"  skipped (blank SKU):       {skipped_blank}")
        print(f"  distinct SKUs:             {len(skus)}")
        print(f"  total BOM lines:           {len(parsed)}")
        print(f"\n  SKU recipe resolution:")
        print(f"    recipe_match: {recipe_match} / no_recipe: {recipe_miss}")
        print(f"\n  Ingredient resolution:")
        for k in ("mi_match", "alias", "unresolved"):
            v = res_counts.get(k, 0)
            print(f"    {k:<12}: {v}")

        top_unresolved = _top_unresolved(parsed)
        if top_unresolved:
            print(f"\n  Top unresolved ingredient_raw values:")
            for ing, cnt in top_unresolved:
                print(f"    {cnt:>4}x  {ing}")

        if dry_run:
            # Estimate DB impact
            with conn.cursor() as cur:
                cur.execute("SELECT sku_code FROM ref_skus")
                existing_codes: set[str] = {r["sku_code"] for r in cur.fetchall()}
                cur.execute("SELECT COUNT(*) AS cnt FROM ref_sku_bom")
                existing_bom_count = cur.fetchone()["cnt"]

            net_new_skus = observed_sku_codes - existing_codes
            to_update_skus = observed_sku_codes & existing_codes
            would_deactivate = existing_codes - observed_sku_codes

            print(f"\n  ref_skus currently in DB:  {len(existing_codes)}")
            print(f"    would insert:  {len(net_new_skus)}")
            print(f"    would update:  {len(to_update_skus)}")
            print(f"    would deactivate: {len(would_deactivate)}")
            print(f"\n  ref_sku_bom currently in DB: {existing_bom_count}")
            print(f"    would upsert: {len(parsed)} BOM lines")

            print(f"\n[dry-run] no rows written. Re-run with --apply to commit.")
            return 0

        # ── Live writes ───────────────────────────────────────────────────────

        snap_path = save_snapshot(raw_rows)
        print(f"  snapshot saved -> {snap_path}")

        # Upsert SKUs
        sku_inserted, sku_updated = upsert_skus(conn, skus)

        # Load sku_code→id map
        sku_id_map = load_sku_id_map(conn, list(observed_sku_codes))

        # Upsert BOM lines
        bom_inserted, bom_updated, observed_bom_ids = upsert_bom_lines(
            conn, parsed, sku_id_map
        )

        # Hard-delete absent BOM lines
        bom_deleted = delete_absent_bom_lines(conn, observed_bom_ids)

        # Soft-deactivate absent SKUs
        sku_deactivated = deactivate_absent_skus(conn, observed_sku_codes)

        print(
            f"\n  SKU upsert:  inserted={sku_inserted}, updated={sku_updated},"
            f" deactivated={sku_deactivated}"
        )
        print(
            f"  BOM upsert:  inserted={bom_inserted}, updated={bom_updated},"
            f" hard-deleted={bom_deleted}"
        )

    finally:
        conn.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
