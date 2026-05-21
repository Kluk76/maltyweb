"""
lib_normalize — beer-name canonicalization via ref_recipe_aliases.

Some BSF tabs (notably RackingData.neb_beer) carry abbreviated beer names
("Div.Blanche", "Div.Gose", "Div.Panaché", "Malt Capone", ...) instead of the
canonical recipe name. This module loads the alias→canonical map from the
DB (ref_recipe_aliases, populated by migration 065) and exposes a helper
that rewrites those columns in parsed rows BEFORE they hit the DB.

Why DB-driven (not hardcoded): adding a future alias is a single INSERT
into ref_recipe_aliases — no code change, no redeploy. The same alias
table is the source of truth for the PHP resolver and any future TS
consumer.
"""
from __future__ import annotations


def load_beer_alias_map(cursor) -> dict[str, str]:
    """
    Return {alias_string: canonical_recipe_name} pulled from
    ref_recipe_aliases JOIN ref_recipes. Case-sensitive — matches the
    raw BSF source verbatim.

    Indexed via dict keys (works with both DictCursor and tuple cursors —
    DictCursor rows are dicts; tuple-style unpacking on a dict iterates
    its keys, which silently corrupts the map. Use explicit dict access.
    """
    cursor.execute(
        "SELECT a.alias AS alias, r.name AS name "
        "FROM ref_recipe_aliases a "
        "JOIN ref_recipes r ON r.id = a.recipe_id"
    )
    rows = cursor.fetchall()
    if rows and isinstance(rows[0], dict):
        return {row["alias"]: row["name"] for row in rows if row.get("alias") and row.get("name")}
    return {row[0]: row[1] for row in rows if row[0] and row[1]}


def normalize_beer_field(rows: list[dict], field: str, aliases: dict[str, str]) -> int:
    """
    In-place rewrite of `field` in each row when the trimmed value matches
    an alias. Returns the number of cells changed.
    """
    n_changed = 0
    for r in rows:
        v = r.get(field)
        if not isinstance(v, str):
            continue
        canonical = aliases.get(v.strip())
        if canonical is not None and v != canonical:
            r[field] = canonical
            n_changed += 1
    return n_changed
