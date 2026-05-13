"""
retry_row.py — single-row retry for ingest_failures.

Usage:
  python retry_row.py --tab <name> --row <sheet_row_index> --failure-id <id> [--apply]

All outcome paths emit a single JSON line to stdout.
Stderr is reserved for unexpected crashes only.

Exit codes:
  0  success (ok, dry_run, already_resolved, duplicate, still_failing)
  2  unknown tab
  3  BSF row empty / out-of-range
  4  unknown failure_id
"""
from __future__ import annotations

import argparse
import json
import sys

from googleapiclient.errors import HttpError

from lib_config import load as load_config
from lib_db import connect
from lib_sheets import SheetsClient

import tab_brewing
import tab_fermenting
import tab_racking
import tab_packaging
from lib_yeast import load_yeast_canonical_map

import pymysql


# ── Tab registry ──────────────────────────────────────────────────────────────
# Maps CLI --tab name → (module, is_brewing_multi, is_packaging_multi)
# brewing is special: one BSF row maps to one of 5 target tables.
# packaging is special: process() has a different return signature.
TAB_MODULES = {
    "brewing":    tab_brewing,
    "fermenting": tab_fermenting,
    "racking":    tab_racking,
    "packaging":  tab_packaging,
}


def _col_letter(n: int) -> str:
    """1-based column index → letter(s). e.g. 1→A, 26→Z, 27→AA."""
    s = ""
    while n > 0:
        n, r = divmod(n - 1, 26)
        s = chr(65 + r) + s
    return s


def _single_row_range(full_range: str, sheet_row: int) -> str:
    """
    Given a full range like 'RackingData!A2:AF' or 'BrewingData!A2:AY',
    return a single-row range like 'RackingData!A12162:AF12162'.
    """
    # Split on '!'
    if "!" not in full_range:
        raise ValueError(f"unexpected RANGE format (no '!'): {full_range!r}")
    tab_name, a1 = full_range.split("!", 1)

    # Parse start col from the range (always 'A')
    # Parse end column — everything after the colon
    if ":" not in a1:
        raise ValueError(f"unexpected RANGE format (no ':'): {full_range!r}")
    _, end_part = a1.split(":", 1)
    # Strip any digits from end_part to get pure column letters
    end_col = end_part.rstrip("0123456789")

    return f"{tab_name}!A{sheet_row}:{end_col}{sheet_row}"


def _fetch_row(sheets: SheetsClient, spreadsheet_id: str, mod, sheet_row: int):
    """
    Fetch exactly one row from BSF.
    Returns (raw_row_list, ts_serials_list) — both may be empty if row is blank.
    """
    range_1row = _single_row_range(mod.RANGE, sheet_row)
    raw_rows = sheets.read_range(spreadsheet_id, range_1row)

    # Timestamp side-channel
    ts_col = getattr(mod, "RANGE_TIMESTAMP", None)
    ts_serials = []
    if ts_col:
        ts_range_1row = _single_row_range(ts_col, sheet_row)
        ts_serials = sheets.read_range_serial(spreadsheet_id, ts_range_1row)

    return raw_rows, ts_serials


def _parse_brewing_row(raw_row: list, ts_serials: list, sheet_row: int, yeast_map: dict) -> dict[str, list[dict]]:
    """Call tab_brewing.process with a single raw row."""
    return tab_brewing.process(
        [raw_row],
        sheet_offset=sheet_row,
        timestamp_serials=ts_serials or None,
        yeast_map=yeast_map,
    )


def _parse_simple_row(mod, raw_row: list, ts_serials: list, sheet_row: int) -> dict[str, list[dict]]:
    """Call a simple tab module's process() with a single raw row."""
    return mod.process(
        [raw_row],
        sheet_offset=sheet_row,
        timestamp_serials=ts_serials or None,
    )


