"""
repair_truncated_racking_o2.py — one-shot data-repair for 11 bd_racking rows
whose bbt_o2 was silently truncated to 999.999 under the pre-migration
DECIMAL(6,3) column (older non-strict MySQL mode accepted the value silently).

Also removes the orphan duplicate at sheet_row_index=282 that was left over
after the operator corrected a CO2/O2 swap directly in BSF (the corrected row
was re-ingested by migration 041; the old truncated row was never deleted).

Real ppb values are read from BSF RackingData column O (0-indexed col 14)
for each affected sheet_row_index.  The QA/QC flag is recomputed using the
same _flag_o2() helper from tab_racking.py so thresholds stay in one place.

Usage:
    python3 scripts/python/maintenance/repair_truncated_racking_o2.py          # dry-run
    python3 scripts/python/maintenance/repair_truncated_racking_o2.py --apply  # commit

The UPDATE uses an AND bbt_o2 = 999.999 guard so re-runs after --apply are
idempotent (no row matches the guard once the real value is stored).

The orphan DELETE uses a precise WHERE clause matching all three discriminating
columns (sheet_row_index, bbt_co2, bbt_o2) so it cannot accidentally remove
the corrected row.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

# Allow running from repo root or from inside scripts/python/maintenance/.
_here = Path(__file__).resolve().parent
for _candidate in [_here.parent, _here.parent.parent / "scripts" / "python"]:
    if (_candidate / "lib_config.py").exists():
        sys.path.insert(0, str(_candidate))
        break

import lib_config
import lib_db
from tab_racking import _flag_o2  # type: ignore[attr-defined]
from lib_sheets import SheetsClient

# ---------------------------------------------------------------------------
# Affected sheet_row_index values and their real bbt_o2 values (ppb).
# Sourced from BSF RackingData col O (verified 2026-05-13).
# ---------------------------------------------------------------------------
REPAIRS: list[tuple[int, float]] = [
    (187,  1100.0),
    (256,  2622.0),
    (263,  2012.0),
    (264,  1071.0),
    (292,  1516.0),
    (308,  1452.0),
    (313,  8000.0),
    (320,  9000.0),
    (328,  1025.0),
    (353, 77777.0),
    (354,  5000.0),
]

# Orphan row at sheet_row_index=282 created before the operator BSF correction.
# The corrected row (bbt_co2=3, bbt_o2=1665) was inserted by migration 041.
# This orphan (bbt_co2=999.999, bbt_o2=3) must be deleted.
ORPHAN = {
    "sheet_row_index": 282,
    "bbt_co2": "999.999",
    "bbt_o2": "3.000",
}


def fetch_bsf_values(cfg: lib_config.Config) -> dict[int, float]:
    """
    Cross-checks BSF RackingData against our REPAIRS table.
    Returns {sheet_row_index: real_ppb} fetched live from BSF.
    Raises if any value doesn't match (safety check before applying).
    """
    sc = SheetsClient(cfg.service_account_path)
    bsf_id = cfg.bsf_spreadsheet_id
    result: dict[int, float] = {}
    mismatches: list[str] = []

    for sri, expected in REPAIRS:
        data = sc.read_range(bsf_id, f"RackingData!O{sri}")
        raw = data[0][0] if data and data[0] else None
        if raw is None:
            mismatches.append(f"  row {sri}: BSF returned empty cell (expected {expected})")
            continue
        try:
            actual = float(raw)
        except (TypeError, ValueError):
            mismatches.append(f"  row {sri}: BSF returned non-numeric {raw!r} (expected {expected})")
            continue
        if abs(actual - expected) > 0.001:
            mismatches.append(
                f"  row {sri}: BSF returned {actual} but expected {expected} — "
                "update REPAIRS table if BSF was corrected"
            )
        result[sri] = actual

    if mismatches:
        print("BSF cross-check FAILED — aborting to prevent wrong repairs:")
        for m in mismatches:
            print(m)
        sys.exit(1)

    return result


def run(*, dry_run: bool, skip_bsf_check: bool) -> None:
    cfg = lib_config.load()

    if not skip_bsf_check:
        print("Fetching real values from BSF for cross-check …")
        bsf_vals = fetch_bsf_values(cfg)
        print(f"  BSF cross-check passed ({len(bsf_vals)} rows matched).")
    else:
        bsf_vals = {sri: ppb for sri, ppb in REPAIRS}
        print("BSF cross-check skipped (--skip-bsf-check).")

    conn = lib_db.connect(cfg)

    try:
        with conn.cursor() as cur:
            # ----------------------------------------------------------------
            # Step 1: Verify the 11 truncated rows are actually present.
            # ----------------------------------------------------------------
            sris = [sri for sri, _ in REPAIRS]
            placeholders = ",".join(["%s"] * len(sris))
            cur.execute(
                f"SELECT sheet_row_index, bbt_o2 FROM bd_racking "
                f"WHERE sheet_row_index IN ({placeholders}) AND bbt_o2 = 999.999",
                sris,
            )
            candidates = {row["sheet_row_index"]: row["bbt_o2"] for row in cur.fetchall()}
            print(f"\nFound {len(candidates)}/11 truncated rows with bbt_o2 = 999.999.")
            if len(candidates) == 0:
                print("  Nothing to repair — all rows already corrected.")

            # ----------------------------------------------------------------
            # Step 2: Verify the orphan row 282 is present.
            # ----------------------------------------------------------------
            cur.execute(
                "SELECT id, sheet_row_index, bbt_co2, bbt_o2 FROM bd_racking "
                "WHERE sheet_row_index = %s AND bbt_co2 = 999.999 AND bbt_o2 = 3.000",
                (ORPHAN["sheet_row_index"],),
            )
            orphan_rows = cur.fetchall()
            print(f"\nOrphan rows at sheet_row_index=282 matching (co2=999.999, o2=3): {len(orphan_rows)}")
            for r in orphan_rows:
                print(f"  id={r['id']} bbt_co2={r['bbt_co2']} bbt_o2={r['bbt_o2']}")

            if dry_run:
                print("\n[DRY-RUN] The following UPDATEs would be applied:")
                for sri, ppb in REPAIRS:
                    flag = _flag_o2(ppb)
                    if sri in candidates:
                        print(
                            f"  UPDATE bd_racking SET bbt_o2={ppb}, bbt_o2_flag='{flag}' "
                            f"WHERE sheet_row_index={sri} AND bbt_o2=999.999"
                        )
                    else:
                        print(f"  SKIP   sheet_row_index={sri} — already corrected (bbt_o2 ≠ 999.999)")

                if orphan_rows:
                    print(
                        f"\n[DRY-RUN] DELETE FROM bd_racking "
                        f"WHERE sheet_row_index=282 AND bbt_co2=999.999 AND bbt_o2=3.000 "
                        f"— would remove {len(orphan_rows)} row(s)"
                    )
                else:
                    print("\n[DRY-RUN] Orphan at row 282 already gone — DELETE is a no-op.")

                print("\nRe-run with --apply to commit changes.")
                return

            # ----------------------------------------------------------------
            # Step 3: Apply repairs in a single transaction.
            # ----------------------------------------------------------------
            repaired = 0
            for sri, ppb in REPAIRS:
                flag = _flag_o2(ppb)
                cur.execute(
                    "UPDATE bd_racking SET bbt_o2 = %s, bbt_o2_flag = %s "
                    "WHERE sheet_row_index = %s AND bbt_o2 = 999.999",
                    (ppb, flag, sri),
                )
                affected = cur.rowcount
                if affected:
                    print(
                        f"  REPAIRED row {sri:>4}: bbt_o2 999.999 → {ppb:.3f}  "
                        f"flag → {flag}  (affected={affected})"
                    )
                    repaired += affected
                else:
                    print(f"  SKIPPED  row {sri:>4}: bbt_o2 ≠ 999.999 (already corrected or missing)")

            # ----------------------------------------------------------------
            # Step 4: Delete orphan row 282.
            # ----------------------------------------------------------------
            cur.execute(
                "DELETE FROM bd_racking "
                "WHERE sheet_row_index = 282 AND bbt_co2 = 999.999 AND bbt_o2 = 3.000",
            )
            orphan_deleted = cur.rowcount
            if orphan_deleted:
                print(f"\n  DELETED orphan at sheet_row_index=282 (co2=999.999, o2=3) — {orphan_deleted} row(s) removed.")
            else:
                print("\n  Orphan at sheet_row_index=282 already gone — DELETE was a no-op.")

        conn.commit()
        print(f"\n✓ Committed: {repaired} O2 rows repaired, {orphan_deleted} orphan(s) deleted.")

    except Exception:
        conn.rollback()
        print("\n✗ Transaction rolled back due to error.")
        raise

    finally:
        conn.close()


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Repair 11 truncated bbt_o2 rows and remove orphan duplicate at row 282."
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Commit changes. Default is --dry-run.",
    )
    parser.add_argument(
        "--skip-bsf-check",
        action="store_true",
        help="Skip live BSF cross-check and trust the hardcoded REPAIRS table directly.",
    )
    args = parser.parse_args()
    run(dry_run=not args.apply, skip_bsf_check=args.skip_bsf_check)


if __name__ == "__main__":
    main()
