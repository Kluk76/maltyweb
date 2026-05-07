"""
tab_brewing — BrewingData (A2:AY, 51 cols).
Heterogeneous: dispatch by col C event_type into 5 tables.

Discovered event_types in live data:
  Brewday              → bd_brewing_brewday      (CCT, yeast, etc.)
  First Wort           → bd_brewing_gravity      (stage='FirstWort', ph included)
  Pfannevoll           → bd_brewing_gravity      (stage='Pfannevoll')
  Kochwürze            → bd_brewing_gravity      (stage='Kochwurze')
  Cooling              → bd_brewing_cooling      (final OG/volume)
  Timings              → bd_brewing_timings      (brew start/end)
  Ingredients & Lot Numbers → bd_brewing_ingredients
"""
from __future__ import annotations

from lib_coerce import s, n, d, dt, dt_serial
from lib_hashing import row_hash

TAB = "BrewingData"
RANGE = f"{TAB}!A2:AY"
# Side-channel range for submitted_at (col A) with SERIAL_NUMBER rendering.
RANGE_TIMESTAMP = f"{TAB}!A2:A"
WIDTH = 51

GRAVITY_STAGES = {
    "First Wort":  "FirstWort",
    "Pfannevoll":  "Pfannevoll",
    "Kochwürze":   "Kochwurze",
}


def _pad(row, width: int) -> list:
    return list(row) + [None] * max(0, width - len(row))


def _common(cells, h: str, idx: int) -> dict:
    return {
        "row_hash": h,
        "sheet_row_index": idx,
        "submitted_at": dt(cells[0]),
        "email": s(cells[1]),
        "event_type": s(cells[2]) or "",
    }


def _trail(cells) -> dict:
    return {
        "concatenate": s(cells[48]),
        "event_date":  d(cells[49]),
        "start_ferm":  s(cells[50]),
    }


def _row_brewday(cells, h, idx, *, yeast_map: dict[str, str] | None = None) -> dict:
    raw_col_i = s(cells[8])
    raw_col_k = s(cells[10])

    # Step 1: fold "New Yeast" placeholder — real strain name is in col K.
    col_k_folded = raw_col_i == "New Yeast" and bool(raw_col_k)
    raw = raw_col_k if col_k_folded else raw_col_i

    # Step 2: canonicalize via alias map (pass-through when no match).
    canonical = (yeast_map or {}).get(raw, raw) if raw else raw

    return _common(cells, h, idx) | {
        "bd_beer":          s(cells[3]),
        "bd_batch":         s(cells[4]),
        "bd_cct":           s(cells[5]),
        "bd_cct_cip":       s(cells[6]),
        "bd_cct_cip_date":  s(cells[7]),
        "bd_yeast":         canonical,
        "bd_yeast_gen":     s(cells[9]),
        # Clear bd_yeast_new once we've successfully folded the placeholder,
        # so the col-K value is not double-stored.
        "bd_yeast_new":     None if col_k_folded else raw_col_k,
        "bd_pitched_from":  s(cells[11]),
        "bd_yt":            s(cells[12]),
        "bd_yt_cip_date":   s(cells[13]),
    } | _trail(cells)


def _row_gravity(cells, h, idx, *, stage: str) -> dict:
    """
    stage = 'FirstWort' | 'Pfannevoll' | 'Kochwurze'
    Source columns:
       FirstWort  → cols 14..18 (beer, batch, brew, gravity, ph)
       Pfannevoll → cols 19..22 (beer, batch, brew, gravity)   no ph
       Kochwurze  → cols 23..26 (beer, batch, brew, gravity)   no ph
    """
    if stage == "FirstWort":
        beer, batch, brew, grav = cells[14], cells[15], cells[16], cells[17]
        ph = cells[18]
    elif stage == "Pfannevoll":
        beer, batch, brew, grav = cells[19], cells[20], cells[21], cells[22]
        ph = None
    elif stage == "Kochwurze":
        beer, batch, brew, grav = cells[23], cells[24], cells[25], cells[26]
        ph = None
    else:
        raise ValueError(f"unknown gravity stage: {stage!r}")

    return _common(cells, h, idx) | {
        "stage":   stage,
        "beer":    s(beer),
        "batch":   s(batch),
        "brew":    s(brew),
        "gravity": n(grav),
        "ph":      n(ph),
    } | _trail(cells)


