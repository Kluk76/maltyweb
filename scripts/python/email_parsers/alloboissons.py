"""
alloboissons.py — Per-sender PDF parser for Alloboissons SA purchase-order emails.

Sender:  Angelique.Ducarroz@alloboissons.ch  (matched on 'alloboissons' in from_address
         OR in the original_sender attribute on the ParserEnv/ctx, OR in PDF body text)

PDF format: "Commande fournisseur #P<number>" layout, born-digital pdftotext output.
Layout example (pdftotext -layout):
  Commande fournisseur #P18494
  Responsable des achats :    Date de la commande :    Date de livraison:
  Angélique Ducarroz          17/06/2026               22/06/2026

  Produit   cl   Conditionnement   Qté   Prix HT   Total HT

  [7640182183943] la nebuleuse diversion s alcool vp VRAC 33 cl   33   54 Car   1296 Bou   1.597500   CHF 2 070,36
  ...
  container                                                         1   60 Unités   60 Uni  50.000000  CHF 3 000,00

                                            Montant HT             CHF 12 697,04

Parsing strategy:
  1. matches(): check from_address OR ctx original_sender for 'alloboissons';
     also accept when PDF text contains identity markers.
  2. Extract PDF text via pdftotext -layout (born-digital — clean text layer).
  3. Locate table header: line matching r'Produit\\s+cl\\s+Conditionnement'.
  4. Collect data lines AFTER the header until a non-data, non-skip line closes the table.
  5. Data line detection: starts with '[' ... ends with 'CHF <amount>',
     OR starts with 'container' (case-insensitive) ... ends with 'CHF <amount>'.
  6. Per line: extract qty (Bou/L/Uni), Prix HT (4+ decimal float before CHF), Total HT.
  7. sku_hint: split stripped line on 3+ spaces, take first token.
  8. Reconcile: sum(line totals) ≈ Montant HT (±0.10 CHF). Failure → return None.
  9. requested_date: parse "Date de livraison" column (last date on the header+next-line pair).

Coverage gate: if ANY data line cannot be fully parsed → return None (100%-or-decline).

Requirements: pdftotext at /usr/bin/pdftotext (poppler-utils); no new pip deps.
"""

from __future__ import annotations

import logging
import os
import re
import subprocess
import tempfile
from datetime import date, datetime
from typing import Optional

from .base import EmailContext, ParsedLine, ParsedOrder, ParserEnv, SenderParser

log = logging.getLogger(__name__)

# ── Constants ──────────────────────────────────────────────────────────────────

_PDFTOTEXT    = "/usr/bin/pdftotext"
_MAX_PDF_BYTES = 25 * 1024 * 1024  # 25 MB

# Identity markers that must appear in the PDF text
_MARKER_ORDER = "Commande fournisseur #P"
_MARKER_BRAND = "Alloboissons"

# Customer name (hardcoded — parser only fires for this supplier)
_CUSTOMER_HINT = "Alloboissons SA"

# Table header detector
_HEADER_RE = re.compile(r"Produit\s+cl\s+Conditionnement", re.IGNORECASE)

# Data line: must start with '[' OR 'container' (case-insensitive),
# AND end with CHF followed by an amount in Swiss/EU comma-decimal format.
_DATA_LINE_RE = re.compile(
    r"^\s*(?:\[.*|container\b.*)\s+CHF\s+[\d ,]+,\d{2}\s*$",
    re.IGNORECASE,
)

# Qty extraction: integer (possibly space-separated thousands) + unit keyword
# Units: Bou (bottles), L (litres), Uni (units)
_QTY_RE = re.compile(r"(\d[\d ]*)\s+(?:Bou|L\b|Uni\b)", re.IGNORECASE)

# Prix HT: float with 4+ decimal places immediately before "CHF"
# e.g. "1.597500  CHF" or "50.000000  CHF"
_PRIX_HT_RE = re.compile(r"(\d+\.\d{4,})\s+CHF")

# Total HT at end of line: "CHF 2 070,36" or "CHF 12 697,04"
_TOTAL_HT_RE = re.compile(r"CHF\s+([\d ,]+,\d{2})\s*$")

