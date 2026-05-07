"""
tab_packaging — PackagingData (A2:CN, 92 cols).
Cols 14..43 (15 paired O2/CO2 readings) → child table bd_packaging_readings.
Col 0 (header empty) is skipped semantically but still counted in width for hashing.
"""
from __future__ import annotations

from lib_coerce import s, n, i, d, dt
from lib_hashing import row_hash

TAB = "PackagingData"
RANGE = f"{TAB}!A2:CN"
WIDTH = 92
TABLE_PARENT = "bd_packaging"
TABLE_READINGS = "bd_packaging_readings"


def _pad(row, width: int) -> list:
    return list(row) + [None] * max(0, width - len(row))


def _parent(cells, h, idx) -> dict:
    return {
        "row_hash":              h,
        "sheet_row_index":       idx,
        "submitted_at":          dt(cells[1]),
        "email":                 s(cells[2]),
        "last_cip_date":         s(cells[3]),
        "cip_type":              s(cells[4]),
        "tank_co2":              n(cells[5]),
        "tank_o2":               n(cells[6]),
        "client":                s(cells[7]),

        "neb_beer":              s(cells[8]),
        "neb_batch":             s(cells[9]),
        "neb_dlc":               s(cells[10]),
        "contract_beer":         s(cells[11]),
        "contract_batch":        s(cells[12]),
        "contract_dlc":          s(cells[13]),

        # cols 14..43 → readings child

        "format":                s(cells[44]),
        "sel_can":               s(cells[45]),
        "sel_pack_can":          s(cells[46]),
        "sel_bottle":            s(cells[47]),
        "sel_pack_bot":          s(cells[48]),

        "prod_total_units":      i(cells[49]),
        "unsaleable_units":      i(cells[50]),

        "loss_liquid_l":         n(cells[51]),
        "loss_4pack":            i(cells[52]),
        "loss_wrap":             i(cells[53]),
        "loss_label":            i(cells[54]),
        "loss_cap":              i(cells[55]),
        "loss_container":        i(cells[56]),

        "special_flag":          s(cells[57]),
        "special_container":     s(cells[58]),
        "special_pack":          s(cells[59]),
        "special_qty_units":     i(cells[60]),
        "special_pack_qty":      i(cells[61]),

        "comments":              s(cells[62]),
        "vendable_hl":           n(cells[63]),

        "total_with_losses_hl":  n(cells[64]),
        "objective_volume_hl":   n(cells[65]),
        "result":                s(cells[66]),
        "avg_o2":                n(cells[67]),
        "avg_co2":               n(cells[68]),
        "min_o2":                n(cells[69]),
        "max_o2":                n(cells[70]),
        "beer":                  s(cells[71]),
        "batch":                 s(cells[72]),
        "pct_loss":              n(cells[73]),
        "delta_o2_pickup":       n(cells[74]),
        "recipe_code":           s(cells[75]),
        "recipe_name":           s(cells[76]),
        "weeknum":               i(cells[77]),
        "year":                  i(cells[78]),
        "total_units":           i(cells[79]),
        "month":                 i(cells[80]),
        "timestamp_2":           dt(cells[81]),
        "second_packaging":      s(cells[82]),
        "second_packaging_qty":  i(cells[83]),
        "hl_second_packaging":   n(cells[84]),
        "hl_first_packaging":    n(cells[85]),
        "weeknum_alt":           i(cells[86]),
    }


def _readings(cells) -> list[dict]:
    """
    Returns up to 15 reading rows (only those where at least one of o2/co2 is non-empty).
    Caller must fill packaging_id once the parent row's id is known.
    """
    readings = []
    for k in range(15):
        o2_cell = cells[14 + 2 * k]
        co2_cell = cells[15 + 2 * k]
        o2v = n(o2_cell)
        co2v = n(co2_cell)
        if o2v is None and co2v is None:
            continue
        readings.append({
            "reading_idx": k + 1,
            "o2": o2v,
            "co2": co2v,
        })
    return readings


def process(raw_rows: list[list], *, sheet_offset: int = 2) -> tuple[list[dict], list[tuple[str, list[dict]]]]:
    """
    Returns:
      parents:  list of dicts ready for INSERT IGNORE into bd_packaging
      readings: list of (parent_row_hash, [reading_dicts]) — the parent_row_hash
                is the lookup key the caller uses to resolve the FK after insert.
    """
    parents: list[dict] = []
    readings: list[tuple[str, list[dict]]] = []

    for i_row, row in enumerate(raw_rows):
        cells = _pad(row, WIDTH)
        if all((c is None or str(c).strip() == "") for c in cells):
            continue
        h = row_hash(row, WIDTH)
        sheet_idx = sheet_offset + i_row
        parents.append(_parent(cells, h, sheet_idx))
        rs = _readings(cells)
        if rs:
            readings.append((h, rs))
    return parents, readings