def _row_cooling(cells, h, idx) -> dict:
    return _common(cells, h, idx) | {
        "cool_beer":            s(cells[27]),
        "cool_batch":           s(cells[28]),
        "cool_brew":            s(cells[29]),
        "cool_final_ph":        n(cells[30]),
        "cool_final_gravity":   n(cells[31]),
        "cool_final_volume_hl": n(cells[32]),
        "cool_batch_dilution":  s(cells[33]),
    } | _trail(cells)


def _row_timings(cells, h, idx) -> dict:
    return _common(cells, h, idx) | {
        "beer":       s(cells[34]),
        "batch":      s(cells[35]),
        "brew":       s(cells[36]),
        "brew_start": dt(cells[37]),
        "brew_end":   dt(cells[38]),
    } | _trail(cells)


def _row_ingredients(cells, h, idx) -> dict:
    return _common(cells, h, idx) | {
        "ing_beer":     s(cells[39]),
        "ing_batch":    s(cells[40]),
        "ing_malt_raw": s(cells[41]),
        "ing_hops_raw": s(cells[42]),
        "comments":     s(cells[43]),
    } | _trail(cells)


def process(raw_rows: list[list], *, sheet_offset: int = 2, timestamp_serials: list | None = None, yeast_map: dict[str, str] | None = None) -> dict[str, list[dict]]:
    out: dict[str, list[dict]] = {
        "bd_brewing_brewday":     [],
        "bd_brewing_gravity":     [],
        "bd_brewing_cooling":     [],
        "bd_brewing_timings":     [],
        "bd_brewing_ingredients": [],
        "_unmatched":             [],
    }

    for i_row, row in enumerate(raw_rows):
        cells = _pad(row, WIDTH)
        if all((c is None or str(c).strip() == "") for c in cells):
            continue
        h = row_hash(row, WIDTH)
        sheet_idx = sheet_offset + i_row
        ev = (s(cells[2]) or "").strip()

        if ev == "Brewday":
            rec = _row_brewday(cells, h, sheet_idx, yeast_map=yeast_map)
        elif ev in GRAVITY_STAGES:
            rec = _row_gravity(cells, h, sheet_idx, stage=GRAVITY_STAGES[ev])
        elif ev == "Cooling":
            rec = _row_cooling(cells, h, sheet_idx)
        elif ev == "Timings":
            rec = _row_timings(cells, h, sheet_idx)
        elif ev == "Ingredients & Lot Numbers":
            rec = _row_ingredients(cells, h, sheet_idx)
        else:
            out["_unmatched"].append({"sheet_row_index": sheet_idx, "event_type": ev})
            continue

        # Override submitted_at with the high-precision serial value when available.
        if timestamp_serials is not None and i_row < len(timestamp_serials):
            serial_row = timestamp_serials[i_row]
            serial_val = serial_row[0] if serial_row else None
            parsed_ts = dt_serial(serial_val)
            if parsed_ts is not None:
                rec["submitted_at"] = parsed_ts

        if ev == "Brewday":
            out["bd_brewing_brewday"].append(rec)
        elif ev in GRAVITY_STAGES:
            out["bd_brewing_gravity"].append(rec)
        elif ev == "Cooling":
            out["bd_brewing_cooling"].append(rec)
        elif ev == "Timings":
            out["bd_brewing_timings"].append(rec)
        elif ev == "Ingredients & Lot Numbers":
            out["bd_brewing_ingredients"].append(rec)
    return out
