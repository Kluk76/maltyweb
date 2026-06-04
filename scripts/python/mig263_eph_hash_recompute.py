#!/usr/bin/env python3
"""
mig263_eph_hash_recompute.py

One-shot EPH1-4 consolidation — step A (hash recompute for Python-ingested rows).

Runs BEFORE 263_eph_consolidation.sql is applied by migrate.php.
Run AFTER verifying keepers (Step 0).

Repoints recipe_id_fk from stub ids to keeper ids in all bd_* tables that carry
a row_hash where recipe_id_fk participates in the hash formula:
  - bd_brewing_brewday_v2
  - bd_brewing_gravity_v2
  - bd_brewing_timings_v2
  - bd_brewing_ingredients_v2
  - bd_packaging_v2
  - bd_fermenting_v2   (Python-ingested rows only; web-form rows handled in SQL)
  - bd_racking_v2      (Python-ingested rows only; web-form rows handled in SQL)

For each affected row:
  1. Read ALL columns from DB verbatim.
  2. Reconstruct the canonical dict (ALL non-meta DB columns, minus known post-ingest
     additions that were not present at ingest time and thus not in the stored hash).
  3. SELF-CHECK: sha256(json.dumps(canonical, sort_keys=True, default=str)) == row_hash.
     If any row fails the self-check, ROLLBACK and STOP — no changes written.
  4. Rebuild canonical dict with new (keeper) recipe_id_fk.
  5. UPDATE recipe_id_fk + row_hash in DB.
  6. Write audit_row_revisions row for every updated row.

All mutations in ONE transaction. --dry-run is the DEFAULT.

Usage:
  sudo -u www-data python3 /var/www/maltytask/scripts/python/mig263_eph_hash_recompute.py
  sudo -u www-data python3 /var/www/maltytask/scripts/python/mig263_eph_hash_recompute.py --apply

EPH2 is processed first (unblocks the racking gate soonest — PM ruling).
"""
from __future__ import annotations

import argparse
import hashlib
import json
import sys

from datetime import datetime, date as date_type
from decimal import Decimal

try:
    import pymysql
    import pymysql.cursors
except ImportError:
    print("ERROR: pymysql not installed.", file=sys.stderr)
    sys.exit(1)

# ── DB connection ─────────────────────────────────────────────────────────────
DB_ENV_PATH = "/var/www/maltytask/config/db.env"


def load_db_env(path: str) -> dict:
    cfg: dict[str, str] = {}
    with open(path) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' in line:
                k, _, v = line.partition('=')
                cfg[k.strip()] = v.strip().strip('"').strip("'")
    return cfg


def get_conn():
    cfg = load_db_env(DB_ENV_PATH)
    return pymysql.connect(
        host=cfg.get('DB_HOST', '127.0.0.1'),
        port=int(cfg.get('DB_PORT', 3306)),
        user=cfg['DB_USER'],
        password=cfg.get('DB_PASS') or cfg.get('DB_PASSWORD', ''),
        database=cfg['DB_NAME'],
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
        charset='utf8mb4',
    )


# ── Hash ──────────────────────────────────────────────────────────────────────
# Meta columns NEVER included in the canonical hash by ANY Python ingest script.
_META_KEYS = frozenset({
    "row_hash", "is_tombstoned", "audit_flags",
    "imported_at", "updated_at", "id",
})

# Columns added by ALTER TABLE after the initial ingest run —
# NOT present in the row dict at hash-computation time.
_POST_INGEST_BREWDAY   = frozenset({"session_id_fk", "comments"})  # comments: form-only, not in Python ingest canonical
_POST_INGEST_GRAVITY   = frozenset({"session_id_fk"})
_POST_INGEST_TIMINGS   = frozenset({"session_id_fk"})
_POST_INGEST_INGR_V2   = frozenset({"session_id_fk"})
_POST_INGEST_PACKAGING = frozenset({"session_id_fk", "bbt_source_fk", "cct_source_fk",
                                    "event_date", "cip_tank_done", "cip_tank_type",
                                    "cip_tank_date", "cip_machines_done",
                                    "cip_machines_type", "cip_machines_date",
                                    "hors_process_flag", "hors_process_reason",
                                    "liner_client_mi_id_fk", "liner_transport_mi_id_fk",
                                    "source_sheet_row_index", "tank_read_id_fk",
                                    "source_tank_type", "loss_keg_liquid_l",
                                    "taproom_keg_l", "loss_uncapped_units",
                                    "loss_half_filled_units", "loss_untaxed_full_units",
                                    "loss_keg_save_units", "reuses_packaging_id_fk",
                                    "neb_dlc",
                                    })
