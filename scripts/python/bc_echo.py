"""
bc_echo.py — Shared echo-tag helpers for the maltytask↔BC order write spine.

The echo tag is the invariant that ties a maltytask-native order to its BC
counterpart across the round-trip:

  maltytask ord_orders.id  ←→  BC SalesOrder.Your_Reference = 'mt:<id>'

Rules:
  - ONE canonical format string: 'mt:<id>' (no trailing spaces, no padding)
  - All writers use bc_echo_format()   — never inline f"mt:{local_id}"
  - All readers use bc_echo_parse()    — never inline re.match / split logic
  - External_Document_No is RESERVED (Kouros's split-order key) — never written

Both push_bc_sales_orders.py and ingest_bc_sales_orders.py import this module.
"""

from __future__ import annotations

_PREFIX = "mt:"


def bc_echo_format(local_id: int) -> str:
    """Return the echo tag string for a given local ord_orders.id.

    >>> bc_echo_format(42)
    'mt:42'
    """
    return f"{_PREFIX}{local_id}"


def bc_echo_parse(value: str | None) -> int | None:
    """Parse an echo tag string back to the local ord_orders.id.

    Returns the integer id when *value* is a well-formed 'mt:<n>' tag,
    or None for any other value (including None, empty string, or other prefixes).

    >>> bc_echo_parse('mt:42')
    42
    >>> bc_echo_parse('mt:0') is None
    True
    >>> bc_echo_parse('bc:ORD210070') is None
    True
    >>> bc_echo_parse(None) is None
    True
    >>> bc_echo_parse('') is None
    True
    """
    if not value or not isinstance(value, str):
        return None
    if not value.startswith(_PREFIX):
        return None
    tail = value[len(_PREFIX):]
    if not tail.isdigit():
        return None
    parsed = int(tail)
    # id=0 is never a valid ord_orders PK
    return parsed if parsed > 0 else None
