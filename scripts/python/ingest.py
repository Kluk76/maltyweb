"""
ingest.py — CLI orchestrator. Reads BSF tabs and upserts into MySQL.

Usage:
  python ingest.py --tab=brewing|fermenting|racking|packaging|all [--apply] [--limit N] [--trigger=cron|manual|cli]

Default mode is dry-run: parses + counts, prints summary, writes nothing.
With --apply: INSERT IGNORE into bd_* tables (idempotent via row_hash UNIQUE).
"""
from __future__ import annotations

import argparse
import sys

from lib_config import load as load_config
from lib_db import (
    connect,
    insert_ignore,
    insert_with_failure_log,
    auto_resolve_failures,
    open_ingest_run,
    close_ingest_run,
)
from lib_sheets import SheetsClient

import tab_brewing
import tab_fermenting
import tab_racking
import tab_packaging
from lib_yeast import load_yeast_canonical_map


TAB_HANDLERS = {
    "brewing":    ("brewing",    tab_brewing),
    "fermenting": ("fermenting", tab_fermenting),
    "racking":    ("racking",    tab_racking),
    "packaging":  ("packaging",  tab_packaging),
}


def _print_header(tab_name: str):
    print(f"\n══ {tab_name} ".ljust(60, "═"))


def _make_tab_summary() -> dict:
    return {"fetched": 0, "parsed": 0, "inserted": 0, "duplicates": 0, "failed": 0}


def _ingest_simple(
    name: str,
    mod,
    sheets: SheetsClient,
    conn,
    *,
    apply_writes: bool,
    limit: int | None,
    run_id: int,
) -> tuple[int, dict]:
    """Used for fermenting + racking (single table). Returns (fk_failure_count, tab_summary)."""
    _print_header(name)
    tab_sum = _make_tab_summary()

    raw = sheets.read_range(_spreadsheet_id(), mod.RANGE)
    ts_serials = sheets.read_range_serial(_spreadsheet_id(), mod.RANGE_TIMESTAMP)
    if limit is not None:
        raw = raw[:limit]
        ts_serials = ts_serials[:limit]
    tab_sum["fetched"] = len(raw)
    print(f"  fetched     {len(raw):>5} rows")

    parsed = mod.process(raw, timestamp_serials=ts_serials)
    table = mod.TABLE
    rows = parsed[table]
    tab_sum["parsed"] = len(rows)
    print(f"  parsed      {len(rows):>5} rows → {table}")

    if apply_writes and rows:
        ins, dups, fk_fail = insert_with_failure_log(conn, table, rows, source_tab=name, run_id=run_id)
        auto_resolve_failures(conn, table)
        conn.commit()
        tab_sum["inserted"] = ins
        tab_sum["duplicates"] = dups
        tab_sum["failed"] = fk_fail
        print(f"  inserted    {ins:>5} → {table} (skipped {dups} duplicates, {fk_fail} FK failures logged)")
        return fk_fail, tab_sum
    elif apply_writes:
        print(f"  inserted    {0:>5} (no rows)")
    else:
        print(f"  [dry-run] would INSERT up to {len(rows)} rows")
    return 0, tab_sum


_BREWING_TABLES = (
    "bd_brewing_brewday",
    "bd_brewing_gravity",
    "bd_brewing_cooling",
    "bd_brewing_timings",
    "bd_brewing_ingredients",
)


def _ingest_brewing(
    sheets: SheetsClient,
    conn,
    *,
    apply_writes: bool,
    limit: int | None,
    run_id: int,
) -> tuple[int, dict]:
    """Returns (total_fk_failure_count, tab_summary)."""
    _print_header(f"brewing ({len(_BREWING_TABLES)} tables)")
    tab_sum = _make_tab_summary()

    raw = sheets.read_range(_spreadsheet_id(), tab_brewing.RANGE)
    ts_serials = sheets.read_range_serial(_spreadsheet_id(), tab_brewing.RANGE_TIMESTAMP)
    if limit is not None:
        raw = raw[:limit]
        ts_serials = ts_serials[:limit]
    tab_sum["fetched"] = len(raw)
    print(f"  fetched     {len(raw):>5} rows")

    yeast_map = load_yeast_canonical_map(conn)
    parsed = tab_brewing.process(raw, timestamp_serials=ts_serials, yeast_map=yeast_map)
    total_parsed = 0
    for table in _BREWING_TABLES:
        rows = parsed[table]
        total_parsed += len(rows)
        print(f"  parsed      {len(rows):>5} rows → {table}")
    tab_sum["parsed"] = total_parsed

    unmatched = parsed.get("_unmatched", [])
    if unmatched:
        print(f"  unmatched   {len(unmatched):>5} rows (event_type not in dispatch map)")
        seen_types = {u['event_type'] for u in unmatched}
        for et in sorted(seen_types):
            cnt = sum(1 for u in unmatched if u['event_type'] == et)
            print(f"     · {et!r:30s} {cnt}")

    total_fk_failures = 0
    if apply_writes:
        for table in _BREWING_TABLES:
            rows = parsed[table]
            if rows:
                ins, dups, fk_fail = insert_with_failure_log(
                    conn, table, rows, source_tab="brewing", run_id=run_id
                )
                auto_resolve_failures(conn, table)
                total_fk_failures += fk_fail
                tab_sum["inserted"] += ins
                tab_sum["duplicates"] += dups
                tab_sum["failed"] += fk_fail
                print(f"  inserted    {ins:>5} → {table} (skipped {dups} duplicates, {fk_fail} FK failures logged)")
        conn.commit()
    else:
        total = sum(len(parsed[t]) for t in _BREWING_TABLES)
        print(f"  [dry-run] would INSERT up to {total} rows across {len(_BREWING_TABLES)} tables")
    return total_fk_failures, tab_sum


