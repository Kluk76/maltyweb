"""
gen_mig236_contract_brewday.py — Generate migration 236 SQL for 21 historical
contract brews missing from bd_brewing_brewday_v2.

Reads the 21 source rows from v1 `bd_brewing_brewday`, builds the canonical dict
IDENTICALLY to ingest_bd_brewing_v2.ingest_brewday(), computes row_hash, and
writes the migration SQL to:
  /home/kluk/projects/maltyweb/db/migrations/236_backfill_v1_contract_brewday.sql

Also prints a validation preview table to stdout.

Run on the LOCAL machine (needs the maltyweb Python env + VPS DB accessible via
the SSH tunnel on 127.0.0.1:13306).

Usage:
  python3 scripts/python/gen_mig236_contract_brewday.py

On VPS (where DB is local):
  python3 /var/www/maltytask/scripts/python/gen_mig236_contract_brewday.py
"""
from __future__ import annotations

import sys
import os
from pathlib import Path

# ── Path bootstrap ─────────────────────────────────────────────────────────────
_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

from ingest_bd_brewing_v2 import _row_hash, _s, _i, _dt, _date
from lib_config import load as load_config
from lib_db import connect

# ── Target assignments  (beer → [(event_date_str, batch_str), ...]) ────────────
# Groups sorted ASC by event_date; batch numbers per specification.
ASSIGNMENTS: list[tuple[str, str, str]] = [
    # beer, event_date (matches v1 DATE(event_date)), batch
    ("BLZ Company - WestCoast Pale Ale", "2021-02-09", "1"),
    ("BLZ Company - WestCoast Pale Ale", "2021-04-29", "2"),
    ("BLZ Company - WestCoast Pale Ale", "2021-06-23", "3"),
    ("BLZ Company - WestCoast Pale Ale", "2021-08-17", "4"),
    ("BLZ Company - WestCoast Pale Ale", "2021-09-08", "5"),
    ("BLZ Company - WestCoast Pale Ale", "2021-11-16", "6"),
    ("Brasserie du Château - Faya",       "2021-05-12", "1"),
    ("Brasserie du Château - Faya",       "2021-07-23", "2"),
    ("BLZ Company - Lager",               "2022-06-24", "1"),
    ("MeltingPote - Cropette",            "2021-07-26", "1"),
    ("Brasserie du Château - 4.4",        "2021-04-22", "3"),
    ("Brasserie du Château - 4.4",        "2021-06-11", "4"),
    ("Brasserie du Château - 4.4",        "2021-08-06", "5"),
    ("Brasserie du Château - 4.4",        "2021-11-15", "6"),
    ("Chien Bleu - Jasper",               "2021-05-21", "15"),
    ("Chien Bleu - Jasper",               "2021-09-13", "16"),
    ("Chien Bleu - Bamse",                "2021-07-06", "17"),
    ("Chien Bleu - Pomelo",               "2021-02-04", "14"),
    ("BadFish - Witshark",                "2021-01-08", "21"),
    ("BadFish - 915",                     "2021-02-02", "19"),
    ("BadFish - Cryo IPA",                "2021-04-27", "9"),
]

assert len(ASSIGNMENTS) == 21, f"Expected 21 assignments, got {len(ASSIGNMENTS)}"

# ── Per-beer expected batch lists (for assertion) ──────────────────────────────
EXPECTED_BATCHES: dict[str, list[str]] = {
    "BLZ Company - WestCoast Pale Ale": ["1","2","3","4","5","6"],
    "Brasserie du Château - Faya":       ["1","2"],
    "BLZ Company - Lager":               ["1"],
    "MeltingPote - Cropette":            ["1"],
    "Brasserie du Château - 4.4":        ["3","4","5","6"],
    "Chien Bleu - Jasper":               ["15","16"],
    "Chien Bleu - Bamse":                ["17"],
    "Chien Bleu - Pomelo":               ["14"],
    "BadFish - Witshark":                ["21"],
    "BadFish - 915":                     ["19"],
    "BadFish - Cryo IPA":                ["9"],
}

# Verify per-beer batch lists match
from collections import defaultdict
actual_by_beer: dict[str, list[str]] = defaultdict(list)
for beer, ev_date, batch in ASSIGNMENTS:
    actual_by_beer[beer].append(batch)
for beer, expected in EXPECTED_BATCHES.items():
    actual = actual_by_beer[beer]
    assert actual == expected, (
        f"Batch list mismatch for {beer!r}: expected {expected}, got {actual}"
    )
