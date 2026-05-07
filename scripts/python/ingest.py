"""
ingest.py — CLI orchestrator. Reads BSF tabs and upserts into MySQL.

Usage:
  python ingest.py --tab=brewing|fermenting|racking|packaging|all [--apply] [--limit N]

Default mode is dry-run: parses + counts, prints summary, writes nothing.
With --apply: INSERT IGNORE into bd_* tables (idempotent via row_hash UNIQUE).
"""
from __future__ import annotations

import argparse
import sys

from lib_config import load as load_config
from lib_db import connect, insert_ignore
from lib_sheets import SheetsClient

import tab_brewing
import tab_fermenting
import tab_racking
import tab_packaging


TAB_HANDLERS = {
    "brewing":    ("brewing",    tab_brewing),
    "fermenting": ("fermenting", tab_fermenting),
    "racking":    ("racking",    tab_racking),
    "packaging":  ("packaging",  tab_packaging),
}


def _print_header(tab_name: str):
    print(f"\n══ {tab_name} ".ljust(60, "═"))


def _ingest_simple(name: str, mod, sheets: SheetsClient, conn, *, apply_writes: bool, limit: int | None):
    """Used for fermenting + racking (single table)."""
    _print_header(name)
    raw = sheets.read_range(_spreadsheet_id(), mod.RANGE)
    if limit is not None:
        raw = raw[:limit]
    print(f"  fetched     {len(raw):>5} rows")
    parsed = mod.process(raw)
    table = mod.TABLE
    rows = parsed[table]
    print(f"  parsed      {len(rows):>5} rows → {table}")
    if apply_writes and rows:
        ins = insert_ignore(conn, table, rows)
        conn.commit()
        print(f"  inserted    {ins:>5} (skipped {len(rows) - ins} duplicates)")
    elif apply_writes:
        print(f"  inserted    {0:>5} (no rows)")
    else:
        print(f"  [dry-run] would INSERT IGNORE up to {len(rows)} rows")


_BREWING_TABLES = (
    "bd_brewing_brewday",
    "bd_brewing_gravity",
    "bd_brewing_cooling",
    "bd_brewing_timings",
    "bd_brewing_ingredients",
)


def _ingest_brewing(sheets: SheetsClient, conn, *, apply_writes: bool, limit: int | None):
    _print_header(f"brewing ({len(_BREWING_TABLES)} tables)")
    raw = sheets.read_range(_spreadsheet_id(), tab_brewing.RANGE)
    if limit is not None:
        raw = raw[:limit]
    print(f"  fetched     {len(raw):>5} rows")
    parsed = tab_brewing.process(raw)
    for table in _BREWING_TABLES:
        rows = parsed[table]
        print(f"  parsed      {len(rows):>5} rows → {table}")
    unmatched = parsed.get("_unmatched", [])
    if unmatched:
        print(f"  unmatched   {len(unmatched):>5} rows (event_type not in dispatch map)")
        seen_types = {u['event_type'] for u in unmatched}
        for et in sorted(seen_types):
            cnt = sum(1 for u in unmatched if u['event_type'] == et)
            print(f"     · {et!r:30s} {cnt}")
    if apply_writes:
        for table in _BREWING_TABLES:
            rows = parsed[table]
            if rows:
                ins = insert_ignore(conn, table, rows)
                print(f"  inserted    {ins:>5} → {table} (skipped {len(rows) - ins} duplicates)")
        conn.commit()
    else:
        total = sum(len(parsed[t]) for t in _BREWING_TABLES)
        print(f"  [dry-run] would INSERT IGNORE up to {total} rows across {len(_BREWING_TABLES)} tables")


def _ingest_packaging(sheets: SheetsClient, conn, *, apply_writes: bool, limit: int | None):
    _print_header("packaging (parent + readings)")
    raw = sheets.read_range(_spreadsheet_id(), tab_packaging.RANGE)
    if limit is not None:
        raw = raw[:limit]
    print(f"  fetched     {len(raw):>5} rows")
    parents, readings = tab_packaging.process(raw)
    print(f"  parsed      {len(parents):>5} rows → bd_packaging")
    total_reads = sum(len(rs) for _, rs in readings)
    print(f"  parsed      {total_reads:>5} O2/CO2 readings → bd_packaging_readings")

    if not apply_writes:
        print(f"  [dry-run] would INSERT IGNORE up to {len(parents)} parents + {total_reads} readings")
        return

    if not parents:
        return

    # Insert parents first
    ins = insert_ignore(conn, "bd_packaging", parents)
    print(f"  inserted    {ins:>5} → bd_packaging (skipped {len(parents) - ins} duplicates)")
    conn.commit()

    # Resolve packaging_id for each parent_hash (whether newly inserted or pre-existing)
    if not readings:
        return
    hashes = [h for h, _ in readings]
    placeholders = ", ".join(["%s"] * len(hashes))
    with conn.cursor() as cur:
        cur.execute(
            f"SELECT row_hash, id FROM bd_packaging WHERE row_hash IN ({placeholders})",
            hashes,
        )
        hash_to_id = {r["row_hash"]: r["id"] for r in cur.fetchall()}

    reading_rows: list[dict] = []
    for parent_hash, rs in readings:
        pid = hash_to_id.get(parent_hash)
        if pid is None:
            continue  # parent insert failed somehow
        for r in rs:
            reading_rows.append({
                "packaging_id": pid,
                "reading_idx": r["reading_idx"],
                "o2": r["o2"],
                "co2": r["co2"],
            })
    if reading_rows:
        rins = insert_ignore(conn, "bd_packaging_readings", reading_rows)
        print(f"  inserted    {rins:>5} → bd_packaging_readings (skipped {len(reading_rows) - rins} duplicates)")
        conn.commit()


def _spreadsheet_id() -> str:
    return _CFG.bsf_spreadsheet_id


def main() -> int:
    parser = argparse.ArgumentParser(description="Ingest BSF Sheets tabs into MySQL.")
    parser.add_argument("--tab", default="all",
                        choices=["brewing", "fermenting", "racking", "packaging", "all"])
    parser.add_argument("--apply", action="store_true",
                        help="Actually write to MySQL. Default is dry-run.")
    parser.add_argument("--limit", type=int, default=None,
                        help="Cap rows per tab (debug).")
    args = parser.parse_args()

    global _CFG
    _CFG = load_config()
    sheets = SheetsClient(_CFG.service_account_path)
    conn = connect(_CFG)

    try:
        tabs = list(TAB_HANDLERS.keys()) if args.tab == "all" else [args.tab]
        for t in tabs:
            if t == "brewing":
                _ingest_brewing(sheets, conn, apply_writes=args.apply, limit=args.limit)
            elif t == "packaging":
                _ingest_packaging(sheets, conn, apply_writes=args.apply, limit=args.limit)
            else:
                _, mod = TAB_HANDLERS[t]
                _ingest_simple(t, mod, sheets, conn, apply_writes=args.apply, limit=args.limit)
        if not args.apply:
            print("\n[dry-run] no rows written. Re-run with --apply to commit.")
    finally:
        conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
