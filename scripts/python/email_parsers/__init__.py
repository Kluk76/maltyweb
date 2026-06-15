"""
email_parsers/__init__.py — Sender-parser registry + dispatcher.

Dispatch contract (mirrors lib/invoice-parsers/index.js doctrine):
  • REGISTRY is an ordered list of SenderParser instances.
  • dispatch(ctx) iterates REGISTRY in declaration order.
  • The FIRST parser whose matches(ctx) returns True wins; its parse(ctx) is called.
  • If parse(ctx) raises, the exception propagates — the caller catches it and
    records parse_status='error' with the message.  No swallowing.
  • If no parser matches, dispatch returns None — the caller records
    parse_status='no_match'.

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
  2. Implement name / matches() / parse() per the template docstring.
  3. Import it here and append an instance to REGISTRY (order matters — more
     specific matchers go before more generic ones, exactly as in the invoice
     parser dispatcher).
  4. Add a .eml fixture under tests/fixtures/email_parsers/<sender_slug>/ and
     a corresponding expected_output.json for regression testing.
  5. Run py_compile + the fixture test before committing.
"""

from __future__ import annotations

from .base import EmailContext, ParsedOrder, SenderParser

# ── Sender-parser imports ──────────────────────────────────────────────────────
# Uncomment each line as real parsers are added.
# from .example_template import ExampleTemplateSenderParser  # NOT registered (template)
# from .customer_acme import AcmeSenderParser

# ── Registry ──────────────────────────────────────────────────────────────────
# Ordered list: first match wins.
# NEVER add ExampleTemplateSenderParser here — it is a template, not a real parser.
REGISTRY: list[SenderParser] = [
    # AcmeSenderParser(),  # add real parsers here, ordered most-specific first
]


# ── Dispatcher ────────────────────────────────────────────────────────────────

def dispatch(ctx: EmailContext) -> ParsedOrder | None:
    """
    Run ctx through the registry; return the first ParsedOrder produced,
    or None when no parser matches.

    Raises: whatever the winning parser's parse() raises (ValueError, etc.).
    Caller is responsible for catching and recording parse_status='error'.

    NO LLM FALLBACK.  A None return means parse_status='no_match'.
    The caller must NEVER escalate a None to an LLM call.
    """
    for parser in REGISTRY:
        if parser.matches(ctx):
            # Propagate exceptions — caller records parse_status='error'
            return parser.parse(ctx)
    return None
