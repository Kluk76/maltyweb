"""
cobra.py — Per-sender body parser for Cobra Traders SA order emails.

Sender:  g.dutoit@cobratraders.com  (matched on @cobratraders.com domain)
Format:  Plain-text order emails with a structured bullet list of product lines.

Email structure example:
  Subject: Nouvelle commande "COOP Pratteln" / 6 palettes / Cde 7210273130
  Body:
    Voici une nouvelle commande à préparer pour sortie marchandises
    le mercredi 24/06 à Crissier (13:00-16:00).
    - La Nébuleuse Embuscade bouteilles 6x4x33cl : 108 cartons (2 palettes)
    - La Nébuleuse Stirling bouteilles 6x4x33cl : 108 cartons (2 palettes)
    ...

Parsing strategy:
  1. Match on @cobratraders.com sender domain (no other guard needed — domain
     is specific enough to Cobra Traders orders).
  2. Extract customer_hint from subject: the quoted customer name after
     'Nouvelle commande "..."'.
  3. Extract requested_date from the body phrase "sortie marchandises le <date>".
  4. Parse product bullets starting with "- La Nébuleuse …" — strict coverage gate:
     if ANY product bullet cannot be fully parsed, decline (return None) rather
     than emit partial.
  5. Best-effort vocab resolution of the description part via generic_vocab helpers.

Coverage gate:
  Count all "- La Nébuleuse" bullets first; then match them all with the full
  product regex.  If counts differ → return None (decline, fall through to
  GenericVocabParser).  This prevents partial orders silently entering the system.

Encoding noise:
  Cobra emails contain ISO-8859-1 mojibake artefacts: the byte sequence for
  U+FFFD (replacement char) is stored as the literal ASCII string "ï¿½" in the
  DB column, OR as the actual Unicode replacement character U+FFFD (the DB
  connection charset determines which).  We strip both before matching so that
  "La Nébuleuse" survives intact.
"""

from __future__ import annotations

import re
from datetime import date
from typing import Optional

from .base import (
    EmailContext,
    ParsedLine,
    ParsedOrder,
    ParserEnv,
    SenderParser,
)

# Import vocab helpers from generic_vocab for best-effort SKU resolution.
# generic_vocab does NOT import from cobra, so no circular dependency.
from .generic_vocab import _best_match, _normalise  # noqa: WPS437


# ── Constants ─────────────────────────────────────────────────────────────────

_SENDER_DOMAIN = "@cobratraders.com"
_BROKER_NAME   = "Cobratraders SA"

# ── Encoding-noise normaliser ─────────────────────────────────────────────────

def _strip_encoding_noise(text: str) -> str:
    """
    Remove encoding artefacts that appear in Cobra email bodies.

    The raw_body field from doc_email_messages may contain either:
      - The literal ASCII string "ï¿½" (mojibake of U+FFFD in latin1 columns), or
      - The actual Unicode replacement character U+FFFD.

    Strip both so that "La Nébuleuse" survives intact for regex matching.
    """
    text = text.replace("ï¿½", "")
    text = text.replace("�", "")  # U+FFFD replacement char
    return text


# ── Subject: customer hint ────────────────────────────────────────────────────

# Matches the quoted customer name in: Nouvelle commande "COOP Pratteln" / ...
_SUBJECT_CUSTOMER_RE = re.compile(r'"([^"]+)"')


def _extract_customer_hint(subject: str, fallback: str) -> str:
    """
    Extract the quoted customer name from the email subject.

    Example:
        'Nouvelle commande "COOP Pratteln" / 6 palettes / Cde 7210273130'
        → 'COOP Pratteln'

    Falls back to `fallback` (typically ctx.from_address) when no quoted name is
    found.
    """
    m = _SUBJECT_CUSTOMER_RE.search(subject)
    if m:
        return m.group(1).strip()
    return fallback


# ── Body: date extraction ──────────────────────────────────────────────────────

# Matches dd/mm date fragment (day-first, Swiss convention).
# Cobra bodies use patterns like:
#   "sortie marchandises le mercredi 24/06 à Crissier"
#   "sortie marchandises à Crissier le vendredi matin 24/07"
_DATE_DDMM_RE = re.compile(r'\b(\d{1,2})/(\d{1,2})\b')


def _extract_requested_date(body: str, received_at: object) -> Optional[date]:
    """
    Extract the first dd/mm date found in the body.

    Day-first (Swiss convention): group(1)=day, group(2)=month.
    Year is taken from received_at.year when available, else date.today().year.

    Returns None when no date pattern is found.
    """
    # Determine reference year
    year: int
    if received_at is not None:
        try:
            year = received_at.year  # type: ignore[union-attr]
        except AttributeError:
            year = date.today().year
    else:
        year = date.today().year

    m = _DATE_DDMM_RE.search(body)
    if not m:
        return None

    day   = int(m.group(1))
    month = int(m.group(2))
    try:
        return date(year, month, day)
    except ValueError:
        # Swap day/month as a last-resort fallback
        try:
            return date(year, day, month)
        except ValueError:
            return None


# ── Body: product line extraction ─────────────────────────────────────────────

# Detects any bullet that begins with "- La Nébuleuse" (accounting for encoding
# variants: La N + any continuation chars).  Used for the coverage gate.
_PRODUCT_BULLET_RE = re.compile(
    r"^-\s+La\s+N[eé]buleuse\b",
    re.IGNORECASE | re.MULTILINE,
)

