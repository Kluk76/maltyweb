"""
tab_racking — RackingData (A2:AF, 32 cols).
"""
from __future__ import annotations

from lib_coerce import s, n, d, dt, dt_serial
from lib_hashing import row_hash

# ---------------------------------------------------------------------------
# QA/QC thresholds — adjustable constants, not magic numbers.
# Outliers are IMPORTED and flagged; KPI calculators downstream filter on flags.
# ---------------------------------------------------------------------------

# O2 in ppb (dissolved oxygen pickup into BBT post-rack).
# Healthy fermentation target: ≤ 50 ppb. Above 500 ppb = process incident.
O2_NORMAL_MAX   = 50.0
O2_ELEVATED_MAX = 500.0

# CO2 in g/L (carbonation level in BBT post-rack).
# Normal lager/ale range: 2.5–5.0 g/L. Outside 1.0–7.0 = outlier / unit error.
CO2_NORMAL_MIN   = 2.5
CO2_NORMAL_MAX   = 5.0
CO2_OUTLIER_MIN  = 1.0
CO2_OUTLIER_MAX  = 7.0


def _flag_o2(v) -> str:
    """
    Classify an O2 pickup value (ppb) into a QA/QC flag.
    Accepts Decimal, float, int, or None. Returns ENUM string.
    """
    if v is None:
        return "missing"
    try:
        fv = float(v)
    except (TypeError, ValueError):
        return "missing"
    if fv <= O2_NORMAL_MAX:
        return "normal"
    if fv <= O2_ELEVATED_MAX:
        return "elevated"
    return "outlier"


def _flag_co2(v) -> str:
    """
    Classify a CO2 level (g/L) into a QA/QC flag.
    Accepts Decimal, float, int, or None. Returns ENUM string.
    """
    if v is None:
        return "missing"
    try:
        fv = float(v)
    except (TypeError, ValueError):
        return "missing"
    if CO2_NORMAL_MIN <= fv <= CO2_NORMAL_MAX:
        return "normal"
    if CO2_OUTLIER_MIN <= fv <= CO2_OUTLIER_MAX:
        return "elevated"
    return "outlier"


TAB = "RackingData"
RANGE = f"{TAB}!A2:AF"
# Side-channel range for submitted_at (col A) with SERIAL_NUMBER rendering.
RANGE_TIMESTAMP = f"{TAB}!A2:A"
WIDTH = 32
TABLE = "bd_racking"


def _pad(row, width: int) -> list:
    return list(row) + [None] * max(0, width - len(row))


def _row(cells, h, idx) -> dict:
    return {
        "row_hash":          h,
        "sheet_row_index":   idx,
        "submitted_at":      dt(cells[0]),
        "email":             s(cells[1]),
        "last_cip_date":     s(cells[2]),
        "cip_type":          s(cells[3]),
        "rack_type":         s(cells[4]),
        "client":            s(cells[5]),
        "neb_beer":          s(cells[6]),
        "neb_batch":         s(cells[7]),
        "contract_beer":     s(cells[8]),
        "contract_batch":    s(cells[9]),
        "start_time":        dt(cells[10]),
        "end_time":          dt(cells[11]),
        "bbt_old":           s(cells[12]),
        "bbt_co2":           n(cells[13]),
        "bbt_co2_flag":      _flag_co2(n(cells[13])),
        "bbt_o2":            n(cells[14]),
        "bbt_o2_flag":       _flag_o2(n(cells[14])),
        "racked_vol_hl":     n(cells[15]),
        "blend_text":        s(cells[16]),
        "avg_turbidity":     n(cells[17]),
        "avg_speed":         n(cells[18]),
        "bbt_pressure":      n(cells[19]),
        "centri_rinsed":     s(cells[20]),
        "comments":          s(cells[21]),
        "blend_volume_hl":   n(cells[22]),
        "nomenclature":      s(cells[23]),
        "concat_nom_batch":  s(cells[24]),
        "bbt":               s(cells[25]),
        "cc_date":           d(cells[26]),
        "lagering_time":     s(cells[27]),
        "racking_time":      s(cells[28]),
        "avg_seep":          n(cells[29]),
        "avg_turbidity_calc": n(cells[30]),
        "helper":            s(cells[31]),
    }


def process(raw_rows: list[list], *, sheet_offset: int = 2, timestamp_serials: list | None = None) -> dict[str, list[dict]]:
    out = {TABLE: []}
    for i_row, row in enumerate(raw_rows):
        cells = _pad(row, WIDTH)
        if all((c is None or str(c).strip() == "") for c in cells):
            continue
        h = row_hash(row, WIDTH)
        rec = _row(cells, h, sheet_offset + i_row)

        # Override submitted_at with the high-precision serial value when available.
        if timestamp_serials is not None and i_row < len(timestamp_serials):
            serial_row = timestamp_serials[i_row]
            serial_val = serial_row[0] if serial_row else None
            parsed_ts = dt_serial(serial_val)
            if parsed_ts is not None:
                rec["submitted_at"] = parsed_ts

        out[TABLE].append(rec)
    return out
