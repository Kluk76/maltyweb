"""
seed_references.py — populate ref_recipes, ref_clients, ref_yeast_strains.

Sources:
  ref_recipes        ← BSF!BeerTypes (canonical brewery catalog)
  ref_clients        ← derived from ref_recipes via " - " split + manual mappings
  ref_yeast_strains  ← DISTINCT yeasts observed in bd_brewing_brewday

Idempotent: re-runnable. Uses INSERT IGNORE; existing rows are not touched
(operators may have curated supplier/type fields after the initial seed).
The vessel tables (ref_cct, ref_yt, ref_bbt) are seeded directly by
migration 012 — this script does not touch them.

Manual client mappings (special cases that don't follow "Client - Recipe"):
  NYL                  → Nylo (recipe = "NYL"; operator should refine to fruit name)
  TM-BLO/IPA/TR/ST     → Brasserie28 (with short names "Blonde", "IPA", "Triple", "ST"-pending)

Usage:
  python seed_references.py [--dry-run]
"""
from __future__ import annotations

import argparse
import sys
from typing import Optional

from lib_config import load as load_config
from lib_db import connect
from lib_sheets import SheetsClient

BEERTYPES_RANGE = "BeerTypes!A2:F"


# ── Manual client + short-name mappings for special cases ────────────────────
TM_RECIPE_MAP = {
    "TM-BLO": "Blonde",
    "TM-IPA": "IPA",
    "TM-TR":  "Triple",
    "TM-ST":  "ST",  # operator pending; placeholder kept so FK still resolves
}
SPECIAL_CLIENTS = {
    "NYL":    "Nylo",
    "TM-BLO": "Brasserie28",
    "TM-IPA": "Brasserie28",
    "TM-TR":  "Brasserie28",
    "TM-ST":  "Brasserie28",
}
SPECIAL_NOTES = {
    "NYL":   "BeerName field contains placeholder; recipe should be a fruit name.",
    "TM-ST": "Recipe short name 'ST' pending operator confirmation.",
}


def parse_client_and_short_name(beer_name: str, classification: str) -> tuple[Optional[str], str]:
    """Return (client_name | None, recipe_short_name)."""
    if classification == "Neb":
        return None, beer_name

    # Special cases first
    if beer_name in SPECIAL_CLIENTS:
        client = SPECIAL_CLIENTS[beer_name]
        short = TM_RECIPE_MAP.get(beer_name, beer_name)
        return client, short

    # Standard "Client - Recipe" pattern
    if " - " in beer_name:
        client, short = beer_name.split(" - ", 1)
        return client.strip(), short.strip()

    # No separator and not a special case → treat the beer_name as both
    # (e.g. "BLZ Company - Lager"-style entries that have only one part).
    return beer_name, beer_name


def seed_recipes_and_clients(sheets: SheetsClient, conn, *, dry_run: bool):
    cfg = load_config()
    raw = sheets.read_range(cfg.bsf_spreadsheet_id, BEERTYPES_RANGE)
    print(f"  fetched {len(raw)} BeerTypes rows")

    parsed: list[dict] = []
    for row in raw:
        if not row or not row[0]:
            continue
        beer_name = str(row[0]).strip()
        classification = str(row[1]).strip() if len(row) > 1 and row[1] else None
        subtype = str(row[2]).strip() if len(row) > 2 and row[2] else None
        notes = str(row[3]).strip() if len(row) > 3 and row[3] else None
        vintage = str(row[4]).strip() if len(row) > 4 and row[4] else None
        sku_prefix = str(row[5]).strip() if len(row) > 5 and row[5] else None

        if classification not in ("Neb", "Contract"):
            print(f"    skipping {beer_name!r} — classification={classification!r}")
            continue

        client_name, short = parse_client_and_short_name(beer_name, classification)
        # Augment notes with special-case info
        sp_note = SPECIAL_NOTES.get(beer_name)
        merged_notes = " | ".join(filter(None, [notes, sp_note]))

        parsed.append({
            "name": beer_name,
            "classification": classification,
            "subtype": subtype if subtype in ("Core","EPH","CollabIn","CollabOut","WhiteLabel","Archive") else None,
            "client_name": client_name,
            "recipe_short_name": short,
            # Empty-string sentinel (NOT None) — UNIQUE(name, vintage) treats
            # NULLs as distinct, so INSERT IGNORE wouldn't dedup. '' as the
            # default means non-EPH recipes share vintage='' and stay unique.
            "vintage": vintage or "",
            "sku_prefix": sku_prefix,
            "notes": merged_notes or None,
        })

    # Distinct clients
    distinct_clients = sorted({p["client_name"] for p in parsed if p["client_name"]})
    print(f"  → {len(distinct_clients)} distinct clients to ensure")
    print(f"  → {len(parsed)} recipes to ensure")

    if dry_run:
        print("    [dry-run] not writing.")
        # Show a preview
        print("    sample clients:", distinct_clients[:8])
        print("    sample recipes:")
        for p in parsed[:5]:
            print(f"      {p['name']!r:50s} class={p['classification']:8s} client={p['client_name']!s:25s} short={p['recipe_short_name']!r}")
        return

    # Insert clients first
    with conn.cursor() as cur:
        for c in distinct_clients:
            cur.execute("INSERT IGNORE INTO ref_clients (name) VALUES (%s)", (c,))
        conn.commit()

        # Resolve client name → id
        cur.execute("SELECT id, name FROM ref_clients")
        client_to_id = {r["name"]: r["id"] for r in cur.fetchall()}

        # Insert recipes
        ins = 0
        for p in parsed:
            cid = client_to_id.get(p["client_name"]) if p["client_name"] else None
            cur.execute(
                "INSERT IGNORE INTO ref_recipes "
                "(name, classification, subtype, client_id, recipe_short_name, vintage, sku_prefix, notes) "
                "VALUES (%s, %s, %s, %s, %s, %s, %s, %s)",
                (p["name"], p["classification"], p["subtype"], cid,
                 p["recipe_short_name"], p["vintage"], p["sku_prefix"], p["notes"]),
            )
            ins += cur.rowcount
        conn.commit()
        print(f"  inserted {ins} recipes ({len(parsed) - ins} already present)")