# Full product-line parser.  After stripping encoding noise "La Nébuleuse"
# is reliably present.  Captures:
#   group(1) — description between "La Nébuleuse " and " :"
#   group(2) — integer carton count
_PRODUCT_LINE_RE = re.compile(
    r"^-\s+La\s+N[eé]buleuse\s+([^:]+?)\s*:\s*(\d+)\s+carton",
    re.IGNORECASE | re.MULTILINE,
)


def _parse_product_lines(
    body: str,
    vocab: object,
) -> Optional[list[ParsedLine]]:
    """
    Parse all product bullets from the Cobra email body.

    Coverage gate:
      Count all "- La Nébuleuse" bullets via the loose detector first.
      Parse each via the strict regex.  If the counts differ (a bullet
      was detected but not parsed), return None to signal a decline.

    Returns:
      list[ParsedLine]  on full success
      None              on any coverage failure (partial parse)
    """
    detected = _PRODUCT_BULLET_RE.findall(body)
    n_detected = len(detected)

    if n_detected == 0:
        # No product bullets at all — not a structured order; decline.
        return None

    parsed_matches = list(_PRODUCT_LINE_RE.finditer(body))
    n_parsed = len(parsed_matches)

    if n_parsed != n_detected:
        # Mismatch: at least one product bullet couldn't be fully parsed.
        # Return None (decline) rather than emit a partial order.
        return None

    lines: list[ParsedLine] = []
    for m in parsed_matches:
        desc_raw = m.group(1).strip()
        qty_int  = int(m.group(2))
        raw_line = m.group(0).strip()[:150]

        # Best-effort vocab resolution: normalise the description string and
        # attempt a fuzzy match against the canonical product vocabulary.
        sku_hint = desc_raw
        if vocab is not None:
            vocab_result = _best_match(_normalise(desc_raw), vocab)  # type: ignore[arg-type]
            if vocab_result is not None:
                sku_hint = vocab_result[0]

        lines.append(ParsedLine(
            sku_hint=sku_hint,
            qty=float(qty_int),
            raw=raw_line,
        ))

    return lines


# ── Notes extraction ───────────────────────────────────────────────────────────

# Operator-facing notes: lines starting with "- " that are NOT product lines
# but appear to carry instructions (DLC, transport, no delivery note, etc.).
_INSTRUCTION_BULLET_RE = re.compile(
    r"^-\s+(?!La\s+N[eé]buleuse)(.+)",
    re.IGNORECASE | re.MULTILINE,
)


def _extract_notes(body: str, from_address: str) -> str:
    """
    Build the notes string for the ParsedOrder.

    Includes:
      • Broker provenance line (always first).
      • Any instruction bullets from the body (DLC deadline, transport info, etc.)
        that are NOT product lines.
    """
    parts: list[str] = [
        "Broker: " + _BROKER_NAME + " (" + from_address + ")"
    ]

    instructions: list[str] = []
    for m in _INSTRUCTION_BULLET_RE.finditer(body):
        instr = m.group(1).strip()
        if instr:
            instructions.append(instr)

    if instructions:
        parts.append("Instructions: " + " | ".join(instructions))

    return "\n".join(parts)


# ── Parser class ───────────────────────────────────────────────────────────────

class CobraBodyParser(SenderParser):
    """
    Per-sender body parser for Cobra Traders SA order emails.

    matches(): fires on from_address ending with @cobratraders.com.
    parse():
      1. Strip encoding noise from body.
      2. Extract customer_hint from subject (quoted customer name).
      3. Extract requested_date from first dd/mm date in body.
      4. Parse product bullets — coverage gate: all bullets must parse cleanly
         or the parser declines (returns None).
      5. Return ParsedOrder with broker provenance in notes.

    Returns None (decline) when:
      - Body has no product bullets.
      - Product bullet count differs from parseable count (partial parse).
    Raises on unexpected structural errors (signals parse_status='error').
    """

    name = "cobra"

    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """Match on the @cobratraders.com sender domain."""
        from_addr = (ctx.from_address or "").lower()
        return from_addr.endswith(_SENDER_DOMAIN)

    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Extract order hints from a Cobra Traders email body.

        Returns ParsedOrder on full parse success.
        Returns None (decline) on partial or unrecognised body layout.
        Raises on structural errors.
        """
        raw_body = ctx.body_text or ""

        # 1. Normalise encoding noise so "La Nébuleuse" is clean.
        body = _strip_encoding_noise(raw_body)

        # 2. Customer hint from subject.
        customer_hint = _extract_customer_hint(ctx.subject or "", ctx.from_address or "")

        # 3. Requested date: first dd/mm in body, year from received_at.
        requested_date = _extract_requested_date(body, ctx.received_at)

        # 4. Product lines — coverage gate applies inside _parse_product_lines.
        vocab = env.vocab  # may be None in offline/test mode
        lines = _parse_product_lines(body, vocab)

        if lines is None:
            # Coverage gate triggered or no product bullets — decline.
            return None

        # 5. Notes: broker provenance + instruction bullets.
        notes = _extract_notes(body, ctx.from_address or "")

        return ParsedOrder(
            customer_hint=customer_hint,
            requested_date=requested_date,
            lines=lines,
            notes=notes,
        )
