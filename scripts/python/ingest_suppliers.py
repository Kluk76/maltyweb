"""
ingest_suppliers.py — ingest BSF!Suppliers A2:G into ref_suppliers + ref_supplier_summary.

Source: BSF spreadsheet, tab Suppliers, range A2:G (7 columns, row 1 is header).
Column map (0-based):
  0  ID (supplier_id)   e.g. SUP_BRAU_RAUCHSHOP_4102
  1  NAME               canonical display name
  2  ACCOUNT            GL account string ("4101", "4200", …)
  3  CATEGORY           formula-derived display field (may be stale)
  4  CURRENCY           EUR | CHF
  5  ACTIVE             "Yes" | "No"
  6  NOTES              aggregate stats + human notes

Multi-GL suppliers emit one row per (supplier, GL) pair — same NAME, distinct ID.
Each row is treated as independent. Do NOT collapse.

Upsert policy: ON DUPLICATE KEY UPDATE — last_seen_at refreshed every run;
imported_at kept from original INSERT (never overwritten on update).

Deactivation pass: any supplier_id NOT seen in the current BSF fetch is set
is_active=0 (not deleted — downstream FK references must survive).

Summary recompute: ref_supplier_summary is TRUNCATE+INSERT at the end of every
live run. It is a derived projection only — safe to truncate.

Snapshot: before any live write the raw BSF range is snapshotted to
  scripts/python/data/supplier-snapshots/<timestamp>.json  (last 3 kept).

Usage:
  python ingest_suppliers.py             # dry-run (default) — prints counts, no writes
  python ingest_suppliers.py --apply     # live write
  python ingest_suppliers.py --limit 20  # debug: process only first N data rows
"""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from pathlib import Path

from lib_config import load as load_config
from lib_coerce import s
from lib_db import connect
from lib_hashing import row_hash
from lib_sheets import SheetsClient

SUPPLIERS_RANGE = "Suppliers!A2:G"
SUPPLIERS_WIDTH = 7  # columns A..G

# Snapshot directory relative to this script's location
SCRIPT_DIR = Path(__file__).parent
SNAPSHOT_DIR = SCRIPT_DIR / "data" / "supplier-snapshots"


# ── Snapshot ─────────────────────────────────────────────────────────────────

def save_snapshot(raw_rows: list) -> Path:
    SNAPSHOT_DIR.mkdir(parents=True, exist_ok=True)
    ts = datetime.utcnow().strftime("%Y%m%dT%H%M%S")
    dest = SNAPSHOT_DIR / f"suppliers-{ts}.json"
    dest.write_text(json.dumps(raw_rows, indent=2), encoding="utf-8")
    # Keep last 3 only
    existing = sorted(SNAPSHOT_DIR.glob("suppliers-*.json"))
    for old in existing[:-3]:
        old.unlink()
    return dest


# ── Row parsing ───────────────────────────────────────────────────────────────

def parse_row(row: list) -> dict | None:
    """
    Parse a raw Sheets row into a typed dict.
    Returns None if supplier_id (col 0) is empty — skip row.
    """
    # Pad to full width so indexing is safe
    padded = list(row) + [""] * max(0, SUPPLIERS_WIDTH - len(row))

    supplier_id = s(padded[0])
    if not supplier_id:
        return None

    active_raw = s(padded[5])
    is_active = 1 if (active_raw or "").lower() == "yes" else 0

    return {
        "supplier_id": supplier_id,
        "name":        s(padded[1]) or supplier_id,  # fall back to ID if name blank
        "gl_account":  s(padded[2]),
        "category":    s(padded[3]),
        "currency":    s(padded[4]),
        "is_active":   is_active,
        "notes":       s(padded[6]),
        # raw hash over the full 7-cell row (before any coercion)
        "row_hash":    row_hash(padded, SUPPLIERS_WIDTH),
    }


# ── Main upsert ───────────────────────────────────────────────────────────────

