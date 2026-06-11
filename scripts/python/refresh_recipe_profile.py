"""
refresh_recipe_profile — populate ref_recipe_profile, ref_recipe_profile_malt,
ref_recipe_profile_hops from live brewing/fermenting/packaging data.

For every active recipe × 3 time windows, compute observed averages and
UPSERT the result rows. Idempotent: re-running with no new data produces the
same rows.

Usage:
  python refresh_recipe_profile.py            # dry-run
  python refresh_recipe_profile.py --apply    # write to DB
  python refresh_recipe_profile.py --apply --recipe "Embuscade"
  python refresh_recipe_profile.py --apply --window rolling_12mo
  python refresh_recipe_profile.py --apply --verbose
"""
from __future__ import annotations

import argparse
import re
import time
import sys
from collections import defaultdict
from decimal import Decimal, ROUND_HALF_UP
from typing import Any

import pymysql
import numpy as np

from lib_config import load as load_config
from lib_db import connect



# ---------------------------------------------------------------------------
# Time window definitions
# ---------------------------------------------------------------------------

WINDOWS: dict[str, tuple[str, str | None]] = {
    "rolling_12mo":   ("event_date >= DATE_SUB(NOW(), INTERVAL 365 DAY)", None),
    "all_time":       ("1=1", None),
    "since_revision": (
        "event_date >= COALESCE((SELECT revision_date FROM ref_recipes WHERE id = %(recipe_id)s), DATE('1970-01-01'))",
        "requires_revision",
    ),
}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _dec(v, places: int = 2) -> Decimal | None:
    """Round a float/Decimal/None to a fixed-precision Decimal for MySQL."""
    if v is None:
        return None
    try:
        q = Decimal("0." + "0" * places)
        return Decimal(str(float(v))).quantize(q, rounding=ROUND_HALF_UP)
    except Exception:
        return None


def _median(values: list) -> float | None:
    """Return float median of a list, or None if empty."""
    filtered = [float(v) for v in values if v is not None]
    if not filtered:
        return None
    return float(np.median(filtered))


def _avg(values: list) -> float | None:
    """Return float avg of a list, or None if empty."""
    filtered = [float(v) for v in values if v is not None]
    if not filtered:
        return None
    return sum(filtered) / len(filtered)


def _clamp(v: float | None, lo: float, hi: float) -> float | None:
    if v is None:
        return None
    return max(lo, min(hi, v))


# ---------------------------------------------------------------------------
# Data fetchers (all return per-batch dicts keyed by (beer, batch))
# ---------------------------------------------------------------------------

def fetch_active_recipes(conn, recipe_filter: str | None) -> list[dict]:
    sql = "SELECT id, name, revision_date FROM ref_recipes WHERE is_active = 1"
    params: list = []
    if recipe_filter:
        sql += " AND name = %s"
        params.append(recipe_filter)
    sql += " ORDER BY name"
    with conn.cursor() as cur:
        cur.execute(sql, params)
        return cur.fetchall()


def fetch_cooling_batches(conn, recipe_name: str, window_sql: str, recipe_id: int) -> list[dict]:
    """
    Returns one row per cooling event for this recipe inside the window.
    We use MAX(final_gravity) as OG per batch (matches tanks.php fix).
    Uses bd_brewing_gravity_v2 with event_type='Cooling'.
    """
    adapted_window = window_sql.replace('event_date', 'DATE(submitted_at)')
    sql = f"""
        SELECT
            batch                   AS batch,
            MIN(DATE(submitted_at)) AS batch_date,
            AVG(final_ph)           AS cooling_ph,
            MAX(final_gravity)      AS og
        FROM bd_brewing_gravity_v2
        WHERE beer = %(recipe_name)s
          AND event_type = 'Cooling'
          AND is_tombstoned = 0
          AND {adapted_window}
        GROUP BY batch
    """
    with conn.cursor() as cur:
        cur.execute(sql, {"recipe_name": recipe_name, "recipe_id": recipe_id})
        return cur.fetchall()


def fetch_gravity_stage(conn, recipe_name: str, stage: str, window_sql: str, recipe_id: int) -> dict[str, dict]:
    """Returns {batch: {gravity, ph}} for the given gravity stage from bd_brewing_gravity_v2."""
    # Map stage name to the correct v2 column names
    gravity_col_map = {
        "FirstWort":  ("firstwort_gravity", "firstwort_ph"),
        "Pfannevoll": ("pfannevoll_gravity", None),
        "Kochwurze":  ("kochwurze_gravity",  None),
        "Cooling":    ("final_gravity",       "final_ph"),
    }
    cols = gravity_col_map.get(stage)
    if cols is None:
        return {}
    grav_col, ph_col = cols
    ph_expr = f"AVG({ph_col})" if ph_col else "NULL"
    # window_sql references event_date; v2 gravity uses submitted_at as the date proxy
    adapted_window = window_sql.replace('event_date', 'DATE(submitted_at)')
    sql = f"""
        SELECT batch,
               AVG({grav_col}) AS gravity,
               {ph_expr}       AS ph
        FROM bd_brewing_gravity_v2
        WHERE beer = %(recipe_name)s
          AND event_type = %(stage)s
          AND is_tombstoned = 0
          AND {adapted_window}
        GROUP BY batch
    """
    with conn.cursor() as cur:
        cur.execute(sql, {"recipe_name": recipe_name, "stage": stage, "recipe_id": recipe_id})
        rows = cur.fetchall()
    return {r["batch"]: r for r in rows}


