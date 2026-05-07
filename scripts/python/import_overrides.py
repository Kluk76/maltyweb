"""
import_overrides.py — load supplierAliases from entity-overrides.json into ref_supplier_aliases.

Reads the JSON, resolves each canonical supplier name against ref_suppliers (case-insensitive),
and inserts alias rows idempotently.

Path resolution (first path that exists wins):
  1. /var/www/maltytask/data/entity-overrides.json  (VPS production path)
  2. <repo root>/data/entity-overrides.json          (local dev, relative to this script)

Usage:
  python import_overrides.py              # dry-run (default)
  python import_overrides.py --apply      # live write
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from lib_config import load as load_config
from lib_db import connect

VPS_PATH = Path("/var/www/maltytask/data/entity-overrides.json")
LOCAL_PATH = Path(__file__).parent.parent.parent / "data" / "entity-overrides.json"


def find_overrides_file() -> Path:
    if VPS_PATH.exists():
        print(f"  using VPS path: {VPS_PATH}")
        return VPS_PATH
    if LOCAL_PATH.exists():
        print(f"  using local path: {LOCAL_PATH}")
        return LOCAL_PATH
    raise FileNotFoundError(
        f"entity-overrides.json not found at {VPS_PATH} or {LOCAL_PATH}"
    )


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Import supplierAliases from entity-overrides.json into ref_supplier_aliases.",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Commit writes to DB (default is dry-run).",
    )
    args = parser.parse_args()
    dry_run = not args.apply

    print(f"\n{'[DRY-RUN] ' if dry_run else ''}Importing supplier aliases from entity-overrides.json ...")

    overrides_path = find_overrides_file()
    data = json.loads(overrides_path.read_text(encoding="utf-8"))
    supplier_aliases: dict[str, str] = data.get("supplierAliases", {})

    print(f"  aliases in JSON: {len(supplier_aliases)}")

    cfg = load_config()
    conn = connect(cfg)

    inserted = 0
    already_exist = 0
    warnings = 0
    unresolved_names: list[str] = []

    try:
        with conn.cursor() as cur:
            for alias_raw, canonical_name in supplier_aliases.items():
                # Look up canonical name (case-insensitive); multi-GL suppliers may have
                # multiple rows — pick the first (aliases attach at name level, not name+GL).
                cur.execute(
                    "SELECT id FROM ref_suppliers WHERE LOWER(name) = LOWER(%s) LIMIT 1",
                    (canonical_name,),
                )
                row = cur.fetchone()

                if row is None:
                    print(f"  WARNING: canonical name not in ref_suppliers — skipping: {canonical_name!r}")
                    warnings += 1
                    unresolved_names.append(canonical_name)
                    continue

                supplier_id = row["id"]

                if dry_run:
                    # Check whether it already exists to give accurate would-insert count
                    cur.execute(
                        "SELECT 1 FROM ref_supplier_aliases"
                        " WHERE supplier_id_fk = %s AND LOWER(alias) = LOWER(%s)",
                        (supplier_id, alias_raw),
                    )
                    exists = cur.fetchone() is not None
                    if exists:
                        already_exist += 1
                    else:
                        inserted += 1
                else:
                    # Idempotent upsert — re-run replaces nothing meaningful
                    cur.execute(
                        "INSERT INTO ref_supplier_aliases (supplier_id_fk, alias, source)"
                        " VALUES (%s, %s, 'manual')"
                        " ON DUPLICATE KEY UPDATE source = 'manual'",
                        (supplier_id, alias_raw),
                    )
                    affected = cur.rowcount
                    # rowcount=1 → inserted, rowcount=2 → updated (ON DUPLICATE), rowcount=0 → no-op
                    if affected == 1:
                        inserted += 1
                    else:
                        already_exist += 1

        if not dry_run:
            conn.commit()

    finally:
        conn.close()

    print(f"\n  total aliases:    {len(supplier_aliases)}")
    print(f"  {'would insert' if dry_run else 'inserted'}:      {inserted}")
    print(f"  already exist:    {already_exist}")
    print(f"  warnings (canonical name not found): {warnings}")
    if unresolved_names:
        print(f"\n  unresolved canonical names:")
        for name in unresolved_names:
            print(f"    {name!r}")

    if dry_run:
        print(f"\n[dry-run] no rows written. Re-run with --apply to commit.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
