"""
lib_db — PyMySQL connection helper.
Provides insert_with_failure_log for FK-aware upsert into transactional tabs,
and run-level tracking helpers (open_ingest_run / close_ingest_run).

Since migration 046b, bd_* tables have UNIQUE KEY uq_sri (sheet_row_index).
insert_with_failure_log uses INSERT ... ON DUPLICATE KEY UPDATE so in-place BSF
cell corrections update the existing row rather than creating a phantom duplicate.
"""
from __future__ import annotations

import json
import sys
import pymysql
from pymysql.cursors import DictCursor

from lib_config import Config


def connect(cfg: Config) -> pymysql.connections.Connection:
    return pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_user,
        password=cfg.db_password,
        database=cfg.db_name,
        charset="utf8mb4",
        cursorclass=DictCursor,
        autocommit=False,
    )


def insert_ignore(conn, table: str, rows: list[dict]) -> int:
    """
    INSERT IGNORE … VALUES (…) for a list of dict rows.
    Returns the number of rows actually inserted (driver-reported affected count
    minus any skipped via the duplicate-row_hash UNIQUE constraint).
    """
    if not rows:
        return 0
    cols = list(rows[0].keys())
    placeholders = ", ".join(["%s"] * len(cols))
    col_list = ", ".join(f"`{c}`" for c in cols)
    sql = f"INSERT IGNORE INTO `{table}` ({col_list}) VALUES ({placeholders})"
    inserted = 0
    with conn.cursor() as cur:
        for r in rows:
            cur.execute(sql, [r[c] for c in cols])
            inserted += cur.rowcount  # 1 if inserted, 0 if ignored
    return inserted


def insert_with_failure_log(
    conn,
    table: str,
    rows: list[dict],
    *,
    source_tab: str,
    run_id: int | None = None,
) -> tuple[int, int, int]:
    """
    Upsert each row individually using INSERT ... ON DUPLICATE KEY UPDATE,
    keyed on the UNIQUE KEY uq_sri (sheet_row_index) added in migration 046b.

    When an operator corrects a BSF cell, sheet_row_index stays the same but
    row_hash changes.  The UPSERT detects the sri collision and updates all
    mutable columns in place, so the old (wrong-value) row is overwritten
    rather than left as a phantom duplicate.

    imported_at is preserved on duplicate-update (records first-ever-import
    timestamp).  updated_at auto-advances via ON UPDATE CURRENT_TIMESTAMP.

    Outcome accounting:
      inserted  — brand-new row (rowcount == 1 from pure INSERT path)
      updated   — existing row replaced in place (rowcount == 2, MySQL convention
                  for ON DUPLICATE KEY UPDATE that actually changed data)
      unchanged — sri already present, content identical (rowcount == 0,
                  MySQL skips the update when no column value changed)
      These map to the returned tuple: (inserted, updated_or_unchanged, fk_failures).
      The caller's "duplicates" counter now reflects updated+unchanged rows.

    Error handling:
      - 1216 / 1452 (FK constraint violation) → log to ingest_failures
      - 1264 / 1406 (DataError — value out of range / data too long) → log to ingest_failures
      - other IntegrityError / DataError → log to ingest_failures (do NOT re-raise)
      Each caught exception writes a row to ingest_failures and continues; the loop
      never aborts due to a single row error.

    Returns (inserted, upserted_or_unchanged, fk_failures).
    """
    if not rows:
        return (0, 0, 0)

    cols = list(rows[0].keys())
    placeholders = ", ".join(["%s"] * len(cols))
    col_list = ", ".join(f"`{c}`" for c in cols)

    # Build SET clause for ON DUPLICATE KEY UPDATE.
    # - imported_at is deliberately excluded: it must not change on update
    #   (first-ever-import timestamp is meaningful for audit).
    # - updated_at is excluded: it self-updates via ON UPDATE CURRENT_TIMESTAMP.
    # - sheet_row_index is excluded: it is the key being matched.
    _excluded = frozenset({"imported_at", "updated_at", "sheet_row_index"})
    update_cols = [c for c in cols if c not in _excluded]
    if not update_cols:
        # Degenerate case: all columns excluded.  Fall back to a no-op update.
        update_set = "sheet_row_index = sheet_row_index"
    else:
        update_set = ",\n            ".join(
            f"`{c}` = VALUES(`{c}`)" for c in update_cols
        )

    insert_sql = (
        f"INSERT INTO `{table}` ({col_list}) VALUES ({placeholders})\n"
        f"ON DUPLICATE KEY UPDATE\n"
        f"            {update_set}"
    )

    # Failure logging: ON DUPLICATE KEY UPDATE on (target_table, row_hash) keeps
    # the existing failure row fresh rather than re-inserting.
    failure_sql = """
        INSERT INTO ingest_failures
            (run_id, source_tab, target_table, sheet_row_index, row_hash,
             reason_code, reason_text, raw_row)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            run_id        = VALUES(run_id),
            last_seen_at  = CURRENT_TIMESTAMP,
            reason_text   = VALUES(reason_text)
    """

    inserted    = 0
    upserted    = 0   # includes both 'updated' and 'unchanged' (rowcount 2 and 0)
    fk_failures = 0

    with conn.cursor() as cur:
        for r in rows:
            try:
                cur.execute(insert_sql, [r[c] for c in cols])
                rc = cur.rowcount
                # MySQL rowcount semantics for INSERT ... ON DUPLICATE KEY UPDATE:
                #   1  → INSERT path (new row)
                #   2  → UPDATE path (existing row, data changed)
                #   0  → UPDATE path (existing row, no data changed — identical content)
                if rc == 1:
                    inserted += 1
                else:
                    upserted += 1
            except (pymysql.IntegrityError, pymysql.err.IntegrityError) as e:
                code = e.args[0]
                # 1062 can still fire on secondary UNIQUE keys (e.g. row_hash if
                # a separate UNIQUE index on row_hash were added in future), or on
                # FK violations that manifest as integrity errors on some MySQL versions.
                if code in (1216, 1452):
                    fk_failures += 1
                    _write_failure(cur, failure_sql, run_id, source_tab, table, r, str(code), e)
                else:
                    fk_failures += 1
                    _write_failure(cur, failure_sql, run_id, source_tab, table, r, str(code), e)
            except (pymysql.DataError, pymysql.err.DataError) as e:
                # Value out of range (1264), data too long (1406), etc.
                code = e.args[0]
                fk_failures += 1
                _write_failure(cur, failure_sql, run_id, source_tab, table, r, str(code), e)

    return (inserted, upserted, fk_failures)


