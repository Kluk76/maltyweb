"""
lib_yeast — Yeast strain canonicalization helpers.

Loads the alias map from MySQL once per ingest run.
Returns a dict {raw_value -> canonical_name} that covers:
  - every ref_yeast_strains.name  (identity mapping, pass-through)
  - every ref_yeast_strain_aliases.alias  (mapped to parent strain's name)

Unknown raw values are NOT inserted into ref_yeast_strains automatically.
They pass through to bd_yeast as-typed, keeping the canonical table clean.
Operators add new strains or aliases manually.
"""
from __future__ import annotations

import pymysql


def load_yeast_canonical_map(conn: pymysql.connections.Connection) -> dict[str, str]:
    """
    Returns {raw_value -> canonical_name}.

    Includes both identity entries (canonical name -> itself) and alias
    entries (alias -> canonical name). Call once per ingest run and pass
    the result into tab_brewing.process() via the yeast_map kwarg.
    """
    mapping: dict[str, str] = {}

    with conn.cursor() as cur:
        # Identity entries: canonical name maps to itself.
        cur.execute("SELECT name FROM ref_yeast_strains")
        for row in cur.fetchall():
            name = row["name"]
            mapping[name] = name

        # Alias entries: alias maps to parent strain's canonical name.
        cur.execute(
            """
            SELECT a.alias, s.name AS canonical
              FROM ref_yeast_strain_aliases a
              JOIN ref_yeast_strains s ON s.id = a.strain_id
            """
        )
        for row in cur.fetchall():
            mapping[row["alias"]] = row["canonical"]

    return mapping