def seed_eph_from_brewdays(conn, *, dry_run: bool):
    """
    Auto-create missing EPH vintage rows in ref_recipes from observed brewdays.
    Convention: bd_batch is the 2-digit year (verified 19/19 in 2021-2026 data).
    Inserts only EPH (name, '20'+bd_batch) tuples not already present in ref_recipes.
    Marks them with a note so it's clear they were inferred, not from BeerTypes.
    """
    with conn.cursor() as cur:
        cur.execute("""
          SELECT DISTINCT bd_beer AS name, CONCAT('20', bd_batch) AS vintage
          FROM bd_brewing_brewday
          WHERE bd_beer LIKE 'EPH%'
            AND bd_batch IS NOT NULL
            AND bd_batch REGEXP '^[0-9]{2}$'
        """)
        observed = cur.fetchall()
        cur.execute("""
          SELECT name, vintage FROM ref_recipes WHERE subtype = 'EPH'
        """)
        existing = {(r["name"], r["vintage"]) for r in cur.fetchall()}

    missing = [o for o in observed if (o["name"], o["vintage"]) not in existing]
    print(f"  → {len(observed)} EPH (name, vintage) tuples observed in brewdays")
    print(f"  → {len(missing)} missing from ref_recipes (gaps in BeerTypes)")
    for m in missing:
        print(f"     · {m['name']} {m['vintage']}")

    if dry_run or not missing:
        if dry_run and missing:
            print("    [dry-run] not writing.")
        return

    with conn.cursor() as cur:
        ins = 0
        for m in missing:
            short = f"{m['name']} {m['vintage']}"
            cur.execute(
                "INSERT IGNORE INTO ref_recipes "
                "(name, classification, subtype, recipe_short_name, vintage, notes) "
                "VALUES (%s, 'Neb', 'EPH', %s, %s, %s)",
                (m["name"], short, m["vintage"],
                 "Auto-created from observed brewday — missing in BeerTypes; backfill in source.")
            )
            ins += cur.rowcount
        conn.commit()
        print(f"  inserted {ins} EPH backfill rows")


def seed_yeast_strains(conn, *, dry_run: bool):
    with conn.cursor() as cur:
        cur.execute("""
          SELECT bd_yeast AS name, COUNT(*) AS n
          FROM bd_brewing_brewday
          WHERE bd_yeast IS NOT NULL AND bd_yeast != ''
          GROUP BY bd_yeast
          ORDER BY n DESC
        """)
        observed = cur.fetchall()
    print(f"  → {len(observed)} distinct yeast strains observed in bd_brewing_brewday")

    if dry_run:
        print("    [dry-run] not writing.")
        for r in observed[:8]:
            print(f"      {r['name']!r:30s} ×{r['n']}")
        return

    with conn.cursor() as cur:
        ins = 0
        for r in observed:
            cur.execute(
                "INSERT IGNORE INTO ref_yeast_strains (name) VALUES (%s)",
                (r["name"],)
            )
            ins += cur.rowcount
        conn.commit()
        print(f"  inserted {ins} yeast strains ({len(observed) - ins} already present)")


def main() -> int:
    parser = argparse.ArgumentParser(description="Seed ref_* tables from BSF + raw data.")
    parser.add_argument("--dry-run", action="store_true", help="Parse + count; do not write.")
    args = parser.parse_args()

    cfg = load_config()
    sheets = SheetsClient(cfg.service_account_path)
    conn = connect(cfg)

    try:
        print("\n══ ref_recipes + ref_clients ════════════════════════════════")
        seed_recipes_and_clients(sheets, conn, dry_run=args.dry_run)

        print("\n══ ref_recipes (EPH backfill from brewdays) ═════════════════")
        seed_eph_from_brewdays(conn, dry_run=args.dry_run)

        print("\n══ ref_yeast_strains ════════════════════════════════════════")
        seed_yeast_strains(conn, dry_run=args.dry_run)

        if args.dry_run:
            print("\n[dry-run] no rows written. Re-run without --dry-run to commit.")
    finally:
        conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