def fetch_fermenting_last_pre_cc(conn, recipe_name: str, batches_and_dates: list[dict]) -> dict[str, dict]:
    """
    For each (batch, cc_date), fetch the last fermenting Reads measurement
    (gravity + ph) whose event_date is strictly before cc_date.
    Returns {batch: {fg, end_ferm_ph}}.

    bd_fermenting_v2 has direct beer_raw+batch+recipe_id_fk columns.
    We join via ref_recipes to match by name (handles both old prefix-encoded
    beer_raw and new bare-name format consistently).
    """
    if not batches_and_dates:
        return {}

    batch_cc: dict[str, Any] = {}
    for item in batches_and_dates:
        batch_cc[item["batch"]] = item.get("cc_date")

    batches = list(batch_cc.keys())
    if not batches:
        return {}

    placeholders = ", ".join(["%s"] * len(batches))
    sql = f"""
        SELECT
            f.batch,
            f.event_date,
            f.gravity,
            f.ph
        FROM bd_fermenting_v2 f
        JOIN ref_recipes r ON r.id = f.recipe_id_fk
        WHERE r.name = %s
          AND f.batch IN ({placeholders})
          AND f.event_type = 'Reads'
          AND f.is_tombstoned = 0
          AND f.event_date IS NOT NULL
        ORDER BY f.batch, f.event_date DESC
    """
    with conn.cursor() as cur:
        cur.execute(sql, [recipe_name] + batches)
        rows = cur.fetchall()

    result: dict[str, dict] = {}
    for row in rows:
        b = row["batch"]
        if b not in batch_cc or b in result:
            continue  # already resolved
        cc = batch_cc[b]
        if cc is None:
            # no cc_date: take the latest Reads row regardless
            result[b] = {"fg": row["gravity"], "end_ferm_ph": row["ph"]}
        elif row["event_date"] < cc:
            result[b] = {"fg": row["gravity"], "end_ferm_ph": row["ph"]}

    return result


def fetch_timings(conn, recipe_name: str, batches: list[str]) -> dict[str, Any]:
    """
    Fetch start_ferm per batch from bd_brewing_timings_v2.
    start_ferm is 100% NULL in v2; fall back to MIN(event_date) as ferment-start anchor.
    Returns {batch: start_ferm_str_or_date}.
    """
    if not batches:
        return {}
    placeholders = ", ".join(["%s"] * len(batches))
    sql = f"""
        SELECT batch,
               COALESCE(
                   MAX(STR_TO_DATE(start_ferm, '%%d.%%m.%%Y %%H:%%i:%%s')),
                   MIN(event_date)
               ) AS start_ferm
        FROM bd_brewing_timings_v2
        WHERE beer = %s
          AND batch IN ({placeholders})
          AND is_tombstoned = 0
        GROUP BY batch
    """
    with conn.cursor() as cur:
        cur.execute(sql, [recipe_name] + batches)
        rows = cur.fetchall()
    return {r["batch"]: r["start_ferm"] for r in rows}


def fetch_cc_dates(conn, recipe_name: str, batches: list[str]) -> dict[str, Any]:
    """
    Fetch the earliest cold-crash date per batch from bd_fermenting_v2.
    event_type='ColdCrash' discriminates CC events; batch is a direct column.
    Keys on recipe_id_fk+batch where available, else beer_raw+batch.
    Returns {batch: earliest_cc_date}.
    """
    if not batches:
        return {}
    placeholders = ", ".join(["%s"] * len(batches))
    sql = f"""
        SELECT
            f.batch,
            MIN(f.event_date) AS cc_date
        FROM bd_fermenting_v2 f
        JOIN ref_recipes r ON r.id = f.recipe_id_fk
        WHERE r.name = %s
          AND f.batch IN ({placeholders})
          AND f.event_type = 'ColdCrash'
          AND f.is_tombstoned = 0
          AND f.event_date IS NOT NULL
        GROUP BY f.batch
    """
    with conn.cursor() as cur:
        cur.execute(sql, [recipe_name] + batches)
        rows = cur.fetchall()
    return {r["batch"]: r["cc_date"] for r in rows}


