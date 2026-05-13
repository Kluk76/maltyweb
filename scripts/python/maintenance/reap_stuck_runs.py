"""
reap_stuck_runs.py — mark stuck ingest_runs rows as failed.

A run stays in status='running' forever when the worker crashes hard (kill -9,
OOM, container eviction). This script reaps any 'running' row older than N hours
(default 3) by setting finished_at = NOW(6) and status = 'failed'.

Usage:
    python scripts/python/maintenance/reap_stuck_runs.py                # dry-run
    python scripts/python/maintenance/reap_stuck_runs.py --apply        # commit
    python scripts/python/maintenance/reap_stuck_runs.py --stale-hours 5  # wider window
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

# Allow running from repo root or from inside scripts/python/maintenance/.
_here = Path(__file__).resolve().parent
for _candidate in [_here.parent, _here.parent.parent / "scripts" / "python"]:
    if (_candidate / "lib_config.py").exists():
        sys.path.insert(0, str(_candidate))
        break

import lib_config
import lib_db


def count_stuck(cur, stale_hours: int) -> int:
    cur.execute(
        "SELECT COUNT(*) AS n FROM ingest_runs WHERE status = 'running' AND started_at < NOW(6) - INTERVAL %s HOUR",
        (stale_hours,),
    )
    return cur.fetchone()["n"]


def main() -> None:
    parser = argparse.ArgumentParser(description="Reap stuck ingest_runs rows")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true", default=True,
                      help="Preview changes without writing (default)")
    mode.add_argument("--apply", action="store_true", help="Commit changes")
    parser.add_argument("--stale-hours", type=int, default=3, metavar="N",
                        help="Reap running rows older than N hours (default: 3)")
    args = parser.parse_args()

    cfg  = lib_config.load()
    conn = lib_db.connect(cfg)

    try:
        with conn.cursor() as cur:
            stuck = count_stuck(cur, args.stale_hours)

        print(f"[reap_stuck_runs] stale_hours={args.stale_hours}  dry_run={not args.apply}")
        print(f"  stuck runs to reap: {stuck}")

        if stuck == 0:
            print("  nothing to reap.")
            return

        if not args.apply:
            print("  [dry-run] no changes written. Pass --apply to commit.")
            return

        with conn.cursor() as cur:
            cur.execute(
                """
                UPDATE ingest_runs
                   SET finished_at    = NOW(6),
                       status         = 'failed',
                       error_message  = COALESCE(error_message,
                                        'reaped: stuck in status=running > %s h')
                 WHERE status = 'running'
                   AND started_at < NOW(6) - INTERVAL %s HOUR
                """,
                (args.stale_hours, args.stale_hours),
            )
            reaped = cur.rowcount

        conn.commit()
        print(f"  [applied] reaped {reaped} stuck run(s).")

    finally:
        conn.close()


if __name__ == "__main__":
    main()
