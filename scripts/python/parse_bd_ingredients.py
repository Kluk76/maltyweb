"""
parse_bd_ingredients — parse malt/hops free-text from bd_brewing_ingredients
and bd_fermenting (Dry Hop events) into bd_brewing_ingredients_parsed.

Sources:
  bd_brewing_ingredients.ing_malt_raw  → category='malt',       unit='kg'
  bd_brewing_ingredients.ing_hops_raw  → category='hops_kettle', unit='g'
  bd_fermenting.hops_raw               → category='hops_dry',   unit='g'
    (only rows where event_type='Dry Hop')

Idempotent: UNIQUE KEY uk_source_line (source_table, source_id, line_idx)
drives INSERT ... ON DUPLICATE KEY UPDATE.

Usage:
  python parse_bd_ingredients.py           # dry-run
  python parse_bd_ingredients.py --apply   # write to DB
"""
from __future__ import annotations

import argparse
import re
import sys
from collections import Counter
from decimal import Decimal

import pymysql

from lib_coerce import n
from lib_config import load as load_config
from lib_db import connect


# ---------------------------------------------------------------------------
# Beer-prefix → canonical recipe name map
# ---------------------------------------------------------------------------

PREFIX_TO_RECIPE: dict[str, str] = {
    'ZEP':  'Zepp',
    'EMB':  'Embuscade',
    'MOO':  'Moonshine',
    'STI':  'Stirling',
    'SPY':  'Speakeasy',
    'DIV':  'Diversion',
    'DOA':  'Double Oat',
    'ALT':  'Alternative',
    'DIB':  'Diversion Blanche',
    'EST':  'Estafette',
    'EPH1': 'EPH1',
    'EPH2': 'EPH2',
    'EPH3': 'EPH3',
    'EPH4': 'EPH4',
}


# ---------------------------------------------------------------------------
# Normalisation helper (MI resolution)
# ---------------------------------------------------------------------------

_STRIP_PUNCT = re.compile(r"[()[\]{}\.,;:]")


def _normalise(raw: str) -> str:
    """Lowercase, strip punctuation, collapse whitespace."""
    s = raw.lower()
    s = _STRIP_PUNCT.sub("", s)
    s = re.sub(r"\s+", " ", s).strip()
    return s


# ---------------------------------------------------------------------------
# MI map loader
# ---------------------------------------------------------------------------

def load_mi_map(conn) -> dict[str, int]:
    """
    Returns {normalised_name_or_alias: ref_mi.id (int)}.

    Builds from ref_mi (canonical names) first, then ref_mi_aliases.
    Aliases do NOT override canonical names if a collision occurs (canonical wins).
    """
    mi_map: dict[str, int] = {}

    with conn.cursor() as cur:
        cur.execute("SELECT id, name FROM ref_mi WHERE name IS NOT NULL AND is_active = 1")
        for row in cur.fetchall():
            key = _normalise(row["name"])
            if key:
                mi_map[key] = row["id"]

        cur.execute("SELECT mi_id_fk, alias FROM ref_mi_aliases WHERE alias IS NOT NULL")
        for row in cur.fetchall():
            key = _normalise(row["alias"])
            if key and key not in mi_map:
                mi_map[key] = row["mi_id_fk"]

    return mi_map


# ---------------------------------------------------------------------------
# Triplet parser (port of parseIngredientCell from parse-all-consumption.js)
# ---------------------------------------------------------------------------

def _is_numeric(s: str) -> Decimal | None:
    """Return Decimal if s looks like a number, else None."""
    return n(s.strip()) if s.strip() else None


def parse_ingredient_cell(cell_value: str | None) -> list[dict]:
    """
    Parse a newline-separated triplets string into a list of dicts:
      {name, qty, lot, line_idx}

    Standard order:  name / qty / lot
    Swapped fallback: name / lot / qty  (operator typo)

    Non-multiple-of-3 trailing lines are silently ignored.
    """
    if not cell_value or not cell_value.strip():
        return []

    lines = [l.strip() for l in cell_value.split("\n") if l.strip()]
    results: list[dict] = []
    i = 0
    triplet_idx = 0

    while i + 2 < len(lines):
        name  = lines[i]
        line2 = lines[i + 1]
        line3 = lines[i + 2]

        qty_std = _is_numeric(line2)
        if qty_std is not None:
            results.append({"name": name, "qty": qty_std, "lot": line3, "line_idx": triplet_idx})
            i += 3
            triplet_idx += 1
            continue

        # Swapped: name / lot / qty
        qty_swapped = _is_numeric(line3)
        if qty_swapped is not None:
            results.append({"name": name, "qty": qty_swapped, "lot": line2, "line_idx": triplet_idx})
            i += 3
            triplet_idx += 1
            continue

        # Malformed triplet — skip all three lines
        i += 3
        triplet_idx += 1

    return results