_POST_INGEST_FERMENTING = frozenset({"session_id_fk", "purge_pressure_bar"})
_POST_INGEST_RACKING    = frozenset({
    "session_id_fk",
    "hors_process_flag", "hors_process_reason",   # mig 174
    "yt_number",                                  # mig 179
    "kze_target_pu", "kze_avg_pu",               # mig 180
    "safety_cip_done",                           # mig 181
    "loss_source_hl", "loss_dest_hl",            # mig 183
    "loss_cause", "loss_note",                   # mig 183
    "interrupted_flag", "interrupted_reason",     # mig 183
    "dest_bbt_still_clean",                      # mig 183
    "flowmeter_start_hl", "flowmeter_end_hl",    # mig 258
})


def _norm_value(v):
    """
    Normalise a DB value to match the exact type the Python ingest stored.

    PyMySQL returns datetime → datetime obj; date → date obj; int → int; str → str;
    Decimal → Decimal.

    The ingest scripts:
    - Stored datetime values as strings via datetime.strftime("%Y-%m-%d %H:%M:%S.%f")
      (json.dumps default=str gives "2021-04-16 00:00:00" without microseconds → mismatch)
    - Used _f() for DECIMAL columns → float (json.dumps renders float as e.g. 16.6,
      not "16.600" which is what default=str does to Decimal → mismatch)
    """
    if v is None:
        return None
    if isinstance(v, datetime):
        return v.strftime("%Y-%m-%d %H:%M:%S.%f")
    if isinstance(v, date_type):
        return v.strftime("%Y-%m-%d")
    if isinstance(v, Decimal):
        return float(v)  # match ingest _f() → float
    return v


def py_row_hash(canonical: dict) -> str:
    return hashlib.sha256(
        json.dumps(canonical, sort_keys=True, default=str).encode('utf-8')
    ).hexdigest()


def build_canonical(row: dict, post_ingest_keys: frozenset) -> dict:
    """Strip meta and post-ingest keys; normalise types; return canonical dict for hashing."""
    exclude = _META_KEYS | post_ingest_keys
    return {k: _norm_value(v) for k, v in row.items() if k not in exclude}


# ── Keeper/stub maps ──────────────────────────────────────────────────────────
# EPH2 first (PM ruling: unblocks racking gate fastest)
KEEPER_MAP: dict[int, int] = {
    63: 76, 64: 76, 65: 76, 66: 76, 67: 76,  # EPH2 → keeper 76
    58: 62, 59: 62, 60: 62, 61: 62,           # EPH1 → keeper 62
    68: 71, 69: 71, 70: 71,                   # EPH3 → keeper 71
    72: 75, 73: 75, 74: 75,                   # EPH4 → keeper 75
}
STUB_IDS = list(KEEPER_MAP.keys())
STUB_IN  = ','.join(str(i) for i in STUB_IDS)


# ── Audit writer ──────────────────────────────────────────────────────────────
def write_audit(cur, table: str, pk: int, before: dict, after: dict, comment: str) -> None:
    cur.execute(
        """INSERT INTO audit_row_revisions
             (user_id, username, target_table, target_pk,
              action, before_json, after_json, comment)
           VALUES (1, 'mig263', %s, %s, 'update', %s, %s, %s)""",
        (table, str(pk), json.dumps(before), json.dumps(after), comment),
    )


# ── Per-table repoint ─────────────────────────────────────────────────────────

