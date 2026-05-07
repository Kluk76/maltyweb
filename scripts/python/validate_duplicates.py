"""
validate_duplicates.py — interactive walk-through of detected duplicate groups.

For each group of byte-identical rows in BSF tabs (i.e. rows with the same
row_hash), prompts the user to decide:
  [K]eep all members      — INSERT the missing members into MySQL.
                            Requires migration 011 (UNIQUE on idx+hash, not hash alone).
  [S]kip                  — leave dedup intact (1 row in DB per group).
  [Q]uit                  — stop and persist decisions made so far.

Decisions are persisted to /var/www/maltytask/data/duplicate-decisions.json
(append, with timestamp + actor) for audit.

Run with a TTY (ssh -t …) so prompts work.
"""
from __future__ import annotations

import argparse
import getpass
import json
import os
import sys
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

from lib_config import load as load_config
from lib_db import connect, insert_ignore
from lib_sheets import SheetsClient
from lib_hashing import row_hash

import tab_brewing
import tab_fermenting
import tab_racking
import tab_packaging


TAB_MODULES = {
    "brewing":    tab_brewing,
    "fermenting": tab_fermenting,
    "racking":    tab_racking,
    "packaging":  tab_packaging,
}

DECISIONS_PATH = Path("/var/www/maltytask/data/duplicate-decisions.json")


def _is_empty(cells: list, width: int) -> bool:
    return all(c is None or str(c).strip() == "" for c in cells[:width])


def _load_decisions() -> list[dict]:
    if not DECISIONS_PATH.exists():
        return []
    return json.loads(DECISIONS_PATH.read_text(encoding="utf-8"))


def _save_decisions(rows: list[dict]) -> None:
    DECISIONS_PATH.parent.mkdir(parents=True, exist_ok=True)
    DECISIONS_PATH.write_text(json.dumps(rows, indent=2, default=str), encoding="utf-8")


def _format_cells_table(cells: list, headers: list[str] | None = None) -> str:
    """Pretty-print a row's cells one per line: idx | header | value (truncated)."""
    out = []
    for i, c in enumerate(cells):
        v = "" if c is None else str(c).strip()
        if not v:
            continue
        h = headers[i] if headers and i < len(headers) else f"col {i}"
        if len(v) > 80:
            v = v[:77] + "…"
        out.append(f"      {i:>3}  {h[:30]:30s}  {v}")
    return "\n".join(out)


def _build_row_dispatch_brewing(parsed: dict) -> dict[int, tuple[str, dict]]:
    """sheet_row_index → (table_name, row_dict). Brewing has 5 tables."""
    out: dict[int, tuple[str, dict]] = {}
    for table, rows in parsed.items():
        if table.startswith("_"):
            continue
        for r in rows:
            out[r["sheet_row_index"]] = (table, r)
    return out


def _walk_simple_tab(name: str, mod, sheets, conn, cfg, decisions: list[dict], headers: list[str] | None) -> bool:
    """Single-table tab (fermenting, racking). Returns False if user quit."""
    print(f"\n══ {name} ".ljust(60, "═"))
    raw = sheets.read_range(cfg.bsf_spreadsheet_id, mod.RANGE)
    by_hash: dict[str, list[int]] = defaultdict(list)
    for i_row, row in enumerate(raw):
        cells = list(row) + [None] * max(0, mod.WIDTH - len(row))
        if _is_empty(cells, mod.WIDTH):
            continue
        h = row_hash(row, mod.WIDTH)
        by_hash[h].append(2 + i_row)

    dup_hashes = [h for h, idxs in by_hash.items() if len(idxs) > 1]
    if not dup_hashes:
        print("  (no duplicate groups)")
        return True

    parsed = mod.process(raw)
    table = mod.TABLE
    by_idx = {r["sheet_row_index"]: r for r in parsed[table]}

    return _walk_groups(name, table, dup_hashes, by_hash, raw, by_idx, conn, decisions)


