"""
ingest_mi.py — ingest MasterIngredients from BSF!Validations FB:FP into ref_mi_*.

Source: BSF spreadsheet, tab Validations, range FB:FP (15 columns, no header row).
Column map (0-based):
  0  ID (mi_id)          e.g. HOPS_CITRA_PEL
  1  NAME
  2  CATEGORY
  3  SUBCATEGORY
  4  INPUT_UNIT
  5  PRICING_UNIT
  6  CONVERSION (numeric)
  7  CURRENCY
  8  PRICE (numeric)
  9  ACTIVE              "Yes" | "No"
 10  PACK_SIZE (numeric)
 11  ALIASES             pipe-separated supplier-side names
 12  SUPPLIER
 13  NOTES
 14  ACCOUNT             GL account string e.g. "4101"

Upsert policy: ON DUPLICATE KEY UPDATE — last_seen_at is refreshed every run;
imported_at is kept from the original INSERT (unchanged on update).

Deactivation pass: any mi_id NOT seen in the current BSF fetch is set is_active=0
(not deleted — downstream FK references from inv_deliveries must survive).

Snapshot: before any live write the raw BSF range is snapshotted to
  scripts/python/data/mi-snapshots/<timestamp>.json  (last 3 kept).

Usage:
  python ingest_mi.py             # dry-run (default) — prints counts, no writes
  python ingest_mi.py --apply     # live write
  python ingest_mi.py --limit 20  # debug: process only first N data rows
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import datetime
from pathlib import Path

from lib_config import load as load_config
from lib_coerce import n, s
from lib_db import connect
from lib_hashing import row_hash
from lib_sheets import SheetsClient

MI_RANGE = "Validations!FB4:FP"  # data rows only (rows 1-3 are section title + header)
MI_WIDTH = 15  # columns FB..FP

# Snapshot directory relative to this script's location
SCRIPT_DIR = Path(__file__).parent
SNAPSHOT_DIR = SCRIPT_DIR / "data" / "mi-snapshots"


# ── Snapshot ─────────────────────────────────────────────────────────────────

def save_snapshot(raw_rows: list) -> Path:
    SNAPSHOT_DIR.mkdir(parents=True, exist_ok=True)
    ts = datetime.utcnow().strftime("%Y%m%dT%H%M%S")
    dest = SNAPSHOT_DIR / f"mi-{ts}.json"
    dest.write_text(json.dumps(raw_rows, indent=2), encoding="utf-8")
    # Keep last 3 only
    existing = sorted(SNAPSHOT_DIR.glob("mi-*.json"))
    for old in existing[:-3]:
        old.unlink()
    return dest


# ── Row parsing ───────────────────────────────────────────────────────────────

def parse_row(row: list) -> dict | None:
    """
    Parse a raw Sheets row into a typed dict.
    Returns None if mi_id is empty (skip row).
    """
    # Pad to full width so indexing is safe
    padded = list(row) + [""] * max(0, MI_WIDTH - len(row))

    mi_id = s(padded[0])
    if not mi_id:
        return None

    active_raw = s(padded[9])
    is_active = 1 if (active_raw or "").lower() == "yes" else 0

    # Aliases: split on '|', strip each, discard empties
    aliases_raw = s(padded[11])
    aliases: list[str] = []
    if aliases_raw:
        aliases = [a.strip() for a in aliases_raw.split("|") if a.strip()]

    return {
        "mi_id":             mi_id,
        "name":              s(padded[1]) or mi_id,  # fall back to ID if name blank
        "category":          s(padded[2]),
        "subcategory":       s(padded[3]),
        "input_unit":        s(padded[4]),
        "pricing_unit":      s(padded[5]),
        "conversion_factor": n(padded[6]),
        "currency":          s(padded[7]),
        "price":             n(padded[8]),
        "is_active":         is_active,
        "pack_size":         n(padded[10]),
        "aliases":           aliases,
        "preferred_supplier": s(padded[12]),
        "notes":             s(padded[13]),
        "gl_account":        s(padded[14]),
        # raw hash over the full 15-cell row (before any coercion)
        "row_hash":          row_hash(padded, MI_WIDTH),
    }


# ── Category / subcategory upserts ───────────────────────────────────────────

def ensure_categories(conn, parsed_rows: list[dict]) -> dict[str, int]:
    """
    INSERT IGNORE all distinct categories.
    Then UPDATE default_gl_account from the first non-empty account seen per cat
    (only when the column is still NULL — never overwrites an operator-set value).
    Returns {category_name: id}.
    """
    # Collect distinct cats and the first non-empty account seen for each
    cat_accounts: dict[str, str | None] = {}
    for r in parsed_rows:
        cat = r["category"]
        if not cat:
            continue
        if cat not in cat_accounts:
            cat_accounts[cat] = r["gl_account"]
        elif cat_accounts[cat] is None and r["gl_account"]:
            cat_accounts[cat] = r["gl_account"]

    with conn.cursor() as cur:
        for cat_name in cat_accounts:
            cur.execute(
                "INSERT IGNORE INTO ref_mi_categories (name) VALUES (%s)",
                (cat_name,),
            )

        # Backfill default_gl_account where still NULL
        for cat_name, gl in cat_accounts.items():
            if gl:
                cur.execute(
                    "UPDATE ref_mi_categories"
                    "  SET default_gl_account = %s"
                    "  WHERE name = %s AND default_gl_account IS NULL",
                    (gl, cat_name),
                )

        conn.commit()

        cur.execute("SELECT id, name FROM ref_mi_categories")
        return {r["name"]: r["id"] for r in cur.fetchall()}


def ensure_subcategories(conn, parsed_rows: list[dict],
                         cat_to_id: dict[str, int]) -> dict[tuple[int, str], int]:
    """
    INSERT IGNORE all distinct (category_id, subcategory) pairs.
    Returns {(category_id, subcat_name): id}.
    """
    seen: set[tuple[int, str]] = set()
    for r in parsed_rows:
        cat = r["category"]
        sub = r["subcategory"]
        if not cat or not sub:
            continue
        cat_id = cat_to_id.get(cat)
        if cat_id is None:
            continue
        seen.add((cat_id, sub))

    with conn.cursor() as cur:
        for cat_id, sub_name in seen:
            cur.execute(
                "INSERT IGNORE INTO ref_mi_subcategories (category_id, name) VALUES (%s, %s)",
                (cat_id, sub_name),
            )
        conn.commit()

        cur.execute("SELECT id, category_id, name FROM ref_mi_subcategories")
        return {(r["category_id"], r["name"]): r["id"] for r in cur.fetchall()}


# ── Main upsert ───────────────────────────────────────────────────────────────

def upsert_mi_rows(conn, parsed_rows: list[dict],
                   cat_to_id: dict[str, int],
                   subcat_to_id: dict[tuple[int, str], int]) -> tuple[int, int]:
    """
    INSERT ... ON DUPLICATE KEY UPDATE for ref_mi.
    Returns (inserted, updated) counts.
    """
    inserted = 0
    updated = 0

    with conn.cursor() as cur:
        for r in parsed_rows:
            cat_id = cat_to_id.get(r["category"]) if r["category"] else None
            sub_id = None
            if r["subcategory"] and cat_id is not None:
                sub_id = subcat_to_id.get((cat_id, r["subcategory"]))

            cur.execute(
                """
                INSERT INTO ref_mi
                  (mi_id, name, category_id, subcategory_id,
                   input_unit, pricing_unit, conversion_factor,
                   currency, price, pack_size, preferred_supplier,
                   gl_account, notes, is_active, row_hash,
                   last_seen_at, imported_at, last_modified_by)
                VALUES
                  (%s, %s, %s, %s,
                   %s, %s, %s,
                   %s, %s, %s, %s,
                   %s, %s, %s, %s,
                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'ingest')
                ON DUPLICATE KEY UPDATE
                  -- Emancipation: rows pinned by Maltyweb (last_modified_by='web')
                  -- preserve every column. last_seen_at always refreshes so
                  -- the deactivation pass below knows the row was observed.
                  name               = IF(last_modified_by = 'web', name,               VALUES(name)),
                  category_id        = IF(last_modified_by = 'web', category_id,        VALUES(category_id)),
                  subcategory_id     = IF(last_modified_by = 'web', subcategory_id,     VALUES(subcategory_id)),
                  input_unit         = IF(last_modified_by = 'web', input_unit,         VALUES(input_unit)),
                  pricing_unit       = IF(last_modified_by = 'web', pricing_unit,       VALUES(pricing_unit)),
                  conversion_factor  = IF(last_modified_by = 'web', conversion_factor,  VALUES(conversion_factor)),
                  currency           = IF(last_modified_by = 'web', currency,           VALUES(currency)),
                  price              = IF(last_modified_by = 'web', price,              VALUES(price)),
                  pack_size          = IF(last_modified_by = 'web', pack_size,          VALUES(pack_size)),
                  preferred_supplier = IF(last_modified_by = 'web', preferred_supplier, VALUES(preferred_supplier)),
                  gl_account         = IF(last_modified_by = 'web', gl_account,         VALUES(gl_account)),
                  notes              = IF(last_modified_by = 'web', notes,              VALUES(notes)),
                  is_active          = IF(last_modified_by = 'web', is_active,          VALUES(is_active)),
                  row_hash           = IF(last_modified_by = 'web', row_hash,           VALUES(row_hash)),
                  last_seen_at       = CURRENT_TIMESTAMP
                """,
                (
                    r["mi_id"], r["name"], cat_id, sub_id,
                    r["input_unit"], r["pricing_unit"], r["conversion_factor"],
                    r["currency"], r["price"], r["pack_size"], r["preferred_supplier"],
                    r["gl_account"], r["notes"], r["is_active"], r["row_hash"],
                ),
            )
            # MySQL: ON DUPLICATE KEY UPDATE returns rowcount=1 for insert, 2 for update,
            # 0 when the row existed but no field changed.
            if cur.rowcount == 1:
                inserted += 1
            elif cur.rowcount == 2:
                updated += 1

    conn.commit()
    return inserted, updated


# ── Alias replacement ─────────────────────────────────────────────────────────

def replace_aliases(conn, parsed_rows: list[dict]) -> int:
    """
    For each MI row that carries aliases:
      DELETE existing aliases for that ref_mi.id, then INSERT the current set.
    Returns total alias rows written.
    """
    total = 0
    with conn.cursor() as cur:
        for r in parsed_rows:
            if not r["aliases"]:
                continue
            # Resolve internal PK
            cur.execute("SELECT id FROM ref_mi WHERE mi_id = %s", (r["mi_id"],))
            row = cur.fetchone()
            if not row:
                continue
            pk = row["id"]
            cur.execute("DELETE FROM ref_mi_aliases WHERE mi_id_fk = %s", (pk,))
            for alias in r["aliases"]:
                cur.execute(
                    "INSERT IGNORE INTO ref_mi_aliases (mi_id_fk, alias) VALUES (%s, %s)",
                    (pk, alias),
                )
                total += cur.rowcount
    conn.commit()
    return total


# ── Deactivation pass ─────────────────────────────────────────────────────────

def deactivate_absent(conn, observed_mi_ids: set[str]) -> int:
    """
    Mark is_active=0 for any ref_mi row whose mi_id was NOT observed in the
    current BSF fetch. Does not delete rows (downstream FKs must survive).
    Returns count of rows deactivated.
    """
    if not observed_mi_ids:
        return 0
    # Build a placeholder list — pymysql doesn't support IN with a set directly
    placeholders = ", ".join(["%s"] * len(observed_mi_ids))
    # Emancipation: web-pinned rows are exempt from auto-deactivation.
    sql = (
        f"UPDATE ref_mi SET is_active = 0"
        f"  WHERE mi_id NOT IN ({placeholders})"
        f"    AND is_active = 1"
        f"    AND last_modified_by != 'web'"
    )
    with conn.cursor() as cur:
        cur.execute(sql, list(observed_mi_ids))
        count = cur.rowcount
    conn.commit()
    return count


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Ingest BSF MasterIngredients (Validations!FB:FP) into ref_mi_*.",
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
        print(f"\n{'[DRY-RUN] ' if dry_run else ''}Fetching {MI_RANGE} from BSF …")
        raw_rows = sheets.read_range(cfg.bsf_spreadsheet_id, MI_RANGE)
        print(f"  fetched {len(raw_rows)} raw rows")

        # Parse rows — first row is data (no header)
        parsed: list[dict] = []
        skipped_blank = 0
        for row in raw_rows:
            r = parse_row(row)
            if r is None:
                skipped_blank += 1
                continue
            parsed.append(r)

        if args.limit:
            parsed = parsed[: args.limit]

        observed_ids: set[str] = {r["mi_id"] for r in parsed}

        # Summary counts
        distinct_cats = {r["category"] for r in parsed if r["category"]}
        distinct_subcats = {
            (r["category"], r["subcategory"])
            for r in parsed if r["category"] and r["subcategory"]
        }
        total_aliases = sum(len(r["aliases"]) for r in parsed)

        print(f"  data rows parsed:          {len(parsed)}")
        print(f"  skipped (blank ID):        {skipped_blank}")
        print(f"  distinct categories:       {len(distinct_cats)}")
        print(f"  distinct subcategories:    {len(distinct_subcats)}")
        print(f"  total alias values:        {total_aliases}")

        if dry_run:
            # Check what already exists in DB to estimate net-new vs updates
            with conn.cursor() as cur:
                cur.execute("SELECT mi_id FROM ref_mi")
                existing_ids: set[str] = {r["mi_id"] for r in cur.fetchall()}
                cur.execute(
                    "SELECT COUNT(*) AS cnt FROM ref_mi WHERE is_active = 1"
                )
                active_count = cur.fetchone()["cnt"]

            net_new = observed_ids - existing_ids
            to_update = observed_ids & existing_ids
            would_deactivate = existing_ids - observed_ids - {
                mi for mi in existing_ids
            }
            # Recompute properly
            would_deactivate = len(existing_ids - observed_ids)

            print(f"\n  currently in DB:           {len(existing_ids)} (active: {active_count})")
            print(f"  would insert (net-new):    {len(net_new)}")
            print(f"  would update (existing):   {len(to_update)}")
            print(f"  would deactivate (absent): {would_deactivate}")

            print("\n  sample rows (first 5):")
            for r in parsed[:5]:
                print(
                    f"    {r['mi_id']:<40s}  cat={r['category'] or '—':<16s}"
                    f"  price={r['price']}  active={r['is_active']}"
                    f"  aliases={len(r['aliases'])}"
                )
            print(f"\n[dry-run] no rows written. Re-run with --apply to commit.")
            return 0

        # ── Live writes ───────────────────────────────────────────────────────

        snap_path = save_snapshot(raw_rows)
        print(f"  snapshot saved → {snap_path}")

        cat_to_id = ensure_categories(conn, parsed)
        subcat_to_id = ensure_subcategories(conn, parsed, cat_to_id)

        inserted, updated = upsert_mi_rows(conn, parsed, cat_to_id, subcat_to_id)
        aliases_written = replace_aliases(conn, parsed)
        deactivated = deactivate_absent(conn, observed_ids)

        print(
            f"\n  inserted {inserted}, updated {updated}, "
            f"aliases {aliases_written}, deactivated {deactivated}"
        )

    finally:
        conn.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
