"""
example_template.py — TEMPLATE: copy this to add a real sender parser.

TEMPLATE — copy this to add a real sender parser once samples arrive.
Match on from_address first.  Keep deterministic — no LLM, ever.

Step-by-step:
  1. Copy this file to <sender_slug>.py (e.g. customer_acme.py).
  2. Set name = '<sender_slug>' (shows in parser_matched column).
  3. Implement matches() — from_address domain/exact-address is the primary
     discriminator.  Add subject/body guards only when needed.
  4. Implement parse() — extract customer_hint, requested_date, lines, notes
     as HINTS (no ID resolution here).
  5. Import your class in __init__.py and add it to REGISTRY.
  6. Add a .eml fixture to tests/fixtures/email_parsers/<sender_slug>/
     and a corresponding expected_output.json.
  7. Run: python3 -m pytest tests/test_email_parsers.py -k <sender_slug>

DO NOT register this template file itself — it will always return
matches()=False and must never appear in the live registry.

NO LLM FALLBACK: a no-match goes to review_status='pending', never
to any language-model inference path.
"""

from __future__ import annotations

import re
from datetime import date

from .base import EmailContext, ParsedLine, ParsedOrder, SenderParser

# REGISTERED = False  ← this parser is intentionally excluded from the registry


class ExampleTemplateSenderParser(SenderParser):
    """
    Template parser — NOT REGISTERED.  Shows the expected structure.

    Matching strategy: from_address exact-match or domain-match comes first.
    Only add body/subject guards if the sender domain is shared by multiple
    non-order email types (e.g. invoices + newsletters from the same company).
    """

    name = "example_template"  # change this to your sender slug

    def matches(self, ctx: EmailContext) -> bool:
        """
        TEMPLATE: return True when this email is from the target sender
        and looks like an order (not a confirmation, invoice, etc.).

        Primary check: from_address.
        """
        # ── Primary: from_address domain or exact address ──────────────────
        # from_addr = (ctx.from_address or "").lower()
        # if "@example-customer.ch" not in from_addr:
        #     return False

        # ── Optional guards (add only if needed) ──────────────────────────
        # subject = (ctx.subject or "").lower()
        # if "commande" not in subject and "order" not in subject:
        #     return False

        # This template never matches — it is not registered.
        return False

    def parse(self, ctx: EmailContext) -> ParsedOrder:
        """
        TEMPLATE: extract order hints from the email body.

        Returns ParsedOrder with:
          customer_hint  — raw sender name or company from the email
          requested_date — delivery date parsed from body, or None
          lines          — list of ParsedLine(sku_hint, qty, raw)
          notes          — any free-text notes for the logistics team

        Rules:
          • All returned values are HINTS — no ID resolution.
          • Raise ValueError on unrecoverable parse failure.
          • NO LLM calls, ever.
          • Be deterministic: same input → same output.
        """
        body = ctx.body_text or ""

        # ── Customer hint ──────────────────────────────────────────────────
        # Usually the From display name or a signature line.
        customer_hint = ctx.from_address or ""

        # ── Requested date ─────────────────────────────────────────────────
        # Try to find a delivery / order date in the body.
        # Return None if absent — the ingest script uses received_at as fallback.
        requested_date: date | None = None
        # date_match = re.search(r'(\d{2})\.(\d{2})\.(\d{4})', body)
        # if date_match:
        #     requested_date = date(int(date_match[3]), int(date_match[2]), int(date_match[1]))

        # ── Order lines ───────────────────────────────────────────────────
        lines: list[ParsedLine] = []
        # for m in re.finditer(r'(\w[\w\s-]+?)\s+x\s*(\d+)', body, re.IGNORECASE):
        #     sku_hint = m.group(1).strip()
        #     qty      = float(m.group(2))
        #     lines.append(ParsedLine(sku_hint=sku_hint, qty=qty, raw=m.group(0)))

        # ── Notes ─────────────────────────────────────────────────────────
        notes = ""

        return ParsedOrder(
            customer_hint=customer_hint,
            requested_date=requested_date,
            lines=lines,
            notes=notes,
        )