# Montant HT grand total
_MONTANT_HT_RE = re.compile(r"Montant\s+HT\s+CHF\s+([\d ,]+,\d{2})", re.IGNORECASE)

# Date pattern dd/mm/yyyy
_DATE_RE = re.compile(r"\b(\d{2}/\d{2}/\d{4})\b")

# Lines to skip inside the table region
_SKIP_LINE_RES = [
    re.compile(r"La\s+N[eé]buleuse\s+Renens", re.IGNORECASE),  # location note
    re.compile(r"Montant\s+HT", re.IGNORECASE),                  # grand total line
    _HEADER_RE,                                                    # header repeated on page 2
]


# ── PDF text extraction ────────────────────────────────────────────────────────

def _pdf_to_text(pdf_bytes: bytes) -> str:
    """
    Write pdf_bytes to a temp file, run pdftotext -layout, return the text.
    Returns '' on any subprocess failure.
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
                "pdftotext returned %d: %s", result.returncode, result.stderr[:200]
            )
            return ""
        return result.stdout.decode("utf-8", errors="replace")
    except Exception as exc:
        log.warning("_pdf_to_text failed: %s", exc)
        return ""
    finally:
        if tmp_path and os.path.exists(tmp_path):
            os.unlink(tmp_path)


# ── Amount helpers ─────────────────────────────────────────────────────────────

def _parse_ch_amount(s: str) -> float:
    """
    Parse Swiss/EU comma-decimal amount string.
    '2 070,36'  → 2070.36
    '12 697,04' → 12697.04
    Strips spaces (thousands separator) then replaces comma decimal → dot.
    """
    return float(s.replace(" ", "").replace(",", "."))


# ── Requested date extraction ──────────────────────────────────────────────────

def _extract_requested_date(text: str) -> Optional[date]:
    """
    Find the "Date de livraison" value from the PDF.

    Layout:
      Line A:  "Responsable des achats :    Date de la commande :    Date de livraison:"
      Line B:  "Angélique Ducarroz          17/06/2026               22/06/2026"

    Strategy:
      Scan for a line containing "Date de livraison".
      Look on that same line AND the next line for all dd/mm/yyyy patterns.
      Take the LAST (rightmost) date found, which corresponds to the
      rightmost "Date de livraison:" column.
    """
    lines = text.splitlines()
    for i, line in enumerate(lines):
        if "Date de livraison" not in line:
            continue
        # Collect all dates from this line and the next
        candidate_lines = [line]
        if i + 1 < len(lines):
            candidate_lines.append(lines[i + 1])
        combined = " ".join(candidate_lines)
        dates_found = _DATE_RE.findall(combined)
        if dates_found:
            # Take the last one — rightmost in the header+value row
            last_date_str = dates_found[-1]
            try:
                return datetime.strptime(last_date_str, "%d/%m/%Y").date()
            except ValueError:
                log.debug("alloboissons: could not parse date '%s'", last_date_str)
        break  # found the header line; no need to keep scanning
    return None


# ── sku_hint extraction ────────────────────────────────────────────────────────

def _extract_sku_hint(line: str) -> str:
    """
    Extract the sku_hint from a data line.

    For lines starting with '[code] description ...':
      Split on 3+ consecutive spaces, take the first token.
      This captures the full "[code] description" text before column padding.
    For 'container' lines:
      Return 'container'.
    """
    stripped = line.strip()

    # container lines
    if re.match(r"^container\b", stripped, re.IGNORECASE):
        return "container"

    # Split on 3+ spaces to separate the description column from numeric columns
    parts = re.split(r"\s{3,}", stripped)
    if parts:
        return parts[0].strip()
    return stripped


# ── Data line parser ───────────────────────────────────────────────────────────

def _should_skip(line: str) -> bool:
    """Return True if this line should be skipped inside the table region."""
    stripped = line.strip()
    if not stripped:
        return True
    for pat in _SKIP_LINE_RES:
        if pat.search(stripped):
            return True
    return False


def _parse_data_line(
    line: str,
) -> Optional[tuple[str, float, float, float, str]]:
    """
    Parse one data line into (sku_hint, qty, prix_ht, total_ht, raw).

    Returns None if any required field cannot be extracted.

    Field extraction:
      - sku_hint : from _extract_sku_hint()
      - qty      : from _QTY_RE (Bou/L/Uni), space-stripped int
      - prix_ht  : from _PRIX_HT_RE (4+ decimal float before CHF)
      - total_ht : from _TOTAL_HT_RE (CHF <amount> at end of line)
    """
    # qty
    qty_m = _QTY_RE.search(line)
    if not qty_m:
        log.debug("alloboissons: no qty match in line: %.100s", line.strip())
        return None
    qty = int(qty_m.group(1).replace(" ", ""))

    # prix HT
    prix_m = _PRIX_HT_RE.search(line)
    if not prix_m:
        log.debug("alloboissons: no prix HT match in line: %.100s", line.strip())
        return None
    try:
        prix_ht = float(prix_m.group(1))
    except ValueError:
        return None

    # total HT
    total_m = _TOTAL_HT_RE.search(line)
    if not total_m:
        log.debug("alloboissons: no total HT match in line: %.100s", line.strip())
        return None
    try:
        total_ht = _parse_ch_amount(total_m.group(1))
    except ValueError:
        return None

    # sku_hint
    sku_hint = _extract_sku_hint(line)

    raw = line.strip()[:200]
    return (sku_hint, float(qty), prix_ht, total_ht, raw)


# ── Table extraction ───────────────────────────────────────────────────────────

def _extract_table_region(text: str) -> list[str]:
    """
    Return only the lines AFTER the table header
    (line matching Produit + cl + Conditionnement) up to the grand total line.

    Multi-page PDFs may repeat the header; we collect all lines from the first
    header onward, skipping repeated headers.
    """
    lines = text.splitlines()
    table_lines: list[str] = []
    in_table = False

    for line in lines:
        if _HEADER_RE.search(line):
            in_table = True
            continue  # skip the header row itself
        if in_table:
            # Stop at Montant HT line (grand total)
            if _MONTANT_HT_RE.search(line):
                break
            table_lines.append(line)

    return table_lines


# ── Sender identity check ──────────────────────────────────────────────────────

def _is_alloboissons_sender(ctx: EmailContext, env: ParserEnv) -> bool:
    """
    Return True if the email originates from Alloboissons SA.
    Checks ctx.from_address and env.original_sender (set by dispatcher).
    """
    from_addr = (ctx.from_address or "").lower()
    if "alloboissons" in from_addr:
        return True
    # The dispatcher sets env.original_sender for Google-Group-rewritten From: headers
    original = getattr(env, "original_sender", None)
    if original and "alloboissons" in str(original).lower():
        return True
    return False


# ── Parser class ───────────────────────────────────────────────────────────────

class AlloboissonsParser(SenderParser):
    """
    Per-sender parser for Alloboissons SA purchase-order PDF emails.

    matches():
      - 'alloboissons' found in from_address or original_sender, AND a PDF
        attachment with the identity markers is present; OR
      - from_address is unknown but the PDF contains both identity markers.

    parse():
      1. Extract PDF text via pdftotext -layout.
      2. Confirm identity markers in PDF text.
      3. Extract requested_date (Date de livraison column).
      4. Locate table region (after Produit/cl/Conditionnement header).
      5. Parse each data line (100%-or-decline gate).
      6. Reconcile: sum(line_total) ≈ Montant HT ±0.10 CHF.
      7. Return ParsedOrder.

    Returns None (decline) when:
      - No PDF attachment found.
      - PDF does not contain identity markers.
      - Any data line cannot be fully parsed.
      - Reconciliation gate fails.
      - No data lines found.
    """

    name = "alloboissons"

    # ── attachment helpers ──────────────────────────────────────────────────────

    def _get_pdf_attachment(self, ctx: EmailContext):
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

    # ── matches ─────────────────────────────────────────────────────────────────

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """
        Match when from_address/original_sender is Alloboissons AND a PDF
        attachment exists with the identity markers, OR when the PDF itself
        carries the identity markers regardless of sender (forwarded emails).
        """
        att = self._get_pdf_attachment(ctx)
        if att is None:
            return False

        text = _pdf_to_text(att["content"])
        if not text:
            return False

        has_markers = _MARKER_ORDER in text and _MARKER_BRAND in text

        if _is_alloboissons_sender(ctx, env):
            return has_markers

        # Forwarded / unknown sender path: require markers in PDF
        return has_markers

    # ── parse ───────────────────────────────────────────────────────────────────

    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Extract order lines from the Alloboissons SA PDF attachment.

        100%-or-decline: if ANY data line cannot be parsed, return None.
        Reconciliation gate: sum(line_total) must be within ±0.10 CHF of Montant HT.
        """
        att = self._get_pdf_attachment(ctx)
        if att is None:
            log.debug("alloboissons: no PDF attachment with bytes found")
            return None

        text = _pdf_to_text(att["content"])
        if not text:
            log.debug("alloboissons: pdftotext returned empty text")
            return None

        # Confirm identity markers
        if _MARKER_ORDER not in text or _MARKER_BRAND not in text:
            log.debug("alloboissons: identity markers not found in PDF text")
            return None

        # ── Requested date ──────────────────────────────────────────────────────
        requested_date = _extract_requested_date(text)
        if requested_date is None:
            log.debug("alloboissons: could not extract Date de livraison")

        # ── Montant HT (grand total) ────────────────────────────────────────────
        montant_m = _MONTANT_HT_RE.search(text)
        if montant_m is None:
            log.debug("alloboissons: Montant HT not found in PDF")
            return None
        try:
            montant_ht = _parse_ch_amount(montant_m.group(1))
        except ValueError:
            log.debug("alloboissons: could not parse Montant HT value")
            return None

        # ── Table region ────────────────────────────────────────────────────────
        table_lines = _extract_table_region(text)
        if not table_lines:
            log.debug("alloboissons: no table lines found after header")
            return None

        # ── Parse data lines (100%-or-decline) ─────────────────────────────────
        parsed_entries: list[tuple[str, float, float, float, str]] = []

        for line in table_lines:
            if _should_skip(line):
                continue
            stripped = line.strip()
            if not stripped:
                continue
            # Is this a data line?
            if not _DATA_LINE_RE.match(stripped):
                continue
            result = _parse_data_line(line)
            if result is None:
                log.warning(
                    "alloboissons: could not parse data line, declining: %.120s",
                    stripped,
                )
                return None  # 100%-or-decline
            parsed_entries.append(result)

        if not parsed_entries:
            log.debug("alloboissons: no data lines found in table region")
            return None

        # ── Reconciliation gate ─────────────────────────────────────────────────
        sum_line_totals = sum(entry[3] for entry in parsed_entries)
        diff = abs(sum_line_totals - montant_ht)
        if diff > 0.10:
            log.warning(
                "alloboissons: reconciliation failed — sum_lines=%.2f montant_ht=%.2f diff=%.4f",
                sum_line_totals,
                montant_ht,
                diff,
            )
            return None

        log.debug(
            "alloboissons: reconciliation OK — sum_lines=%.2f montant_ht=%.2f diff=%.4f",
            sum_line_totals,
            montant_ht,
            diff,
        )

        # ── Build ParsedLine list ───────────────────────────────────────────────
        lines: list[ParsedLine] = []
        for sku_hint, qty, _prix_ht, _total_ht, raw in parsed_entries:
            lines.append(ParsedLine(
                sku_hint=sku_hint,
                qty=qty,
                raw=raw,
            ))

        # ── Notes ───────────────────────────────────────────────────────────────
        # Extract order reference e.g. "#P18494"
        order_ref_m = re.search(r"Commande\s+fournisseur\s+(#P\d+)", text)
        notes_parts: list[str] = []
        if order_ref_m:
            notes_parts.append(f"Commande {order_ref_m.group(1)}")
        notes_parts.append(f"Montant HT: CHF {montant_ht:.2f}")
        notes = " | ".join(notes_parts)

        return ParsedOrder(
            customer_hint=_CUSTOMER_HINT,
            requested_date=requested_date,
            lines=lines,
            notes=notes,
        )
