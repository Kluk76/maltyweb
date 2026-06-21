"""
nausikraft.py — Per-sender PDF parser for Nausikraft SA purchase-order PDFs.

Sender:  order@nausikraft.ch
Format:  Born-digital PDF purchase orders.  pdftotext -layout produces clean
         columnar text.

PDF structure:
  • Header block: Nausikraft SA / address / "COMMANDE AU FOURNISSEUR N° XXXX"
  • Date line:    "Date:            DD.MM.YYYY"
  • Table header: "Description   Référence   Quantité"
  • Section headers (ignored, used only to track notes context):
      "Pris dans le stock Nausikraft"
      "Si dispo à nous livrer lors de votre prochaine passage"
  • Order lines:  "Nébuleuse <product> <ABV> <format>   [Ref]   <qty> Fût(s)"
                  "Nébuleuse <product> <ABV> <format>   [Ref]   <N> x <M>"
  • Footer:       "Nausikraft SA" (ignored)

Identity markers (both must appear for matches() to fire):
  - "COMMANDE AU FOURNISSEUR" in PDF text
  - "Nausikraft" in PDF text (or sender address / original_sender)

Parsing strategy:
  1. Extract the PDF attachment (first PDF byte blob found).
  2. Convert to text via pdftotext -layout.
  3. Verify identity markers in the text.
  4. Extract requested_date from the "Date:" line (dd.mm.yyyy).
  5. Iterate lines; detect active section header; parse order lines.
  6. Coverage gate: if ANY order line candidate fails regex → return None.

Order line recognition:
  • After lstrip(), line starts with "Nébuleuse "
  • AND ends with qty suffix:
      - r"(\d+)\s+Fût\(s\)$"   → qty = int(group 1)
      - r"(\d+)\s+x\s+\d+$"   → qty = int(group 1)

Section header for notes:
  Lines starting with "Si dispo" trigger a section note.

Coverage gate:
  100%-or-decline: if ANY line that starts with "Nébuleuse " after lstrip()
  cannot be matched by the qty suffix regex → return None.

Trailing " **" stripping:
  Some descriptions carry a trailing " **" annotation — strip it before
  building sku_hint.
"""

from __future__ import annotations

import logging
import os
import re
import subprocess
import tempfile
from datetime import date
from typing import Optional

from .base import (
    EmailContext,
    ParsedLine,
    ParsedOrder,
    ParserEnv,
    SenderParser,
)

log = logging.getLogger(__name__)

_PDFTOTEXT    = "/usr/bin/pdftotext"
_MAX_PDF_BYTES = 25 * 1024 * 1024

# ── PDF extraction ─────────────────────────────────────────────────────────────

def _pdf_to_text(pdf_bytes: bytes) -> str:
    """Convert PDF bytes to plain text using pdftotext -layout."""
    tmp_path = None
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
            log.warning(
                "pdftotext returned %d: %s",
                result.returncode,
                result.stderr[:200],
            )
            return ""
        return result.stdout.decode("utf-8", errors="replace")
    except Exception as exc:
        log.warning("_pdf_to_text failed: %s", exc)
        return ""
    finally:
        if tmp_path and os.path.exists(tmp_path):
            os.unlink(tmp_path)


def _get_pdf_bytes(ctx: EmailContext) -> Optional[bytes]:
    """
    Return the content of the first PDF attachment found in ctx.attachments.
    Returns None when no PDF attachment is present or content is missing/empty.
    """
    for att in ctx.attachments or []:
        ct = (att.get("content_type") or "").lower()
        fn = (att.get("filename") or "").lower()
        if ct == "application/pdf" or fn.endswith(".pdf"):
            content = att.get("content")
            if content and len(content) <= _MAX_PDF_BYTES:
                return content
    return None


# ── Identity check ─────────────────────────────────────────────────────────────

_IDENTITY_COMMANDE_RE = re.compile(r"COMMANDE AU FOURNISSEUR", re.IGNORECASE)
_IDENTITY_NAUSIKRAFT_RE = re.compile(r"Nausikraft", re.IGNORECASE)


def _is_nausikraft_pdf(text: str) -> bool:
    """Return True iff the PDF text contains both identity markers."""
    return bool(_IDENTITY_COMMANDE_RE.search(text)) and bool(
        _IDENTITY_NAUSIKRAFT_RE.search(text)
    )


# ── Date extraction ────────────────────────────────────────────────────────────

# Matches: "Date:            17.06.2026"
_DATE_LINE_RE = re.compile(r"Date\s*:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})")


def _extract_date(text: str) -> Optional[date]:
    """
    Parse the 'Date: DD.MM.YYYY' line from the PDF text.
    Returns None when the pattern is absent or the date is invalid.
    """
    m = _DATE_LINE_RE.search(text)
    if not m:
        return None
    try:
        return date(int(m.group(3)), int(m.group(2)), int(m.group(1)))
    except ValueError:
        log.warning(
            "nausikraft: invalid date values in '%s'", m.group(0)
        )
        return None


# ── Order-line extraction ──────────────────────────────────────────────────────

# Qty suffix: "6 Fût(s)"  — qty = the leading int
_QTY_FUTS_RE = re.compile(r"(\d+)\s+Fût\(s\)\s*$", re.IGNORECASE)

# Qty suffix: "48 x 1"  — qty = the FIRST int
_QTY_X_RE = re.compile(r"(\d+)\s+x\s+\d+\s*$")

# Section header that marks lines as "if available" notes
_SI_DISPO_RE = re.compile(r"^\s*Si dispo", re.IGNORECASE)

