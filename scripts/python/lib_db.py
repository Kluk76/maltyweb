"""
lib_db — PyMySQL connection helper. INSERT IGNORE pattern for idempotence.
Also provides insert_with_failure_log for FK-aware insertion into transactional tabs.
"""
from __future__ import annotations

import json
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
    conn, table: str, rows: list[dict], *, source_tab: str
) -> tuple[int, int, int]:
    """
    INSERT each row individually, with explicit handling of:
      - 1062 (Duplicate entry on row_hash UNIQUE) → silent skip (expected idempotency)
      - 1216 / 1452 (FK constraint violation — same semantic, two MySQL variants) → log to ingest_failures
      - other IntegrityError → re-raise

    Returns (inserted, duplicates_skipped, fk_failures).
    """
    if not rows:
        return (0, 0, 0)

    cols = list(rows[0].keys())
    placeholders = ", ".join(["%s"] * len(cols))
    col_list = ", ".join(f"`{c}`" for c in cols)
    insert_sql = f"INSERT INTO `{table}` ({col_list}) VALUES ({placeholders})"

    failure_sql = """
        INSERT INTO ingest_failures
            (source_tab, target_table, sheet_row_index, row_hash,
             reason_code, reason_text, raw_row)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
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
                    # FK violation (1216 = ER_NO_REFERENCED_ROW, 1452 = ER_NO_REFERENCED_ROW_2)
                    # — same semantic, MySQL picks one or the other depending on
                    # context. Log either way to ingest_failures.
                    fk_failures += 1
                    cur.execute(failure_sql, (
                        source_tab,
                        table,
                        int(r.get("sheet_row_index", 0)),
                        str(r.get("row_hash", "")),
                        code,
                        str(e.args[1])[:512] if len(e.args) > 1 else str(e)[:512],
                        json.dumps(r, default=str),
                    ))
                else:
                    raise

    return (inserted, duplicates, fk_failures)


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