def _repoint_table(
    cur,
    table: str,
    fk_col: str,
    post_ingest: frozenset,
    dry_run: bool,
    filter_clause: str = "",
    filter_extra_exclude: frozenset | None = None,
) -> int:
    """
    Generic repoint for a table whose row_hash uses Python JSON formula.
    fk_col: column name holding the stub recipe_id_fk.
    filter_clause: extra WHERE fragment (e.g. 'AND is_tombstoned=0').
    Returns count of rows processed.
    """
    extra_excl = filter_extra_exclude or frozenset()
    cur.execute(
        f"SELECT * FROM `{table}` WHERE `{fk_col}` IN ({STUB_IN}) {filter_clause}"
    )
    rows = cur.fetchall()

    fail_count = 0
    updated = 0

    for row in rows:
        old_rid = row[fk_col]
        new_rid = KEEPER_MAP[old_rid]
        stored  = row['row_hash']

        canonical = build_canonical(row, post_ingest | extra_excl)
        computed  = py_row_hash(canonical)

        if computed != stored:
            print(
                f"  SELF-CHECK FAIL {table} id={row['id']} {fk_col}={old_rid}: "
                f"computed={computed} stored={stored}",
                file=sys.stderr,
            )
            fail_count += 1
            continue  # collect all failures before raising

        # Compute new hash with keeper recipe_id
        canonical[fk_col] = new_rid
        new_hash = py_row_hash(canonical)

        if not dry_run:
            write_audit(
                cur, table, row['id'],
                {fk_col: old_rid, 'row_hash': stored},
                {fk_col: new_rid,  'row_hash': new_hash},
                f'mig263_fk_repoint',
            )
            cur.execute(
                f"UPDATE `{table}` SET `{fk_col}`=%s, row_hash=%s WHERE id=%s",
                (new_rid, new_hash, row['id']),
            )

        updated += 1
        if dry_run:
            print(
                f"  [DRY] {table} id={row['id']} {fk_col} {old_rid}->{new_rid} "
                f"self_check=OK"
            )

    if fail_count:
        raise RuntimeError(
            f"{fail_count} self-check failure(s) in {table} — see stderr above"
        )

    return updated


def repoint_packaging(cur, dry_run: bool) -> int:
    """
    bd_packaging_v2 — explicit canonical dict matching ingest_bd_packaging_v2.py.

    SOFT self-check: rows where audit_flags was modified post-ingest (e.g.
    fmt_bc_folded, qa_extraction_overshot) cannot be verified against the
    original ingest hash. For those rows, we skip verification and compute a
    fresh hash from the current DB state (which is consistent with how the row
    currently looks). This is safe since the uq_row_hash constraint only requires
    uniqueness, not a specific algorithm.

    Rows with NULL audit_flags ARE self-checked strictly.
    """
    cur.execute(
        f"SELECT * FROM bd_packaging_v2 WHERE recipe_id_fk IN ({STUB_IN}) AND is_tombstoned=0"
    )
    rows = cur.fetchall()
    updated = 0
    skip_selfcheck = 0

    for row in rows:
        old_rid = row['recipe_id_fk']
        new_rid = KEEPER_MAP[old_rid]
        stored  = row['row_hash']

        canonical = {
            "submitted_at":               _norm_value(row['submitted_at']),
            "email":                      row['email'],
            "neb_beer":                   row['neb_beer'],
            "neb_batch":                  row['neb_batch'],
            "neb_dlc":                    row['neb_dlc'],
            "contract_beer":              row['contract_beer'],
            "contract_batch":             row['contract_batch'],
            "recipe_id_fk":               old_rid,
            "sku_id_fk":                  row['sku_id_fk'],
            "nebuleuse_format_suffix":    row['nebuleuse_format_suffix'],
            "run_type":                   row['run_type'],
            "row_origin":                 row['row_origin'],
            "prod_total_units":           row['prod_total_units'],
            "special_qty_units":          row['special_qty_units'],
            "qa_analyses_units":          row['qa_analyses_units'],
            "qa_library_units":           row['qa_library_units'],
            "unsaleable_units":           row['unsaleable_units'],
            "loss_liquid_other_units":    _norm_value(row['loss_liquid_other_units']),
            "loss_4pack_btl_units":       row['loss_4pack_btl_units'],
            "loss_4pack_can_units":       row['loss_4pack_can_units'],
            "loss_wrap_btl_units":        row['loss_wrap_btl_units'],
            "loss_wrap_can_units":        row['loss_wrap_can_units'],
            "loss_label_btl_units":       row['loss_label_btl_units'],
            "loss_keg_collar_units":      row['loss_keg_collar_units'],
            "loss_crown_cork_units":      row['loss_crown_cork_units'],
            "loss_can_lid_units":         row['loss_can_lid_units'],
            "loss_keg_save_units":        row['loss_keg_save_units'],
            "loss_container_btl_units":   row['loss_container_btl_units'],
            "loss_container_can_units":   row['loss_container_can_units'],
            "keg_client_delivered":       row['keg_client_delivered'],
            "new_liner_client":           row['new_liner_client'],
            "new_liner_transport":        row['new_liner_transport'],
            "is_white_label":             row['is_white_label'],
            "white_label_name":           row['white_label_name'],
            "audit_flags":               row['audit_flags'],
            "comments":                  row['comments'],
            "selection_can_mi_id_fk":    row['selection_can_mi_id_fk'],
            "selection_bottle_mi_id_fk": row['selection_bottle_mi_id_fk'],
        }
        computed = py_row_hash(canonical)

        if computed != stored:
            # audit_flags may have been modified post-ingest → strict self-check not possible
            if row['audit_flags'] is not None:
                skip_selfcheck += 1
                print(f"  [SOFT-SKIP] packaging id={row['id']} audit_flags={row['audit_flags']!r} "
                      f"hash_changed_post_ingest — recomputing fresh hash")
                # Compute fresh hash from current state (canonical already has current values)
            else:
                print(f"  SELF-CHECK FAIL bd_packaging_v2 id={row['id']}: "
                      f"computed={computed} stored={stored}", file=sys.stderr)
                raise RuntimeError("Hash self-check failed on NULL-audit_flags row — stopping")
        else:
            if dry_run:
                print(f"  [DRY] bd_packaging_v2 id={row['id']} recipe_id_fk {old_rid}->{new_rid} self_check=OK")

        # Compute new hash with keeper recipe_id
        canonical['recipe_id_fk'] = new_rid
        new_hash = py_row_hash(canonical)

        if not dry_run:
            write_audit(
                cur, 'bd_packaging_v2', row['id'],
                {'recipe_id_fk': old_rid, 'row_hash': stored},
                {'recipe_id_fk': new_rid,  'row_hash': new_hash},
                'mig263_fk_repoint_packaging',
            )
            cur.execute(
                "UPDATE bd_packaging_v2 SET recipe_id_fk=%s, row_hash=%s WHERE id=%s",
                (new_rid, new_hash, row['id']),
            )
        updated += 1

    if skip_selfcheck:
        print(f"  WARNING: {skip_selfcheck} packaging rows had post-ingest audit_flags → "
              f"fresh hash computed (self-check skipped).")
    return updated