def _write_failure(
    cur,
    failure_sql: str,
    run_id: int | None,
    source_tab: str,
    table: str,
    row: dict,
    error_code: str,
    exc: Exception,
) -> None:
    """Write one row to ingest_failures. Prints to stderr if the write itself fails."""
    try:
        cur.execute(failure_sql, (
            run_id,
            source_tab,
            table,
            int(row.get("sheet_row_index", 0)),
            str(row.get("row_hash", "")),
            error_code[:32] if error_code else None,
            str(exc.args[1])[:512] if len(exc.args) > 1 else str(exc)[:512],
            json.dumps(row, default=str),
        ))
    except Exception as write_err:
        print(
            f"[lib_db] WARNING: could not write to ingest_failures: {write_err}",
            file=sys.stderr,
        )


def auto_resolve_failures(conn, target_table: str) -> int:
    """
    Two-pass resolver: marks ingest_failures.resolved_at = NOW() for unresolved
    failure rows where the target row is now present in the target table.

    Pass 1 — row_hash match (original path):
      Resolves failures where the exact same row_hash now exists in the target
      table.  This covers the case where the BSF cell was never changed — the
      row just needed its FK parent to appear (e.g. a new yeast alias).

    Pass 2 — sheet_row_index match (new path, added for UPSERT pattern):
      Resolves failures where the same sheet_row_index now has a successful row
      in the target table, regardless of hash.  This covers the case where an
      operator corrected a BSF cell (changing the hash), triggering an UPSERT
      that wrote the corrected row under a new hash.  The old failure row's hash
      no longer exists, but the sri does — it's resolved.

    Pass 2 only fires for rows not already resolved by Pass 1.

    Resolution notes distinguish the two paths:
      'auto: row inserted on later run'           (Pass 1)
      'auto: row updated in target table via sri' (Pass 2)

    Returns total count of rows marked resolved across both passes.
    """
    pass1_sql = f"""
        UPDATE ingest_failures f
        JOIN `{target_table}` t ON t.row_hash = f.row_hash
        SET f.resolved_at      = CURRENT_TIMESTAMP,
            f.resolution_note  = 'auto: row inserted on later run'
        WHERE f.target_table = %s AND f.resolved_at IS NULL
    """
    pass2_sql = f"""
        UPDATE ingest_failures f
        JOIN `{target_table}` t ON t.sheet_row_index = f.sheet_row_index
        SET f.resolved_at      = CURRENT_TIMESTAMP,
            f.resolution_note  = 'auto: row updated in target table via sri'
        WHERE f.target_table = %s AND f.resolved_at IS NULL
    """
    resolved = 0
    with conn.cursor() as cur:
        cur.execute(pass1_sql, (target_table,))
        resolved += cur.rowcount
        cur.execute(pass2_sql, (target_table,))
        resolved += cur.rowcount
    return resolved


# ── Run-level tracking ────────────────────────────────────────────────────────

def open_ingest_run(conn, trigger_source: str = "cron") -> int:
    """
    INSERT a new ingest_runs row with status='running'.
    Returns the auto-increment run_id.
    Logs to stderr and returns 0 if the write fails (non-fatal — ingest continues).
    """
    sql = """
        INSERT INTO ingest_runs (started_at, status, trigger_source)
        VALUES (NOW(6), 'running', %s)
    """
    try:
        with conn.cursor() as cur:
            cur.execute(sql, (trigger_source,))
            run_id = cur.lastrowid
        conn.commit()
        return run_id
    except Exception as e:
        print(f"[lib_db] WARNING: could not open ingest_run: {e}", file=sys.stderr)
        return 0


def close_ingest_run(
    conn,
    run_id: int,
    *,
    summary: dict,
    had_failures: bool,
    error_message: str | None = None,
) -> None:
    """
    UPDATE ingest_runs row with finished_at, status, and summary_json.

    Status logic:
      - error_message is set → 'failed' (top-level uncaught exception)
      - had_failures (>=1 row logged to ingest_failures) → 'partial'
      - otherwise → 'ok'

    Logs to stderr if the UPDATE fails (non-fatal).
    """
    if run_id == 0:
        return  # open_ingest_run already failed — nothing to close

    if error_message:
        status = "failed"
    elif had_failures:
        status = "partial"
    else:
        status = "ok"

    sql = """
        UPDATE ingest_runs
           SET finished_at    = NOW(6),
               status         = %s,
               summary_json   = %s,
               error_message  = %s
         WHERE id = %s
    """
    try:
        with conn.cursor() as cur:
            cur.execute(sql, (
                status,
                json.dumps(summary),
                error_message,
                run_id,
            ))
        conn.commit()
    except Exception as e:
        print(f"[lib_db] WARNING: could not close ingest_run {run_id}: {e}", file=sys.stderr)
