"""
petitecave.py — Per-sender PDF parser for Petite Cave purchase order PDFs.

Sender:  Petite Cave (Swiss wine/beer retailer)
Format:  Born-digital PDF purchase orders.  pdftotext -layout produces clean
         columnar text with heavy space-padding that preserves column positions.

Identity marker:
  The string "Commande (ACHAT)" appears in Petite Cave PDFs and is unique to
  this sender.  PDF-content check is the primary signal; from_address is a
  secondary guard.

PDF structure (relevant excerpt):
  Commande (ACHAT) N° 12558
  ...
  N° Article      Emballage   Quantité Désignation                ...
                   2 CARx24        48 Moonshine Blanche - La Nébuleuse 33cl   33cl  O-9-1/1
                   6 CARx24       144 Nébuleuse Diversion Blanche Sans Alcool  33cl  O-9-2/1
                                      33cl VP
                   ...
                                                                    Somme           508.32

Parsing strategy:
  1. matches(): fire on from_address containing "petitecave"/"petite-cave"/
     "petite_cave" OR on PDF text containing "Commande (ACHAT)".
  2. Extract requested_date from "Aller chercher" pickup line (dd.mm.yy),
     falling back to "Date de commande: dd.mm.yyyy".
  3. Collect table rows after the "N° Article … Quantité" header line.
  4. Each data line matches the columnar pattern:
       <n> CARx<m>  <qty>  <designation text>  <contenu>  <emplacement>
  5. Continuation lines (e.g. "33cl VP") are appended to the previous
     designation to form the full sku_hint.
  6. 100%-or-decline: if ANY order line fails the data-line pattern, return
     None (decline).  No partial orders.

Coverage gate:
  Count candidate order lines (lines between the table header and the "Somme"
  footer that look like data lines or their continuations).  All data-line
  candidates must be parseable or we decline.

No per-line prices are present → no price validation performed.
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


# ── PDF extraction ────────────────────────────────────────────────────────────

def _pdf_to_text(pdf_bytes: bytes) -> str:
    """
    Run pdftotext -layout on raw PDF bytes and return the decoded text.

    Returns an empty string on any failure (bad binary, timeout, etc.).
    """
    tmp_path: Optional[str] = None
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


def _extract_pdf_text(ctx: EmailContext) -> str:
    """
    Return the pdftotext output for the first PDF attachment found in ctx.

    Returns "" when no PDF attachment exists or extraction fails.
    """
    for att in ctx.attachments or []:
        fn = (att.get("filename") or "").lower()
        ct = (att.get("content_type") or "").lower()
        if fn.endswith(".pdf") or "pdf" in ct:
            content = att.get("content")
            if not content:
                log.debug("petitecave: PDF attachment '%s' has no content", att.get("filename"))
                continue
            if len(content) > _MAX_PDF_BYTES:
                log.warning("petitecave: PDF attachment too large (%d bytes), skipping", len(content))
                continue
            return _pdf_to_text(content)
    return ""


# ── Identity check ────────────────────────────────────────────────────────────

# Unique marker present in every Petite Cave PDF
_PDF_MARKER = "Commande (ACHAT)"

# Sender address fragments (case-insensitive)
_ADDR_FRAGMENTS = ("petitecave", "petite-cave", "petite_cave")


def _from_address_matches(ctx: EmailContext) -> bool:
    """Return True when the from_address looks like a Petite Cave address."""
    addr = (ctx.from_address or "").lower()
    # Also check the original_sender attribute that some email wrappers attach
    original = getattr(ctx, "original_sender", None) or ""
    combined = addr + " " + original.lower()
    return any(frag in combined for frag in _ADDR_FRAGMENTS)


def _pdf_text_matches(pdf_text: str) -> bool:
    """Return True when the PDF contains the Petite Cave identity marker."""
    return _PDF_MARKER in pdf_text


# ── Date extraction ───────────────────────────────────────────────────────────

# "Aller chercher lundi 22.06.26" — two-digit year
_DATE_PICKUP_RE  = re.compile(r'\b(\d{2})\.(\d{2})\.(\d{2})\b')
# "Date de commande:           18.06.2026" — four-digit year
_DATE_ORDER_RE   = re.compile(r'\b(\d{2})\.(\d{2})\.(\d{4})\b')


def _extract_requested_date(pdf_text: str) -> Optional[date]:
    """
    Extract the pick-up / delivery date from the PDF text.

    Strategy:
      1. Scan for "Aller chercher" line, take first dd.mm.yy match → year = 2000+yy.
      2. Fallback: scan for "Date de commande" line, take dd.mm.yyyy match.

    Returns None when no parseable date is found.
    """
    for line in pdf_text.splitlines():
        if "Aller chercher" in line:
            m = _DATE_PICKUP_RE.search(line)
            if m:
                day   = int(m.group(1))
                month = int(m.group(2))
                year  = 2000 + int(m.group(3))
                try:
                    return date(year, month, day)
                except ValueError:
                    log.warning("petitecave: invalid pickup date %s.%s.%s", m.group(1), m.group(2), m.group(3))

    # Fallback: Date de commande
    for line in pdf_text.splitlines():
        if "Date de commande" in line:
            m = _DATE_ORDER_RE.search(line)
            if m:
                day   = int(m.group(1))
                month = int(m.group(2))
                year  = int(m.group(3))
                try:
                    return date(year, month, day)
                except ValueError:
                    log.warning("petitecave: invalid order date in line: %s", line.strip())

    return None


# ── Table parsing ─────────────────────────────────────────────────────────────

# Table header line detector
_HEADER_RE = re.compile(r"N°\s+Article\s+Emballage\s+Quantit")

# Data line: "  <n> CARx<m>  <qty>  <designation…>  <contenu 33cl>  <emplacement>"
# The -layout output pads columns with spaces, so we anchor on:
#   \b<int> CARx<int>  <int>  <text ending before the Contenu column>
#
# Breakdown:
#   \b(\d+)\s+CAR[xX]\d+  — emballage block (count CARxN)
#   \s+(\d+)               — Quantité
#   \s+(.+?)               — Désignation (non-greedy)
#   \s{2,}\d+cl            — at least 2 spaces then Contenu (e.g. "33cl")
#   \s+\S                  — Emplacement (non-blank after Contenu)
_DATA_LINE_RE = re.compile(
    r'\b(\d+)\s+CAR[xX]\d+\s+(\d+)\s+(.+?)\s{2,}\d+cl\s+\S'
)

# Footer/total line prefixes that terminate or skip lines
_FOOTER_RE = re.compile(r'^\s*(Somme|Taux|Total|Page)\b', re.IGNORECASE)

# Recognise the start of a new data line (so we know a line is NOT a continuation)
_DATA_LINE_START_RE = re.compile(r'\b\d+\s+CAR[xX]\d+', re.IGNORECASE)


def _parse_table(pdf_text: str) -> Optional[list[ParsedLine]]:
    """
    Parse the order table from the PDF text.

    Returns list[ParsedLine] on full success (all order lines parsed cleanly).
    Returns None to signal decline when:
      - No table header is found.
      - No data lines are found after the header.
      - ANY data line cannot be matched by _DATA_LINE_RE (100%-or-decline).

    Continuation lines (e.g. "33cl VP") are appended to the preceding designation.
    Collection stops at the "Somme" / total footer line.
    """
    lines = pdf_text.splitlines()

    # 1. Find table header
    header_idx: Optional[int] = None
    for i, line in enumerate(lines):
        if _HEADER_RE.search(line):
            header_idx = i
            break

    if header_idx is None:
        log.debug("petitecave: no table header found")
        return None

    # 2. Collect raw segments between header and footer
    #    Each "segment" is (raw_line, continuation_lines[])
    #    We build up ParsedLine objects as we go.
    parsed_lines: list[ParsedLine] = []
    current_match: Optional[re.Match] = None          # latest _DATA_LINE_RE match
    current_raw: str = ""                              # the matched line text
    current_designation: str = ""                     # accumulated designation

    def _flush_current() -> bool:
        """Commit the current data line to parsed_lines; returns False on failure."""
        nonlocal current_match, current_raw, current_designation
        if current_match is None:
            return True  # nothing to flush
        qty = int(current_match.group(2))
        sku_hint = current_designation.strip()
        if not sku_hint:
            log.debug("petitecave: empty sku_hint for line: %s", current_raw)
            return False
        parsed_lines.append(ParsedLine(
            sku_hint=sku_hint,
            qty=float(qty),
            raw=current_raw.strip()[:200],
        ))
        current_match = None
        current_raw = ""
        current_designation = ""
        return True

    for line in lines[header_idx + 1 :]:
        # Stop at footer
        if _FOOTER_RE.match(line):
            break

        stripped = line.strip()

        # Skip blank lines
        if not stripped:
            continue

        # Skip footer-style lines anywhere in the body
        if _FOOTER_RE.match(stripped):
            break

        # Check if this is a new data line
        dm = _DATA_LINE_RE.search(line)
        if dm:
            # Flush the previous data line first
            if not _flush_current():
                return None
            current_match = dm
            current_raw = line
            current_designation = dm.group(3).strip()
        elif _DATA_LINE_START_RE.search(stripped):
            # Line starts like a data line but didn't fully match
            log.debug("petitecave: partial data-line match (decline): %r", line)
            return None
        else:
            # Continuation line: append to current designation
            if current_match is not None:
                current_designation += " " + stripped
            # Lines before the first data line (e.g. address block) — ignore

    # Flush the last data line
    if not _flush_current():
        return None

    if not parsed_lines:
        log.debug("petitecave: no order lines found in table")
        return None

    return parsed_lines


# ── Notes extraction ──────────────────────────────────────────────────────────

# Patterns for contextual notes visible to the logistics team
_PICKUP_ADDR_RE = re.compile(r'Adresse\s+du\s+d[eé]p[oô]t[:\s]+(.+)', re.IGNORECASE)
_EXPEDITION_RE  = re.compile(r'Code\s+d.exp[eé]dition[:\s]+(.+)', re.IGNORECASE)


def _extract_notes(pdf_text: str, order_number: str) -> str:
    """
    Build a notes string for the ParsedOrder.

    Includes:
      • Order number provenance.
      • Pickup address (Adresse du dépôt) when present.
      • Expedition / shipping code when present.
      • Any "ferme à" timing note (closing time).
    """
    parts: list[str] = [f"Commande Petite Cave N° {order_number}"]

    for line in pdf_text.splitlines():
        stripped = line.strip()
        if _EXPEDITION_RE.search(stripped):
            m = _EXPEDITION_RE.search(stripped)
            if m:
                parts.append("Expédition: " + m.group(1).strip())
        if "Adresse du d" in stripped:
            parts.append("Dépôt: " + stripped)
        if "ferme à" in stripped.lower():
            parts.append(stripped)
        if "Aller chercher" in stripped:
            parts.append(stripped)

    return "\n".join(parts)


# ── Order number extraction ───────────────────────────────────────────────────

_ORDER_NUM_RE = re.compile(r'Commande\s+\(ACHAT\)\s+N[°o]\s*(\S+)', re.IGNORECASE)


def _extract_order_number(pdf_text: str) -> str:
    m = _ORDER_NUM_RE.search(pdf_text)
    return m.group(1).strip() if m else "?"


# ── Parser class ───────────────────────────────────────────────────────────────

class PetiteCavePdfParser(SenderParser):
    """
    PDF purchase-order parser for Petite Cave.

    matches():
      Fires when:
        • ctx.from_address (or ctx.original_sender) contains "petitecave",
          "petite-cave", or "petite_cave"; OR
        • the first PDF attachment's pdftotext output contains "Commande (ACHAT)".

      PDF-content check is the primary signal (the PDF is always attached);
      from_address is a secondary guard for cases where the PDF is present but
      we haven't extracted it yet at matches() time — in practice matches() is
      called before parse(), and both signals are evaluated.

    parse():
      1. Extract text from the first PDF attachment via pdftotext -layout.
      2. Verify "Commande (ACHAT)" marker is present (primary identity check).
      3. Extract requested_date from pickup / order-date lines.
      4. Parse the columnar order table (100%-or-decline coverage gate).
      5. Return ParsedOrder with hardcoded customer_hint="Petite Cave".

    Returns None (decline) when:
      - No PDF attachment, or pdftotext fails.
      - PDF text does not contain "Commande (ACHAT)".
      - Table header is absent.
      - No order lines parsed.
      - Any order line cannot be matched (coverage gate).

    Raises on structural corruption (signals parse_status='error').
    """

    name = "petitecave"

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """
        Return True when the email is likely a Petite Cave purchase order.

        Checks from_address / original_sender fragments first (cheap).
        Falls back to PDF text extraction when address doesn't match.
        """
        if _from_address_matches(ctx):
            return True

        # Try PDF content as primary signal
        pdf_text = _extract_pdf_text(ctx)
        if pdf_text and _pdf_text_matches(pdf_text):
            return True

        return False

    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Extract order hints from a Petite Cave PDF purchase order.

        Returns ParsedOrder on full parse success.
        Returns None (decline) on partial, unrecognised, or missing PDF layout.
        Raises on structural errors.
        """
        # 1. Extract PDF text
        pdf_text = _extract_pdf_text(ctx)
        if not pdf_text:
            log.debug("petitecave: no PDF text extracted — declining")
            return None

        # 2. Verify primary identity marker
        if not _pdf_text_matches(pdf_text):
            log.debug("petitecave: 'Commande (ACHAT)' not found in PDF — declining")
            return None

        # 3. Requested date
        requested_date = _extract_requested_date(pdf_text)

        # 4. Parse order table (100%-or-decline inside _parse_table)
        lines = _parse_table(pdf_text)
        if lines is None:
            log.debug("petitecave: table parse failed — declining")
            return None

        # 5. Notes and order provenance
        order_number = _extract_order_number(pdf_text)
        notes = _extract_notes(pdf_text, order_number)

        return ParsedOrder(
            customer_hint="Petite Cave",
            requested_date=requested_date,
            lines=lines,
            notes=notes,
        )
