"""
tab_racking — RackingData (A2:AF, 32 cols).
"""
from __future__ import annotations

from lib_coerce import s, n, d, dt
from lib_hashing import row_hash

TAB = "RackingData"
RANGE = f"{TAB}!A2:AF"
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
        "bbt_o2":            n(cells[14]),
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


def process(raw_rows: list[list], *, sheet_offset: int = 2) -> dict[str, list[dict]]:
    out = {TABLE: []}
    for i_row, row in enumerate(raw_rows):
        cells = _pad(row, WIDTH)
        if all((c is None or str(c).strip() == "") for c in cells):
            continue
        h = row_hash(row, WIDTH)
        out[TABLE].append(_row(cells, h, sheet_offset + i_row))
    return out