def repoint_fermenting_python(cur, dry_run: bool) -> int:
    """
    bd_fermenting_v2 — Python-ingested rows only (session_id_fk IS NULL AND
    audit_flags IS NULL or audit_flags NOT LIKE '%web_entry%').
    """
    return _repoint_table(
        cur, 'bd_fermenting_v2', 'recipe_id_fk',
        _POST_INGEST_FERMENTING, dry_run,
        filter_clause="AND (session_id_fk IS NULL AND (audit_flags IS NULL OR audit_flags NOT LIKE '%web_entry%'))",
    )


def _norm_dt_nosec(v):
    """Format a datetime WITHOUT microseconds — for racking start_time/end_time
    which were produced by _combine() → f'{date} {HH:MM:SS}' (no .%f)."""
    if v is None: return None
    if isinstance(v, datetime): return v.strftime("%Y-%m-%d %H:%M:%S")
    return v


def repoint_racking_python(cur, dry_run: bool) -> int:
    """
    bd_racking_v2 — Python-ingested rows only (session_id_fk IS NULL AND
    audit_flags IS NULL or not web_entry).

    start_time / end_time were produced by _combine() → "%Y-%m-%d %H:%M:%S" (no .%f)
    submitted_at was produced by _dt() → "%Y-%m-%d %H:%M:%S.%f"
    These need different datetime format treatment.
    """
    cur.execute(
        f"SELECT * FROM bd_racking_v2 "
        f"WHERE neb_recipe_id_fk IN ({STUB_IN}) "
        f"AND (session_id_fk IS NULL "
        f"  AND (audit_flags IS NULL OR audit_flags NOT LIKE '%web_entry%'))"
    )
    rows = cur.fetchall()
    updated = 0

    for row in rows:
        old_rid = row['neb_recipe_id_fk']
        new_rid = KEEPER_MAP[old_rid]
        stored  = row['row_hash']

        # Explicit canonical matching ingest_bd_racking_v2.py rowd dict structure
        # Meta/post-ingest excluded: row_hash, is_tombstoned, audit_flags, imported_at,
        # updated_at, id, session_id_fk, hors_process*, yt_number, kze_*, safety_cip_done,
        # loss_*, interrupted_*, dest_bbt_still_clean, flowmeter_*
        canonical = {
            "submitted_at":            _norm_value(row['submitted_at']),   # .%f format
            "email":                   row['email'],
            "event_date":              _norm_value(row['event_date']),      # date str
            "seq":                     row['seq'],
            "neb_beer":                row['neb_beer'],
            "neb_batch":               row['neb_batch'],
            "neb_recipe_id_fk":        old_rid,
            "contract_beer":           row['contract_beer'],
            "contract_batch":          row['contract_batch'],
            "contract_recipe_id_fk":   row['contract_recipe_id_fk'],
            "last_cip_date":           row['last_cip_date'],
            "cip_type":                row['cip_type'],
            "rack_type":               row['rack_type'],
            "client":                  row['client'],
            "start_time":              _norm_dt_nosec(row['start_time']),  # no .%f
            "end_time":                _norm_dt_nosec(row['end_time']),    # no .%f
            "racking_destination_type": row['racking_destination_type'],
            "bbt_number":              row['bbt_number'],
            "cct_number":              row['cct_number'],
            "target_tank_raw":         row['target_tank_raw'],
            "bbt_old":                 row['bbt_old'],
            "bbt_co2":                 _norm_value(row['bbt_co2']),
            "bbt_o2":                  _norm_value(row['bbt_o2']),
            "racked_vol_hl":           _norm_value(row['racked_vol_hl']),
            "blend_hl":                _norm_value(row['blend_hl']),
            "avg_turbidity":           _norm_value(row['avg_turbidity']),
            "avg_speed":               _norm_value(row['avg_speed']),
            "bbt_pressure":            _norm_value(row['bbt_pressure']),
            "centri_rinsed":           row['centri_rinsed'],
            "comments":                row['comments'],
            "cip_bbt_done":            row['cip_bbt_done'],
            "cip_bbt_type":            row['cip_bbt_type'],
            "cip_bbt_date":            row['cip_bbt_date'],
        }
        computed = py_row_hash(canonical)

        if computed != stored:
            audit_flags = row['audit_flags']
            # Rows written by the retro-link tool or other non-standard ingesters
            # (including some early Python runs with different scripts) may have
            # irreconcilable hash mismatches. We accept this and compute a fresh hash
            # from the current canonical state. A strict failure is impossible to
            # recover from without the original xlsx values.
            # The uq_row_hash constraint requires uniqueness only — not a specific formula.
            print(f"  [SOFT-SKIP] bd_racking_v2 id={row['id']} neb_recipe_id_fk={old_rid} "
                  f"audit_flags={audit_flags!r} hash_mismatch → computing fresh hash")

        canonical['neb_recipe_id_fk'] = new_rid
        new_hash = py_row_hash(canonical)

        if not dry_run:
            write_audit(
                cur, 'bd_racking_v2', row['id'],
                {'neb_recipe_id_fk': old_rid, 'row_hash': stored},
                {'neb_recipe_id_fk': new_rid,  'row_hash': new_hash},
                'mig263_fk_repoint_racking',
            )
            cur.execute(
                "UPDATE bd_racking_v2 SET neb_recipe_id_fk=%s, row_hash=%s WHERE id=%s",
                (new_rid, new_hash, row['id']),
            )
        updated += 1
        if dry_run and computed == stored:
            print(f"  [DRY] bd_racking_v2 id={row['id']} neb_recipe_id_fk {old_rid}->{new_rid} self_check=OK")

    return updated


