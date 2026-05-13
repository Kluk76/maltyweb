"""
cleanup_phantom_rows.py — One-shot phantom-row cleanup for bd_* tables.

A "phantom" row is one that shares a sheet_row_index with another row in the
same table but has a different row_hash.  Phantoms are created when an operator
edits a cell in BSF: the hash changes, ingest (pre-046b) treats it as a new row
and inserts it, leaving the old row behind.

For each (sheet_row_index) group with > 1 row:
  - WINNER = row with latest imported_at (most recent ingest = most aligned
    with current BSF state). Tiebreak: highest id.
  - LOSERS = all other rows in the group.
  - Losers are deleted and logged to phantom_cleanup_log for reversibility.

Usage:
  python cleanup_phantom_rows.py            # dry-run (default) — prints plan
  python cleanup_phantom_rows.py --apply    # executes deletions + logs them

Idempotent: re-running after --apply does nothing (no duplicates remain).

Run this AFTER migration 046 and BEFORE migration 046b.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

# Ensure the parent scripts/python directory is in the path for imports.
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from lib_config import load as load_config
from lib_db import connect


# Tables to scan. bd_packaging_readings is excluded — its unique key is
# (packaging_id, reading_idx), not sheet_row_index.
BD_TABLES = [
    "bd_brewing_brewday",
    "bd_brewing_gravity",
    "bd_brewing_cooling",
    "bd_brewing_timings",
    "bd_brewing_ingredients",
    "bd_fermenting",
    "bd_racking",
    "bd_packaging",
]


def _find_phantom_groups(conn, table: str) -> list[dict]:
    """
    Return one dict per sheet_row_index that has > 1 row.
    Each dict: {sri, winner_id, losers: [id, ...], all_rows: [...]}
    """
    sql = f"""
        SELECT id, sheet_row_index, row_hash, imported_at
        FROM `{table}`
        ORDER BY sheet_row_index ASC, imported_at DESC, id DESC
    """
    with conn.cursor() as cur:
        cur.execute(sql)
        rows = cur.fetchall()

    # Group by sheet_row_index
    groups: dict[int, list[dict]] = {}
    for r in rows:
        sri = r["sheet_row_index"]
        groups.setdefault(sri, []).append(r)

    phantoms = []
    for sri, group in groups.items():
        if len(group) <= 1:
            continue
        # group is already ordered: (imported_at DESC, id DESC) so group[0] = winner
        winner = group[0]
        losers = group[1:]
        phantoms.append({
            "sheet_row_index": sri,
            "winner_id":       winner["id"],
            "winner_hash":     winner["row_hash"],
            "winner_imported": str(winner["imported_at"]),
            "losers":          [{"id": r["id"], "row_hash": r["row_hash"], "imported_at": str(r["imported_at"])} for r in losers],
        })
    return phantoms


def _fetch_full_row(conn, table: str, row_id: int) -> dict:
    """Fetch a full row by id for archiving in phantom_cleanup_log."""
    with conn.cursor() as cur:
        cur.execute(f"SELECT * FROM `{table}` WHERE id = %s", (row_id,))
        return cur.fetchone() or {}


def _delete_phantom(conn, table: str, loser_id: int, winner_id: int, loser_row: dict) -> None:
    """Delete one phantom row and log it to phantom_cleanup_log."""
    log_sql = """
        INSERT INTO phantom_cleanup_log
            (table_name, deleted_row_id, kept_row_id, deleted_row_json)
        VALUES (%s, %s, %s, %s)
    """
    delete_sql = f"DELETE FROM `{table}` WHERE id = %s"
    with conn.cursor() as cur:
        cur.execute(log_sql, (
            table,
            loser_id,
            winner_id,
            json.dumps(loser_row, default=str),
        ))
        cur.execute(delete_sql, (loser_id,))


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Remove phantom bd_* rows where sheet_row_index is duplicated."
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Execute deletions. Default is dry-run (print plan only).",
    )
    args = parser.parse_args()
    dry_run = not args.apply

    cfg = load_config()
    conn = connect(cfg)

    total_phantoms = 0
    total_deleted  = 0

    try:
        for table in BD_TABLES:
            groups = _find_phantom_groups(conn, table)
            if not groups:
                print(f"  {table}: 0 phantom groups — OK")
                continue

            phantom_count = sum(len(g["losers"]) for g in groups)
            total_phantoms += phantom_count

            print(f"\n  {table}: {len(groups)} phantom group(s), {phantom_count} row(s) to delete")
            for g in groups:
                print(
                    f"    sri={g['sheet_row_index']}  "
                    f"winner=id:{g['winner_id']} (imported {g['winner_imported']})  "
                    f"losers={[l['id'] for l in g['losers']]}"
                )

            if dry_run:
                continue

            # Apply: delete losers + log to phantom_cleanup_log
            for g in groups:
                for loser in g["losers"]:
                    loser_row = _fetch_full_row(conn, table, loser["id"])
                    _delete_phantom(conn, table, loser["id"], g["winner_id"], loser_row)
                    total_deleted += 1

            conn.commit()
            print(f"    → deleted {sum(len(g['losers']) for g in groups)} phantom(s) from {table}")

    except Exception as e:
        conn.rollback()
        print(f"\n[cleanup_phantom_rows] ERROR: {e}", file=sys.stderr)
        raise
    finally:
        conn.close()

    print(f"\n{'[DRY-RUN] ' if dry_run else ''}Summary: {total_phantoms} phantom(s) found across {len(BD_TABLES)} tables", end="")
    if not dry_run:
        print(f", {total_deleted} deleted.")
    else:
        print(". Re-run with --apply to execute.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
