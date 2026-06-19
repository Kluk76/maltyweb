"""
attachment_xlsx.py — Migros / MP Froideville Sàrl XLSX order parser.

Parses XLSX attachments from Migros Froideville orders (dave.pilloud@gmvd.migros.ch).
The file is "Nébuleuse Froideville.xlsx" with a single sheet "Feuil1" containing
a two-column product layout.

Sheet structure:
  Row 0: "MP Froideville Sàrl"  col 2: "Minimum 10 cartons"  col 4: delivery note
  Row 1: header — col 0="Code" col 1="#VALUE!" col 2="Quantités par 24" col 3="Prix"
                   col 4="Code" col 5=desc col 6="Quantités par 24" col 7="Prix" col 8="Total"
  Rows 2+: data — left block (cols 0-3) and right block (cols 4-7)
  Last row: total — col 1="Total", col 2=left_qty, col 3=left_total,
                    col 6=right_qty, col 7=right_total, col 8=grand_total

"Prix" column semantics: each Prix value is the LINE TOTAL for that row (qty × unit_price
pre-computed by the sender).  The reconciliation gate checks:
  abs(sum(Prix for non-zero-qty left rows) + sum(Prix for non-zero-qty right rows) - grand_total) <= 2.0

Matches on:
  - Any attachment with content_type containing 'spreadsheetml' OR filename ending in '.xlsx'
  - AND (from_address contains 'migros' OR subject contains 'commande' case-insensitive)

Requirements: openpyxl (already installed); no new pip deps; io.BytesIO stdlib.
"""

from __future__ import annotations

import logging
from io import BytesIO
from typing import Any

import openpyxl

from .base import EmailContext, ParsedLine, ParsedOrder, ParserEnv, SenderParser

log = logging.getLogger(__name__)

# ── Constants ──────────────────────────────────────────────────────────────────

_RECON_TOLERANCE = 2.0  # CHF — tolerance for sum(Prix) vs grand total


# ── Helpers ────────────────────────────────────────────────────────────────────

def _is_value_error(v: Any) -> bool:
    """Return True for #VALUE! cells (Excel error strings)."""
    if isinstance(v, str) and v.strip().upper() in ("#VALUE!", "#N/A", "#REF!", "#DIV/0!", "#NAME?"):
        return True
    return False


def _to_float(v: Any) -> float | None:
    """Coerce a cell value to float; return None on failure or #VALUE!."""
    if v is None or _is_value_error(v):
        return None
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def _to_str(v: Any) -> str | None:
    """Return string from cell, or None if blank / #VALUE!."""
    if v is None or _is_value_error(v):
        return None
    s = str(v).strip()
    return s if s else None


# ── Parser class ───────────────────────────────────────────────────────────────