# Trailing annotation to strip from descriptions
_TRAILING_STARS_RE = re.compile(r"\s*\*+\s*$")

# Table-header/decoration lines to ignore even if they start with "Nébuleuse"
# (defensive: shouldn't happen in practice)
_HEADER_RE = re.compile(r"^Description\b", re.IGNORECASE)


def _parse_order_lines(
    text: str,
) -> Optional[tuple[list[ParsedLine], str]]:
    """
    Iterate through PDF text lines; extract ParsedLine objects.

    Returns:
      (lines, notes)  on full success (every Nébuleuse line parsed cleanly)
      None            on coverage failure (any Nébuleuse line failed the qty regex)

    Notes string carries any section-header context detected.
    """
    lines: list[ParsedLine] = []
    notes_parts: list[str] = []
    active_section: Optional[str] = None

    for raw_line in text.splitlines():
        stripped = raw_line.lstrip()

        # Detect section-header transitions (not order lines).
        if _SI_DISPO_RE.match(stripped):
            active_section = stripped.strip()
            notes_parts.append("Lines after this header marked '" + active_section + "'")
            continue

        # Only process lines starting with "Nébuleuse "
        if not stripped.startswith("Nébuleuse "):
            continue

        # Attempt qty suffix match — try Fût(s) first, then N x M.
        m_futs = _QTY_FUTS_RE.search(stripped)
        m_x    = _QTY_X_RE.search(stripped)

        if m_futs:
            qty = float(int(m_futs.group(1)))
            # Description = everything before the qty suffix
            desc_raw = stripped[: m_futs.start()].rstrip()
        elif m_x:
            qty = float(int(m_x.group(1)))
            desc_raw = stripped[: m_x.start()].rstrip()
        else:
            # A "Nébuleuse " line that we cannot parse → coverage failure.
            log.warning(
                "nausikraft: unparseable order line (no qty suffix): %r", stripped[:120]
            )
            return None

        # Strip trailing "**" annotation from description.
        sku_hint = _TRAILING_STARS_RE.sub("", desc_raw).rstrip()

        lines.append(
            ParsedLine(
                sku_hint=sku_hint,
                qty=qty,
                raw=stripped[:200],
            )
        )

    if not lines:
        # No order lines found — decline.
        return None

    notes = "\n".join(notes_parts)
    return lines, notes


# ── Parser class ───────────────────────────────────────────────────────────────

class NausikraftPdfParser(SenderParser):
    """
    Per-sender PDF parser for Nausikraft SA purchase-order PDFs.

    matches():
      • Checks ctx.from_address and env.original_sender (if present) for
        "nausikraft" or "order@nausikraft.ch".
      • Also checks the PDF text for identity markers ("COMMANDE AU FOURNISSEUR"
        AND "Nausikraft") as a secondary guard when the sender address alone is
        ambiguous.

    parse():
      1. Locate the PDF attachment and convert to text via pdftotext -layout.
      2. Verify identity markers; return None if they are absent.
      3. Extract requested_date from "Date: DD.MM.YYYY".
      4. Parse order lines — coverage gate: any unparseable Nébuleuse line → None.
      5. Return ParsedOrder with customer_hint="Nausikraft SA" and notes
         carrying any section context extracted from the PDF.

    Returns None (decline) when:
      - No PDF attachment found.
      - pdftotext produces empty output.
      - Identity markers are absent from PDF text.
      - No "Nébuleuse " order lines found.
      - Any "Nébuleuse " line cannot be matched by the qty suffix regex
        (coverage gate).

    Raises on unrecoverable structural errors.
    """

    name = "nausikraft"

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """
        Return True iff the email originates from Nausikraft SA.

        Checks from_address and getattr(env, 'original_sender', None) for
        "nausikraft" or "order@nausikraft.ch".  A secondary pass checks the
        PDF text for identity markers when a PDF is present.
        """
        _target_email = "order@nausikraft.ch"
        _target_domain = "nausikraft"

        def _addr_matches(addr: Optional[str]) -> bool:
            if not addr:
                return False
            a = addr.lower().strip()
            return _target_domain in a or _target_email in a

        if _addr_matches(ctx.from_address):
            return True

        original_sender = getattr(env, "original_sender", None)
        if _addr_matches(original_sender):
            return True

        # Secondary guard: check PDF text for identity markers.
        pdf_bytes = _get_pdf_bytes(ctx)
        if pdf_bytes:
            text = _pdf_to_text(pdf_bytes)
            if text and _is_nausikraft_pdf(text):
                return True

        return False

    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Extract order hints from a Nausikraft SA PDF purchase order.

        Returns ParsedOrder on full parse success.
        Returns None (decline) on partial or unrecognised layout.
        Raises on structural errors.
        """
        # 1. Get PDF bytes.
        pdf_bytes = _get_pdf_bytes(ctx)
        if not pdf_bytes:
            log.info("nausikraft: no PDF attachment found — declining")
            return None

        # 2. Convert to text.
        text = _pdf_to_text(pdf_bytes)
        if not text.strip():
            log.info("nausikraft: pdftotext produced empty output — declining")
            return None

        # 3. Verify identity markers.
        if not _is_nausikraft_pdf(text):
            log.info(
                "nausikraft: identity markers absent from PDF text — declining"
            )
            return None

        # 4. Requested date.
        requested_date = _extract_date(text)

        # 5. Parse order lines (coverage gate inside).
        result = _parse_order_lines(text)
        if result is None:
            return None

        lines, notes = result

        return ParsedOrder(
            customer_hint="Nausikraft SA",
            requested_date=requested_date,
            lines=lines,
            notes=notes,
        )
