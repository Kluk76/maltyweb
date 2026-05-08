#!/usr/bin/env python3
"""
validate_fk_candidates.py — pré-vol et reporting pour migration 026.

Modes:
  (default)                       Pré-vol FK candidates : retourne 0 si tout match les référentiels.
  --report-unresolved-recipes     Post-026e : compte les rows.recipe_id IS NULL par couple,
                                  affiche + dump dans /tmp/026e-unresolved-recipes-<ts>.log

Usage:
    /var/www/maltytask/.venv/bin/python scripts/python/validate_fk_candidates.py
    /var/www/maltytask/.venv/bin/python scripts/python/validate_fk_candidates.py --report-unresolved-recipes
"""
from __future__ import annotations
import sys
import argparse
from datetime import datetime

sys.path.insert(0, '/var/www/maltytask/scripts/python')
from lib_config import load as load_config
from lib_db import connect


# ----- helpers -----

def has_table(cur, name: str) -> bool:
    cur.execute(
        "SELECT 1 FROM information_schema.tables "
        "WHERE table_schema=DATABASE() AND table_name=%s",
        (name,),
    )
    return cur.fetchone() is not None


def has_column(cur, table: str, column: str) -> bool:
    cur.execute(
        "SELECT 1 FROM information_schema.columns "
        "WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s",
        (table, column),
    )
    return cur.fetchone() is not None


# ----- pré-vol mode -----

def build_preflight_checks(cur):
    has_aliases = has_table(cur, 'ref_recipe_aliases')

    checks = [
        (
            "bd_brewing_brewday.bd_yeast → ref_yeast_strains.name",
            """SELECT bd_yeast AS v, COUNT(*) AS c FROM bd_brewing_brewday
               WHERE bd_yeast IS NOT NULL
                 AND bd_yeast NOT IN (SELECT name FROM ref_yeast_strains)
               GROUP BY bd_yeast ORDER BY c DESC""",
        ),
        (
            "bd_brewing_brewday.bd_cct → ref_cct.number",
            """SELECT bd_cct AS v, COUNT(*) AS c FROM bd_brewing_brewday
               WHERE bd_cct IS NOT NULL AND bd_cct <> ''
                 AND CAST(bd_cct AS UNSIGNED) NOT IN (SELECT number FROM ref_cct)
               GROUP BY bd_cct ORDER BY c DESC""",
        ),
        (
            "bd_brewing_brewday.bd_yt → ref_yt.number",
            """SELECT bd_yt AS v, COUNT(*) AS c FROM bd_brewing_brewday
               WHERE bd_yt IS NOT NULL AND bd_yt <> ''
                 AND CAST(bd_yt AS UNSIGNED) NOT IN (SELECT number FROM ref_yt)
               GROUP BY bd_yt ORDER BY c DESC""",
        ),
        (
            "bd_racking.bbt (parsed 'BBT N'|'CCT N') → ref_bbt|ref_cct",
            """SELECT bbt AS v, COUNT(*) AS c FROM bd_racking
               WHERE bbt IS NOT NULL AND bbt <> ''
                 AND NOT (
                   (bbt REGEXP '^BBT[[:space:]]+[0-9]+$' AND CAST(SUBSTRING_INDEX(bbt,' ',-1) AS UNSIGNED) IN (SELECT number FROM ref_bbt))
                   OR (bbt REGEXP '^CCT[[:space:]]+[0-9]+$' AND CAST(SUBSTRING_INDEX(bbt,' ',-1) AS UNSIGNED) IN (SELECT number FROM ref_cct))
                 )
               GROUP BY bbt ORDER BY c DESC""",
        ),
        (
            "bd_racking.bbt_old → ref_bbt.number",
            """SELECT bbt_old AS v, COUNT(*) AS c FROM bd_racking
               WHERE bbt_old IS NOT NULL AND bbt_old <> ''
                 AND CAST(bbt_old AS UNSIGNED) NOT IN (SELECT number FROM ref_bbt)
               GROUP BY bbt_old ORDER BY c DESC""",
        ),
    ]

    # Recipe checks — name OR alias resolution
    recipe_targets = [
        ('bd_brewing_brewday', 'bd_beer'),
        ('bd_brewing_cooling', 'cool_beer'),
        ('bd_brewing_gravity', 'beer'),
        ('bd_brewing_timings', 'beer'),
        ('bd_brewing_ingredients', 'ing_beer'),
        ('bd_racking', 'neb_beer'),
        ('bd_racking', 'contract_beer'),
    ]
    for table, col in recipe_targets:
        if has_aliases:
            sql = (
                f"SELECT b.{col} AS v, COUNT(*) AS c FROM {table} b "
                f"WHERE b.{col} IS NOT NULL AND b.{col} <> '' "
                f"  AND NOT EXISTS (SELECT 1 FROM ref_recipes r WHERE r.name = b.{col}) "
                f"  AND NOT EXISTS (SELECT 1 FROM ref_recipe_aliases a WHERE a.alias = b.{col}) "
                f"GROUP BY b.{col} ORDER BY c DESC"
            )
            label = f"{table}.{col} → ref_recipes (via name OR alias)"
        else:
            sql = (
                f"SELECT b.{col} AS v, COUNT(*) AS c FROM {table} b "
                f"WHERE b.{col} IS NOT NULL AND b.{col} <> '' "
                f"  AND NOT EXISTS (SELECT 1 FROM ref_recipes r WHERE r.name = b.{col}) "
                f"GROUP BY b.{col} ORDER BY c DESC"
            )
            label = f"{table}.{col} → ref_recipes (via name only — aliases pas encore créés, vérification incomplète)"
        checks.append((label, sql))

    return checks


