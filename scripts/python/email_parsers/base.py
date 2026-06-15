"""
base.py — Contracts for the email-order parser registry.

Defines:
  EmailContext   — normalised representation of one inbound email message.
  ParsedLine     — a single order-line hint (SKU + qty, as the sender expressed them).
  ParsedOrder    — the result a sender-parser produces when it fires.
  ParserEnv      — per-run environment: canonical product vocab + system settings.
  SenderParser   — abstract base class every real sender-parser must implement.

Design notes
────────────
• All fields here are HINTS, not resolved IDs.  customer_hint / sku_hint are
  raw strings taken from the email body.  Resolution to real customer_id /
  sku_id happens later, in the logistics-validation step inside maltyweb.
  No parser should ever write a guessed customer_id or sku_id — that would
  silently corrupt revenue-bearing records.

• NO LLM fallback, anywhere.  A sender whose email this registry cannot parse
  lands in the review bucket (parse_status='no_match').  A parser that raises
  lands as parse_status='error'.  Neither path ever calls a language model.

• attachments is a list of dicts with keys: filename, content_type, size_bytes,
  content (bytes or None if not downloaded).  Parsers may inspect or ignore.

• ParserEnv is built ONCE per run by the poller and passed through to every
  dispatch() call.  It carries the vocab (canonical product index, loaded from
  the DB) and dayfirst (system setting).  Parsers must NOT make DB calls
  themselves — all data access goes through env.
"""

from __future__ import annotations

import abc
from dataclasses import dataclass, field
from datetime import date
from typing import Any


# ── Data transfer objects ──────────────────────────────────────────────────────

@dataclass(frozen=True, slots=True)
class EmailContext:
    """
    Normalised view of one inbound email.  Built from stdlib `email` module
    output (fixtures mode) or from the Gmail API response (live mode).

    message_id : RFC 5322 Message-ID header value.  Used as the UNIQUE
                 idempotency key in doc_email_messages.
    received_at: datetime the server received the message (from Received: header
                 or Date: header as fallback).  Stored as-is; parsers may use it
                 as a fallback for requested_date when the body carries no date.
    body_text  : Plain-text part of the email, already decoded to str (UTF-8).
    body_html  : HTML part of the email, already decoded to str (UTF-8), or ''.
    attachments: List of attachment metadata dicts.  See module docstring.
    """
    message_id:   str
    from_address: str
    to_address:   str
    subject:      str
    received_at:  Any          # datetime | None
    body_text:    str
    body_html:    str
    attachments:  list         # list[dict[str, Any]]


@dataclass(frozen=True, slots=True)
class ParsedLine:
    """
    One order line as expressed by the sender.

    sku_hint : The SKU code or product name as it appears in the email — a HINT,
               never resolved here.  Resolution happens in the validation step.
    qty      : Requested quantity (float; kegs, cases, bottles — unit implied by
               sku_hint context).
    raw      : The verbatim text fragment this line was extracted from; used in
               the review UI and debug logs.
    """
    sku_hint: str
    qty:      float
    raw:      str


@dataclass(frozen=True, slots=True)
class ParsedOrder:
    """
    The structured result a sender-parser returns.

    customer_hint  : Customer name or identifier as it appears in the email
                     (From: address, signature, or explicit name field).
                     Always a HINT — resolution to ref_customers.id happens later.
    requested_date : Delivery / requested date parsed from the email body, or
                     None when absent.  The ingest script falls back to the email's
                     received_at date when None.
    lines          : List of ParsedLine hints.  May be empty if the email is a
                     blanket order with no explicit line breakdown.
    notes          : Any free-text notes, instructions, or context extracted from
                     the email body that the logistics team should see.
    """
    customer_hint:  str
    requested_date: date | None
    lines:          list            # list[ParsedLine]
    notes:          str


@dataclass
class VocabIndex:
    """
    Canonical product vocabulary built from ref_skus + ref_recipes + ref_beer_types
    + ref_recipe_aliases + ref_sku_aliases.

    terms: list of (normalised_term, canonical_hint) pairs used for fuzzy matching.
           canonical_hint is the best surface form to put in ParsedLine.sku_hint.
    lookup: exact normalised term → canonical_hint (for O(1) exact matches before
            falling through to fuzzy scan).

    Built by load_vocab() in generic_vocab.py; held in ParserEnv.vocab.
    """
    terms:  list   # list[tuple[str, str]]  (normalised_term, canonical_hint)
    lookup: dict   # dict[str, str]         normalised_term → canonical_hint


@dataclass
class ParserEnv:
    """
    Per-run parser environment.  Built ONCE by the poller and passed to every
    dispatch() and parse() call.  Parsers are pure functions of (ctx, env) —
    no DB calls inside parse().

    vocab   : VocabIndex built from canonical product tables.  None when the DB
              is unreachable (offline/test mode) — parsers that need vocab must
              decline gracefully when vocab is None.
    dayfirst: When True, ambiguous dates (dd/mm vs mm/dd) are parsed day-first
              (European convention, Swiss system default = True).
              Read from system_settings section='general' key='date_parse_dayfirst'.
    """
    vocab:    VocabIndex | None
    dayfirst: bool = True


# ── Parser base class ──────────────────────────────────────────────────────────

class SenderParser(abc.ABC):
    """
    Abstract base class for all per-sender email-order parsers.

    Subclasses MUST implement:
      name    (class attribute, str)
      matches (EmailContext, ParserEnv → bool)
      parse   (EmailContext, ParserEnv → ParsedOrder | None)

    Dispatch contract (Model B — decline-fall-through):
      dispatch() iterates the registered parsers in declaration order.
      For each parser whose matches() returns True:
        - parse() is called.
        - ParsedOrder returned → caller records parse_status='parsed'.
        - None returned        → parser DECLINED (matched but can't read this body)
                                  → dispatch continues to the next parser.
        - exception raised     → caller catches, marks parse_status='error'.
      If no parser matched, or all declined → parse_status='no_match'.
      NO LLM fallback — ever.

    Matching discipline:
      • Match on from_address FIRST (most specific signal).
      • Add additional guards (subject keywords, body markers) only when a sender
        domain is shared by multiple different sender types.
      • Never match on content that could appear in unrelated domains.
      • If in doubt, return False — a false-negative goes to no_match (review);
        a false-positive silently mis-classifies the order.

    Parsing discipline:
      • Return HINTS only — never resolve to ref_customers / ref_skus IDs.
      • Return None to DECLINE (matched sender but can't parse this body layout)
        — this falls through to the next parser rather than marking 'error'.
      • Raise ValueError (or any exception) on genuine parse failure (structural
        corruption, encoding error); the caller surfaces as parse_status='error'.
      • Be deterministic — same input must always produce the same output.
      • NO DB calls inside parse() — all data comes through env.
      • NO LLM calls, ever.
    """

    name: str  # Must be overridden as a class attribute in every subclass

    @abc.abstractmethod
    def matches(self, ctx: EmailContext, env: ParserEnv) -> bool:
        """Return True iff this parser recognises the email as its sender's format."""

    @abc.abstractmethod
    def parse(self, ctx: EmailContext, env: ParserEnv) -> ParsedOrder | None:
        """
        Extract order hints from the email.

        Return ParsedOrder on success.
        Return None to DECLINE (matched sender, but can't read this body layout)
          — the dispatcher will continue to the next parser.
        Raise on unrecoverable parse failure (caller handles, marks error).

        NEVER call an LLM.  NEVER resolve customer/SKU IDs.
        NEVER make DB calls — use env for any context data.
        """
