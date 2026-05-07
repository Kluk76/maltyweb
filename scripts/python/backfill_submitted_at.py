"""
backfill_submitted_at.py — one-shot backfill of submitted_at for all brewing/
fermenting/racking/packaging rows already in MySQL.

The original ingest read col A (or col B for packaging) via FORMATTED_STRING
which returned date-only strings (e.g. "6/5/2026") when the Sheet's display
format strips the time component — yielding midnight (00:00:00) for every row.

This script:
  1. Fetches the timestamp column for each tab using SERIAL_NUMBER so fractional
     time-of-day is preserved.
  2. Parses each serial → Python datetime via dt_serial().
  3. Issues UPDATE … WHERE sheet_row_index = N for every DB table that carries
     a submitted_at column and is keyed by that row.
  4. Default dry-run — print counts per table. Pass --apply to commit.

Tables updated per sheet tab:
  BrewingData  col A  → bd_brewing_brewday, bd_brewing_cooling, bd_brewing_gravity,
                         bd_brewing_timings, bd_brewing_ingredients
  FermentingData col A → bd_fermenting
  RackingData    col A → bd_racking
  PackagingData  col B → bd_packaging, bd_packaging_readings (via FK)

Usage:
  python backfill_submitted_at.py              # dry-run
  python backfill_submitted_at.py --apply      # commit updates
  python backfill_submitted_at.py --sample 5   # show N sample before/after rows
"""
from __future__ import annotations

import argparse
import sys
from datetime import datetime

from lib_config import load as load_config
from lib_coerce import dt_serial
from lib_db import connect
from lib_sheets import SheetsClient

import tab_brewing
import tab_fermenting
import tab_racking
import tab_packaging


BSF_ID = "1zTgfTJrLd_kQfwQxfS9SjQ5MLkUYK-CyXX13TKRMJiE"

# sheet_offset = 2 for all tabs (data starts at row 2, header is row 1).
SHEET_OFFSET = 2

# Each entry: (tab_label, range_a1_for_serial_read, [db_tables_to_update])
# For PackagingData col B is the timestamp column; for all others it's col A.
TAB_SPECS = [
    (
        "BrewingData",
        tab_brewing.RANGE_TIMESTAMP,
        [
            "bd_brewing_brewday",
            "bd_brewing_cooling",
            "bd_brewing_gravity",
            "bd_brewing_timings",
            "bd_brewing_ingredients",
        ],
    ),
    (
        "FermentingData",
        tab_fermenting.RANGE_TIMESTAMP,
        ["bd_fermenting"],
    ),
    (
        "RackingData",
        tab_racking.RANGE_TIMESTAMP,
        ["bd_racking"],
    ),
    (
        "PackagingData",
        tab_packaging.RANGE_TIMESTAMP,
        ["bd_packaging"],
        # bd_packaging_readings inherits submitted_at from the parent join —
        # it does not have its own submitted_at column; skip it here.
    ),
]


def _fetch_serials(sheets: SheetsClient, range_a1: str) -> list[datetime | None]:
    """
    Returns a list indexed by (sheet_row - SHEET_OFFSET), values are parsed
    datetimes (or None when the cell is blank/unparseable).
    """
    raw = sheets.read_range_serial(BSF_ID, range_a1)
    result: list[datetime | None] = []
    for row in raw:
        val = row[0] if row else None
        result.append(dt_serial(val))
    return result


def _count_affected(conn, table: str, sheet_idx: int) -> int:
    with conn.cursor() as cur:
        cur.execute(
            f"SELECT COUNT(*) AS cnt FROM `{table}` WHERE sheet_row_index = %s",
            (sheet_idx,),
        )
        return (cur.fetchone() or {}).get("cnt", 0)


def _current_submitted_at(conn, table: str, sheet_idx: int) -> datetime | None:
    with conn.cursor() as cur:
        cur.execute(
            f"SELECT submitted_at FROM `{table}` WHERE sheet_row_index = %s LIMIT 1",
            (sheet_idx,),
        )
        row = cur.fetchone()
        return row["submitted_at"] if row else None