def fetch_racking_dates(conn, recipe_name: str, batches: list[str]) -> dict[str, Any]:
    """
    Fetch the racking date per batch from bd_racking_v2.
    Uses event_date directly (v2 has this column).
    Matches on neb_beer/neb_batch OR contract_beer/contract_batch.
    Returns {batch: racking_date}.
    """
    if not batches:
        return {}
    placeholders = ", ".join(["%s"] * len(batches))
    sql = f"""
        SELECT
            COALESCE(neb_batch, contract_batch) AS batch,
            MIN(event_date)                     AS racking_date
        FROM bd_racking_v2
        WHERE (
            (neb_beer = %s AND neb_batch IN ({placeholders}))
            OR (contract_beer = %s AND contract_batch IN ({placeholders}))
        )
        AND is_tombstoned = 0
        AND event_date IS NOT NULL
        GROUP BY COALESCE(neb_batch, contract_batch)
    """
    params = [recipe_name] + batches + [recipe_name] + batches
    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = cur.fetchall()
    return {r["batch"]: r["racking_date"] for r in rows}


def fetch_packaging_window_level(conn, recipe_name: str, window_sql: str, recipe_id: int) -> dict:
    """
    Recipe-level packaging aggregates over all packaging events in the window.
    Uses bd_packaging_v2. O2/CO2 aggregated from bd_packaging_readings child table.

    Returns {yield_pct: float|None, loss_pct: float|None,
             o2_ppb: float|None, co2_vol: float|None,
             event_count: int}.
    """
    sql = f"""
        SELECT
            p.vendable_hl / NULLIF(p.objective_hl, 0)        AS yield_pct,
            p.loss_kpi_hl / NULLIF(p.objective_hl, 0)        AS pct_loss,
            AVG(pr.o2)                                        AS avg_o2,
            AVG(pr.co2)                                       AS avg_co2
        FROM bd_packaging_v2 p
        LEFT JOIN bd_packaging_readings pr ON pr.packaging_v2_id = p.id
        WHERE p.recipe_id_fk = %(recipe_id)s
          AND p.is_tombstoned = 0
          AND p.event_date IS NOT NULL
          AND {window_sql}
        GROUP BY p.id, p.vendable_hl, p.objective_hl, p.loss_kpi_hl
    """
    with conn.cursor() as cur:
        cur.execute(sql, {"recipe_name": recipe_name, "recipe_id": recipe_id})
        rows = cur.fetchall()

    return {
        "yield_pct":   _median([r["yield_pct"] for r in rows]),
        "loss_pct":    _median([r["pct_loss"]  for r in rows]),
        "o2_ppb":      _median([r["avg_o2"]    for r in rows]),
        "co2_vol":     _median([r["avg_co2"]   for r in rows]),
        "event_count": len(rows),
    }


def fetch_garde_days_window(conn, recipe_name: str, window_sql: str, recipe_id: int) -> "float | None":
    """
    Median days between racking and first-packaging, recipe-level.
    For each packaging event (P) in the window, find the most recent racking
    event (R) of the same recipe with R.date <= P.date. delta = P.date - R.date.
    Median delta is the recipe's typical garde duration.
    """
    import bisect

    # 1. Fetch all racking dates for this recipe from bd_racking_v2 (no window —
    #    we may need a racking that pre-dates the window for a packaging in it).
    rack_sql = """
        SELECT event_date AS racking_date
        FROM bd_racking_v2
        WHERE (neb_beer = %s OR contract_beer = %s)
          AND is_tombstoned = 0
          AND event_date IS NOT NULL
        ORDER BY event_date
    """
    with conn.cursor() as cur:
        cur.execute(rack_sql, [recipe_name, recipe_name])
        rack_rows = cur.fetchall()
    racking_dates = sorted(r["racking_date"] for r in rack_rows if r["racking_date"])
    if not racking_dates:
        return None

    # 2. Fetch all packaging dates for this recipe in the window from bd_packaging_v2.
    pkg_sql = f"""
        SELECT event_date AS packaging_date
        FROM bd_packaging_v2
        WHERE recipe_id_fk = %(recipe_id)s
          AND is_tombstoned = 0
          AND event_date IS NOT NULL
          AND {window_sql}
    """
    with conn.cursor() as cur:
        cur.execute(pkg_sql, {"recipe_name": recipe_name, "recipe_id": recipe_id})
        pkg_rows = cur.fetchall()

    # 3. Binary-search the most recent prior racking per packaging date.
    deltas: list[int] = []
    for r in pkg_rows:
        pd = r["packaging_date"]
        if pd is None:
            continue
        idx = bisect.bisect_right(racking_dates, pd) - 1
        if idx < 0:
            continue  # no racking before this packaging
        delta = (pd - racking_dates[idx]).days
        if 0 <= delta <= 365:  # sanity bound
            deltas.append(delta)

    return _median(deltas) if deltas else None


# ---------------------------------------------------------------------------
# Ingredient side-table data
# ---------------------------------------------------------------------------

