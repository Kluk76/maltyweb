"""
amstein.py — Per-sender parser for Amstein SA PDF purchase-order emails.

Sender:  Amstein SA (Swiss beverage distributor) — matched on "amstein" in
         from_address OR original_sender (forwarded) OR identity markers in
         the PDF text body.
Format:  Born-digital PDF purchase order attached to the email; extracted via
         pdftotext -layout.

PDF structure example (pdftotext -layout output):
  AMSTEIN SA
  ...
  Date de livraison         22.06.2026
  ...
  COMMANDE N° 0042228
  ...
  Votre n° article   N° Amstein   Désignation          Pal. Cai./Cart./Fut  Unité  Prix net  Poids

  AMW_24/033 VRAC    NEBAM033VP   Nébuleuse Moonshine *          9            216    42,72    135 Kg
                                          Degré plato : 12,30
                     NEBAM200FU   Nébuleuse Moonshine *         60          1 200    77,26   1500 Kg
  24/033 VRAC        NEBDV033VP   Nébuleuse Diversion *   1     45          1 080    42,72  585,36 Kg
  ...

Parsing strategy:
  1. matches(): accept when "amstein" appears in from_address, original_sender,
     OR when the PDF text carries both "COMMANDE N°" and "AMSTEIN".
  2. Extract the PDF from the first PDF attachment.
  3. Run pdftotext -layout to get a flat text representation.
  4. Extract requested_date from "Date de livraison  DD.MM.YYYY".
  5. Identify order lines: any line containing a NEB[A-Z0-9]+ code.
     Skip sub-note lines that contain "Degré plato".
  6. For each NEB line:
     a. Extract NEB code and designation (text up to and including "*").
     b. Parse the trailing numeric tokens to recover Cart (cases/cartons) count.
     c. Apply space-thousands merge rule for Unité values like "1 200", "1 080".
  7. Coverage gate: if ANY order line cannot be parsed, return None (decline).

Cart-extraction algorithm (critical — see inline comments):
  After "*", the remaining text is:  [Pal?] Cart Unité PrixNet Poids Kg
  - Strip "Kg" suffix.
  - Find rightmost token matching ^\d+,\d{2}$ → that is PrixNet.
  - Tokens left of PrixNet = count_tokens.
  - If last two count_tokens are ("1", NNN) where NNN is exactly 3 digits:
      Unité = 1000 + int(NNN)   (space-separated thousands: "1 200" → 1200)
      Cart  = count_tokens[-3]  (token immediately before the "1 NNN" pair)
  - Otherwise:
      Unité = count_tokens[-1]
      Cart  = count_tokens[-2]
  qty = int(Cart)

100%-or-decline: any line that fails the above → return None.
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
    """
    Convert a PDF to plain text via pdftotext -layout.

    Returns an empty string on any failure; the caller treats that as a decline.
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


def _get_pdf_bytes(ctx: EmailContext) -> Optional[bytes]:
    """
    Return the content bytes of the first PDF attachment, or None.
    """
    for att in (ctx.attachments or []):
        ct = (att.get("content_type") or "").lower()
        fn = (att.get("filename") or "").lower()
        if "pdf" in ct or fn.endswith(".pdf"):
            content = att.get("content")
            if isinstance(content, bytes) and 0 < len(content) <= _MAX_PDF_BYTES:
                return content
    return None


# ── Identity markers ───────────────────────────────────────────────────────────

# Both must be present in the PDF text to confirm this is an Amstein PO.
_MARKER_COMMANDE = re.compile(r"COMMANDE\s+N[°o]", re.IGNORECASE)
_MARKER_AMSTEIN  = re.compile(r"\bAMSTEIN\b",      re.IGNORECASE)


def _has_identity_markers(text: str) -> bool:
    return bool(_MARKER_COMMANDE.search(text) and _MARKER_AMSTEIN.search(text))


# ── Date extraction ────────────────────────────────────────────────────────────

# "Date de livraison         22.06.2026"
_DATE_RE = re.compile(
    r"Date\s+de\s+livraison\s+(\d{2})\.(\d{2})\.(\d{4})",
    re.IGNORECASE,
)


