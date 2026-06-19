"""
attachment_pdf.py — Bevanar/CDDS supplier order PDF parser.

Parses PDF attachments from Bevanar (ahauray@bevanar.ch) that follow the
FournisseurCommande layout: a structured order table with Produit / Description /
Qté / P.A. / Total columns, across one or two pages.

Matches on:
  - At least one attachment with content_type containing 'pdf'
  - PDF text contains 'Bevanar' AND 'N° fournisseur' AND 'Montant total'

Parsing strategy:
  - Extract text with pdftotext -layout (digital PDF, no OCR needed)
  - Find table header: line matching r'Produit\\s+Description'
  - Find table end:    line matching r'Montant total'
  - Each data entry spans 2-3 physical lines; a "data line" ends with:
        <qty:int>  <P.A.:decimal>  <total:decimal-with-apostrophe>
  - Valid SKU codes match r'^[A-Z]{2,8}[0-9]*$'; non-canonical codes ("DIV",
    "0", blank, purely numeric) → fall back to description from the next line.
  - Reconciliation gate: abs(sum(qty * unit_price) - montant_total) <= 0.10 CHF.
    Failure → return None (decline; let dispatcher fall through).

Requirements: pdftotext at /usr/bin/pdftotext (poppler-utils); no new pip deps.
"""

from __future__ import annotations

import logging
import os
import re
import subprocess
import tempfile
from datetime import date, datetime
from typing import Any

from .base import EmailContext, ParsedLine, ParsedOrder, ParserEnv, SenderParser

log = logging.getLogger(__name__)

# ── Constants ──────────────────────────────────────────────────────────────────

_PDFTOTEXT = "/usr/bin/pdftotext"
_MAX_PDF_BYTES = 25 * 1024 * 1024  # 25 MB

# Pattern matching a "data line": ends with  <int_qty>  <decimal_price>  <total>
# The total may contain Swiss apostrophe thousands separators (3'096.00 or 3’096.00)
_DATA_RE = re.compile(
    r"(\d+)\s+([\d.]+)\s+([\d’\'\`]+\.\d{2})\s*$"
)

# Valid SKU code: 2-8 uppercase letters optionally followed by digits
_SKU_RE = re.compile(r"^[A-Z]{2,8}[0-9]*$")

# Delivery date in Bevanar PDFs: "Date de Livraison DD.MM.YY" or "DD.MM.YYYY"
_DATE_RE = re.compile(r"Date\s+de\s+Livraison\s+(\d{2}\.\d{2}\.\d{2,4})", re.IGNORECASE)

# Order reference number
_ORDER_REF_RE = re.compile(r"\*?N[°o]?\s*[:.]?\s*(\d{4,})\b", re.IGNORECASE)

# Bevanar identity markers — specific enough to avoid false positives
_MARKER_BEVANAR = "Bevanar"
_MARKER_FOURNISSEUR = "N° fournisseur"
_MARKER_TOTAL = "Montant total"

# Table header marker (start of order lines)
_HEADER_RE = re.compile(r"Produit\s+Description", re.IGNORECASE)

# Table end marker
_TOTAL_RE = re.compile(r"Montant\s+total", re.IGNORECASE)


# ── PDF text extraction ────────────────────────────────────────────────────────

def _pdf_to_text(pdf_bytes: bytes) -> str:
    """
    Write pdf_bytes to a temp file, run pdftotext -layout, return the text.
    Cleans up the temp file in all cases.
    Returns '' on any subprocess failure.
    """
    tmp_path: str | None = None
    try:
        fd, tmp_path = tempfile.mkstemp(suffix=".pdf")
        try:
            os.write(fd, pdf_bytes)
        finally:
            os.close(fd)

        result = subprocess.run(
            [_PDFTOTEXT, "-layout", tmp_path, "-"],
            capture_output=True,
            timeout=30,
        )
        if result.returncode != 0:
            log.warning("pdftotext returned %d: %s", result.returncode, result.stderr[:200])
            return ""
        return result.stdout.decode("utf-8", errors="replace")
    except Exception as exc:
        log.warning("_pdf_to_text failed: %s", exc)
        return ""
    finally:
        if tmp_path and os.path.exists(tmp_path):
            os.unlink(tmp_path)


# ── Amount normalisation ───────────────────────────────────────────────────────

def _parse_amount(s: str) -> float:
    """
    Parse a Swiss-formatted amount: strip apostrophe and Unicode right-single-quote
    thousands separators, then parse as float.
    """
    s = s.replace("’", "").replace("`", "").replace("'", "")
    return float(s)