def fetch_malt_per_recipe_window(
    conn, recipe_name: str,
    batches_in_window: list[str],
    packaging_hl: dict[str, float],
) -> list[dict]:
    """
    Returns aggregated malt data per mi_id for this recipe+window.
    Batches in window are passed pre-filtered; no window SQL needed here.
    Excludes rows with mi_id IS NULL.
    """
    if not batches_in_window:
        return []

    # The window filter is already applied upstream (batches_in_window was built
    # from cooling rows inside the window). Using the batch list as the filter
    # here avoids mixing positional %s with named %(recipe_id)s window params.
    placeholders = ", ".join(["%s"] * len(batches_in_window))
    sql = f"""
        SELECT
            p.mi_id_fk,
            h.batch,
            SUM(
                CASE WHEN p.unit = 'g' THEN p.qty / 1000 ELSE p.qty END
            )                       AS batch_kg
        FROM bd_brewing_ingredients_parsed_v2 p
        JOIN bd_brewing_ingredients_v2 h ON h.id = p.header_id
        WHERE p.category = 'malt'
          AND h.beer = %s
          AND p.mi_id_fk IS NOT NULL
          AND h.batch IN ({placeholders})
          AND h.is_tombstoned = 0
        GROUP BY p.mi_id_fk, h.batch
    """
    with conn.cursor() as cur:
        cur.execute(sql, [recipe_name] + batches_in_window)
        rows = cur.fetchall()

    # Compute per-batch total grist
    batch_grist: dict[str, float] = defaultdict(float)
    batch_mi_kg: dict[str, dict[str, float]] = defaultdict(lambda: defaultdict(float))

    for r in rows:
        mi_id_fk = r["mi_id_fk"]
        batch = r["batch"]
        kg = float(r["batch_kg"] or 0)
        batch_grist[batch] += kg
        batch_mi_kg[mi_id_fk][batch] += kg

    total_batches = len(batches_in_window)
    all_mi_ids = set(batch_mi_kg.keys())

    result: list[dict] = []
    for mi_id_fk in sorted(all_mi_ids):
        kg_list: list[float] = []
        kg_per_hl_list: list[float] = []
        pct_list: list[float] = []
        appearance = 0

        for batch in batches_in_window:
            kg = batch_mi_kg[mi_id_fk].get(batch)
            if kg is None:
                continue
            appearance += 1
            kg_list.append(kg)

            hl = packaging_hl.get(batch)
            if hl and hl > 0:
                kg_per_hl_list.append(kg / hl)

            grist = batch_grist.get(batch)
            if grist and grist > 0:
                pct_list.append(kg / grist * 100)

        result.append({
            "mi_id_fk":         mi_id_fk,
            "avg_kg_per_brew":  _dec(_avg(kg_list), 2),
            "avg_kg_per_hl":    _dec(_avg(kg_per_hl_list), 3),
            "pct_of_grist":     _dec(_avg(pct_list), 2),
            "appearance_count": appearance,
            "total_batches":    total_batches,
        })

    return result


def fetch_hops_per_recipe_window(
    conn, recipe_name: str,
    batches_in_window: list[str],
    packaging_hl: dict[str, float],
) -> list[dict]:
    """
    Returns aggregated hop data per (mi_id, stage) for this recipe+window.
    Excludes rows with mi_id IS NULL.
    stage: 'kettle' for hops_kettle, 'dry_hop' for hops_dry.
    """
    if not batches_in_window:
        return []

    placeholders = ", ".join(["%s"] * len(batches_in_window))
    sql = f"""
        SELECT
            p.mi_id_fk,
            p.category,
            h.batch,
            SUM(
                CASE WHEN p.unit = 'kg' THEN p.qty * 1000 ELSE p.qty END
            )               AS batch_g
        FROM bd_brewing_ingredients_parsed_v2 p
        JOIN bd_brewing_ingredients_v2 h ON h.id = p.header_id
        WHERE p.category IN ('hops_kettle', 'hops_dry')
          AND h.beer = %s
          AND p.mi_id_fk IS NOT NULL
          AND h.batch IN ({placeholders})
          AND h.is_tombstoned = 0
        GROUP BY p.mi_id_fk, p.category, h.batch
    """
    with conn.cursor() as cur:
        cur.execute(sql, [recipe_name] + batches_in_window)
        rows = cur.fetchall()

    # category → stage mapping
    cat_to_stage = {"hops_kettle": "kettle", "hops_dry": "dry_hop"}

    # Aggregate: (mi_id_fk, stage) → {batch: g}
    combo_g: dict[tuple, dict[str, float]] = defaultdict(lambda: defaultdict(float))

    for r in rows:
        mi_id_fk = r["mi_id_fk"]
        stage = cat_to_stage.get(r["category"], r["category"])
        batch = r["batch"]
        g = float(r["batch_g"] or 0)
        combo_g[(mi_id_fk, stage)][batch] += g

    total_batches = len(batches_in_window)
    result: list[dict] = []

    for (mi_id_fk, stage) in sorted(combo_g.keys()):
        g_list: list[float] = []
        g_per_hl_list: list[float] = []
        appearance = 0

        for batch in batches_in_window:
            g = combo_g[(mi_id_fk, stage)].get(batch)
            if g is None:
                continue
            appearance += 1
            g_list.append(g)

            hl = packaging_hl.get(batch)
            if hl and hl > 0:
                g_per_hl_list.append(g / hl)

        result.append({
            "mi_id_fk":         mi_id_fk,
            "stage":            stage,
            "avg_g_per_brew":   _dec(_avg(g_list), 2),
            "avg_g_per_hl":     _dec(_avg(g_per_hl_list), 3),
            "appearance_count": appearance,
            "total_batches":    total_batches,
        })

    return result


