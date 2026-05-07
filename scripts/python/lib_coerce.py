"""
lib_coerce — type coercion helpers. Permissive: never raise, return None on failure.

Sheets returns mixed types (numbers as float/int, strings, dates as formatted strings
because we set dateTimeRenderOption=FORMATTED_STRING). We need to land them into
strict MySQL columns (DATETIME, DATE, DECIMAL).
"""
from __future__ import annotations

import re
from datetime import date, datetime, timedelta
from decimal import Decimal, InvalidOperation


# Google Sheets date serial epoch: day 1 = 1899-12-31, but Sheets uses a
# Lotus-compatible off-by-one so the epoch base is 1899-12-30.
SHEETS_EPOCH = datetime(1899, 12, 30)


def dt_serial(v) -> datetime | None:
    """
    Convert a Google Sheets date-serial number to a Python datetime.

    Google Sheets stores datetimes as a float where the integer part is the
    number of days since 1899-12-30 and the fractional part is the time of day.
    Example: 46148.49559496528 → 2026-05-06 11:53:39

    Returns None if v is None, empty, or cannot be parsed as float.
    """
    if v is None or v == "":
        return None
    try:
        fval = float(v)
    except (TypeError, ValueError):
        return None
    return SHEETS_EPOCH + timedelta(days=fval)


def s(v) -> str | None:
    """String, or None if cell is empty."""
    if v is None:
        return None
    out = str(v).strip()
    return out or None


def n(v) -> Decimal | None:
    """Numeric → Decimal. Tolerates Swiss/EU comma decimals."""
    if v is None or v == "":
        return None
    if isinstance(v, (int, float, Decimal)):
        try:
            return Decimal(str(v))
        except InvalidOperation:
            return None
    txt = str(v).strip()
    if not txt:
        return None
    txt = txt.replace("'", "").replace(" ", "")
    # If contains comma but no dot → comma is decimal separator
    if "," in txt and "." not in txt:
        txt = txt.replace(",", ".")
    # If both: assume comma is thousands, dot is decimal
    elif "," in txt and "." in txt:
        txt = txt.replace(",", "")
    try:
        return Decimal(txt)
    except InvalidOperation:
        return None


def i(v) -> int | None:
    d = n(v)
    if d is None:
        return None
    try:
        return int(d)
    except (ValueError, OverflowError):
        return None


# Date parsers — covers DD.MM.YYYY (CH/EU), YYYY-MM-DD (ISO), DD/MM/YYYY,
# MM/DD/YYYY (US), and Sheets' default "DD/MM/YYYY HH:MM:SS" datetimes.
_DATE_PATTERNS = [
    "%Y-%m-%d",
    "%d.%m.%Y",
    "%d/%m/%Y",
    "%d-%m-%Y",
]
_DATETIME_PATTERNS = [
    "%Y-%m-%d %H:%M:%S",
    "%Y-%m-%dT%H:%M:%S",
    "%Y-%m-%dT%H:%M:%S.%f",
    "%d.%m.%Y %H:%M:%S",
    "%d/%m/%Y %H:%M:%S",
    "%m/%d/%Y %H:%M:%S",
    "%d/%m/%Y %H:%M",
]


def d(v) -> date | None:
    """Parse a date cell → datetime.date, or None on failure."""
    if v is None or v == "":
        return None
    if isinstance(v, datetime):
        return v.date()
    if isinstance(v, date):
        return v
    txt = str(v).strip()
    if not txt:
        return None
    # Try datetime patterns first (truncate to date if matched)
    for pat in _DATETIME_PATTERNS:
        try:
            return datetime.strptime(txt, pat).date()
        except ValueError:
            pass
    for pat in _DATE_PATTERNS:
        try:
            return datetime.strptime(txt, pat).date()
        except ValueError:
            pass
    return None


def dt(v) -> datetime | None:
    """Parse a datetime cell → datetime, or None on failure."""
    if v is None or v == "":
        return None
    if isinstance(v, datetime):
        return v
    if isinstance(v, date):
        return datetime.combine(v, datetime.min.time())
    txt = str(v).strip()
    if not txt:
        return None
    for pat in _DATETIME_PATTERNS:
        try:
            return datetime.strptime(txt, pat)
        except ValueError:
            pass
    # Plain date → midnight datetime
    parsed = d(txt)
    if parsed is not None:
        return datetime.combine(parsed, datetime.min.time())
    return None