# ── Table extraction ───────────────────────────────────────────────────────────

def _extract_table_lines(text: str) -> list[str]:
    """
    Return only the lines that lie between a Produit/Description header and
    the Montant total marker.  Multi-page PDFs may have multiple header/total
    sections; we concatenate all such windows.

    Note: the final 'Montant total' may appear on a later page than the last
    data row.  We collect all content between any header and the final total.
    """
    lines = text.splitlines()
    table_lines: list[str] = []
    in_table = False

    for line in lines:
        if _HEADER_RE.search(line):
            in_table = True
            continue  # skip the header row itself
        if _TOTAL_RE.search(line):
            in_table = False
            continue  # stop collecting; don't include the total line
        if in_table:
            table_lines.append(line)

    return table_lines


# ── Order line parsing ─────────────────────────────────────────────────────────

def _parse_order_lines(table_lines: list[str]) -> list[tuple[str, float, float, str]]:
    """
    Parse the extracted table lines into (sku_hint, qty, unit_price, raw) tuples.

    Strategy:
    - Scan for "data lines": lines whose trailing tokens match DATA_RE
      (integer qty, decimal price, decimal total).
    - The leftmost non-numeric, non-empty token on the data line is the Produit code.
    - The description line (immediately following the data line) provides a fallback
      when the Produit code is non-canonical.
    - "Prix Brut: ..." lines are skipped.

    Returns list of (sku_hint, qty, unit_price, raw).
    """
    results: list[tuple[str, float, float, str]] = []

    i = 0
    while i < len(table_lines):
        line = table_lines[i]

        # Skip Prix Brut lines and blank lines
        stripped = line.strip()
        if not stripped or stripped.startswith("Prix Brut:"):
            i += 1
            continue

        m = _DATA_RE.search(line)
        if not m:
            i += 1
            continue

        # Matched a data line
        qty_str      = m.group(1)
        price_str    = m.group(2)
        # total_str  = m.group(3)   # not used further — only unit_price matters

        qty = float(qty_str)
        unit_price = float(price_str)

        # Everything before the DATA_RE match is the left part: Produit + article_code
        left_part = line[:m.start()].strip()

        # Split left_part into tokens
        tokens = left_part.split()

        # The first token is the Produit code (if any); the rest is article code
        produit_candidate = tokens[0] if tokens else ""

        # Validate the SKU code
        if produit_candidate and _SKU_RE.match(produit_candidate):
            sku_hint = produit_candidate
        else:
            # Non-canonical: look at the description line (next non-empty line)
            description_text = ""
            j = i + 1
            while j < len(table_lines):
                next_stripped = table_lines[j].strip()
                if next_stripped and not next_stripped.startswith("Prix Brut:"):
                    # Take everything up to the unit keyword (Kfut, Carton, Paquet…)
                    # The unit is the last token; the description is everything before
                    desc_parts = next_stripped.split()
                    # Last token tends to be the unit (single word like 'Kfut', 'Carton')
                    if desc_parts:
                        description_text = " ".join(desc_parts[:-1]) if len(desc_parts) > 1 else desc_parts[0]
                    break
                j += 1
            sku_hint = description_text or produit_candidate or f"article:{tokens[-1] if tokens else '?'}"

        # Build raw: data line + description line
        raw_parts = [line.strip()]
        # Peek at next non-blank, non-"artisanale Suisse", non-"Prix Brut" line for desc
        j = i + 1
        if j < len(table_lines):
            desc_line = table_lines[j].strip()
            if desc_line and not desc_line.startswith("Prix Brut:"):
                raw_parts.append(desc_line)

        raw = " | ".join(raw_parts)[:150]

        results.append((sku_hint, qty, unit_price, raw))
        i += 1

    return results


# ── Parser class ───────────────────────────────────────────────────────────────

