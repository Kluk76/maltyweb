"""
repair_truncated_packaging_o2.py — one-shot data-repair for bd_packaging rows
(and related bd_packaging_readings rows) whose O2 columns were silently
truncated to 999.999 under the pre-migration DECIMAL(6,3) column.

Migration 043 widened tank_o2 / avg_o2 / min_o2 / max_o2 / delta_o2_pickup in
bd_packaging, and the bd_packaging_readings.o2 column will be widened by
migration 045.  The stored 999.999 sentinel values, however, remain until this
repair script is run.

Real ppb values are read live from BSF PackagingData using each row's
sheet_row_index.  Column mappings (0-indexed cells[]):
  tank_o2        → cells[6]
  avg_o2         → cells[67]
  min_o2         → cells[69]
  max_o2         → cells[70]
  delta_o2_pickup → cells[74]
  reading o2     → cells[14 + 2*(reading_idx-1)]   (0-indexed reading_idx starts at 1)

These mappings are read from tab_packaging.py — DO NOT hardcode column letters.

bd_packaging: 10 rows affected across 5 columns.
bd_packaging_readings: 6 rows affected (o2 column, pending migration 045 which
  widens readings.o2 from DECIMAL(6,3) to DECIMAL(10,3)).

NOTE: bd_packaging has no flag helper for O2 (unlike bd_racking which has
_flag_o2 / _flag_co2 in tab_racking.py).  The packaging O2 fields store raw ppb
only — no *_flag column exists.  No flag recomputation is needed here; if a
QA flag column is added to bd_packaging in a future migration, derive it from
the same tab_racking._flag_o2 thresholds.

Usage:
    python3 scripts/python/maintenance/repair_truncated_packaging_o2.py          # dry-run
    python3 scripts/python/maintenance/repair_truncated_packaging_o2.py --apply  # commit

The UPDATE WHERE clauses include `<col> = 999.999` guards so re-runs after
--apply are idempotent (no row matches the guard once the real value is stored).

All updates are applied in a single transaction; any error rolls back everything.
"""
from __future__ import annotations

import argparse
import sys
from decimal import Decimal
from pathlib import Path

# Allow running from repo root or from inside scripts/python/maintenance/.
_here = Path(__file__).resolve().parent
for _candidate in [_here.parent, _here.parent.parent / "scripts" / "python"]:
    if (_candidate / "lib_config.py").exists():
        sys.path.insert(0, str(_candidate))
        break

import lib_config
import lib_db
from lib_sheets import SheetsClient  # type: ignore[attr-defined]

# ---------------------------------------------------------------------------
# Column indices (0-based) in PackagingData rows — sourced from tab_packaging.py
# ---------------------------------------------------------------------------
COL_TANK_O2        = 6    # cells[6]
COL_AVG_O2         = 67   # cells[67]
COL_MIN_O2         = 69   # cells[69]
COL_MAX_O2         = 70   # cells[70]
COL_DELTA_O2       = 74   # cells[74]

TRUNCATION_SENTINEL = Decimal("999.999")

# ---------------------------------------------------------------------------
# Known affected rows in bd_packaging (discovered by querying DB 2026-05-13).
# Format: {sheet_row_index: {col_name: real_bsf_value, ...}}
# These are verified against live BSF — the script re-reads BSF on every run
# and uses these as a cross-check, not as the source of truth.
# ---------------------------------------------------------------------------
PARENT_REPAIRS: dict[int, dict[str, float]] = {
    1706: {"tank_o2": 1298.0},
    1943: {"tank_o2": 1374.0},
    1967: {"avg_o2": 1468.333, "max_o2": 1826.0, "delta_o2_pickup": 1319.333},
    1968: {"avg_o2": 1468.333, "max_o2": 1826.0, "delta_o2_pickup": 1319.333},
    1995: {"tank_o2": 1580.0, "avg_o2": 1065.0, "max_o2": 1327.0},
    1996: {"tank_o2": 1580.0, "avg_o2": 1065.0, "max_o2": 1327.0},
    2045: {"tank_o2": 3563.0},
    2048: {"tank_o2": 3385.0},
    2061: {"tank_o2": 3094.0},
    2070: {"tank_o2": 2046.0},
}