def _parse_packaging_row(raw_row: list, ts_serials: list, sheet_row: int):
    """Call tab_packaging.process with a single raw row.
    Returns (parents, readings) tuple.
    """
    return tab_packaging.process(
        [raw_row],
        sheet_offset=sheet_row,
        timestamp_serials=ts_serials or None,
    )


def _emit(obj: dict) -> None:
    print(json.dumps(obj, default=str), flush=True)


def main() -> int:
    parser = argparse.ArgumentParser(description="Retry a single ingest failure row.")
    parser.add_argument("--tab",        required=True, help="Source tab name (brewing|fermenting|racking|packaging)")
    parser.add_argument("--row",        required=True, type=int, help="Sheet row index (1-based, as stored in ingest_failures.sheet_row_index)")
    parser.add_argument("--failure-id", required=True, type=int, dest="failure_id", help="ingest_failures.id to resolve")
    parser.add_argument("--apply",      action="store_true", help="Actually write to DB. Default is dry-run.")
    args = parser.parse_args()

    # ── 1. Validate tab name ──────────────────────────────────────────────────
    if args.tab not in TAB_MODULES:
        _emit({"status": "error", "reason": "unknown_tab", "tab": args.tab})
        return 2

    mod = TAB_MODULES[args.tab]
    cfg = load_config()
    sheets = SheetsClient(cfg.service_account_path)
    conn = connect(cfg)

    try:
        # ── 2. Fetch the single BSF row ───────────────────────────────────────
        try:
            raw_rows, ts_serials = _fetch_row(sheets, cfg.bsf_spreadsheet_id, mod, args.row)
        except HttpError as e:
            # 400 "exceeds grid limits" = row beyond sheet bounds → treat as empty
            if e.resp.status == 400:
                _emit({"status": "error", "reason": "bsf_row_empty", "row": args.row})
                return 3
            raise

        if not raw_rows:
            _emit({"status": "error", "reason": "bsf_row_empty", "row": args.row})
            return 3

        raw_row = raw_rows[0]

        # ── 3. Parse the row ──────────────────────────────────────────────────
        yeast_map: dict[str, str] = {}
        if args.tab == "brewing":
            yeast_map = load_yeast_canonical_map(conn)
            parsed = _parse_brewing_row(raw_row, ts_serials, args.row, yeast_map)
            # Collect all rows across all brewing sub-tables
            all_parsed_rows: list[dict] = []
            for tbl_name in ("bd_brewing_brewday", "bd_brewing_gravity", "bd_brewing_cooling",
                              "bd_brewing_timings", "bd_brewing_ingredients"):
                all_parsed_rows.extend(parsed.get(tbl_name, []))

            if not all_parsed_rows:
                _emit({"status": "error", "reason": "bsf_row_empty", "row": args.row})
                return 3

            # The failure tells us the specific target_table; we need to know which
            # sub-table this particular row maps to.  We'll look it up from the DB
            # after connecting.
            target_rows_by_table: dict[str, list[dict]] = {}
            for tbl_name in ("bd_brewing_brewday", "bd_brewing_gravity", "bd_brewing_cooling",
                              "bd_brewing_timings", "bd_brewing_ingredients"):
                if parsed.get(tbl_name):
                    target_rows_by_table[tbl_name] = parsed[tbl_name]
        elif args.tab == "packaging":
            parents, readings = _parse_packaging_row(raw_row, ts_serials, args.row)
            if not parents:
                _emit({"status": "error", "reason": "bsf_row_empty", "row": args.row})
                return 3
            # For packaging retry, we only handle the parent row here.
            # readings require FK resolution which needs the parent to be inserted first.
            target_rows_by_table = {"bd_packaging": parents}
        else:
            # fermenting / racking — single-table
            parsed = _parse_simple_row(mod, raw_row, ts_serials, args.row)
            table = mod.TABLE
            rows = parsed.get(table, [])
            if not rows:
                _emit({"status": "error", "reason": "bsf_row_empty", "row": args.row})
                return 3
            target_rows_by_table = {table: rows}

        # ── 4. Load the failure record ────────────────────────────────────────
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, source_tab, target_table, row_hash, resolved_at "
                "FROM ingest_failures WHERE id = %s",
                (args.failure_id,),
            )
            failure = cur.fetchone()

        if failure is None:
            _emit({"status": "error", "reason": "unknown_failure_id", "failure_id": args.failure_id})
            return 4

        if failure["resolved_at"] is not None:
            _emit({"status": "ok", "reason": "already_resolved", "failure_id": args.failure_id})
            return 0

        target_table = failure["target_table"]

        # Find the parsed row that belongs to the target_table
        candidate_rows = target_rows_by_table.get(target_table, [])
        if not candidate_rows:
            # The row parsed into a different sub-table than what was recorded.
            # Fall back to whichever sub-table we got.
            all_rows_flat = [r for rows in target_rows_by_table.values() for r in rows]
            if not all_rows_flat:
                _emit({"status": "error", "reason": "bsf_row_empty", "row": args.row})
                return 3
            candidate_rows = all_rows_flat
            target_table = next(iter(target_rows_by_table))

        row_dict = candidate_rows[0]

        # ── 5. Dry-run ────────────────────────────────────────────────────────
        if not args.apply:
            _emit({"status": "dry_run", "would_insert": row_dict, "target_table": target_table, "failure_id": args.failure_id})
            return 0

        # ── 6. Apply: INSERT ... ON DUPLICATE KEY UPDATE (mirrors lib_db.py) ──
        cols = list(row_dict.keys())
        placeholders = ", ".join(["%s"] * len(cols))
        col_list = ", ".join(f"`{c}`" for c in cols)
        _excluded = frozenset({"imported_at", "updated_at", "sheet_row_index"})
        update_cols = [c for c in cols if c not in _excluded]
        update_set = (
            ",\n            ".join(f"`{c}` = VALUES(`{c}`)" for c in update_cols)
            if update_cols else "sheet_row_index = sheet_row_index"
        )
        insert_sql = (
            f"INSERT INTO `{target_table}` ({col_list}) VALUES ({placeholders})\n"
            f"ON DUPLICATE KEY UPDATE\n"
            f"            {update_set}"
        )

        try:
            with conn.cursor() as cur:
                cur.execute(insert_sql, [row_dict[c] for c in cols])
                affected = cur.rowcount
            # MySQL rowcount: 1=inserted, 2=updated (content changed), 0=unchanged
            if affected == 1:
                outcome = "inserted"
                resolution = "auto: retried successfully"
            elif affected == 2:
                outcome = "updated"
                resolution = "auto: retried — row updated in place via sri"
            else:
                # affected == 0: sri exists, content identical — still resolves.
                outcome = "unchanged"
                resolution = "auto: row already in target table (content identical)"

            with conn.cursor() as cur:
                cur.execute(
                    "UPDATE ingest_failures "
                    "SET resolved_at = NOW(6), resolution_note = %s "
                    "WHERE id = %s",
                    (resolution, args.failure_id),
                )
            conn.commit()
            _emit({"status": "ok", "outcome": outcome, "failure_id": args.failure_id,
                   "target_table": target_table})
            return 0

        except (pymysql.err.DataError, pymysql.err.IntegrityError) as e:
            code = str(e.args[0])
            msg = str(e.args[1])[:512] if len(e.args) > 1 else str(e)[:512]
            with conn.cursor() as cur:
                cur.execute(
                    "UPDATE ingest_failures "
                    "SET last_seen_at = NOW(6), "
                    "    reason_code = %s, "
                    "    reason_text = %s, "
                    "    raw_row = %s "
                    "WHERE id = %s",
                    (code[:32], msg, json.dumps(row_dict, default=str), args.failure_id),
                )
            conn.commit()
            _emit({"status": "failed", "outcome": "still_failing",
                   "reason_code": code, "reason_text": msg,
                   "failure_id": args.failure_id})
            return 0

    except Exception:
        # Unexpected crash — let it propagate to stderr
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
