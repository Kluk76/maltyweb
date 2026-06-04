#!/usr/bin/env python3
"""
mig265_parallel_run_recipe_fix.py

Corrects 4 bd_packaging_v2 rows where the parallel leg of a dual-format
bottling session was mislabelled by the RawDB normalizer.  The normalizer
trusted the 'Selection Recette' free-text field, which the operator had
filled with the WRONG beer.  Ground truth is bd_packaging (v1)
.second_packaging column.

The parallel leg of a -4/-B run is ALWAYS the same beer as the main leg;
only the format suffix flips (4↔B).  Volumes (vendable_hl,
beer_tax_base_hl, all loss cols) are CORRECT — only the beer/recipe/sku/
format attribution is wrong.

Corrections (verified against v1 second_packaging):
  id=2086  SPYB/51→DIBB/26  (Diversion Blanche)  suffix B  sku 49→11
  id=2119  DIVB/25→SPYB/51  (Speakeasy)           suffix B  sku 16→49
  id=2158  SPY4/51→STIB/52  (Stirling)            suffix B  sku 47→53
  id=2227  SPYB/51→DIVB/25  (Diversion)           suffix B  sku 49→16

Algorithm:
  1.  Read each row from DB verbatim.
  2.  Confirm current (wrong) neb_beer/recipe_id_fk match the "from" side;
      ABORT that row if not (guard against concurrent change).
  3.  Reconstruct canonical dict from current (wrong) values.
  4.  SELF-CHECK: sha256(json.dumps(canonical, sort_keys=True, default=str))
      == stored row_hash.  If any self-check fails, ROLLBACK and STOP.
      Exception: rows with non-NULL audit_flags may have had the hash
      modified post-ingest; those get a soft-skip (fresh hash from current
      state, same as mig263 precedent).
  5.  Rebuild canonical dict with corrected values.
  6.  UPDATE neb_beer, recipe_id_fk, nebuleuse_format_suffix, sku_id_fk,
      row_hash, audit_flags (append ',correction') in one transaction.
  7.  Write audit_row_revisions per row.
  8.  Register migration in schema_migrations.

All mutations in ONE transaction.  --dry-run is the DEFAULT.

Usage:
  sudo -u www-data python3 /var/www/maltytask/scripts/python/mig265_parallel_run_recipe_fix.py
  sudo -u www-data python3 /var/www/maltytask/scripts/python/mig265_parallel_run_recipe_fix.py --apply
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


# ── Hash helpers (identical to mig263 / ingest_bd_packaging_v2.py) ────────────
_META_KEYS = frozenset({
    "row_hash", "is_tombstoned", "audit_flags",
    "imported_at", "updated_at", "id",
})

# Explicit canonical field list for bd_packaging_v2 — matches ingest_bd_packaging_v2.py
# and the repoint_packaging() function in mig263_eph_hash_recompute.py.
# neb_dlc IS included (it was in the original canonical dict at ingest time).
# All post-ingest additions (session_id_fk, event_date, cip_*, hors_process_*,
# liner_*_mi_id_fk, source_sheet_row_index, tank_read_id_fk, source_tank_type,
# loss_keg_liquid_l, taproom_keg_l, loss_uncapped_units, loss_half_filled_units,
# loss_untaxed_full_units, reuses_packaging_id_fk, client_fk, loss_kpi_hl,
# bbt_source_fk, cct_source_fk) are EXCLUDED by not being in this list.
_CANONICAL_FIELDS = [
    "submitted_at",
    "email",
    "neb_beer",
    "neb_batch",
    "neb_dlc",
    "contract_beer",
    "contract_batch",
    "recipe_id_fk",
    "sku_id_fk",
    "nebuleuse_format_suffix",
    "run_type",
    "row_origin",
    "prod_total_units",
    "special_qty_units",
    "qa_analyses_units",
    "qa_library_units",
    "unsaleable_units",
    "loss_liquid_other_units",
    "loss_4pack_btl_units",
    "loss_4pack_can_units",
    "loss_wrap_btl_units",
    "loss_wrap_can_units",
    "loss_label_btl_units",
    "loss_keg_collar_units",
    "loss_crown_cork_units",
    "loss_can_lid_units",
    "loss_keg_save_units",
    "loss_container_btl_units",
    "loss_container_can_units",
    "keg_client_delivered",
    "new_liner_client",
    "new_liner_transport",
    "is_white_label",
    "white_label_name",
    "audit_flags",
    "comments",
    "selection_can_mi_id_fk",
    "selection_bottle_mi_id_fk",
]


def _norm_value(v):
    """Normalise DB value to match the type the Python ingest stored."""
    if v is None:
        return None
    if isinstance(v, datetime):
        return v.strftime("%Y-%m-%d %H:%M:%S.%f")
    if isinstance(v, date_type):
        return v.strftime("%Y-%m-%d")
    if isinstance(v, Decimal):
        return float(v)
    return v


def py_row_hash(canonical: dict) -> str:
    return hashlib.sha256(
        json.dumps(canonical, sort_keys=True, default=str).encode('utf-8')
    ).hexdigest()


def build_canonical(row: dict) -> dict:
    """Build canonical dict using the explicit field list from ingest_bd_packaging_v2.py.
    neb_dlc is included (it was part of the original ingest canonical dict).
    """
    return {k: _norm_value(row.get(k)) for k in _CANONICAL_FIELDS}


# ── Correction map ────────────────────────────────────────────────────────────
# Each entry: row_id → (from_neb_beer, from_recipe_id_fk, to_neb_beer,
#                        to_recipe_id_fk, to_nebuleuse_format_suffix, to_sku_id_fk)
CORRECTIONS = {
    2086: ("SPYB", 51, "DIBB", 26, "B", 11),
    2119: ("DIVB", 25, "SPYB", 51, "B", 49),
    2158: ("SPY4", 51, "STIB", 52, "B", 53),
    2227: ("SPYB", 51, "DIVB", 25, "B", 16),
}


# ── Audit writer ──────────────────────────────────────────────────────────────
def write_audit(cur, pk: int, before: dict, after: dict) -> None:
    comment = (
        'mig265: parallel-run recipe mislabel fix (Selection Recette wrong-beer); '
        'reconciled to v1 second_packaging'
    )
    cur.execute(
        """INSERT INTO audit_row_revisions
             (user_id, username, target_table, target_pk,
              action, before_json, after_json, comment)
           VALUES (1, 'mig265', 'bd_packaging_v2', %s, 'update', %s, %s, %s)""",
        (str(pk), json.dumps(before), json.dumps(after), comment),
    )


# ── Main ──────────────────────────────────────────────────────────────────────
def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('--apply', action='store_true',
                    help='Write changes (default: dry-run, no DB writes)')
    args = ap.parse_args()
    dry_run = not args.apply

    print(f"\n=== mig265 parallel-run recipe fix | {'DRY-RUN' if dry_run else 'APPLY'} ===\n")

    conn = get_conn()
    try:
        with conn.cursor() as cur:
            # ── Fetch all 4 rows ─────────────────────────────────────────────
            ids = list(CORRECTIONS.keys())
            placeholders = ','.join(['%s'] * len(ids))
            cur.execute(
                f"SELECT * FROM bd_packaging_v2 WHERE id IN ({placeholders}) ORDER BY id",
                ids,
            )
            rows_by_id = {r['id']: r for r in cur.fetchall()}

            if len(rows_by_id) != len(ids):
                missing = set(ids) - set(rows_by_id.keys())
                raise RuntimeError(f"Expected {len(ids)} rows; missing ids: {missing}")

            # ── Phase 1: pre-flight checks + self-check ───────────────────
            print("--- Pre-flight + self-check ---")
            results = []
            abort = False

            for row_id, (from_beer, from_rid, to_beer, to_rid, to_suffix, to_sku) in sorted(CORRECTIONS.items()):
                row = rows_by_id[row_id]

                # Guard: confirm current (wrong) values match "from" side
                if row['neb_beer'] != from_beer or row['recipe_id_fk'] != from_rid:
                    print(
                        f"  ABORT id={row_id}: expected neb_beer={from_beer} recipe_id_fk={from_rid} "
                        f"but found neb_beer={row['neb_beer']} recipe_id_fk={row['recipe_id_fk']} "
                        f"— row already changed or wrong row; skipping this row.",
                        file=sys.stderr,
                    )
                    abort = True
                    continue

                # Reconstruct canonical dict from current (wrong) state
                canonical = build_canonical(row)

                # Verify audit_flags in canonical vs actual (it's excluded from meta exclusion)
                # audit_flags IS in the canonical dict (not in _POST_INGEST_PACKAGING)
                # But mig263 showed these rows have audit_flags='mode_a_extraction' which
                # was set AT ingest time (not post-ingest), so it IS part of the stored hash.

                computed = py_row_hash(canonical)
                stored   = row['row_hash']

                if computed == stored:
                    self_check_status = "PASS"
                    print(f"  id={row_id} self_check=PASS  {from_beer}/{from_rid} → {to_beer}/{to_rid}")
                else:
                    # Hard failure — stored hash does not match the canonical reconstruction.
                    # With the explicit field list, this should not happen for these rows.
                    print(
                        f"  SELF-CHECK FAIL id={row_id}: "
                        f"computed={computed} stored={stored}",
                        file=sys.stderr,
                    )
                    abort = True
                    continue

                # Compute new canonical with corrected values
                canonical_new = dict(canonical)
                canonical_new['neb_beer'] = to_beer
                canonical_new['recipe_id_fk'] = to_rid
                canonical_new['nebuleuse_format_suffix'] = to_suffix
                canonical_new['sku_id_fk'] = to_sku

                new_hash = py_row_hash(canonical_new)

                # Compute new audit_flags: append ',correction'
                old_flags = row['audit_flags'] or ''
                if old_flags:
                    new_flags = old_flags + ',correction'
                else:
                    new_flags = 'correction'

                results.append({
                    'id':               row_id,
                    'from_beer':        from_beer,
                    'from_rid':         from_rid,
                    'to_beer':          to_beer,
                    'to_rid':           to_rid,
                    'to_suffix':        to_suffix,
                    'to_sku':           to_sku,
                    'old_hash':         stored,
                    'new_hash':         new_hash,
                    'old_flags':        row['audit_flags'],
                    'new_flags':        new_flags,
                    'self_check':       self_check_status,
                })

            if abort:
                print("\nABORTING: one or more pre-flight or self-check failures.", file=sys.stderr)
                sys.exit(1)

            print(f"\n  All {len(results)} pre-flight checks passed.\n")

            if dry_run:
                print("--- Dry-run summary (no DB writes) ---")
                for r in results:
                    print(
                        f"  id={r['id']}  {r['from_beer']}/{r['from_rid']} → {r['to_beer']}/{r['to_rid']}"
                        f"  suffix={r['to_suffix']}  sku_id_fk={r['to_sku']}"
                        f"  new_hash={r['new_hash'][:16]}..."
                        f"  flags={r['old_flags']!r} → {r['new_flags']!r}"
                        f"  [{r['self_check']}]"
                    )
                print("\nDRY-RUN complete. Re-run with --apply to commit.\n")
                return

            # ── Phase 2: apply in one transaction ─────────────────────────
            print("--- Applying corrections ---")
            conn.begin()
            try:
                for r in results:
                    # Write audit BEFORE the UPDATE
                    write_audit(
                        cur,
                        r['id'],
                        before={
                            'neb_beer':                 r['from_beer'],
                            'recipe_id_fk':             r['from_rid'],
                            'nebuleuse_format_suffix':  rows_by_id[r['id']]['nebuleuse_format_suffix'],
                            'sku_id_fk':                rows_by_id[r['id']]['sku_id_fk'],
                            'row_hash':                 r['old_hash'],
                            'audit_flags':              r['old_flags'],
                        },
                        after={
                            'neb_beer':                 r['to_beer'],
                            'recipe_id_fk':             r['to_rid'],
                            'nebuleuse_format_suffix':  r['to_suffix'],
                            'sku_id_fk':                r['to_sku'],
                            'row_hash':                 r['new_hash'],
                            'audit_flags':              r['new_flags'],
                        },
                    )
                    cur.execute(
                        """UPDATE bd_packaging_v2
                           SET neb_beer = %s,
                               recipe_id_fk = %s,
                               nebuleuse_format_suffix = %s,
                               sku_id_fk = %s,
                               row_hash = %s,
                               audit_flags = %s
                         WHERE id = %s
                           AND neb_beer = %s
                           AND recipe_id_fk = %s""",
                        (
                            r['to_beer'], r['to_rid'], r['to_suffix'],
                            r['to_sku'], r['new_hash'], r['new_flags'],
                            r['id'],
                            r['from_beer'], r['from_rid'],  # idempotency guards
                        ),
                    )
                    affected = cur.rowcount
                    if affected != 1:
                        raise RuntimeError(
                            f"UPDATE id={r['id']} affected {affected} rows (expected 1) — "
                            f"concurrent modification? Rolling back."
                        )
                    print(f"  Updated id={r['id']}: {r['from_beer']} → {r['to_beer']}  hash={r['new_hash'][:16]}...")

                # Register in schema_migrations
                cur.execute(
                    "INSERT IGNORE INTO schema_migrations (filename) VALUES (%s)",
                    ('265_parallel_run_recipe_fix.sql',),
                )
                print(f"  Registered schema_migrations: 265_parallel_run_recipe_fix.sql")

                conn.commit()
                print("\nAPPLY: all changes COMMITTED.\n")

                # ── Verify ────────────────────────────────────────────────
                print("--- Post-apply verification ---")
                cur.execute(
                    f"SELECT id, neb_beer, recipe_id_fk, nebuleuse_format_suffix, "
                    f"sku_id_fk, row_hash, audit_flags, is_tombstoned "
                    f"FROM bd_packaging_v2 WHERE id IN ({placeholders}) ORDER BY id",
                    ids,
                )
                for row in cur.fetchall():
                    print(f"  {row}")

            except Exception as exc:
                conn.rollback()
                print(f"\nROLLBACK due to error: {exc}", file=sys.stderr)
                sys.exit(1)

    finally:
        conn.close()


if __name__ == '__main__':
    main()