# ---------------------------------------------------------------------------
# Known affected rows in bd_packaging_readings.
# The readings table stores individual per-reading O2 values; these 6 rows
# were truncated to 999.999 under the old DECIMAL(6,3).
# Migration 045 must run before --apply is used on readings (the column is
# widened there).  This script checks that the column is DECIMAL(10,3) before
# attempting to write; if not, it prints a clear error.
# Format: {(packaging_id, reading_idx): real_o2_value}
# ---------------------------------------------------------------------------
READINGS_REPAIRS: dict[tuple[int, int], float] = {
    (1966, 2): 1623.0,
    (1966, 3): 1826.0,
    (1967, 2): 1623.0,
    (1967, 3): 1826.0,
    (1994, 2): 1327.0,
    (1995, 2): 1327.0,
}

# Map packaging_id → sheet_row_index (for BSF cross-check of readings).
READINGS_PARENT_SRI: dict[int, int] = {
    1966: 1967,
    1967: 1968,
    1994: 1995,
    1995: 1996,
}


def _col_for_db_field(field: str) -> int:
    """Return 0-indexed BSF column for a bd_packaging field name."""
    mapping = {
        "tank_o2": COL_TANK_O2,
        "avg_o2": COL_AVG_O2,
        "min_o2": COL_MIN_O2,
        "max_o2": COL_MAX_O2,
        "delta_o2_pickup": COL_DELTA_O2,
    }
    return mapping[field]


def _reading_o2_col(reading_idx: int) -> int:
    """Return 0-indexed BSF column for reading o2 at reading_idx (1-based)."""
    # From tab_packaging.py: o2_cell = cells[14 + 2 * k] where k = reading_idx - 1
    return 14 + 2 * (reading_idx - 1)


def _bsf_float(row: list, col: int) -> float | None:
    if col >= len(row):
        return None
    val = row[col]
    if val is None or str(val).strip() == "":
        return None
    try:
        return float(val)
    except (TypeError, ValueError):
        return None


def fetch_and_verify_bsf(cfg: lib_config.Config) -> tuple[
    dict[int, dict[str, float]],
    dict[tuple[int, int], float],
]:
    """
    Read live BSF values for all affected rows and cross-check against the
    hardcoded PARENT_REPAIRS and READINGS_REPAIRS tables.

    Returns:
      (parent_bsf_vals, readings_bsf_vals) — same shape as PARENT_REPAIRS /
      READINGS_REPAIRS but sourced from BSF directly.

    Raises SystemExit on any mismatch (within 0.01 tolerance for float rounding).
    """
    sc = SheetsClient(cfg.service_account_path)
    bsf_id = cfg.bsf_spreadsheet_id
    mismatches: list[str] = []

    parent_bsf: dict[int, dict[str, float]] = {}
    for sri, expected_fields in PARENT_REPAIRS.items():
        data = sc.read_range(bsf_id, f"PackagingData!A{sri}:CN{sri}")
        row = data[0] if data else []
        parent_bsf[sri] = {}
        for field, expected in expected_fields.items():
            col = _col_for_db_field(field)
            actual = _bsf_float(row, col)
            if actual is None:
                mismatches.append(
                    f"  parent sri={sri} {field}: BSF returned empty (expected {expected})"
                )
                continue
            if abs(actual - expected) > 0.01:
                mismatches.append(
                    f"  parent sri={sri} {field}: BSF={actual} expected={expected} — "
                    "update PARENT_REPAIRS if BSF was corrected"
                )
            parent_bsf[sri][field] = actual

    readings_bsf: dict[tuple[int, int], float] = {}
    for (pkg_id, ridx), expected in READINGS_REPAIRS.items():
        sri = READINGS_PARENT_SRI[pkg_id]
        data = sc.read_range(bsf_id, f"PackagingData!A{sri}:CN{sri}")
        row = data[0] if data else []
        col = _reading_o2_col(ridx)
        actual = _bsf_float(row, col)
        if actual is None:
            mismatches.append(
                f"  reading pkg_id={pkg_id} idx={ridx} sri={sri}: BSF returned empty "
                f"(expected {expected})"
            )
            continue
        if abs(actual - expected) > 0.01:
            mismatches.append(
                f"  reading pkg_id={pkg_id} idx={ridx} sri={sri}: BSF={actual} "
                f"expected={expected} — update READINGS_REPAIRS if BSF was corrected"
            )
        readings_bsf[(pkg_id, ridx)] = actual

    if mismatches:
        print("BSF cross-check FAILED — aborting to prevent wrong repairs:")
        for m in mismatches:
            print(m)
        sys.exit(1)

    return parent_bsf, readings_bsf