# ---------------------------------------------------------------------------
# Date arithmetic helpers
# ---------------------------------------------------------------------------

def _date_to_py(v):
    """Normalize a MySQL date/datetime/str to a Python date or None."""
    from datetime import date as date_type, datetime as dt_type
    if v is None:
        return None
    if isinstance(v, dt_type):
        return v.date()
    if isinstance(v, date_type):
        return v
    from lib_coerce import d
    return d(str(v))


def _ferm_days(start_ferm_str, cc_date) -> float | None:
    """DATEDIFF(cc_date, start_ferm) — days in primary fermentation."""
    from lib_coerce import dt as parse_dt
    sf = parse_dt(start_ferm_str)
    if sf is None:
        return None
    cc = _date_to_py(cc_date)
    if cc is None:
        return None
    from datetime import datetime, date as dt_date
    sf_date = sf.date() if hasattr(sf, "date") else sf
    delta = (cc - sf_date).days
    return float(delta) if delta >= 0 else None


def _cc_days(cc_date, racking_date) -> float | None:
    """DATEDIFF(racking_date, cc_date) — cold-conditioning duration."""
    cc = _date_to_py(cc_date)
    rd = _date_to_py(racking_date)
    if cc is None or rd is None:
        return None
    delta = (rd - cc).days
    return float(delta) if delta >= 0 else None


def _garde_days(racking_date, first_packaging_date) -> float | None:
    """DATEDIFF(first_packaging_date, racking_date)."""
    rd = _date_to_py(racking_date)
    pd = _date_to_py(first_packaging_date)
    if rd is None or pd is None:
        return None
    delta = (pd - rd).days
    return float(delta) if delta >= 0 else None


# ---------------------------------------------------------------------------
# Core: compute profile for one (recipe, window)
# ---------------------------------------------------------------------------

