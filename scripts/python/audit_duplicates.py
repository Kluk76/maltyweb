"""
audit_duplicates.py — find duplicate rows in BSF tabs by row_hash.

For each tab, computes row_hash on the live Sheets data and reports any group
where two or more rows share the same hash (these are exactly the rows that
INSERT IGNORE skipped during ingest.py --apply).

Output for each duplicate group:
  - row_hash[:12]    short hex prefix
  - count            number of identical rows
  - sheet rows       1-based row numbers in the BSF tab
  - snippet          first ~5 non-empty cells joined for context

Usage:
  python audit_duplicates.py [--tab=brewing|fermenting|racking|packaging|all] [--max-groups=N]
"""
from __future__ import annotations

import argparse
import sys
from collections import defaultdict

from lib_config import load as load_config
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


def _is_empty(cells: list) -> bool:
    return all(c is None or str(c).strip() == "" for c in cells)


def _snippet(cells: list, max_len: int = 110) -> str:
    parts = []
    for c in cells:
        if c is None:
            continue
        v = str(c).strip()
        if not v:
            continue
        parts.append(v)
        if len(" · ".join(parts)) >= max_len:
            break
    s = " · ".join(parts)
    return s[:max_len] + ("…" if len(s) > max_len else "")


def audit_tab(name: str, mod, sheets: SheetsClient, spreadsheet_id: str, max_groups: int):
    print(f"\n══ {name} ".ljust(60, "═"))
    raw = sheets.read_range(spreadsheet_id, mod.RANGE)
    width = mod.WIDTH

    by_hash: dict[str, list[tuple[int, list]]] = defaultdict(list)
    empty = 0
    total = 0
    for i_row, row in enumerate(raw):
        cells = list(row) + [None] * max(0, width - len(row))
        if _is_empty(cells[:width]):
            empty += 1
            continue
        total += 1
        h = row_hash(row, width)
        sheet_idx = 2 + i_row  # row 1 is header
        by_hash[h].append((sheet_idx, cells))

    dup_groups = [g for g in by_hash.values() if len(g) > 1]
    rows_lost = sum(len(g) - 1 for g in dup_groups)

    print(f"  fetched         {len(raw):>5}")
    print(f"  empty rows      {empty:>5}")
    print(f"  parsed (non-empty) {total:>2}")
    print(f"  unique hashes   {len(by_hash):>5}")
    print(f"  dup groups      {len(dup_groups):>5}")
    print(f"  rows that would be skipped by INSERT IGNORE: {rows_lost}")

    if not dup_groups:
        return

    # Sort by group size desc, then by smallest sheet row asc
    dup_groups.sort(key=lambda g: (-len(g), g[0][0]))

    print(f"\n  Top {min(max_groups, len(dup_groups))} duplicate groups:")
    for grp in dup_groups[:max_groups]:
        h = row_hash(grp[0][1], width)[:12]
        rows_str = ", ".join(str(idx) for idx, _ in grp)
        snip = _snippet(grp[0][1])
        print(f"    {h}  ×{len(grp)}  rows {rows_str}")
        print(f"      → {snip}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Audit duplicate rows in BSF tabs.")
    parser.add_argument("--tab", default="all",
                        choices=["brewing", "fermenting", "racking", "packaging", "all"])
    parser.add_argument("--max-groups", type=int, default=10,
                        help="Max duplicate groups to display per tab (default: 10).")
    args = parser.parse_args()

    cfg = load_config()
    sheets = SheetsClient(cfg.service_account_path)

    tabs = list(TAB_MODULES.keys()) if args.tab == "all" else [args.tab]
    for t in tabs:
        audit_tab(t, TAB_MODULES[t], sheets, cfg.bsf_spreadsheet_id, args.max_groups)

    return 0


if __name__ == "__main__":
    sys.exit(main())