def _check_readings_column_width(conn) -> bool:
    """Return True iff bd_packaging_readings.o2 is already DECIMAL(10,3)."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS "
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bd_packaging_readings' "
            "AND COLUMN_NAME = 'o2'"
        )
        row = cur.fetchone()
        if not row:
            return False
        return row["COLUMN_TYPE"].lower() == "decimal(10,3)"


def run(*, dry_run: bool, skip_bsf_check: bool) -> None:
    cfg = lib_config.load()

    if not skip_bsf_check:
        print("Fetching real values from BSF for cross-check …")
        parent_bsf, readings_bsf = fetch_and_verify_bsf(cfg)
        print(
            f"  BSF cross-check passed: {len(parent_bsf)} parent rows, "
            f"{len(readings_bsf)} reading rows matched."
        )
    else:
        # Build from hardcoded tables
        parent_bsf = {sri: dict(fields) for sri, fields in PARENT_REPAIRS.items()}
        readings_bsf = dict(READINGS_REPAIRS)
        print("BSF cross-check skipped (--skip-bsf-check).")

    conn = lib_db.connect(cfg)

    try:
        with conn.cursor() as cur:
            # ----------------------------------------------------------------
            # Step 1: Discover which parent rows still need repair.
            # ----------------------------------------------------------------
            conditions = " OR ".join(
                [f"`{col}` = 999.999"
                 for col in ("tank_o2", "avg_o2", "min_o2", "max_o2", "delta_o2_pickup")]
            )
            cur.execute(
                f"SELECT sheet_row_index, tank_o2, avg_o2, min_o2, max_o2, delta_o2_pickup "
                f"FROM bd_packaging WHERE {conditions} ORDER BY sheet_row_index"
            )
            db_parents = {r["sheet_row_index"]: r for r in cur.fetchall()}
            print(f"\nFound {len(db_parents)} bd_packaging rows with at least one 999.999 column.")

            # ----------------------------------------------------------------
            # Step 2: Check readings column width.
            # ----------------------------------------------------------------
            readings_col_wide = _check_readings_column_width(conn)
            if not readings_col_wide:
                print(
                    "\nWARNING: bd_packaging_readings.o2 is still DECIMAL(6,3). "
                    "Run migration 045_packaging_readings_o2_widen.sql first.\n"
                    "  Readings repair will be SKIPPED (parent repairs will still apply)."
                )

            # ----------------------------------------------------------------
            # Step 3: Discover which readings rows still need repair.
            # ----------------------------------------------------------------
            cur.execute(
                "SELECT id, packaging_id, reading_idx, o2 "
                "FROM bd_packaging_readings WHERE o2 = 999.999 ORDER BY id"
            )
            db_readings = {
                (r["packaging_id"], r["reading_idx"]): r for r in cur.fetchall()
            }
            print(
                f"Found {len(db_readings)} bd_packaging_readings rows with o2 = 999.999."
            )

            # ----------------------------------------------------------------
            # Step 4: Build repair plans.
            # ----------------------------------------------------------------
            parent_plan: list[dict] = []
            for sri, db_row in sorted(db_parents.items()):
                bsf_vals = parent_bsf.get(sri, {})
                for col in ("tank_o2", "avg_o2", "min_o2", "max_o2", "delta_o2_pickup"):
                    db_val = db_row.get(col)
                    if db_val != TRUNCATION_SENTINEL:
                        continue
                    real_val = bsf_vals.get(col)
                    if real_val is None:
                        print(
                            f"  SKIP  sri={sri} {col}: 999.999 in DB but no BSF value found "
                            "(BSF cell empty — manual review needed)"
                        )
                        continue
                    parent_plan.append({"sri": sri, "col": col, "value": real_val})

            readings_plan: list[dict] = []
            for (pkg_id, ridx), db_row in sorted(db_readings.items()):
                real_val = readings_bsf.get((pkg_id, ridx))
                if real_val is None:
                    print(
                        f"  SKIP  pkg_id={pkg_id} idx={ridx}: 999.999 in DB but "
                        "no BSF value found (manual review needed)"
                    )
                    continue
                readings_plan.append({"pkg_id": pkg_id, "ridx": ridx, "value": real_val})

            # ----------------------------------------------------------------
            # Step 5: Print or apply.
            # ----------------------------------------------------------------
            if dry_run:
                print(f"\n[DRY-RUN] bd_packaging repairs ({len(parent_plan)} column updates):")
                for p in parent_plan:
                    print(
                        f"  UPDATE bd_packaging SET `{p['col']}`={p['value']:.3f} "
                        f"WHERE sheet_row_index={p['sri']} AND `{p['col']}`=999.999"
                    )

                if readings_col_wide:
                    print(
                        f"\n[DRY-RUN] bd_packaging_readings repairs ({len(readings_plan)} rows):"
                    )
                    for p in readings_plan:
                        print(
                            f"  UPDATE bd_packaging_readings SET o2={p['value']:.3f} "
                            f"WHERE packaging_id={p['pkg_id']} AND reading_idx={p['ridx']} "
                            f"AND o2=999.999"
                        )
                else:
                    print(
                        f"\n[DRY-RUN] bd_packaging_readings repairs BLOCKED "
                        f"({len(readings_plan)} rows need migration 045 first)."
                    )

                print("\nRe-run with --apply to commit changes.")
                return

            # Live apply
            parent_repaired = 0
            for p in parent_plan:
                cur.execute(
                    f"UPDATE bd_packaging SET `{p['col']}` = %s "
                    f"WHERE sheet_row_index = %s AND `{p['col']}` = 999.999",
                    (p["value"], p["sri"]),
                )
                affected = cur.rowcount
                if affected:
                    print(
                        f"  REPAIRED bd_packaging sri={p['sri']:>5} {p['col']}: "
                        f"999.999 → {p['value']:.3f}  (affected={affected})"
                    )
                    parent_repaired += affected
                else:
                    print(
                        f"  SKIPPED  bd_packaging sri={p['sri']:>5} {p['col']}: "
                        "already corrected or row missing"
                    )

            readings_repaired = 0
            if readings_col_wide and readings_plan:
                for p in readings_plan:
                    cur.execute(
                        "UPDATE bd_packaging_readings SET o2 = %s "
                        "WHERE packaging_id = %s AND reading_idx = %s AND o2 = 999.999",
                        (p["value"], p["pkg_id"], p["ridx"]),
                    )
                    affected = cur.rowcount
                    if affected:
                        print(
                            f"  REPAIRED bd_packaging_readings "
                            f"pkg_id={p['pkg_id']} idx={p['ridx']}: "
                            f"999.999 → {p['value']:.3f}  (affected={affected})"
                        )
                        readings_repaired += affected
                    else:
                        print(
                            f"  SKIPPED  bd_packaging_readings "
                            f"pkg_id={p['pkg_id']} idx={p['ridx']}: "
                            "already corrected or row missing"
                        )
            elif not readings_col_wide and readings_plan:
                print(
                    f"\n  SKIPPED all {len(readings_plan)} readings repairs "
                    "(migration 045 not yet applied)."
                )

        conn.commit()
        print(
            f"\n✓ Committed: {parent_repaired} bd_packaging column(s) repaired, "
            f"{readings_repaired} bd_packaging_readings row(s) repaired."
        )

    except Exception:
        conn.rollback()
        print("\n✗ Transaction rolled back due to error.")
        raise

    finally:
        conn.close()


def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Repair truncated O2 values (999.999 sentinel) in bd_packaging and "
            "bd_packaging_readings. Default is --dry-run."
        )
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Commit changes. Default is --dry-run.",
    )
    parser.add_argument(
        "--skip-bsf-check",
        action="store_true",
        help="Skip live BSF cross-check and trust the hardcoded repair tables directly.",
    )
    args = parser.parse_args()
    run(dry_run=not args.apply, skip_bsf_check=args.skip_bsf_check)


if __name__ == "__main__":
    main()