def compute_profile(
    conn,
    recipe: dict,
    window_name: str,
    window_sql: str,
    *,
    verbose: bool = False,
) -> dict | None:
    """
    Returns a dict suitable for UPSERT into ref_recipe_profile, or None if
    the window should be skipped (since_revision with no revision_date).

    Also returns side-table data under keys '_malt_rows' and '_hop_rows'.
    """
    recipe_id = recipe["id"]
    recipe_name = recipe["name"]
    revision_date = recipe.get("revision_date")

    # Skip since_revision if revision_date is NULL
    if window_name == "since_revision" and revision_date is None:
        if verbose:
            print(f"    [{recipe_name}] skip since_revision — no revision_date")
        return None

    # ── 1. Get batches inside this window ───────────────────────────────────
    cooling_batches = fetch_cooling_batches(conn, recipe_name, window_sql, recipe_id)
    batches_in_window = [r["batch"] for r in cooling_batches if r["batch"]]
    batch_count = len(batches_in_window)

    if verbose:
        print(f"    [{recipe_name}] window={window_name} batches={batch_count}")

    if batch_count == 0:
        # Still write the row with NULLs (documents that recipe exists in window)
        return {
            "recipe_id":    recipe_id,
            "window_kind":  window_name,
            "batch_count":  0,
            "earliest_batch_date": None,
            "latest_batch_date":   None,
            # All scalars NULL
            "avg_first_wort_gravity": None,
            "avg_kochwurze_gravity":  None,
            "avg_og":                 None,
            "avg_fg":                 None,
            "avg_apparent_atten_pct": None,
            "avg_first_wort_ph":      None,
            "avg_kochwurze_ph":       None,
            "avg_cooling_ph":         None,
            "avg_end_ferm_ph":        None,
            "avg_ferm_days":          None,
            "avg_cc_days":            None,
            "avg_garde_days":         None,
            "median_packaging_yield_pct": None,
            "median_loss_pct":        None,
            "median_packaged_o2_ppb": None,
            "median_packaged_co2_vol": None,
            "_malt_rows": [],
            "_hop_rows":  [],
        }

    # Batch dates
    batch_dates = [r["batch_date"] for r in cooling_batches if r.get("batch_date")]
    earliest = min(batch_dates) if batch_dates else None
    latest   = max(batch_dates) if batch_dates else None

    # ── 2. Gravity stages (per-batch averages from bd_brewing_gravity_v2) ───
    fw_map  = fetch_gravity_stage(conn, recipe_name, "FirstWort",  window_sql, recipe_id)
    kw_map  = fetch_gravity_stage(conn, recipe_name, "Kochwurze",  window_sql, recipe_id)

    # ── 3. Cooling metrics (og, cooling_ph) — already in cooling_batches ────
    cooling_map = {r["batch"]: r for r in cooling_batches}

    # ── 4. CC dates (needed for fermenting last-pre-cc lookup) ──────────────
    cc_date_map = fetch_cc_dates(conn, recipe_name, batches_in_window)

    # Build list for fermenting fetch: {batch, cc_date}
    batch_cc_list = [{"batch": b, "cc_date": cc_date_map.get(b)} for b in batches_in_window]

    # ── 5. Fermenting: last row before cc_date ───────────────────────────────
    ferm_map = fetch_fermenting_last_pre_cc(conn, recipe_name, batch_cc_list)

    # ── 6. Timings: start_ferm ───────────────────────────────────────────────
    timings_map = fetch_timings(conn, recipe_name, batches_in_window)

    # ── 7. Racking dates ─────────────────────────────────────────────────────
    racking_map = fetch_racking_dates(conn, recipe_name, batches_in_window)

    # ── 8. Packaging — recipe-level (batch counter mismatch; no per-batch join) ─
    pkg_metrics = fetch_packaging_window_level(conn, recipe_name, window_sql, recipe_id)
    garde_days  = fetch_garde_days_window(conn, recipe_name, window_sql, recipe_id)

    # ── 9. Per-batch aggregation ──────────────────────────────────────────────
    fw_grav_list:  list = []
    fw_ph_list:    list = []
    kw_grav_list:  list = []
    og_list:       list = []
    fg_list:       list = []
    cooling_ph_list: list = []
    end_ferm_ph_list: list = []
    atten_list:    list = []
    ferm_days_list: list = []
    cc_days_list:  list = []

    # packaging_hl for side tables (built from cooling batches; packaging HL
    # is unavailable per-batch due to the counter mismatch, so side-table
    # kg_per_hl is left as NULL for now)
    packaging_hl: dict[str, float] = {}

    for batch in batches_in_window:
        # First wort
        fw = fw_map.get(batch)
        if fw:
            fw_grav_list.append(fw.get("gravity"))
            fw_ph_list.append(fw.get("ph"))

        # Kochwurze
        kw = kw_map.get(batch)
        if kw:
            kw_grav_list.append(kw.get("gravity"))

        # Cooling
        cool = cooling_map.get(batch, {})
        og_val = cool.get("og")
        og_list.append(og_val)
        cooling_ph_list.append(cool.get("cooling_ph"))

        # Fermenting (last pre-cc)
        ferm = ferm_map.get(batch, {})
        fg_val = ferm.get("fg")
        fg_list.append(fg_val)
        end_ferm_ph_list.append(ferm.get("end_ferm_ph"))

        # Attenuation
        if og_val is not None and fg_val is not None:
            og_f = float(og_val)
            fg_f = float(fg_val)
            if og_f > 0:
                atten = (og_f - fg_f) / og_f * 100
                atten = max(0.0, min(100.0, atten))
                atten_list.append(atten)

        # Timings (ferm_days + cc_days remain per-batch — same counter as cooling)
        cc_date = cc_date_map.get(batch)
        start_ferm = timings_map.get(batch)
        racking_date = racking_map.get(batch)

        ferm_d = _ferm_days(start_ferm, cc_date)
        ferm_days_list.append(ferm_d)

        cc_d = _cc_days(cc_date, racking_date)
        cc_days_list.append(cc_d)

    # ── 10. Side tables ───────────────────────────────────────────────────────
    malt_rows = fetch_malt_per_recipe_window(
        conn, recipe_name,
        batches_in_window, packaging_hl,
    )
    hop_rows = fetch_hops_per_recipe_window(
        conn, recipe_name,
        batches_in_window, packaging_hl,
    )

    return {
        "recipe_id":    recipe_id,
        "window_kind":  window_name,
        "batch_count":  batch_count,
        "earliest_batch_date": _date_to_py(earliest),
        "latest_batch_date":   _date_to_py(latest),

        # Gravity
        "avg_first_wort_gravity": _dec(_avg(fw_grav_list)),
        "avg_kochwurze_gravity":  _dec(_avg(kw_grav_list)),
        "avg_og":                 _dec(_avg(og_list)),
        "avg_fg":                 _dec(_avg(fg_list)),
        "avg_apparent_atten_pct": _dec(_avg(atten_list)),

        # pH
        "avg_first_wort_ph":  _dec(_avg(fw_ph_list)),
        "avg_kochwurze_ph":   None,   # Kochwurze stage has no pH column → always NULL
        "avg_cooling_ph":     _dec(_avg(cooling_ph_list)),
        "avg_end_ferm_ph":    _dec(_avg(end_ferm_ph_list)),

        # Timing
        "avg_ferm_days":  _dec(_avg(ferm_days_list)),
        "avg_cc_days":    _dec(_avg(cc_days_list)),
        "avg_garde_days": _dec(garde_days),

        # Packaging (recipe-level medians from fetch_packaging_window_level)
        "median_packaging_yield_pct": _dec(pkg_metrics["yield_pct"]),
        "median_loss_pct":            _dec(pkg_metrics["loss_pct"]),
        "median_packaged_o2_ppb":     _dec(pkg_metrics["o2_ppb"], 2),
        "median_packaged_co2_vol":    _dec(pkg_metrics["co2_vol"]),

        "_malt_rows": malt_rows,
        "_hop_rows":  hop_rows,
    }