def _walk_brewing(sheets, conn, cfg, decisions: list[dict]) -> bool:
    print(f"\n══ brewing ".ljust(60, "═"))
    raw = sheets.read_range(cfg.bsf_spreadsheet_id, tab_brewing.RANGE)
    by_hash: dict[str, list[int]] = defaultdict(list)
    for i_row, row in enumerate(raw):
        cells = list(row) + [None] * max(0, tab_brewing.WIDTH - len(row))
        if _is_empty(cells, tab_brewing.WIDTH):
            continue
        h = row_hash(row, tab_brewing.WIDTH)
        by_hash[h].append(2 + i_row)

    dup_hashes = [h for h, idxs in by_hash.items() if len(idxs) > 1]
    if not dup_hashes:
        print("  (no duplicate groups)")
        return True

    parsed = tab_brewing.process(raw)
    by_idx = {r["sheet_row_index"]: (t, r)
              for t, rows in parsed.items() if not t.startswith("_")
              for r in rows}

    return _walk_groups("brewing", "<dispatched>", dup_hashes, by_hash, raw, by_idx, conn, decisions)


def _walk_packaging(sheets, conn, cfg, decisions: list[dict]) -> bool:
    print(f"\n══ packaging ".ljust(60, "═"))
    raw = sheets.read_range(cfg.bsf_spreadsheet_id, tab_packaging.RANGE)
    by_hash: dict[str, list[int]] = defaultdict(list)
    for i_row, row in enumerate(raw):
        cells = list(row) + [None] * max(0, tab_packaging.WIDTH - len(row))
        if _is_empty(cells, tab_packaging.WIDTH):
            continue
        h = row_hash(row, tab_packaging.WIDTH)
        by_hash[h].append(2 + i_row)

    dup_hashes = [h for h, idxs in by_hash.items() if len(idxs) > 1]
    if not dup_hashes:
        print("  (no duplicate groups)")
        return True

    parents, readings = tab_packaging.process(raw)
    parents_by_idx = {p["sheet_row_index"]: p for p in parents}
    # readings: list[(parent_hash, [reading_dicts])]. The same hash repeats for
    # multiple parents, so we map by sheet_idx via re-derivation.
    readings_by_idx: dict[int, list[dict]] = {}
    for parent_hash, rs in readings:
        for idx in by_hash.get(parent_hash, []):
            readings_by_idx[idx] = rs

    return _walk_groups("packaging", "bd_packaging", dup_hashes, by_hash, raw,
                        parents_by_idx, conn, decisions, readings_by_idx=readings_by_idx)


def _print_group_full(idxs: list[int], raw: list, headers: list[str] | None):
    """Show all members of a dup group, full content (non-empty cells)."""
    for n_member, idx in enumerate(idxs, 1):
        i = idx - 2  # back to 0-based
        cells = raw[i]
        print(f"\n    [{n_member}/{len(idxs)}] sheet row {idx}")
        print(_format_cells_table(cells, headers))


