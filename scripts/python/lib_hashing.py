"""
lib_hashing — deterministic row_hash for Sheets row content.

Hash policy:
  row_hash = SHA-256( cell0 || US || cell1 || US || … || celln )
where US = '\\x1f' (ASCII unit separator, never appears in spreadsheet data).

The number of cells is FIXED to `width` (the table's expected col count); any
shorter row is right-padded with empty strings before hashing. This guarantees
that two rows with identical visible content produce the same hash regardless
of trailing-empty-cell behavior in the Sheets API.

The sheet row index is intentionally NOT included in the hash — sheets append
rather than reorder, so re-fetching the same content must yield the same hash.
"""
from __future__ import annotations

import hashlib

US = "\x1f"


def row_hash(cells: list, width: int) -> str:
    """
    cells: a row from sheets (list of any types — numbers, strings, dates).
    width: expected total column count (right-pad with '' if cells is shorter).
    Returns hex SHA-256.
    """
    padded = list(cells) + [""] * max(0, width - len(cells))
    norm = [_norm(c) for c in padded[:width]]
    payload = US.join(norm).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


def _norm(c) -> str:
    if c is None:
        return ""
    return str(c)