# ---------------------------------------------------------------------------
# UPSERT helpers
# ---------------------------------------------------------------------------

_PROFILE_SQL = """
INSERT INTO ref_recipe_profile (
    recipe_id, window_kind, batch_count,
    earliest_batch_date, latest_batch_date,
    avg_first_wort_gravity, avg_kochwurze_gravity, avg_og, avg_fg,
    avg_apparent_atten_pct,
    avg_first_wort_ph, avg_kochwurze_ph, avg_cooling_ph, avg_end_ferm_ph,
    avg_ferm_days, avg_cc_days, avg_garde_days,
    median_packaging_yield_pct, median_loss_pct,
    median_packaged_o2_ppb, median_packaged_co2_vol
) VALUES (
    %(recipe_id)s, %(window_kind)s, %(batch_count)s,
    %(earliest_batch_date)s, %(latest_batch_date)s,
    %(avg_first_wort_gravity)s, %(avg_kochwurze_gravity)s, %(avg_og)s, %(avg_fg)s,
    %(avg_apparent_atten_pct)s,
    %(avg_first_wort_ph)s, %(avg_kochwurze_ph)s, %(avg_cooling_ph)s, %(avg_end_ferm_ph)s,
    %(avg_ferm_days)s, %(avg_cc_days)s, %(avg_garde_days)s,
    %(median_packaging_yield_pct)s, %(median_loss_pct)s,
    %(median_packaged_o2_ppb)s, %(median_packaged_co2_vol)s
)
ON DUPLICATE KEY UPDATE
    batch_count               = VALUES(batch_count),
    earliest_batch_date       = VALUES(earliest_batch_date),
    latest_batch_date         = VALUES(latest_batch_date),
    avg_first_wort_gravity    = VALUES(avg_first_wort_gravity),
    avg_kochwurze_gravity     = VALUES(avg_kochwurze_gravity),
    avg_og                    = VALUES(avg_og),
    avg_fg                    = VALUES(avg_fg),
    avg_apparent_atten_pct    = VALUES(avg_apparent_atten_pct),
    avg_first_wort_ph         = VALUES(avg_first_wort_ph),
    avg_kochwurze_ph          = VALUES(avg_kochwurze_ph),
    avg_cooling_ph            = VALUES(avg_cooling_ph),
    avg_end_ferm_ph           = VALUES(avg_end_ferm_ph),
    avg_ferm_days             = VALUES(avg_ferm_days),
    avg_cc_days               = VALUES(avg_cc_days),
    avg_garde_days            = VALUES(avg_garde_days),
    median_packaging_yield_pct = VALUES(median_packaging_yield_pct),
    median_loss_pct           = VALUES(median_loss_pct),
    median_packaged_o2_ppb    = VALUES(median_packaged_o2_ppb),
    median_packaged_co2_vol   = VALUES(median_packaged_co2_vol),
    computed_at               = CURRENT_TIMESTAMP
"""

_MALT_INSERT_SQL = """
INSERT INTO ref_recipe_profile_malt (
    recipe_id, window_kind, mi_id_fk,
    avg_kg_per_brew, avg_kg_per_hl, pct_of_grist,
    appearance_count, total_batches
) VALUES (
    %(recipe_id)s, %(window_kind)s, %(mi_id_fk)s,
    %(avg_kg_per_brew)s, %(avg_kg_per_hl)s, %(pct_of_grist)s,
    %(appearance_count)s, %(total_batches)s
)
ON DUPLICATE KEY UPDATE
    avg_kg_per_brew  = VALUES(avg_kg_per_brew),
    avg_kg_per_hl    = VALUES(avg_kg_per_hl),
    pct_of_grist     = VALUES(pct_of_grist),
    appearance_count = VALUES(appearance_count),
    total_batches    = VALUES(total_batches),
    computed_at      = CURRENT_TIMESTAMP
"""