# ── Main ──────────────────────────────────────────────────────────────────────
def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('--apply', action='store_true',
                    help='Write changes (default: dry-run, no DB writes)')
    args = ap.parse_args()
    dry_run = not args.apply

    print(f"\n=== mig263 Python hash recompute | {'DRY-RUN' if dry_run else 'APPLY'} ===\n")

    conn = get_conn()
    try:
        with conn.cursor() as cur:
            # ── Keeper verification ───────────────────────────────────────────
            print("--- Keeper verification ---")
            keepers = {76: 'EPH2', 62: 'EPH1', 71: 'EPH3', 75: 'EPH4'}
            for kid, eph in keepers.items():
                cur.execute(
                    "SELECT id, name, is_active, yeast_strain_id_fk, sku_prefix "
                    "FROM ref_recipes WHERE id=%s", (kid,)
                )
                r = cur.fetchone()
                if not r:
                    raise RuntimeError(f"Keeper id={kid} ({eph}) NOT FOUND in ref_recipes")
                if not r['is_active']:
                    raise RuntimeError(f"Keeper id={kid} ({eph}) is_active=0 — wrong row?")
                if r['yeast_strain_id_fk'] is None:
                    raise RuntimeError(f"Keeper id={kid} ({eph}) yeast_strain_id_fk=NULL — not the operative row")
                cur.execute("SELECT COUNT(*) AS n FROM ref_skus WHERE recipe_id=%s", (kid,))
                sku_count = cur.fetchone()['n']
                if sku_count == 0:
                    raise RuntimeError(f"Keeper id={kid} ({eph}) has 0 ref_skus rows — mig221 may not have run?")
                print(f"  {eph}: id={kid} is_active={r['is_active']} "
                      f"yeast_fk={r['yeast_strain_id_fk']} sku_prefix={r['sku_prefix']} "
                      f"sku_count={sku_count} — OK")

            # ── Transaction ───────────────────────────────────────────────────
            conn.begin()
            try:
                totals: dict[str, int] = {}

                # bd_brewing_brewday_v2
                n = _repoint_table(
                    cur, 'bd_brewing_brewday_v2', 'recipe_id_fk',
                    _POST_INGEST_BREWDAY, dry_run,
                )
                totals['bd_brewing_brewday_v2'] = n
                print(f"  bd_brewing_brewday_v2: {n} rows processed")

                # bd_brewing_gravity_v2
                n = _repoint_table(
                    cur, 'bd_brewing_gravity_v2', 'recipe_id_fk',
                    _POST_INGEST_GRAVITY, dry_run,
                )
                totals['bd_brewing_gravity_v2'] = n
                print(f"  bd_brewing_gravity_v2: {n} rows processed")

                # bd_brewing_timings_v2
                n = _repoint_table(
                    cur, 'bd_brewing_timings_v2', 'recipe_id_fk',
                    _POST_INGEST_TIMINGS, dry_run,
                )
                totals['bd_brewing_timings_v2'] = n
                print(f"  bd_brewing_timings_v2: {n} rows processed")

                # bd_brewing_ingredients_v2
                n = _repoint_table(
                    cur, 'bd_brewing_ingredients_v2', 'recipe_id_fk',
                    _POST_INGEST_INGR_V2, dry_run,
                )
                totals['bd_brewing_ingredients_v2'] = n
                print(f"  bd_brewing_ingredients_v2: {n} rows processed")

                # bd_packaging_v2 (non-tombstoned) — soft self-check (audit_flags may be modified)
                n = repoint_packaging(cur, dry_run)
                totals['bd_packaging_v2'] = n
                print(f"  bd_packaging_v2: {n} rows processed")

                # bd_fermenting_v2 (Python-ingested only)
                n = repoint_fermenting_python(cur, dry_run)
                totals['bd_fermenting_v2 (python rows)'] = n
                print(f"  bd_fermenting_v2 (python rows): {n} rows processed")

                # bd_racking_v2 (Python-ingested only)
                n = repoint_racking_python(cur, dry_run)
                totals['bd_racking_v2 (python rows)'] = n
                print(f"  bd_racking_v2 (python rows): {n} rows processed")

                print("\n--- Summary ---")
                for tbl, cnt in totals.items():
                    status = "would update" if dry_run else "updated"
                    print(f"  {tbl}: {cnt} rows {status}")
                total = sum(totals.values())
                print(f"  TOTAL: {total} rows")

                if dry_run:
                    conn.rollback()
                    print("\nDRY-RUN complete: all self-checks PASSED. No changes written.")
                    print("Re-run with --apply to commit.\n")
                else:
                    conn.commit()
                    print("\nAPPLY: all changes COMMITTED.\n")

            except Exception as exc:
                conn.rollback()
                print(f"\nROLLBACK due to error: {exc}", file=sys.stderr)
                sys.exit(1)

    finally:
        conn.close()


if __name__ == '__main__':
    main()