class BevanarPdfParser(SenderParser):
    """
    Per-sender parser for Bevanar/CDDS supplier order PDFs.

    Triggered when:
      - from_address contains 'bevanar' (most specific signal)
      - OR: any PDF attachment contains the Bevanar identity markers

    matches() runs pdftotext on the first PDF attachment to confirm layout.
    parse()   extracts order lines, applies reconciliation gate.
    """

    name = "bevanar_pdf"

    def _get_pdf_attachment(self, ctx: EmailContext) -> dict[str, Any] | None:
        """Return the first PDF attachment dict with real bytes, or None."""
        for att in ctx.attachments:
            ct = (att.get("content_type") or "").lower()
            fn = (att.get("filename") or "").lower()
            if "pdf" in ct or fn.endswith(".pdf"):
                if att.get("content"):
                    return att
        return None

    def _get_pdf_text(self, ctx: EmailContext) -> str:
        """Extract text from the first PDF attachment.  Returns '' if none found."""
        att = self._get_pdf_attachment(ctx)
        if att is None:
            return ""
        content = att.get("content")
        if not content:
            return ""
        return _pdf_to_text(content)

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """
        Match when from_address is from bevanar OR the first PDF contains
        the Bevanar identity markers.

        We check from_address first (fast path); fall back to PDF inspection
        when from_address is unknown (forwarded email, etc.).
        """
        from_addr = (ctx.from_address or "").lower()

        # Fast path: known Bevanar sender domain
        if "bevanar" in from_addr:
            # Confirm there's a PDF attachment with real bytes
            att = self._get_pdf_attachment(ctx)
            if att is None:
                return False
            # Quick text probe to confirm it's really this layout
            text = _pdf_to_text(att["content"])
            return (
                _MARKER_BEVANAR in text
                and _MARKER_FOURNISSEUR in text
                and _MARKER_TOTAL in text
            )

        # Slow path: check PDF content regardless of sender
        text = self._get_pdf_text(ctx)
        if not text:
            return False
        return (
            _MARKER_BEVANAR in text
            and _MARKER_FOURNISSEUR in text
            and _MARKER_TOTAL in text
        )

    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Extract order lines from the Bevanar PDF attachment.

        Returns ParsedOrder on success, None (decline) if:
          - No PDF attachment found
          - PDF text cannot be extracted
          - Reconciliation gate fails (sum mismatch > 0.10 CHF)
          - No order lines extracted
        """
        att = self._get_pdf_attachment(ctx)
        if att is None:
            log.debug("bevanar_pdf: no PDF attachment with bytes found")
            return None

        text = _pdf_to_text(att["content"])
        if not text:
            log.debug("bevanar_pdf: pdftotext returned empty text")
            return None

        # ── Extract order number ────────────────────────────────────────────────
        order_ref: str | None = None
        ref_m = _ORDER_REF_RE.search(text)
        if ref_m:
            order_ref = ref_m.group(1)

        # ── Extract delivery date ───────────────────────────────────────────────
        requested_date: date | None = None
        date_m = _DATE_RE.search(text)
        if date_m:
            date_str = date_m.group(1)
            # Parse DD.MM.YY or DD.MM.YYYY
            for fmt in ("%d.%m.%y", "%d.%m.%Y"):
                try:
                    requested_date = datetime.strptime(date_str, fmt).date()
                    break
                except ValueError:
                    continue

        # ── Extract Montant total ───────────────────────────────────────────────
        montant_total: float | None = None
        total_m = re.search(
            r"Montant\s+total\s+([\d’\'\`]+\.\d{2})\s+CHF",
            text,
            re.IGNORECASE,
        )
        if total_m:
            try:
                montant_total = _parse_amount(total_m.group(1))
            except ValueError:
                pass

        if montant_total is None:
            log.debug("bevanar_pdf: could not find Montant total CHF")
            return None

        # ── Extract table lines ─────────────────────────────────────────────────
        table_lines = _extract_table_lines(text)
        raw_entries = _parse_order_lines(table_lines)

        if not raw_entries:
            log.debug("bevanar_pdf: no order lines found in table")
            return None

        # ── Reconciliation gate ─────────────────────────────────────────────────
        computed_total = sum(qty * unit_price for _, qty, unit_price, _ in raw_entries)
        diff = abs(computed_total - montant_total)
        if diff > 0.10:
            log.warning(
                "bevanar_pdf: reconciliation failed — computed=%.2f montant=%.2f diff=%.2f",
                computed_total, montant_total, diff,
            )
            return None

        log.debug(
            "bevanar_pdf: reconciliation OK — computed=%.2f montant=%.2f diff=%.4f",
            computed_total, montant_total, diff,
        )

        # ── Build ParsedLine list ───────────────────────────────────────────────
        lines: list[ParsedLine] = []
        for sku_hint, qty, _unit_price, raw in raw_entries:
            lines.append(ParsedLine(
                sku_hint=sku_hint,
                qty=qty,
                raw=raw[:150],
            ))

        # ── Build notes ─────────────────────────────────────────────────────────
        notes_parts: list[str] = []
        if order_ref:
            notes_parts.append(f"Commande N° {order_ref}")
        notes = " | ".join(notes_parts) if notes_parts else ""

        return ParsedOrder(
            customer_hint=ctx.from_address,
            requested_date=requested_date,
            lines=lines,
            notes=notes,
        )
