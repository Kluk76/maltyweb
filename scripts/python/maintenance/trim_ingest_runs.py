"""
trim_ingest_runs.py — periodic retention trim for ingest_runs + ingest_failures.

Deletes ingest_runs rows older than N days (default 90). Because ingest_failures
has ON DELETE CASCADE on run_id, cascade would silently remove unresolved failures.
Strategy:
  Step 1 — Detach unresolved failures from to-be-deleted runs by setting
            run_id = NULL (they stay visible in triage but lose their run link).
  Step 2 — DELETE old ingest_runs rows (cascade removes only resolved failures).

Usage:
    python scripts/python/maintenance/trim_ingest_runs.py               # dry-run
    python scripts/python/maintenance/trim_ingest_runs.py --apply       # commit
    python scripts/python/maintenance/trim_ingest_runs.py --retention-days 30   # shorter window
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


def count_old_runs(cur, retention_days: int) -> int:
    cur.execute(
        "SELECT COUNT(*) AS n FROM ingest_runs WHERE started_at < NOW() - INTERVAL %s DAY",
        (retention_days,),
    )
    return cur.fetchone()["n"]


def count_unresolved_linked(cur, retention_days: int) -> int:
    cur.execute(
        """
        SELECT COUNT(*) AS n FROM ingest_failures
         WHERE resolved_at IS NULL
           AND run_id IN (
               SELECT id FROM ingest_runs WHERE started_at < NOW() - INTERVAL %s DAY
           )
        """,
        (retention_days,),
    )
    return cur.fetchone()["n"]


def count_resolved_linked(cur, retention_days: int) -> int:
    cur.execute(
        """
        SELECT COUNT(*) AS n FROM ingest_failures
         WHERE resolved_at IS NOT NULL
           AND run_id IN (
               SELECT id FROM ingest_runs WHERE started_at < NOW() - INTERVAL %s DAY
           )
        """,
        (retention_days,),
    )
    return cur.fetchone()["n"]


def main() -> None:
    parser = argparse.ArgumentParser(description="Trim old ingest_runs rows")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true", default=True,
                      help="Preview changes without writing (default)")
    mode.add_argument("--apply", action="store_true", help="Commit changes")
    parser.add_argument("--retention-days", type=int, default=90, metavar="N",
                        help="Delete runs older than N days (default: 90)")
    args = parser.parse_args()

    cfg  = lib_config.load()
    conn = lib_db.connect(cfg)

    try:
        with conn.cursor() as cur:
            old_runs         = count_old_runs(cur, args.retention_days)
            unresolved_linked = count_unresolved_linked(cur, args.retention_days)
            resolved_linked   = count_resolved_linked(cur, args.retention_days)

        print(f"[trim_ingest_runs] retention_days={args.retention_days}  dry_run={not args.apply}")
        print(f"  ingest_runs rows to delete : {old_runs}")
        print(f"  ingest_failures to detach  : {unresolved_linked}  (unresolved — run_id → NULL)")
        print(f"  ingest_failures to cascade : {resolved_linked}  (resolved — will be deleted)")

        if old_runs == 0:
            print("  nothing to do.")
            return

        if not args.apply:
            print("  [dry-run] no changes written. Pass --apply to commit.")
            return

        with conn.cursor() as cur:
            # Step 1: detach unresolved failures so cascade won't delete them.
            cur.execute(
                """
                UPDATE ingest_failures
                   SET run_id = NULL
                 WHERE run_id IN (
                     SELECT id FROM ingest_runs WHERE started_at < NOW() - INTERVAL %s DAY
                 )
                   AND resolved_at IS NULL
                """,
                (args.retention_days,),
            )
            detached = cur.rowcount

            # Step 2: delete old runs; resolved failures cascade-delete automatically.
            cur.execute(
                "DELETE FROM ingest_runs WHERE started_at < NOW() - INTERVAL %s DAY",
                (args.retention_days,),
            )
            deleted_runs = cur.rowcount

        conn.commit()
        print(f"  [applied] detached {detached} unresolved failures, deleted {deleted_runs} runs "
              f"(+ {resolved_linked} resolved failures via cascade).")

    finally:
        conn.close()


if __name__ == "__main__":
    main()