def run_preflight() -> int:
    conn = connect(load_config())
    cur = conn.cursor()
    checks = build_preflight_checks(cur)
    failed = 0
    for label, sql in checks:
        cur.execute(sql)
        rows = cur.fetchall()
        if rows:
            failed += 1
            print(f"FAIL  {label}")
            for r in rows[:10]:
                print(f"        [{r['c']}] {r['v']!r}")
            if len(rows) > 10:
                print(f"        ... +{len(rows) - 10} autres")
        else:
            print(f"OK    {label}")
    print()
    if failed:
        print(f"✗ {failed}/{len(checks)} FK candidates ont des orphelins — résoudre avant migration 026.")
        return 1
    print(f"✓ {len(checks)}/{len(checks)} FK candidates valides — migration 026 peut être appliquée.")
    return 0


# ----- report mode -----

REPORT_TARGETS = [
    ('bd_brewing_brewday',     'bd_beer',       'bd_beer_recipe_id'),
    ('bd_brewing_cooling',     'cool_beer',     'cool_beer_recipe_id'),
    ('bd_brewing_gravity',     'beer',          'beer_recipe_id'),
    ('bd_brewing_timings',     'beer',          'beer_recipe_id'),
    ('bd_brewing_ingredients', 'ing_beer',      'ing_beer_recipe_id'),
    ('bd_racking',             'neb_beer',      'neb_beer_recipe_id'),
    ('bd_racking',             'contract_beer', 'contract_beer_recipe_id'),
]


def run_report_unresolved_recipes() -> int:
    conn = connect(load_config())
    cur = conn.cursor()
    ts = datetime.now().strftime("%Y%m%d-%H%M%S")
    iso = datetime.now().isoformat(timespec='seconds')
    out_path = f"/tmp/026e-unresolved-recipes-{ts}.log"

    lines = []
    lines.append(f"# Unresolved recipes report — {iso}")
    lines.append("# Source: post-026e backfill diagnostic")
    lines.append("")
    total = 0

    for table, raw_col, fk_col in REPORT_TARGETS:
        # If fk_col doesn't exist yet (026e not applied), skip gracefully.
        if not has_column(cur, table, fk_col):
            lines.append(f"{table}.{raw_col} → {fk_col}: SKIPPED ({fk_col} column missing — 026e not applied?)")
            continue
        cur.execute(
            f"SELECT COUNT(*) AS c FROM {table} "
            f"WHERE {raw_col} IS NOT NULL AND {raw_col} <> '' AND {fk_col} IS NULL"
        )
        c = cur.fetchone()['c']
        total += c
        header = f"{table}.{raw_col} → {fk_col}: {c} unresolved"
        lines.append(header)
        if c > 0:
            cur.execute(
                f"SELECT {raw_col} AS v, COUNT(*) AS n FROM {table} "
                f"WHERE {raw_col} IS NOT NULL AND {raw_col} <> '' AND {fk_col} IS NULL "
                f"GROUP BY {raw_col} ORDER BY n DESC LIMIT 20"
            )
            for r in cur.fetchall():
                lines.append(f"    [{r['n']}] {r['v']!r}")
            cur.execute(
                f"SELECT COUNT(DISTINCT {raw_col}) AS d FROM {table} "
                f"WHERE {raw_col} IS NOT NULL AND {raw_col} <> '' AND {fk_col} IS NULL"
            )
            d = cur.fetchone()['d']
            if d > 20:
                lines.append(f"    ... +{d - 20} autres valeurs distinctes")

    lines.append("")
    lines.append(f"TOTAL unresolved: {total}")

    text = "\n".join(lines)
    print(text)

    with open(out_path, "w", encoding="utf-8") as f:
        f.write(text + "\n")
    print(f"\nDumped: {out_path}")
    return 0


# ----- main -----

def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument(
        "--report-unresolved-recipes",
        action="store_true",
        help="Post-026e: count rows where bd_*.recipe_id IS NULL, print + dump to /tmp/026e-unresolved-recipes-<ts>.log",
    )
    args = ap.parse_args()

    if args.report_unresolved_recipes:
        return run_report_unresolved_recipes()
    return run_preflight()


if __name__ == '__main__':
    sys.exit(main())