def _extract_requested_date(text: str) -> Optional[date]:
    m = _DATE_RE.search(text)
    if not m:
        return None
    try:
        return date(int(m.group(3)), int(m.group(2)), int(m.group(1)))
    except ValueError:
        log.warning("amstein: invalid date in PDF: %s", m.group(0))
        return None


# ── Order line parsing ─────────────────────────────────────────────────────────

# Detects any line that contains a NEB product code.
_NEB_LINE_RE   = re.compile(r"\bNEB[A-Z0-9]+\b")

# Matches the NEB code itself (for capture).
_NEB_CODE_RE   = re.compile(r"(NEB[A-Z0-9]+)")

# Prix net: rightmost token in the form  \d+,\d{2}  (e.g. "42,72", "1 500,00")
_PRIX_NET_RE   = re.compile(r"^\d+,\d{2}$")

# Three-digit block that signals a space-thousands Unité (e.g. "1 200").
_THREE_DIGIT_RE = re.compile(r"^\d{3}$")

# "Degré plato" sub-note lines — must be skipped.
_DEGRE_PLATO_RE = re.compile(r"degr[eé]\s+plato", re.IGNORECASE)


def _parse_neb_line(line: str) -> Optional[ParsedLine]:
    """
    Parse one order line from the pdftotext output.

    Returns a ParsedLine on success, None if the line cannot be cleanly parsed.

    Algorithm:
    1. Find the NEB code.
    2. Extract designation: text between end of NEB code and the "*" character.
    3. Extract trailing numeric block (after "*") to recover Cart count.
    """
    # Step 1 — NEB code.
    neb_m = _NEB_CODE_RE.search(line)
    if not neb_m:
        return None
    neb_code = neb_m.group(1)
    after_code_start = neb_m.end()

    # Step 2 — Designation: text from after the NEB code up to (but not including) "*".
    star_idx = line.find("*", after_code_start)
    if star_idx == -1:
        log.debug("amstein: no '*' sentinel in line: %.120s", line)
        return None

    designation = line[after_code_start:star_idx].strip()
    sku_hint    = f"{neb_code} {designation}"

    # Step 3 — Trailing block: everything after "*".
    after_star = line[star_idx + 1:]

    # Remove "Kg" suffix (including space-separated variants like "135 Kg", "585,36 Kg").
    after_star = re.sub(r"\s+Kg\s*$", "", after_star.strip())

    tokens = after_star.split()
    if not tokens:
        log.debug("amstein: no numeric tokens after '*' in line: %.120s", line)
        return None

    # Find the prix-net token (\d+,\d{2}).
    #
    # The Poids (weight) column can also be a comma-decimal when fractional
    # (e.g. "585,36 Kg", "133,92 Kg"), which means the LAST \d+,\d{2} token
    # may be poids, not prix net.  The Poids token ALWAYS appears AFTER prix
    # net in the line.  Therefore:
    #   • Collect all comma-decimal token indices.
    #   • If there are ≥ 2 → prix net is the SECOND-TO-LAST (poids is last).
    #   • If there is exactly 1 → that single token IS prix net (poids is
    #     an integer, e.g. "135 Kg" after strip leaves "135").
    comma_decimal_indices = [
        i for i, tok in enumerate(tokens) if _PRIX_NET_RE.match(tok)
    ]
    if len(comma_decimal_indices) >= 2:
        prix_idx: int = comma_decimal_indices[-2]
    elif len(comma_decimal_indices) == 1:
        prix_idx = comma_decimal_indices[0]
    else:
        log.debug("amstein: no prix net token found in line: %.120s", line)
        return None

    count_tokens = tokens[:prix_idx]  # everything before prix net

    # Apply space-thousands merge rule.
    # If the last two count_tokens are ("1", NNN) where NNN is exactly 3 digits
    # → they are a space-separated Unité like "1 200" or "1 080".
    # Cart is then count_tokens[-3].
    if (
        len(count_tokens) >= 3
        and count_tokens[-2] == "1"
        and _THREE_DIGIT_RE.match(count_tokens[-1])
    ):
        # Merged Unité = 1000 + int(NNN); Cart = count_tokens[-3].
        cart_str = count_tokens[-3]
    elif len(count_tokens) >= 2:
        # Normal case: Unité = last token, Cart = second-to-last.
        cart_str = count_tokens[-2]
    else:
        log.debug("amstein: insufficient count_tokens in line: %.120s", line)
        return None

    try:
        cart = int(cart_str)
    except ValueError:
        log.debug("amstein: non-integer cart '%s' in line: %.120s", cart_str, line)
        return None

    raw_snippet = line.strip()[:200]
    return ParsedLine(sku_hint=sku_hint, qty=float(cart), raw=raw_snippet)


