"""
lib_db — PyMySQL connection helper. INSERT IGNORE pattern for idempotence.
"""
from __future__ import annotations

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
