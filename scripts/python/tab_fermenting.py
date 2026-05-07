"""
tab_fermenting — FermentingData (A2:R, 18 cols).
Single table — all event types share the same column layout.
"""
from __future__ import annotations

from lib_coerce import s, n, d, dt
from lib_hashing import row_hash

TAB = "FermentingData"
RANGE = f"{TAB}!A2:R"
WIDTH = 18
TABLE = "bd_fermenting"


def _pad(row, width: int) -> list:
    return list(row) + [None] * max(0, width - len(row))


def _row(cells, h, idx) -> dict:
    return {
        "row_hash":             h,
        "sheet_row_index":      idx,
        "submitted_at":         dt(cells[0]),
        "email":                s(cells[1]),
        "event_type":           s(cells[2]) or "",
        "beers_to_read":        s(cells[3]),
        "gravity":              n(cells[4]),
        "ph":                   n(cells[5]),
        "temperature":          n(cells[6]),
        "beers_to_dry_hop":     s(cells[7]),
        "hops_raw":             s(cells[8]),
        "dry_hop_comment":      s(cells[9]),
        "beers_to_purge":       s(cells[10]),
        "purge_comment":        s(cells[11]),
        "beers_to_cold_crash":  s(cells[12]),
        "cold_crash_comment":   s(cells[13]),
        "final_comments":       s(cells[14]),
        "event_date":           d(cells[15]),
        "beer_reads":           s(cells[16]),
        "beer_dh":              s(cells[17]),
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