print("  [assert] Per-beer batch list lengths all match")
print(f"  [assert] Total assignments: {len(ASSIGNMENTS)} == 21 ✓")


def sql_str(v: str | None) -> str:
    """Return SQL literal: NULL or single-quote-escaped string."""
    if v is None:
        return "NULL"
    escaped = v.replace("\\", "\\\\").replace("'", "\\'")
    return f"'{escaped}'"


def sql_int(v: int | None) -> str:
    if v is None:
        return "NULL"
    return str(v)


def main() -> None:
    cfg  = load_config()
    conn = connect(cfg)

    # Determine output path: prefer repo migrations dir, fall back to /tmp
    # (VPS execution: www-data cannot write to /var/www/maltytask/db/migrations)
    repo_root = _SCRIPT_DIR.parent.parent  # maltyweb/
    migrations_dir = repo_root / "db" / "migrations"
    if migrations_dir.exists() and os.access(migrations_dir, os.W_OK):
        out_path = migrations_dir / "236_backfill_v1_contract_brewday.sql"
    else:
        out_path = Path("/tmp") / "236_backfill_v1_contract_brewday.sql"
        print(f"  [info] migrations dir not writable, writing to {out_path}")

    rows_out: list[dict] = []

    print("\n=== Fetching 21 source rows from bd_brewing_brewday ===")
    with conn.cursor() as cur:
        for (beer, ev_date_str, batch) in ASSIGNMENTS:
            cur.execute(
                """
                SELECT bd_beer, event_date, submitted_at, email,
                       bd_beer_recipe_id, bd_cct, bd_cct_cip, bd_cct_cip_date,
                       bd_yeast, bd_yeast_gen, bd_yeast_new,
                       bd_pitched_from, bd_yt, bd_yt_cip_date
                FROM bd_brewing_brewday
                WHERE bd_beer = %s AND DATE(event_date) = %s
                """,
                (beer, ev_date_str),
            )
            r = cur.fetchone()
            if r is None:
                print(f"  ERROR: NOT FOUND in v1: {beer!r} / {ev_date_str}")
                conn.close()
                sys.exit(1)

            # ── Verify event_date == DATE(submitted_at) ──────────────────────
            ev_date  = r["event_date"]
            sub_date = str(r["submitted_at"])[:10] if r["submitted_at"] else None
            ev_date_s = str(ev_date)[:10] if ev_date else None
            if ev_date_s != sub_date:
                print(
                    f"  STOP: event_date ({ev_date_s}) != DATE(submitted_at) "
                    f"({sub_date}) for {beer!r} / {ev_date_str}"
                )
                conn.close()
                sys.exit(1)

            # ── Build canonical dict IDENTICALLY to the loader ───────────────
            # The loader does: submitted_at = _dt(r[0])  where r[0] is the
            # xlsx Timestamp cell.  For rows already in v1 submitted_at is
            # the brew date at 00:00:00.000000 — same semantics as xlsx cell.
            # We pass v1 submitted_at directly (already a datetime object from
            # PyMySQL DictCursor).

            v1_submitted_at  = r["submitted_at"]   # datetime or None
            recipe_id_raw = _i(r["bd_beer_recipe_id"])
            valid_cct: set[int] = set()   # filled below
            valid_yt:  set[int] = set()   # filled below
            rows_out.append({
                "_beer":           beer,
                "_batch":          batch,
                "_ev_date":        ev_date_str,
                "_recipe_id_raw":  recipe_id_raw,
                "_cct_raw":        _i(r["bd_cct"]),
                "_submitted_at":   v1_submitted_at,
                "_email":          r["email"],
                "_cct_cip":        r["bd_cct_cip"],
                "_cct_cip_date":   r["bd_cct_cip_date"],
                "_yeast":          r["bd_yeast"],
                "_yeast_gen":      r["bd_yeast_gen"],
                "_yeast_new":      r["bd_yeast_new"],
                "_pitched_from":   r["bd_pitched_from"],
                "_yt":             r["bd_yt"],
                "_yt_cip_date":    r["bd_yt_cip_date"],
            })

        # Load valid CCT / YT sets for FK validation
        cur.execute("SELECT number FROM ref_cct")
        valid_cct_set = {row["number"] for row in cur.fetchall()}
        cur.execute("SELECT number FROM ref_yt")
        valid_yt_set = {row["number"] for row in cur.fetchall()}

    conn.close()

    # ── Build canonical + row_hash per row ────────────────────────────────────
    insert_rows: list[tuple] = []   # (canonical, row_hash, batch_str, audit_flags)

    print("\n=== Preview table ===")
    print(f"{'Beer':<42} {'Batch':>6} {'EventDate':<12} {'RecipeID':>9} {'CCT':>4} {'row_hash[:12]':<14}")
    print("-" * 90)

    for data in rows_out:
        beer          = data["_beer"]
        batch         = data["_batch"]
        ev_date_str_  = data["_ev_date"]
        recipe_id_raw = data["_recipe_id_raw"]
        cct_raw       = data["_cct_raw"]
        submitted_at  = data["_submitted_at"]

        audit_flags: list[str] = []

        recipe_id_fk: int | None = None
        if recipe_id_raw is not None:
            recipe_id_fk = recipe_id_raw  # already validated present per spec

        cct: int | None = cct_raw
        if cct is not None and cct not in valid_cct_set:
            audit_flags.append(f"cct_not_found:{cct}")
            cct = None

        yt_number: int | None = _i(data["_yt"])
        if yt_number is not None and yt_number not in valid_yt_set:
            audit_flags.append(f"yt_not_found:{yt_number}")
            yt_number = None

        # The canonical dict must be byte-for-byte identical to ingest_brewday():
        #   submitted_at = _dt(r[0])   where r[0] = the xlsx Timestamp cell
        #   event_date   = _date(r[0])
        # Here r[0] ≡ submitted_at from v1 (same datetime object).
        canonical = {
            "beer":          beer,
            "batch":         batch,
            "recipe_id_fk":  recipe_id_fk,
            "submitted_at":  _dt(submitted_at),
            "email":         _s(data["_email"]),
            "event_date":    _date(submitted_at),
            "cct":           cct,
            "cct_cip":       _s(data["_cct_cip"]),
            "cct_cip_date":  _s(data["_cct_cip_date"]),
            "yeast":         _s(data["_yeast"]),
            "yeast_gen":     _s(data["_yeast_gen"]),
            "new_yeast":     _s(data["_yeast_new"]),
            "pitched_from":  _s(data["_pitched_from"]),
            "yt_number":     yt_number,
            "yt_cip_date":   _s(data["_yt_cip_date"]),
            "start_ferm":    None,
        }

        rh = _row_hash(canonical)
        audit_flags_str = "backfill_v1_contract_mig236"
        if audit_flags:
            audit_flags_str += "," + ",".join(audit_flags)

        insert_rows.append((canonical, rh, batch, audit_flags_str))

        print(
            f"{beer:<42} {batch:>6} {canonical['event_date']:<12} "
            f"{str(recipe_id_fk):>9} {str(cct):>4} {rh[:12]:<14}"
        )

    assert len(insert_rows) == 21, f"Expected 21 insert rows, got {len(insert_rows)}"

    # ── Check row_hash collisions against existing v2 rows ───────────────────
    # (read-only — no apply)
    cfg2  = load_config()
    conn2 = connect(cfg2)
    print("\n=== row_hash collision check vs bd_brewing_brewday_v2 ===")
    hash_collisions = 0
    with conn2.cursor() as cur:
        for (canonical, rh, batch, _) in insert_rows:
            cur.execute(
                "SELECT COUNT(*) AS n FROM bd_brewing_brewday_v2 WHERE row_hash = %s",
                (rh,)
            )
            r = cur.fetchone()
            if r["n"] > 0:
                print(f"  HASH COLLISION: {canonical['beer']!r} batch={batch} hash={rh[:16]}")
                hash_collisions += 1
    conn2.close()
    if hash_collisions == 0:
        print("  Zero row_hash collisions in v2 ✓")

    # ── Emit migration SQL ─────────────────────────────────────────────────────
    lines: list[str] = []
    lines.append("-- ============================================================")
    lines.append("-- Migration 236: backfill 21 historical contract brews")
    lines.append("-- into bd_brewing_brewday_v2")
    lines.append("--")
    lines.append("-- What:")
    lines.append("--   21 rows from v1 `bd_brewing_brewday` for contract clients")
    lines.append("--   (BLZ Company, Brasserie du Château, Chien Bleu, BadFish,")
    lines.append("--    MeltingPote) that were not yet present in bd_brewing_brewday_v2.")
    lines.append("--   NYL and Chien Bleu - Moût Froid are deliberately excluded")
    lines.append("--   (deferred per operator decision).")
    lines.append("--")
    lines.append("-- Why:")
    lines.append("--   Completes the v2 migration for all in-scope contract brews,")
    lines.append("--   unblocking downstream packaging-gate and reporting queries")
    lines.append("--   that join bd_brewing_brewday_v2 for batch attribution.")
    lines.append("--")
    lines.append("-- row_hash: computed via gen_mig236_contract_brewday.py using")
    lines.append("--   the same canonical dict + _row_hash() as ingest_bd_brewing_v2.py.")
    lines.append("--")
    lines.append("-- Rollback:")
    lines.append("--   DELETE FROM bd_brewing_brewday_v2")
    lines.append("--     WHERE audit_flags LIKE '%backfill_v1_contract_mig236%';")
    lines.append("-- ============================================================")
    lines.append("")

    cols = (
        "beer, batch, recipe_id_fk, submitted_at, email, event_date, "
        "cct, cct_cip, cct_cip_date, yeast, yeast_gen, new_yeast, "
        "pitched_from, yt_number, yt_cip_date, start_ferm, "
        "session_id_fk, is_tombstoned, audit_flags, row_hash"
    )

    update_clause = (
        "recipe_id_fk=VALUES(recipe_id_fk), "
        "event_date=VALUES(event_date), "
        "submitted_at=VALUES(submitted_at), "
        "email=VALUES(email), "
        "cct=VALUES(cct), "
        "cct_cip=VALUES(cct_cip), "
        "cct_cip_date=VALUES(cct_cip_date), "
        "yeast=VALUES(yeast), "
        "yeast_gen=VALUES(yeast_gen), "
        "new_yeast=VALUES(new_yeast), "
        "pitched_from=VALUES(pitched_from), "
        "yt_number=VALUES(yt_number), "
        "yt_cip_date=VALUES(yt_cip_date)"
    )

    for (canonical, rh, batch, audit_flags_str) in insert_rows:
        beer_sql          = sql_str(canonical["beer"])
        batch_sql         = sql_str(batch)
        recipe_sql        = sql_int(canonical["recipe_id_fk"])
        submitted_at_sql  = sql_str(canonical["submitted_at"])
        email_sql         = sql_str(canonical["email"])
        event_date_sql    = sql_str(canonical["event_date"])
        cct_sql           = sql_int(canonical["cct"])
        cct_cip_sql       = sql_str(canonical["cct_cip"])
        cct_cip_date_sql  = sql_str(canonical["cct_cip_date"])
        yeast_sql         = sql_str(canonical["yeast"])
        yeast_gen_sql     = sql_str(canonical["yeast_gen"])
        new_yeast_sql     = sql_str(canonical["new_yeast"])
        pitched_from_sql  = sql_str(canonical["pitched_from"])
        yt_number_sql     = sql_int(canonical["yt_number"])
        yt_cip_date_sql   = sql_str(canonical["yt_cip_date"])
        start_ferm_sql    = "NULL"
        session_id_fk_sql = "NULL"
        is_tombstoned_sql = "0"
        audit_flags_sql   = sql_str(audit_flags_str)
        row_hash_sql      = sql_str(rh)

        values = (
            f"({beer_sql}, {batch_sql}, {recipe_sql}, "
            f"{submitted_at_sql}, {email_sql}, {event_date_sql}, "
            f"{cct_sql}, {cct_cip_sql}, {cct_cip_date_sql}, "
            f"{yeast_sql}, {yeast_gen_sql}, {new_yeast_sql}, "
            f"{pitched_from_sql}, {yt_number_sql}, {yt_cip_date_sql}, "
            f"{start_ferm_sql}, {session_id_fk_sql}, {is_tombstoned_sql}, "
            f"{audit_flags_sql}, {row_hash_sql})"
        )
        stmt = (
            f"INSERT INTO bd_brewing_brewday_v2 ({cols}) VALUES {values}\n"
            f"ON DUPLICATE KEY UPDATE {update_clause};"
        )
        lines.append(stmt)
        lines.append("")

    sql_content = "\n".join(lines)
    out_path.write_text(sql_content, encoding="utf-8")
    print(f"\n  [output] Migration written to: {out_path}")
    print(f"  [output] {len(insert_rows)} INSERT statements generated")


if __name__ == "__main__":
    main()
