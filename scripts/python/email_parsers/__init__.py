"""
email_parsers/__init__.py — Sender-parser registry + dispatcher.

Dispatch contract (Model B — decline-fall-through):
  • REGISTRY is an ordered list of SenderParser instances.
  • dispatch(ctx, env) iterates REGISTRY in declaration order.
  • For each parser whose matches(ctx, env) is True:
      - call parse(ctx, env)
      - if it returns a ParsedOrder → return it immediately (winner)
      - if it returns None → parser DECLINES ("matched my sender but can't read
        this body") → continue to the NEXT parser
      - if it raises → propagate (caller marks parse_status='error')
  • If all matched parsers declined, or no parser matched at all → return None
    (caller marks parse_status='no_match').

Why decline-fall-through?
  A sender-specific parser may recognise the from_address but be unable to parse
  this particular body layout (a confirmation email, a format change, etc.).  By
  returning None instead of raising, it passes control to the next parser rather
  than short-circuiting to 'error'.  This allows the generic_vocab parser (always
  registered LAST) to attempt a best-effort parse as a layer-2 fallback.

Parser registration order MATTERS:
  • Most-specific per-sender parsers go FIRST.
  • generic_vocab is always LAST — it is the universal layer-2 fallback.
  • Forwarder / system-email guards go before generic parsers (mirrors
    lib/invoice-parsers/index.js ordering discipline).

NO LLM FALLBACK — EVER.
  A no-match email goes to the review bucket (parse_status='no_match').
  An erroring parser goes to parse_status='error'.
  Neither path invokes any language model inference.
  This is a hard architectural constraint: deterministic parsers only.
  When a new sender's emails cannot be classified, add a parser once real
  samples are available; do not route through an LLM.

Adding a new sender parser
──────────────────────────
  1. Copy scripts/python/email_parsers/example_template.py to <sender_slug>.py.
  2. Implement name / matches(ctx, env) / parse(ctx, env) per the template.
  3. Import it here and INSERT an instance into REGISTRY BEFORE GenericVocabParser.
  4. Add a .eml fixture under tests/fixtures/email_parsers/<sender_slug>/ and
     a corresponding expected_output.json for regression testing.
  5. Run py_compile + the fixture test before committing.
"""

from __future__ import annotations

from typing import TYPE_CHECKING

from .base import EmailContext, ParsedOrder, ParserEnv, SenderParser

if TYPE_CHECKING:
    pass

# ── Sender-parser imports ──────────────────────────────────────────────────────
# Uncomment each line as real parsers are added (insert BEFORE generic_vocab).
# from .example_template import ExampleTemplateSenderParser  # NOT registered (template)
# from .customer_acme import AcmeSenderParser

from .alloboissons import AlloboissonsParser
from .amstein import AmsteinPdfParser
from .attachment_pdf import BevanarPdfParser
from .attachment_xlsx import MigrosFroidevilleXlsxParser
from .cobra import CobraBodyParser
from .nausikraft import NausikraftPdfParser
from .petitecave import PetiteCavePdfParser
from .generic_vocab import GenericVocabParser  # always last

# ── Registry ──────────────────────────────────────────────────────────────────
# Ordered list: first match-and-parse wins.
# generic_vocab is intentionally LAST — per-sender parsers go before it.
# NEVER add ExampleTemplateSenderParser here — it is a template, not a real parser.
REGISTRY: list[SenderParser] = [
    CobraBodyParser(),              # Cobra Traders SA body-format orders
    BevanarPdfParser(),             # Bevanar/CDDS supplier order PDFs
    MigrosFroidevilleXlsxParser(),  # Migros / MP Froideville XLSX orders
    NausikraftPdfParser(),          # Nausikraft SA purchase-order PDFs
    PetiteCavePdfParser(),          # Petite Cave born-digital PDF purchase orders
    AmsteinPdfParser(),             # Amstein SA born-digital PDF purchase orders
    AlloboissonsParser(),           # Alloboissons SA born-digital PDF purchase orders
    GenericVocabParser(),           # layer-2 universal fallback — always last
]


# ── Dispatcher ────────────────────────────────────────────────────────────────

def dispatch(ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
    """
    Run ctx through the registry with the decline-fall-through protocol.

    For each parser whose matches(ctx, env) returns True:
      - call parse(ctx, env)
      - ParsedOrder returned → return it (winner)
      - None returned       → parser DECLINED → continue to next parser
      - exception raised    → propagate (caller marks parse_status='error')

    Returns None when:
      - no parser matched at all, OR
      - all matched parsers declined.

    A None return means parse_status='no_match'.
    The caller must NEVER escalate a None to an LLM call.

    NO LLM FALLBACK — EVER.
    """
    for parser in REGISTRY:
        if parser.matches(ctx, env):
            result = parser.parse(ctx, env)
            if result is not None:
                return result
            # None = declined — continue to next parser in registry
    return None