class MigrosFroidevilleXlsxParser(SenderParser):
    """
    Per-sender parser for Migros / MP Froideville XLSX order files.

    matches(): attachment is an XLSX AND sender is Migros / subject says 'commande'.
    parse():   reads the two-column product layout, reconciles totals, emits ParsedLines.
    """

    name = "migros_froideville_xlsx"

    def _get_xlsx_attachment(self, ctx: EmailContext) -> dict[str, Any] | None:
        """Return the first XLSX attachment dict with real bytes, or None."""
        for att in ctx.attachments:
            ct = (att.get("content_type") or "").lower()
            fn = (att.get("filename") or "").lower()
            is_xlsx = "spreadsheetml" in ct or fn.endswith(".xlsx")
            if is_xlsx and att.get("content"):
                return att
        return None

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """
        Match when:
          - There is an XLSX attachment with real bytes
          - AND the sender is Migros OR the subject mentions 'commande'
        """
        att = self._get_xlsx_attachment(ctx)
        if att is None:
            return False

        from_addr = (ctx.from_address or "").lower()
        subject   = (ctx.subject or "").lower()

        sender_ok = "migros" in from_addr or "commande" in subject
        return sender_ok

    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Parse the Froideville XLSX.

        Returns ParsedOrder on success; None (decline) when:
          - No XLSX attachment found
          - Sheet layout not recognised (no 'Code' header row)
          - Reconciliation fails (sum mismatch > 2.0 CHF)
          - No order lines found
        """
        att = self._get_xlsx_attachment(ctx)
        if att is None:
            log.debug("migros_froideville_xlsx: no XLSX attachment with bytes")
            return None

        content = att["content"]
        try:
            wb = openpyxl.load_workbook(BytesIO(content), read_only=True, data_only=True)
        except Exception as exc:
            log.warning("migros_froideville_xlsx: failed to open workbook: %s", exc)
            return None

        ws = wb.worksheets[0]
        rows = list(ws.iter_rows(values_only=True))
        wb.close()

        if not rows:
            return None

        # ── Sheet metadata ──────────────────────────────────────────────────────
        # Row 0: supplier note (col 0), used as notes
        supplier_label = _to_str(rows[0][0]) if rows[0] else None

        # ── Find header row ─────────────────────────────────────────────────────
        # Look for a row where col 0 == "Code" (case-insensitive)
        header_idx: int | None = None
        for idx, row in enumerate(rows):
            if row and _to_str(row[0]) and str(row[0]).strip().lower() == "code":
                header_idx = idx
                break

        if header_idx is None:
            log.debug("migros_froideville_xlsx: no 'Code' header row found")
            return None

        # ── Find total row ──────────────────────────────────────────────────────
        # Look for a row where col 1 == "Total"
        total_idx: int | None = None
        grand_total: float | None = None

        for idx in range(header_idx + 1, len(rows)):
            row = rows[idx]
            if not row:
                continue
            col1_val = _to_str(row[1]) if len(row) > 1 else None
            if col1_val and col1_val.lower() == "total":
                total_idx = idx
                # col 8 = grand total
                if len(row) > 8:
                    grand_total = _to_float(row[8])
                break

        if total_idx is None or grand_total is None:
            log.debug("migros_froideville_xlsx: no Total row / grand total found")
            return None

        # ── Parse data rows ─────────────────────────────────────────────────────
        lines: list[ParsedLine] = []
        sum_left: float = 0.0
        sum_right: float = 0.0

        for idx in range(header_idx + 1, total_idx):
            row = rows[idx]
            if not row:
                continue

            # Left block: cols 0 (code), 1 (#VALUE!), 2 (qty), 3 (prix/line_total)
            code_l = _to_str(row[0]) if len(row) > 0 else None
            qty_l  = _to_float(row[2]) if len(row) > 2 else None
            prix_l = _to_float(row[3]) if len(row) > 3 else None

            # Right block: cols 4 (code), 5 (#VALUE!), 6 (qty), 7 (prix/line_total)
            code_r = _to_str(row[4]) if len(row) > 4 else None
            qty_r  = _to_float(row[6]) if len(row) > 6 else None
            prix_r = _to_float(row[7]) if len(row) > 7 else None

            # Left: emit if code and qty > 0
            if code_l and qty_l and qty_l > 0:
                lines.append(ParsedLine(
                    sku_hint=code_l,
                    qty=qty_l,
                    raw=f"{code_l} qty={qty_l}",
                ))
                if prix_l is not None:
                    sum_left += prix_l

            # Right: emit if code and qty > 0 (prix may be None for free-form rows)
            if code_r and qty_r and qty_r > 0:
                lines.append(ParsedLine(
                    sku_hint=code_r,
                    qty=qty_r,
                    raw=f"{code_r} qty={qty_r}",
                ))
                if prix_r is not None:
                    sum_right += prix_r

        if not lines:
            log.debug("migros_froideville_xlsx: no non-zero order lines found")
            return None

        # ── Reconciliation gate ─────────────────────────────────────────────────
        computed = sum_left + sum_right
        diff = abs(computed - grand_total)
        if diff > _RECON_TOLERANCE:
            log.warning(
                "migros_froideville_xlsx: reconciliation failed — "
                "computed=%.2f grand_total=%.2f diff=%.2f",
                computed, grand_total, diff,
            )
            return None

        log.debug(
            "migros_froideville_xlsx: reconciliation OK — computed=%.2f total=%.2f diff=%.4f",
            computed, grand_total, diff,
        )

        # ── Notes ───────────────────────────────────────────────────────────────
        notes = supplier_label or ""

        return ParsedOrder(
            customer_hint=ctx.from_address,
            requested_date=None,
            lines=lines,
            notes=notes,
        )