# ---------------------------------------------------------------------------
# Source queries
# ---------------------------------------------------------------------------

def fetch_brewing_ingredients(conn, *, limit: int | None, source_id: int | None, since: str | None) -> list[dict]:
    where_clauses = ["(ing_malt_raw IS NOT NULL OR ing_hops_raw IS NOT NULL)"]
    params: list = []

    if source_id is not None:
        where_clauses.append("id = %s")
        params.append(source_id)
    if since is not None:
        where_clauses.append("event_date >= %s")
        params.append(since)

    where_sql = " AND ".join(where_clauses)
    sql = (
        f"SELECT id, ing_beer, ing_batch, event_date, ing_malt_raw, ing_hops_raw "
        f"FROM bd_brewing_ingredients WHERE {where_sql} ORDER BY id"
    )
    if limit is not None:
        sql += f" LIMIT {int(limit)}"

    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.fetchall()


def fetch_dry_hops(conn, *, limit: int | None, source_id: int | None, since: str | None) -> list[dict]:
    where_clauses = [
        "event_type = 'Dry Hop'",
        "hops_raw IS NOT NULL",
        "beers_to_dry_hop IS NOT NULL",
        "beers_to_dry_hop != ''",
    ]
    params: list = []

    if source_id is not None:
        where_clauses.append("id = %s")
        params.append(source_id)
    if since is not None:
        where_clauses.append("event_date >= %s")
        params.append(since)

    where_sql = " AND ".join(where_clauses)
    sql = (
        f"SELECT id, beers_to_dry_hop, event_date, hops_raw "
        f"FROM bd_fermenting WHERE {where_sql} ORDER BY id"
    )
    if limit is not None:
        sql += f" LIMIT {int(limit)}"

    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.fetchall()


# ---------------------------------------------------------------------------
# Row builder
# ---------------------------------------------------------------------------

def build_parsed_rows(
    source_table: str,
    source_id: int,
    beer: str,
    batch: str | None,
    event_date,
    cell_value: str | None,
    category: str,
    unit: str,
    mi_map: dict[str, int],
) -> list[dict]:
    triplets = parse_ingredient_cell(cell_value)
    rows: list[dict] = []
    for t in triplets:
        mi_id_fk = mi_map.get(_normalise(t["name"]))
        lot_raw = t["lot"].strip() if t["lot"] else None
        # Treat "NA", "na", "N/A" placeholder lots as NULL
        lot = None if (lot_raw or "").upper() in ("NA", "N/A", "") else lot_raw
        rows.append({
            "source_table": source_table,
            "source_id":    source_id,
            "line_idx":     t["line_idx"],
            "beer":         (beer or "")[:128],
            "batch":        (str(batch) if batch else "")[:32],
            "event_date":   event_date,
            "category":     category,
            "raw_name":     t["name"][:255],
            "mi_id_fk":     mi_id_fk,
            "qty":          t["qty"],
            "unit":         unit,
            "lot":          lot[:64] if lot else None,
        })
    return rows


# ---------------------------------------------------------------------------
# Upsert helper (plain executemany with ON DUPLICATE KEY UPDATE)
# ---------------------------------------------------------------------------

_INSERT_SQL = """
INSERT INTO bd_brewing_ingredients_parsed
  (source_table, source_id, line_idx,
   beer, batch, event_date,
   category, raw_name, mi_id_fk, qty, unit, lot)
VALUES
  (%(source_table)s, %(source_id)s, %(line_idx)s,
   %(beer)s, %(batch)s, %(event_date)s,
   %(category)s, %(raw_name)s, %(mi_id_fk)s, %(qty)s, %(unit)s, %(lot)s)
ON DUPLICATE KEY UPDATE
  beer       = VALUES(beer),
  batch      = VALUES(batch),
  event_date = VALUES(event_date),
  category   = VALUES(category),
  raw_name   = VALUES(raw_name),
  mi_id_fk   = VALUES(mi_id_fk),
  qty        = VALUES(qty),
  unit       = VALUES(unit),
  lot        = VALUES(lot)
"""


