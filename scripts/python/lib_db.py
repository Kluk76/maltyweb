"""
lib_db — PyMySQL connection helper. INSERT IGNORE pattern for idempotence.
Also provides insert_with_failure_log for FK-aware insertion into transactional tabs,
and run-level tracking helpers (open_ingest_run / close_ingest_run).
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
    INSERT each row individually, with explicit handling of:
      - 1062 (Duplicate entry on row_hash UNIQUE) → silent skip (expected idempotency)
      - 1216 / 1452 (FK constraint violation) → log to ingest_failures
      - 1264 / 1406 (DataError — value out of range / data too long) → log to ingest_failures
      - other IntegrityError / DataError → log to ingest_failures (do NOT re-raise)

    Each caught exception writes a row to ingest_failures and continues; the loop
    never aborts due to a single row error. If writing to ingest_failures itself
    fails (e.g. DB connection lost mid-run), the error is printed to stderr and
    we continue — the cron must always terminate.

    Returns (inserted, duplicates_skipped, fk_failures).
    """
    if not rows:
        return (0, 0, 0)

    cols = list(rows[0].keys())
    placeholders = ", ".join(["%s"] * len(cols))
    col_list = ", ".join(f"`{c}`" for c in cols)
    insert_sql = f"INSERT INTO `{table}` ({col_list}) VALUES ({placeholders})"

    # ON DUPLICATE KEY: re-runs touching the same (target_table, row_hash) pair
    # update last_seen_at instead of inserting a duplicate row.
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

    inserted = 0
    duplicates = 0
    fk_failures = 0

    with conn.cursor() as cur:
        for r in rows:
            try:
                cur.execute(insert_sql, [r[c] for c in cols])
                inserted += cur.rowcount
            except (pymysql.IntegrityError, pymysql.err.IntegrityError) as e:
                code = e.args[0]
                if code == 1062:
                    # Duplicate row_hash — expected idempotency, skip silently.
                    duplicates += 1
                elif code in (1216, 1452):
                    # FK violation — log and continue.
                    fk_failures += 1
                    _write_failure(cur, failure_sql, run_id, source_tab, table, r, str(code), e)
                else:
                    # Other integrity error — log and continue rather than re-raising
                    # so the cron always completes.
                    fk_failures += 1
                    _write_failure(cur, failure_sql, run_id, source_tab, table, r, str(code), e)
            except (pymysql.DataError, pymysql.err.DataError) as e:
                # Value out of range (1264), data too long (1406), etc.
                code = e.args[0]
                fk_failures += 1
                _write_failure(cur, failure_sql, run_id, source_tab, table, r, str(code), e)

    return (inserted, duplicates, fk_failures)


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
    Marks ingest_failures.resolved_at = NOW() for any unresolved rows whose
    row_hash now exists in the target table (i.e., a later ingest succeeded
    where an earlier one failed, e.g. operator added a yeast alias).
    Returns count of rows marked resolved.
    """
    sql = f"""
        UPDATE ingest_failures f
        JOIN `{target_table}` t ON t.row_hash = f.row_hash
        SET f.resolved_at = CURRENT_TIMESTAMP,
            f.resolution_note = 'auto: row inserted on later run'
        WHERE f.target_table = %s AND f.resolved_at IS NULL
    """
    with conn.cursor() as cur:
        cur.execute(sql, (target_table,))
        return cur.rowcount


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