_HOPS_INSERT_SQL = """
INSERT INTO ref_recipe_profile_hops (
    recipe_id, window_kind, mi_id_fk, stage,
    avg_g_per_brew, avg_g_per_hl,
    appearance_count, total_batches
) VALUES (
    %(recipe_id)s, %(window_kind)s, %(mi_id_fk)s, %(stage)s,
    %(avg_g_per_brew)s, %(avg_g_per_hl)s,
    %(appearance_count)s, %(total_batches)s
)
ON DUPLICATE KEY UPDATE
    avg_g_per_brew   = VALUES(avg_g_per_brew),
    avg_g_per_hl     = VALUES(avg_g_per_hl),
    appearance_count = VALUES(appearance_count),
    total_batches    = VALUES(total_batches),
    computed_at      = CURRENT_TIMESTAMP
"""


def upsert_profile(conn, profile: dict, *, apply: bool) -> None:
    """
    UPSERT one profile row + side tables in a single transaction.
    Side tables: DELETE-then-INSERT to handle removed ingredients.
    """
    recipe_id   = profile["recipe_id"]
    window_kind = profile["window_kind"]
    malt_rows   = profile.pop("_malt_rows", [])
    hop_rows    = profile.pop("_hop_rows", [])

    if not apply:
        return

    with conn.cursor() as cur:
        # 1. UPSERT scalar profile
        cur.execute(_PROFILE_SQL, profile)

        # 2. Delete + insert malt side table (transaction wraps both)
        cur.execute(
            "DELETE FROM ref_recipe_profile_malt WHERE recipe_id = %s AND window_kind = %s",
            (recipe_id, window_kind),
        )
        for row in malt_rows:
            cur.execute(_MALT_INSERT_SQL, {
                "recipe_id":  recipe_id,
                "window_kind": window_kind,
                **row,
            })

        # 3. Delete + insert hops side table
        cur.execute(
            "DELETE FROM ref_recipe_profile_hops WHERE recipe_id = %s AND window_kind = %s",
            (recipe_id, window_kind),
        )
        for row in hop_rows:
            cur.execute(_HOPS_INSERT_SQL, {
                "recipe_id":  recipe_id,
                "window_kind": window_kind,
                **row,
            })

    conn.commit()


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(
        description="Compute and upsert recipe brewing profiles for all active recipes."
    )
    parser.add_argument("--apply",   action="store_true", help="Default is dry-run")
    parser.add_argument("--recipe",  help="Limit to one recipe name (debug)")
    parser.add_argument("--window",  choices=list(WINDOWS), help="Limit to one window")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    t0 = time.perf_counter()

    cfg  = load_config()
    conn = connect(cfg)

    try:
        recipes = fetch_active_recipes(conn, args.recipe)
        if not recipes:
            print("No active recipes found.", file=sys.stderr)
            return

        windows_to_run = {args.window: WINDOWS[args.window]} if args.window else WINDOWS

        total_profile_rows = 0
        total_malt_rows    = 0
        total_hop_rows     = 0
        skipped_no_batches = 0
        windows_computed   = 0

        for recipe in recipes:
            if args.verbose:
                print(f"  recipe: {recipe['name']} (id={recipe['id']})")

            for window_name, (window_sql, flag) in windows_to_run.items():
                profile = compute_profile(
                    conn, recipe, window_name, window_sql,
                    verbose=args.verbose,
                )
                if profile is None:
                    continue  # since_revision skipped

                windows_computed += 1
                malt_rows = profile.get("_malt_rows", [])
                hop_rows  = profile.get("_hop_rows", [])

                if profile["batch_count"] == 0:
                    skipped_no_batches += 1

                total_malt_rows += len(malt_rows)
                total_hop_rows  += len(hop_rows)

                if args.apply:
                    upsert_profile(conn, profile, apply=True)
                    total_profile_rows += 1
                else:
                    # dry-run: count but don't write
                    total_profile_rows += 1
                    if args.verbose:
                        print(
                            f"      [dry-run] would upsert profile "
                            f"batch_count={profile['batch_count']} "
                            f"malt_rows={len(malt_rows)} hop_rows={len(hop_rows)}"
                        )

        elapsed = time.perf_counter() - t0

        if not args.apply:
            print("[dry-run] no writes performed")

        print("refresh_recipe_profile summary:")
        print(f"  active recipes:        {len(recipes)}")
        print(f"  windows computed:      {windows_computed}")
        print(f"  profile rows upserted: {total_profile_rows}")
        print(f"  malt rows upserted:    {total_malt_rows}")
        print(f"  hop  rows upserted:    {total_hop_rows}")
        print(f"  recipes skipped (no batches): {skipped_no_batches}")
        print(f"  elapsed: {elapsed:.1f}s")

    finally:
        conn.close()


if __name__ == "__main__":
    main()