def upsert_rows(conn, rows: list[dict]) -> tuple[int, int]:
    """
    Execute the INSERT ... ON DUPLICATE KEY UPDATE batch.
    Returns (inserted, updated): MySQL rowcount semantics:
      1 = new row inserted, 2 = existing row updated, 0 = unchanged.
    """
    inserted = 0
    updated  = 0
    with conn.cursor() as cur:
        for row in rows:
            cur.execute(_INSERT_SQL, row)
            rc = cur.rowcount
            if rc == 1:
                inserted += 1
            elif rc == 2:
                updated += 1
            # rc == 0: unchanged, count neither
    return inserted, updated


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(
        description="Parse malt/hops triplets from bd_brewing_ingredients + bd_fermenting"
    )
    parser.add_argument("--apply",     action="store_true", help="Default is dry-run")
    parser.add_argument("--limit",     type=int,            help="Cap source rows processed (debug)")
    parser.add_argument("--source-id", type=int,            help="Re-parse only one source row (applies to all source tables)")
    parser.add_argument("--since",                          help="Only process source rows with event_date >= YYYY-MM-DD")
    args = parser.parse_args()

    cfg  = load_config()
    conn = connect(cfg)

    try:
        mi_map = load_mi_map(conn)

        all_rows: list[dict] = []
        unmatched_names: list[str] = []

        # ── bd_brewing_ingredients ──────────────────────────────────────────
        bdi_rows = fetch_brewing_ingredients(
            conn,
            limit=args.limit,
            source_id=args.source_id,
            since=args.since,
        )
        source_rows_scanned = len(bdi_rows)

        for row in bdi_rows:
            beer  = row["ing_beer"] or ""
            batch = row["ing_batch"] or ""
            ed    = row["event_date"]

            if row["ing_malt_raw"]:
                parsed = build_parsed_rows(
                    "bd_brewing_ingredients", row["id"],
                    beer, batch, ed,
                    row["ing_malt_raw"], "malt", "kg", mi_map,
                )
                all_rows.extend(parsed)
                unmatched_names.extend(p["raw_name"] for p in parsed if p["mi_id_fk"] is None)

            if row["ing_hops_raw"]:
                parsed = build_parsed_rows(
                    "bd_brewing_ingredients", row["id"],
                    beer, batch, ed,
                    row["ing_hops_raw"], "hops_kettle", "g", mi_map,
                )
                all_rows.extend(parsed)
                unmatched_names.extend(p["raw_name"] for p in parsed if p["mi_id_fk"] is None)

        # ── bd_fermenting (Dry Hop) ─────────────────────────────────────────
        dh_rows = fetch_dry_hops(
            conn,
            limit=args.limit,
            source_id=args.source_id,
            since=args.since,
        )
        source_rows_scanned += len(dh_rows)

        for row in dh_rows:
            raw_identifier = (row["beers_to_dry_hop"] or "").strip()
            parts = raw_identifier.rsplit(" ", 1)
            if len(parts) < 2:
                print(f"[warning] dry-hop row id={row['id']}: cannot parse batch from {raw_identifier!r} — skipping", file=sys.stderr)
                continue
            prefix, batch = parts[0].strip(), parts[1].strip()
            if prefix not in PREFIX_TO_RECIPE:
                print(f"[warning] dry-hop row id={row['id']}: unknown prefix {prefix!r} — using raw prefix as beer name", file=sys.stderr)
            beer = PREFIX_TO_RECIPE.get(prefix, prefix)
            ed    = row["event_date"]

            parsed = build_parsed_rows(
                "bd_fermenting", row["id"],
                beer, batch, ed,
                row["hops_raw"], "hops_dry", "g", mi_map,
            )
            all_rows.extend(parsed)
            unmatched_names.extend(p["raw_name"] for p in parsed if p["mi_id_fk"] is None)

        # ── Counts ──────────────────────────────────────────────────────────
        triplets_parsed = len(all_rows)
        mi_matched      = sum(1 for r in all_rows if r["mi_id_fk"] is not None)
        mi_unmatched    = triplets_parsed - mi_matched

        inserted = 0
        updated  = 0

        if args.apply and all_rows:
            inserted, updated = upsert_rows(conn, all_rows)
            conn.commit()
        elif not args.apply:
            print("[dry-run] no writes performed")

        # ── Summary ─────────────────────────────────────────────────────────
        print("parse_bd_ingredients summary:")
        print(f"  source rows scanned: {source_rows_scanned}")
        print(f"  triplets parsed:     {triplets_parsed}")
        print(f"  rows inserted:       {inserted}")
        print(f"  rows updated:        {updated}")
        print(f"  MI matched:          {mi_matched}")
        print(f"  MI unmatched:        {mi_unmatched}")

        if unmatched_names:
            top10 = Counter(unmatched_names).most_common(10)
            print("  top unmatched names:")
            for name, count in top10:
                print(f"    {count:4d}×  {name}")

    finally:
        conn.close()


if __name__ == "__main__":
    main()