def _walk_groups(tab_name: str, table: str, dup_hashes, by_hash, raw, by_idx,
                 conn, decisions: list[dict], *, readings_by_idx: dict | None = None) -> bool:
    """Returns False if user quit, True otherwise."""
    actor = f"{getpass.getuser()}@{os.uname().nodename}"
    total = len(dup_hashes)
    for k, h in enumerate(dup_hashes, 1):
        idxs = by_hash[h]
        print(f"\n┌─ {tab_name}  group {k}/{total}  hash {h[:12]}…  ×{len(idxs)} members ─")
        _print_group_full(idxs, raw, _headers_cache.get(tab_name))

        prompt = "    [K]eep all  [S]kip dups  [Q]uit  → "
        while True:
            try:
                choice = input(prompt).strip().upper()
            except (EOFError, KeyboardInterrupt):
                print()
                return False
            if choice in ("K", "S", "Q"):
                break
            print("      (please type K, S, or Q)")

        decision_record = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "actor": actor,
            "tab": tab_name,
            "row_hash": h,
            "sheet_row_indices": idxs,
            "decision": {"K": "keep", "S": "skip", "Q": "quit"}[choice],
        }
        decisions.append(decision_record)

        if choice == "Q":
            return False

        if choice == "K":
            inserted_count = 0
            for idx in idxs:
                if tab_name == "brewing":
                    target_table, row_dict = by_idx[idx]
                    n = insert_ignore(conn, target_table, [row_dict])
                    inserted_count += n
                elif tab_name == "packaging":
                    parent_row = by_idx[idx]
                    with conn.cursor() as cur:
                        cols = list(parent_row.keys())
                        placeholders = ", ".join(["%s"] * len(cols))
                        col_list = ", ".join(f"`{c}`" for c in cols)
                        cur.execute(
                            f"INSERT IGNORE INTO bd_packaging ({col_list}) VALUES ({placeholders})",
                            [parent_row[c] for c in cols],
                        )
                        if cur.rowcount == 1:
                            packaging_id = cur.lastrowid
                            inserted_count += 1
                        else:
                            cur.execute(
                                "SELECT id FROM bd_packaging WHERE sheet_row_index=%s AND row_hash=%s",
                                (parent_row["sheet_row_index"], parent_row["row_hash"]),
                            )
                            packaging_id = cur.fetchone()["id"]
                        rs = (readings_by_idx or {}).get(idx, [])
                        for r in rs:
                            cur.execute(
                                "INSERT IGNORE INTO bd_packaging_readings "
                                "(packaging_id, reading_idx, o2, co2) VALUES (%s,%s,%s,%s)",
                                (packaging_id, r["reading_idx"], r["o2"], r["co2"]),
                            )
                else:
                    row_dict = by_idx[idx]
                    n = insert_ignore(conn, table, [row_dict])
                    inserted_count += n
            conn.commit()
            print(f"    → kept all  ({inserted_count} new rows inserted, {len(idxs) - inserted_count} already in DB)")
        else:
            print(f"    → skipped  ({len(idxs) - 1} duplicates remain dedup'd)")

        # Persist after each decision so a crash/quit doesn't lose progress
        all_decisions = _load_decisions() + [decision_record]
        # but avoid double-counting in-memory decisions: rebuild from on-disk
        # NOTE: this is simple; for production you'd want a real append.
        _save_decisions(all_decisions)

    return True


# Cache headers per tab once, for prettier display
_headers_cache: dict[str, list[str]] = {}


def _fetch_headers(sheets, cfg, mod) -> list[str]:
    range_a1 = mod.RANGE.split("!")[0] + "!1:1"
    rows = sheets.read_range(cfg.bsf_spreadsheet_id, range_a1)
    return rows[0] if rows else []


def main() -> int:
    parser = argparse.ArgumentParser(description="Interactive duplicate validation.")
    parser.add_argument("--tab", default="all",
                        choices=["brewing", "fermenting", "racking", "packaging", "all"])
    args = parser.parse_args()

    cfg = load_config()
    sheets = SheetsClient(cfg.service_account_path)
    conn = connect(cfg)

    # pre-load headers
    for name, mod in TAB_MODULES.items():
        try:
            _headers_cache[name] = _fetch_headers(sheets, cfg, mod)
        except Exception:
            _headers_cache[name] = []

    decisions: list[dict] = []
    tabs = list(TAB_MODULES.keys()) if args.tab == "all" else [args.tab]

    try:
        for t in tabs:
            if t == "brewing":
                ok = _walk_brewing(sheets, conn, cfg, decisions)
            elif t == "packaging":
                ok = _walk_packaging(sheets, conn, cfg, decisions)
            else:
                ok = _walk_simple_tab(t, TAB_MODULES[t], sheets, conn, cfg,
                                      decisions, _headers_cache.get(t))
            if not ok:
                print("\n[quit] decisions saved. exiting.")
                break
        else:
            print("\n✓ all duplicate groups reviewed.")
    finally:
        conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