def _ingest_packaging(
    sheets: SheetsClient,
    conn,
    *,
    apply_writes: bool,
    limit: int | None,
    run_id: int,
) -> tuple[int, dict]:
    """Returns (total_fk_failure_count, tab_summary)."""
    _print_header("packaging (parent + readings)")
    tab_sum = _make_tab_summary()

    raw = sheets.read_range(_spreadsheet_id(), tab_packaging.RANGE)
    ts_serials = sheets.read_range_serial(_spreadsheet_id(), tab_packaging.RANGE_TIMESTAMP)
    if limit is not None:
        raw = raw[:limit]
        ts_serials = ts_serials[:limit]
    tab_sum["fetched"] = len(raw)
    print(f"  fetched     {len(raw):>5} rows")

    parents, readings = tab_packaging.process(raw, timestamp_serials=ts_serials)
    total_reads = sum(len(rs) for _, rs in readings)
    tab_sum["parsed"] = len(parents) + total_reads
    print(f"  parsed      {len(parents):>5} rows → bd_packaging")
    print(f"  parsed      {total_reads:>5} O2/CO2 readings → bd_packaging_readings")

    if not apply_writes:
        print(f"  [dry-run] would INSERT up to {len(parents)} parents + {total_reads} readings")
        return 0, tab_sum

    if not parents:
        return 0, tab_sum

    total_fk_failures = 0

    # Insert parents first
    ins, dups, fk_fail = insert_with_failure_log(
        conn, "bd_packaging", parents, source_tab="packaging", run_id=run_id
    )
    auto_resolve_failures(conn, "bd_packaging")
    total_fk_failures += fk_fail
    tab_sum["inserted"] += ins
    tab_sum["duplicates"] += dups
    tab_sum["failed"] += fk_fail
    print(f"  inserted    {ins:>5} → bd_packaging (skipped {dups} duplicates, {fk_fail} FK failures logged)")
    conn.commit()

    # Resolve packaging_id for each parent_hash (whether newly inserted or pre-existing)
    if not readings:
        return total_fk_failures, tab_sum
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
        tab_sum["inserted"] += rins
        tab_sum["duplicates"] += len(reading_rows) - rins
        print(f"  inserted    {rins:>5} → bd_packaging_readings (skipped {len(reading_rows) - rins} duplicates)")
        conn.commit()

    return total_fk_failures, tab_sum


def _spreadsheet_id() -> str:
    return _CFG.bsf_spreadsheet_id


def _detect_trigger(explicit: str | None) -> str:
    """
    Determine trigger_source:
      - explicit CLI arg (--trigger=X) wins if provided
      - sys.stdin.isatty() → 'manual' (operator ran it interactively)
      - otherwise → 'cron'
    """
    if explicit:
        return explicit
    try:
        return "manual" if sys.stdin.isatty() else "cron"
    except Exception:
        return "cron"


def main() -> int:
    parser = argparse.ArgumentParser(description="Ingest BSF Sheets tabs into MySQL.")
    parser.add_argument("--tab", default="all",
                        choices=["brewing", "fermenting", "racking", "packaging", "all"])
    parser.add_argument("--apply", action="store_true",
                        help="Actually write to MySQL. Default is dry-run.")
    parser.add_argument("--limit", type=int, default=None,
                        help="Cap rows per tab (debug).")
    parser.add_argument("--trigger", default=None,
                        choices=["cron", "manual", "cli"],
                        help="Override trigger source label stored in ingest_runs.")
    args = parser.parse_args()

    global _CFG
    _CFG = load_config()
    sheets = SheetsClient(_CFG.service_account_path)
    conn = connect(_CFG)

    trigger_source = _detect_trigger(args.trigger)

    # Open a run record (0 = db write failed, non-fatal).
    run_id = 0
    if args.apply:
        run_id = open_ingest_run(conn, trigger_source)

    summary: dict[str, dict] = {}
    total_fk_failures = 0
    top_level_error: str | None = None

    try:
        tabs = list(TAB_HANDLERS.keys()) if args.tab == "all" else [args.tab]

        for t in tabs:
            try:
                if t == "brewing":
                    fk, tab_sum = _ingest_brewing(
                        sheets, conn, apply_writes=args.apply, limit=args.limit, run_id=run_id
                    )
                elif t == "packaging":
                    fk, tab_sum = _ingest_packaging(
                        sheets, conn, apply_writes=args.apply, limit=args.limit, run_id=run_id
                    )
                else:
                    _, mod = TAB_HANDLERS[t]
                    fk, tab_sum = _ingest_simple(
                        t, mod, sheets, conn,
                        apply_writes=args.apply, limit=args.limit, run_id=run_id,
                    )
                total_fk_failures += fk
                summary[t] = tab_sum
            except Exception as tab_err:
                # Per-tab exception: log, continue to next tab.
                print(
                    f"\n[ingest] ERROR in tab '{t}': {tab_err}",
                    file=sys.stderr,
                )
                summary[t] = {"fetched": 0, "parsed": 0, "inserted": 0, "duplicates": 0, "failed": -1}
                total_fk_failures += 1

        if not args.apply:
            print("\n[dry-run] no rows written. Re-run with --apply to commit.")
        elif total_fk_failures > 0:
            print(f"\n⚠ {total_fk_failures} rows logged to ingest_failures — review at /admin/ingest.php")

    except Exception as fatal_err:
        top_level_error = str(fatal_err)
        print(f"\n[ingest] FATAL: {fatal_err}", file=sys.stderr)
        raise
    finally:
        if args.apply:
            close_ingest_run(
                conn,
                run_id,
                summary=summary,
                had_failures=(total_fk_failures > 0),
                error_message=top_level_error,
            )
        conn.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
