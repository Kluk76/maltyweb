"""
bc_echo.py — Shared echo-tag helpers for the maltytask↔BC write spines.

Two namespaces coexist — callers MUST use the typed helpers so parsers can
tell them apart cleanly:

  ORDER echo    (D1 — push_bc_sales_orders.py):
    maltytask ord_orders.id  ←→  BC SalesOrder.Your_Reference = 'mt:<id>'

  CREDIT-MEMO echo  (D2 — push_bc_credit_memos.py):
    maltytask ord_orders.id  ←→  BC SalesCreditMemo.externalDocumentNumber = 'mt:cm:<id>'

Rules:
  - ONE canonical format per namespace (no trailing spaces, no padding)
  - All writers use the typed format helpers — never inline f-strings
  - All readers use the typed parse helpers — never inline re.match / split logic
  - Order echo      → Your_Reference          (OData v4 field on SalesOrder)
  - Credit-memo echo → externalDocumentNumber  (API v2.0 field on salesCreditMemo)
  - The two echo namespaces ('mt:' vs 'mt:cm:') are disjoint: bc_echo_parse()
    returns None for 'mt:cm:…' strings so readers cannot cross-contaminate.

All three scripts (push_bc_sales_orders, ingest_bc_sales_orders,
push_bc_credit_memos) import from this module.
"""

from __future__ import annotations

# ── Order namespace ────────────────────────────────────────────────────────────

_PREFIX = "mt:"

_CM_PREFIX = "mt:cm:"


def bc_echo_format(local_id: int) -> str:
    """Return the ORDER echo tag string for a given local ord_orders.id.

    Written to BC SalesOrder.Your_Reference by push_bc_sales_orders.py.

    >>> bc_echo_format(42)
    'mt:42'
    """
    return f"{_PREFIX}{local_id}"


def bc_echo_parse(value: str | None) -> int | None:
    """Parse an ORDER echo tag string back to the local ord_orders.id.

    Returns the integer id when *value* is a well-formed 'mt:<n>' tag,
    or None for any other value (including None, empty string, credit-memo tags,
    or other prefixes).

    IMPORTANT: returns None for 'mt:cm:…' strings — the CM namespace is handled
    by bc_cm_echo_parse().

    >>> bc_echo_parse('mt:42')
    42
    >>> bc_echo_parse('mt:0') is None
    True
    >>> bc_echo_parse('mt:cm:42') is None
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
    # Reject credit-memo tags — they share the 'mt:' prefix so test CM first
    if value.startswith(_CM_PREFIX):
        return None
    if not value.startswith(_PREFIX):
        return None
    tail = value[len(_PREFIX):]
    if not tail.isdigit():
        return None
    parsed = int(tail)
    # id=0 is never a valid ord_orders PK
    return parsed if parsed > 0 else None


# ── Credit-memo namespace ──────────────────────────────────────────────────────


def bc_cm_echo_format(order_id: int) -> str:
    """Return the CREDIT-MEMO echo tag string for a given local ord_orders.id.

    Written to BC salesCreditMemo.externalDocumentNumber by
    push_bc_credit_memos.py.  Namespace 'mt:cm:' is disjoint from the order
    namespace 'mt:' so parsers can distinguish them unambiguously.

    >>> bc_cm_echo_format(42)
    'mt:cm:42'
    """
    return f"{_CM_PREFIX}{order_id}"


def bc_cm_echo_parse(value: str | None) -> int | None:
    """Parse a CREDIT-MEMO echo tag string back to the local ord_orders.id.

    Returns the integer id when *value* is a well-formed 'mt:cm:<n>' tag,
    or None for any other value (including order-echo tags like 'mt:42').

    >>> bc_cm_echo_parse('mt:cm:42')
    42
    >>> bc_cm_echo_parse('mt:cm:0') is None
    True
    >>> bc_cm_echo_parse('mt:42') is None
    True
    >>> bc_cm_echo_parse(None) is None
    True
    >>> bc_cm_echo_parse('') is None
    True
    """
    if not value or not isinstance(value, str):
        return None
    if not value.startswith(_CM_PREFIX):
        return None
    tail = value[len(_CM_PREFIX):]
    if not tail.isdigit():
        return None
    parsed = int(tail)
    return parsed if parsed > 0 else None