def upsert_supplier_rows(conn, parsed_rows: list[dict]) -> tuple[int, int]:
    """
    INSERT ... ON DUPLICATE KEY UPDATE for ref_suppliers.
    Returns (inserted, updated) counts.
    """
    inserted = 0
    updated = 0

    with conn.cursor() as cur:
        for r in parsed_rows:
            cur.execute(
                """
                INSERT INTO ref_suppliers
                  (supplier_id, name, gl_account, category,
                   currency, is_active, notes, row_hash,
                   last_seen_at, imported_at, last_modified_by)
                VALUES
                  (%s, %s, %s, %s,
                   %s, %s, %s, %s,
                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'ingest')
                ON DUPLICATE KEY UPDATE
                  -- Emancipation: web-pinned rows preserve every column.
                  name         = IF(last_modified_by = 'web', name,       VALUES(name)),
                  gl_account   = IF(last_modified_by = 'web', gl_account, VALUES(gl_account)),
                  category     = IF(last_modified_by = 'web', category,   VALUES(category)),
                  currency     = IF(last_modified_by = 'web', currency,   VALUES(currency)),
                  is_active    = IF(last_modified_by = 'web', is_active,  VALUES(is_active)),
                  notes        = IF(last_modified_by = 'web', notes,      VALUES(notes)),
                  row_hash     = IF(last_modified_by = 'web', row_hash,   VALUES(row_hash)),
                  last_seen_at = CURRENT_TIMESTAMP
                """,
                (
                    r["supplier_id"], r["name"], r["gl_account"], r["category"],
                    r["currency"], r["is_active"], r["notes"], r["row_hash"],
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


# ── Deactivation pass ─────────────────────────────────────────────────────────

def deactivate_absent(conn, observed_ids: set[str]) -> int:
    """
    Mark is_active=0 for any ref_suppliers row whose supplier_id was NOT observed
    in the current BSF fetch. Does not delete rows (downstream FKs must survive).
    Returns count of rows deactivated.
    """
    if not observed_ids:
        return 0
    placeholders = ", ".join(["%s"] * len(observed_ids))
    # Emancipation: web-pinned rows are exempt from auto-deactivation.
    sql = (
        f"UPDATE ref_suppliers SET is_active = 0"
        f"  WHERE supplier_id NOT IN ({placeholders})"
        f"    AND is_active = 1"
        f"    AND last_modified_by != 'web'"
    )
    with conn.cursor() as cur:
        cur.execute(sql, list(observed_ids))
        count = cur.rowcount
    conn.commit()
    return count


# ── Summary recompute ─────────────────────────────────────────────────────────

def recompute_summary(conn, dry_run: bool) -> int:
    """
    Truncate ref_supplier_summary and rebuild it as a one-row-per-name projection
    aggregating multi-GL siblings from ref_suppliers.

    modal_gl  = the gl_account that appears most often for that name
    modal_cur = the currency that appears most often for that name
    is_active = 1 if ANY (supplier_id, GL) pair for that name is active

    Returns the number of rows written (or that would be written in dry-run).
    """
    select_sql = """
        SELECT
            s1.name,
            COUNT(*)                                                      AS gl_count,
            (SELECT s2.gl_account
               FROM ref_suppliers s2
              WHERE s2.name = s1.name
                AND s2.gl_account IS NOT NULL
              GROUP BY s2.gl_account
              ORDER BY COUNT(*) DESC
              LIMIT 1)                                                    AS modal_gl,
            MAX(s1.is_active)                                             AS is_active,
            (SELECT s3.currency
               FROM ref_suppliers s3
              WHERE s3.name = s1.name
                AND s3.currency IS NOT NULL
              GROUP BY s3.currency
              ORDER BY COUNT(*) DESC
              LIMIT 1)                                                    AS currency
        FROM ref_suppliers s1
        GROUP BY s1.name
    """

    with conn.cursor() as cur:
        cur.execute(select_sql)
        rows = cur.fetchall()

    if dry_run:
        print(f"\n  [dry-run] ref_supplier_summary would contain {len(rows)} row(s)")
        print("  sample (first 5):")
        for r in rows[:5]:
            print(
                f"    {r['name']:<40s}  gl_count={r['gl_count']}"
                f"  modal_gl={r['modal_gl'] or '—':<6s}"
                f"  active={r['is_active']}  currency={r['currency'] or '—'}"
            )
        return len(rows)

    with conn.cursor() as cur:
        cur.execute("TRUNCATE TABLE ref_supplier_summary")
        for r in rows:
            cur.execute(
                """
                INSERT INTO ref_supplier_summary
                  (name, gl_count, modal_gl, is_active, currency, last_seen_at)
                VALUES
                  (%s, %s, %s, %s, %s, CURRENT_TIMESTAMP)
                """,
                (r["name"], r["gl_count"], r["modal_gl"], r["is_active"], r["currency"]),
            )
    conn.commit()
    return len(rows)


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Ingest BSF Suppliers (Suppliers!A2:G) into ref_suppliers + ref_supplier_summary.",
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
        print(f"\n{'[DRY-RUN] ' if dry_run else ''}Fetching {SUPPLIERS_RANGE} from BSF …")
        raw_rows = sheets.read_range(cfg.bsf_spreadsheet_id, SUPPLIERS_RANGE)
        print(f"  fetched {len(raw_rows)} raw rows")

        # Parse rows — first data row (header is row 1, range starts at row 2)
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

        observed_ids: set[str] = {r["supplier_id"] for r in parsed}

        # Summary counts
        distinct_names = {r["name"] for r in parsed}
        multi_gl_names = {
            r["name"] for r in parsed
            if sum(1 for p in parsed if p["name"] == r["name"]) > 1
        }

        print(f"  data rows parsed:          {len(parsed)}")
        print(f"  skipped (blank ID):        {skipped_blank}")
        print(f"  distinct supplier names:   {len(distinct_names)}")
        print(f"  multi-GL suppliers:        {len(multi_gl_names)}")
        print(f"  total (supplier, GL) pairs:{len(parsed)}")

        if dry_run:
            # Check what already exists in DB to estimate net-new vs updates
            with conn.cursor() as cur:
                cur.execute("SELECT supplier_id FROM ref_suppliers")
                existing_ids: set[str] = {r["supplier_id"] for r in cur.fetchall()}
                cur.execute(
                    "SELECT COUNT(*) AS cnt FROM ref_suppliers WHERE is_active = 1"
                )
                active_count = cur.fetchone()["cnt"]

            net_new = observed_ids - existing_ids
            to_update = observed_ids & existing_ids
            would_deactivate = len(existing_ids - observed_ids)

            print(f"\n  currently in DB:           {len(existing_ids)} (active: {active_count})")
            print(f"  would insert (net-new):    {len(net_new)}")
            print(f"  would update (existing):   {len(to_update)}")
            print(f"  would deactivate (absent): {would_deactivate}")

            print("\n  sample rows (first 5):")
            for r in parsed[:5]:
                print(
                    f"    {r['supplier_id']:<44s}  name={r['name'][:30]:<30s}"
                    f"  gl={r['gl_account'] or '—':<6s}  active={r['is_active']}"
                )

            recompute_summary(conn, dry_run=True)

            print(f"\n[dry-run] no rows written. Re-run with --apply to commit.")
            return 0

        # ── Live writes ───────────────────────────────────────────────────────

        snap_path = save_snapshot(raw_rows)
        print(f"  snapshot saved → {snap_path}")

        inserted, updated = upsert_supplier_rows(conn, parsed)
        deactivated = deactivate_absent(conn, observed_ids)
        summary_rows = recompute_summary(conn, dry_run=False)

        print(
            f"\n  inserted {inserted}, updated {updated}, "
            f"deactivated {deactivated}, summary rows {summary_rows}"
        )

    finally:
        conn.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