def _update_table(conn, table: str, sheet_idx: int, new_dt: datetime, *, apply: bool) -> int:
    """Issue UPDATE for this table × sheet_row_index. Returns affected row count."""
    if not apply:
        return _count_affected(conn, table, sheet_idx)
    with conn.cursor() as cur:
        cur.execute(
            f"UPDATE `{table}` SET submitted_at = %s WHERE sheet_row_index = %s",
            (new_dt, sheet_idx),
        )
        return cur.rowcount


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Backfill submitted_at timestamps for brewing/fermenting/racking/packaging rows."
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Commit updates to MySQL. Default is dry-run.",
    )
    parser.add_argument(
        "--sample",
        type=int,
        default=5,
        metavar="N",
        help="Number of sample before/after rows to print per tab (default: 5).",
    )
    args = parser.parse_args()

    cfg = load_config()
    sheets = SheetsClient(cfg.service_account_path)
    conn = connect(cfg)

    grand_total_affected = 0
    grand_total_updated = 0

    try:
        for spec in TAB_SPECS:
            tab_label = spec[0]
            range_a1 = spec[1]
            tables = spec[2]

            print(f"\n{'═' * 60}")
            print(f"  Tab: {tab_label}  →  tables: {', '.join(tables)}")
            print(f"{'═' * 60}")

            serials = _fetch_serials(sheets, range_a1)
            print(f"  Fetched {len(serials)} serial values from {range_a1}")

            # Count how many serials have valid (non-midnight) time components.
            has_time = sum(
                1 for dt_val in serials
                if dt_val is not None and (dt_val.hour != 0 or dt_val.minute != 0 or dt_val.second != 0)
            )
            print(f"  Non-midnight timestamps: {has_time} / {len(serials)}")

            # Collect sample rows: prefer rows where new_dt has a non-midnight time
            # (most illustrative), then fall back to date-correction rows.
            samples_with_time: list[tuple] = []
            samples_date_fix: list[tuple] = []
            tab_total_affected = 0
            tab_total_updated = 0

            for i_row, new_dt in enumerate(serials):
                if new_dt is None:
                    continue
                sheet_idx = SHEET_OFFSET + i_row

                for table in tables:
                    # Skip rows where current submitted_at already has time-of-day
                    # (avoids redundant updates; also guards against future re-runs).
                    current = _current_submitted_at(conn, table, sheet_idx)
                    if current is None:
                        # Row not in this table (different event_type for brewing).
                        continue
                    if current.hour != 0 or current.minute != 0 or current.second != 0:
                        # Already has a non-midnight time — skip.
                        continue

                    tab_total_affected += 1

                    # Collect samples for display.
                    entry = (sheet_idx, table, current, new_dt)
                    new_has_time = (new_dt.hour != 0 or new_dt.minute != 0 or new_dt.second != 0)
                    date_changed = (current.date() != new_dt.date())
                    if new_has_time and len(samples_with_time) < args.sample:
                        samples_with_time.append(entry)
                    elif date_changed and len(samples_date_fix) < args.sample:
                        samples_date_fix.append(entry)

                    n_updated = _update_table(conn, table, sheet_idx, new_dt, apply=args.apply)
                    tab_total_updated += n_updated

            # Print samples: time-precision wins over date-correction.
            display_samples = samples_with_time or samples_date_fix
            for sheet_idx, table, current, new_dt in display_samples[:args.sample]:
                print(
                    f"  sample  sheet_row={sheet_idx:5d}  table={table}"
                    f"\n          current={current}  →  new={new_dt}"
                )

            if args.apply:
                conn.commit()

            print(f"\n  Rows with midnight submitted_at (needing update): {tab_total_affected}")
            if args.apply:
                print(f"  Rows updated: {tab_total_updated}")
            else:
                print(f"  [dry-run] would update: {tab_total_affected}")

            grand_total_affected += tab_total_affected
            grand_total_updated += tab_total_updated

    finally:
        conn.close()

    print(f"\n{'═' * 60}")
    print(f"  GRAND TOTAL — rows needing update: {grand_total_affected}")
    if args.apply:
        print(f"  GRAND TOTAL — rows updated:        {grand_total_updated}")
        print("\n  ✓ backfill complete")
    else:
        print("\n  [dry-run] no rows written. Re-run with --apply to commit.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