def _parse_order_lines(text: str) -> Optional[list[ParsedLine]]:
    """
    Extract all order lines from the PDF text.

    Scans every line of the PDF for a NEB product code.  Skips "Degré plato"
    sub-note lines.  Returns None (100%-or-decline) if any NEB line fails
    extraction.  Returns None if no lines are found at all.
    """
    lines: list[ParsedLine] = []

    for raw_line in text.splitlines():
        # Skip sub-note lines.
        if _DEGRE_PLATO_RE.search(raw_line):
            continue

        if not _NEB_LINE_RE.search(raw_line):
            continue

        parsed = _parse_neb_line(raw_line)
        if parsed is None:
            log.warning(
                "amstein: failed to parse NEB line — declining: %.120s", raw_line
            )
            return None  # 100%-or-decline

        lines.append(parsed)

    return lines if lines else None


# ── Parser class ───────────────────────────────────────────────────────────────

class AmsteinPdfParser(SenderParser):
    """
    Per-sender parser for Amstein SA PDF purchase-order emails.

    matches():
      Fires when "amstein" appears in ctx.from_address, env's original_sender
      attribute, OR when the PDF text body contains both identity markers
      ("COMMANDE N°" and "AMSTEIN").

    parse():
      1. Locate the first PDF attachment and extract text via pdftotext -layout.
      2. Verify identity markers in the extracted text.
      3. Extract requested_date from "Date de livraison DD.MM.YYYY".
      4. Parse all NEB-code lines to recover Cart quantities.
      5. Return ParsedOrder on full success; None to decline on any failure.

    Returns None (decline) when:
      - No PDF attachment is present or readable.
      - pdftotext produces empty output.
      - PDF lacks identity markers ("COMMANDE N°" + "AMSTEIN").
      - No order lines are found.
      - ANY order line cannot be cleanly parsed (100%-or-decline).
    """

    name = "amstein"

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """
        Accept when "amstein" is present in the sender address (direct or
        forwarded), or when the email carries a PDF with both Amstein identity
        markers.
        """
        from_addr     = (ctx.from_address or "").lower()
        original_sndr = getattr(env, "original_sender", None) or ""
        original_sndr = original_sndr.lower()

        if "amstein" in from_addr or "amstein" in original_sndr:
            return True

        # Fallback: inspect the PDF for identity markers (forwarded orders where
        # the forwarding address hides the original sender domain).
        pdf_bytes = _get_pdf_bytes(ctx)
        if pdf_bytes:
            text = _pdf_to_text(pdf_bytes)
            if _has_identity_markers(text):
                return True

        return False

    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Extract order hints from an Amstein SA PDF purchase order.

        Returns ParsedOrder on full parse success.
        Returns None to decline when the PDF cannot be read, is unrecognised,
        or any order line fails extraction.
        """
        # Step 1 — PDF bytes.
        pdf_bytes = _get_pdf_bytes(ctx)
        if pdf_bytes is None:
            log.info("amstein: no PDF attachment found — declining")
            return None

        # Step 2 — Extract text.
        text = _pdf_to_text(pdf_bytes)
        if not text.strip():
            log.warning("amstein: pdftotext returned empty text — declining")
            return None

        # Step 3 — Verify identity markers.
        if not _has_identity_markers(text):
            log.info("amstein: identity markers not found in PDF — declining")
            return None

        # Step 4 — Requested delivery date.
        requested_date = _extract_requested_date(text)

        # Step 5 — Order lines (100%-or-decline inside _parse_order_lines).
        lines = _parse_order_lines(text)
        if lines is None:
            log.warning("amstein: order line extraction failed — declining")
            return None

        # Step 6 — Notes: provenance.
        notes = f"Distributeur: Amstein SA ({ctx.from_address or 'unknown'})"

        return ParsedOrder(
            customer_hint="Amstein SA",
            requested_date=requested_date,
            lines=lines,
            notes=notes,
        )
