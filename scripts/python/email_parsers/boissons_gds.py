"""
boissons_gds.py — Per-sender multi-PDF parser for Boissons GDS purchase-order PDFs.

Sender:  Boissons de Siebenthal SA — matched on '@boissons-gds.ch' in
         ctx.from_address OR env.original_sender.
Format:  One or more born-digital PDF purchase orders attached to the email;
         each PDF = one depot order.  Extracted via pdftotext -layout.

PDF structure (pdftotext -layout output):
  Header: Boissons de Siebenthal SA / boissons-gds.ch
  "Commande fournisseur XXXXX"
  "Pour le dépôt de <Depot>"
  "Chargement à …"
  "Date de livraison / Lieferdatum:   DD.MM.YYYY"
  Table header: "N° Article   Désignation   Cont.   Emb.   Unité Empl."
  Data lines:   <art_code>  <La Nébuleuse ...>  <N> cl  <emb>  <unite>  <empl>
  "Total Marchandise:   <sum_emb>   <sum_unite>"

Multi-order return:
  parse() returns list[ParsedOrder] — one ParsedOrder per PDF.
  If ANY PDF fails to parse (identity check, line extraction, coverage gate) →
  return None to decline the ENTIRE email (never a partial list).

Coverage gate per PDF:
  sum(emb) must equal total_emb from "Total Marchandise:" line.
  sum(unite) must equal total_unite from "Total Marchandise:" line.
  Both must pass, or that PDF is rejected → entire email declined.

Deterministic, no DB calls, no LLM.
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

_PDFTOTEXT     = "/usr/bin/pdftotext"
_MAX_PDF_BYTES = 25 * 1024 * 1024


# ── PDF extraction ─────────────────────────────────────────────────────────────

def _pdf_to_text(pdf_bytes: bytes) -> str:
    """Convert PDF bytes to plain text via pdftotext -layout."""
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
                "boissons_gds: pdftotext returned %d: %s",
                result.returncode,
                result.stderr[:200],
            )
            return ""
        return result.stdout.decode("utf-8", errors="replace")
    except Exception as exc:
        log.warning("boissons_gds: _pdf_to_text failed: %s", exc)
        return ""
    finally:
        if tmp_path and os.path.exists(tmp_path):
            os.unlink(tmp_path)


# ── Identity check ─────────────────────────────────────────────────────────────

def _has_identity_markers(text: str) -> bool:
    """Return True iff the PDF text contains both required identity strings."""
    return "boissons-gds.ch" in text and "Commande fournisseur" in text


# ── Date extraction ────────────────────────────────────────────────────────────

# "Date de livraison / Lieferdatum:                          22.06.2026"
_DATE_RE = re.compile(
    r"Date\s+de\s+livraison\s*/\s*Lieferdatum\s*:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})",
    re.IGNORECASE,
)


def _extract_requested_date(text: str) -> Optional[date]:
    m = _DATE_RE.search(text)
    if not m:
        return None
    try:
        return date(int(m.group(3)), int(m.group(2)), int(m.group(1)))
    except ValueError:
        log.warning("boissons_gds: invalid date in PDF: %s", m.group(0))
        return None


# ── Header / meta extraction ───────────────────────────────────────────────────

_COMMANDE_RE = re.compile(r"Commande\s+fournisseur\s+(\d+)", re.IGNORECASE)

_DEPOT_RE = re.compile(
    r"Pour\s+le\s+d[eé]p[oô]t\s+de\s+(.+?)(?:\s*\.|  |\s{4,}|$)",
    re.IGNORECASE,
)

_CHARGEMENT_PREFIX = "Chargement à"
_PRIS_A_QUAI_RE    = re.compile(r"Pris\s+à\s+quai", re.IGNORECASE)


def _extract_commande_ref(text: str) -> Optional[str]:
    m = _COMMANDE_RE.search(text)
    return m.group(1) if m else None


def _extract_depot(text: str) -> Optional[str]:
    for line in text.splitlines():
        m = _DEPOT_RE.search(line)
        if m:
            return m.group(1).strip()
    return None


def _extract_chargement_and_pickup(text: str) -> tuple[str, bool]:
    """
    Return (chargement_note, pris_a_quai).

    chargement_note: the content of the 'Chargement à …' line(s) — we take only
    the LEFT-COLUMN sentence which starts with 'Chargement à'.  The right-column
    address that appears on the same physical text line is separated by many
    spaces, so we strip everything after ≥6 consecutive spaces.

    pris_a_quai: True when the string "Pris à quai" appears anywhere in the text.
    """
    chargement_note = ""
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith(_CHARGEMENT_PREFIX):
            # Strip right-column noise (6+ spaces signal a new column region).
            left_col = re.split(r" {6,}", stripped)[0].strip()
            chargement_note = left_col
            break

    pris_a_quai = bool(_PRIS_A_QUAI_RE.search(text))
    return chargement_note, pris_a_quai


# ── Table / data-line extraction ───────────────────────────────────────────────

# Table header sentinel: must contain all four of these strings.
_TABLE_HEADER_TOKENS = ("N° Article", "Désignation", "Emb.", "Unité")

# Total Marchandise line — captures sum_emb and sum_unite (which may be
# space-thousands like "1 116").
_TOTAL_RE = re.compile(
    r"Total\s+Marchandise\s*:\s+(\d+)\s+((?:\d+\s)?\d+)",
    re.IGNORECASE,
)

# Data line:
#   group 1: article code (short, lowercase, left-aligned, ≤ 2 leading spaces)
#   group 2: designation (starts with "La N[eé]buleuse")
#   group 3: Cont. (cl number)
#   group 4: Emb. quantity (what we want as qty)
#   group 5: Unité — always a plain integer on data lines (space-thousands
#             only appear in the Total Marchandise totals line, not data rows)
#   group 6: Empl. location code — ALWAYS exactly 3 groups of 3 digits
#             (e.g. "001 006 610", "007 002 080") — not 2 or 4 groups
#
# NOTE: The Empl. code must be anchored to exactly 3 groups to prevent the
# greedy Unité pattern from consuming the first group of Empl. digits.
# Observed in both PDFs: Empl. is invariably NNN NNN NNN (9 digits, 2 spaces).
_DATA_LINE_RE = re.compile(
    r"^\s{0,2}(\S[^A-Z\n]{0,30}?)\s{3,}"   # article code
    r"(La\s+N[eé]buleuse\s+.+?)"            # designation
    r"\s+(\d+)\s+cl"                         # Cont. (cl)
    r"\s+(\d+)"                              # Emb. (qty)
    r"\s+(\d+)"                              # Unité (plain integer on data lines)
    r"\s+(\d{3}\s\d{3}\s\d{3})\s*$",        # Empl. location code (exactly NNN NNN NNN)
)


def _parse_space_thousands(raw: str) -> int:
    """Convert a possibly space-separated thousands value like '1 116' → 1116."""
    return int(raw.replace(" ", ""))


def _parse_table(
    text: str,
) -> Optional[tuple[list[ParsedLine], int, int, int, int]]:
    """
    Parse the order table from one PDF's text.

    Returns (lines, computed_emb, computed_unite, total_emb, total_unite) on
    success, None when no data lines are found or the Total Marchandise line
    is absent.

    computed_emb / computed_unite: sums derived from data lines.
    total_emb / total_unite: sums from the "Total Marchandise:" footer line.
    """
    lines_text = text.splitlines()
    in_table   = False
    parsed_lines: list[ParsedLine] = []
    computed_emb   = 0
    computed_unite = 0
    total_emb:   Optional[int] = None
    total_unite: Optional[int] = None

    for raw_line in lines_text:
        # ── Detect table header ───────────────────────────────────────────────
        if not in_table:
            if all(tok in raw_line for tok in _TABLE_HEADER_TOKENS):
                in_table = True
            continue

        # ── Total Marchandise: marks end of table ─────────────────────────────
        if re.search(r"Total\s+Marchandise", raw_line, re.IGNORECASE):
            m = _TOTAL_RE.search(raw_line)
            if m:
                total_emb   = int(m.group(1))
                total_unite = _parse_space_thousands(m.group(2))
            break

        # ── Try matching a data line ──────────────────────────────────────────
        m = _DATA_LINE_RE.match(raw_line)
        if m is None:
            # Skip blank / decoration / continuation lines silently.
            continue

        art_code    = m.group(1).strip()
        designation = m.group(2).strip()
        emb_str     = m.group(4)
        unite_str   = m.group(5)

        emb   = int(emb_str)
        unite = int(unite_str)   # plain integer on data lines (no space-thousands)

        sku_hint = f"{art_code} {designation}"
        raw_text = raw_line.strip()[:200]

        parsed_lines.append(
            ParsedLine(
                sku_hint=sku_hint,
                qty=float(emb),
                raw=f"emb={emb_str} unite={unite_str} | {raw_text}",
            )
        )
        computed_emb   += emb
        computed_unite += unite

    if not parsed_lines or total_emb is None or total_unite is None:
        return None

    return (parsed_lines, computed_emb, computed_unite, total_emb, total_unite)


# ── Per-PDF parser ─────────────────────────────────────────────────────────────

def _parse_one_pdf(pdf_bytes: bytes) -> Optional[ParsedOrder]:
    """
    Parse a single PDF attachment and return a ParsedOrder, or None on failure.

    None is returned (not raised) for all recoverable failures:
      - identity markers absent
      - no table found / no lines parsed
      - coverage gate failure (sum mismatch)

    Raises on unrecoverable structural errors (should not happen in practice).
    """
    # Step 1 — Convert to text.
    text = _pdf_to_text(pdf_bytes)
    if not text.strip():
        log.warning("boissons_gds: pdftotext produced empty output — declining PDF")
        return None

    # Step 2 — Identity check.
    if not _has_identity_markers(text):
        log.info("boissons_gds: identity markers absent — declining PDF")
        return None

    # Step 3 — Extract metadata.
    requested_date  = _extract_requested_date(text)
    commande_ref    = _extract_commande_ref(text)
    depot           = _extract_depot(text)
    chargement, paq = _extract_chargement_and_pickup(text)

    # Step 4 — Parse the table.
    result = _parse_table(text)
    if result is None:
        log.warning("boissons_gds: no order lines found or Total Marchandise absent — declining PDF")
        return None

    parsed_lines, computed_emb, computed_unite, total_emb, total_unite = result

    # Step 5 — Coverage gate: both sums must match the footer totals.
    if computed_emb != total_emb:
        log.warning(
            "boissons_gds: Emb. sum mismatch: computed=%d vs total=%d — declining PDF",
            computed_emb, total_emb,
        )
        return None

    if computed_unite != total_unite:
        log.warning(
            "boissons_gds: Unité sum mismatch: computed=%d vs total=%d — declining PDF",
            computed_unite, total_unite,
        )
        return None

    # Step 6 — Build notes.
    notes_parts: list[str] = []
    if depot:
        notes_parts.append(f"Dépôt {depot}")
    if commande_ref:
        notes_parts.append(f"Commande fournisseur {commande_ref}")
    if chargement:
        notes_parts.append(chargement)
    if paq:
        notes_parts.append("Pris à quai / pickup")
    notes = " — ".join(notes_parts)

    return ParsedOrder(
        customer_hint="Boissons GDS",
        requested_date=requested_date,
        lines=parsed_lines,
        notes=notes,
    )


# ── Parser class ───────────────────────────────────────────────────────────────

class BoissonsGdsPdfParser(SenderParser):
    """
    Per-sender multi-PDF parser for Boissons de Siebenthal SA (Boissons GDS)
    purchase-order emails.

    matches():
      Returns True when '@boissons-gds.ch' is in ctx.from_address OR in
      env.original_sender (for forwarded emails).  PDF content is NOT
      inspected in matches() — the sender domain is the sole signal.
      At least one PDF attachment must be present.

    parse():
      Iterates all PDF attachments in order; parses each independently.
      Returns list[ParsedOrder] on full success.
      Returns None (decline) when:
        - No PDF attachments found.
        - ANY PDF fails identity check, table parsing, or coverage gate
          (entire email declined — never a partial list).

    Raises on unrecoverable structural errors.
    """

    name = "boissons_gds"

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """
        Return True iff the email originates from Boissons de Siebenthal SA
        AND has at least one PDF attachment.
        """
        _domain = "@boissons-gds.ch"

        from_addr = (ctx.from_address or "").lower()
        orig      = (getattr(env, "original_sender", None) or "").lower()

        sender_match = _domain in from_addr or _domain in orig
        if not sender_match:
            return False

        # At least one PDF attachment must be present.
        for att in (ctx.attachments or []):
            ct = (att.get("content_type") or "").lower()
            fn = (att.get("filename") or "").lower()
            if "pdf" in ct or fn.endswith(".pdf"):
                return True

        return False

    def parse(
        self, ctx: EmailContext, env: ParserEnv
    ) -> list[ParsedOrder] | None:
        """
        Parse all PDF attachments.

        Returns list[ParsedOrder] (one per PDF) on full success.
        Returns None to decline if any PDF cannot be parsed (coverage-or-refuse).
        """
        pdf_attachments: list[bytes] = []
        for att in (ctx.attachments or []):
            ct = (att.get("content_type") or "").lower()
            fn = (att.get("filename") or "").lower()
            if "pdf" in ct or fn.endswith(".pdf"):
                content = att.get("content")
                if isinstance(content, bytes) and 0 < len(content) <= _MAX_PDF_BYTES:
                    pdf_attachments.append(content)
                else:
                    log.warning(
                        "boissons_gds: attachment %r skipped (missing or oversized content)",
                        fn,
                    )

        if not pdf_attachments:
            log.info("boissons_gds: no usable PDF attachments found — declining")
            return None

        orders: list[ParsedOrder] = []
        for idx, pdf_bytes in enumerate(pdf_attachments):
            order = _parse_one_pdf(pdf_bytes)
            if order is None:
                log.warning(
                    "boissons_gds: PDF #%d failed to parse — declining entire email",
                    idx + 1,
                )
                return None
            orders.append(order)

        if not orders:
            return None

        return orders
