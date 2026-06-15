#!/usr/bin/env python3
"""
ingest_email_orders.py — Email orders (commandes@lanebuleuse.ch) → doc_email_messages.

Reads inbound order emails, classifies them via deterministic per-sender parsers
(scripts/python/email_parsers/), and persists raw messages + parse results to the
doc_email_messages table.  Logistics validates before any ord_orders write or BC push.

MODEL B (parse-time contract)
──────────────────────────────
  • Parser output = HINTS only.  customer_hint (string), lines (sku_hint + qty).
  • Parse-time DB effect: upsert doc_email_messages with parse_status + (when parsed)
    the full ParsedOrder serialised into parsed_json.
  • parse_error is reserved for REAL errors only (parse_status='error').
  • ord_orders rows are NEVER written at parse time.  The schema requires
    customer_id_fk NOT NULL on 'customer' orders (CHECK constraint) and
    requested_date NOT NULL — hints are not resolved IDs.  ord_orders is created
    at logistics validation (a future UI step), with resolved FKs.
  • Idempotency: doc_email_messages.message_id UNIQUE (skip if present unless --force).

ENVIRONMENT (ParserEnv)
────────────────────────
  Built ONCE per run and passed to every dispatch() call.  Carries:
    vocab    — VocabIndex loaded from canonical DB tables (ref_skus, ref_recipes,
               ref_beer_types, ref_recipe_aliases, ref_sku_aliases).  Loaded
               READ-ONLY even in dry-run mode (reads are allowed; only writes are
               gated by --apply).  None when DB is unreachable (offline mode).
    dayfirst — system_settings section='general' key='date_parse_dayfirst';
               default True (Swiss day-first convention).

SOURCE MODES
────────────
  --fixtures-dir PATH
      Offline / dev mode.  Reads *.eml files from PATH using Python stdlib `email`.
      Works NOW without any Gmail API credentials.  Use this for development and
      regression testing.  Vocab is STILL loaded from the DB (read-only) even in
      this mode — use --dry-run if you also want to skip DB writes.

  Live Gmail API mode (GATED):
      Requires config/gmail.env with the following keys:
        GMAIL_DELEGATED_USER=commandes@lanebuleuse.ch
        GMAIL_SA_KEYFILE=/var/www/maltytask/config/gmail-sa.json
        GMAIL_QUERY=is:unread label:inbox
      If config/gmail.env is absent or the google-api-python-client package is not
      installed, the script exits cleanly with an explanation.
      The google-api-python-client import is LAZY — fixtures mode runs without it.

PIPELINE PER MESSAGE
────────────────────
  1. Build EmailContext from .eml or Gmail API message.
  2. Idempotency check: skip if message_id already in doc_email_messages (unless --force).
  3. Dispatch through the parser registry (Model B decline-fall-through):
       None (no match / all declined) → parse_status='no_match', parser_matched=NULL.
       ParsedOrder                    → parse_status='parsed',
                                        parser_matched=<parser.name>,
                                        parsed_json=<ParsedOrder as JSON>.
       Exception                      → parse_status='error', parse_error=<message>.
  4. Upsert doc_email_messages (--apply only; dry-run prints the plan).

NO LLM FALLBACK — EVER.
  A no-match email → parse_status='no_match' → operator review.
  A parse error    → parse_status='error'    → operator review.
  Neither path calls a language model.  This is a hard architectural constraint.

NO ORD_ORDERS WRITE.
  This script does not write to ord_orders.  It is the raw-ingestion step only.
  Logistics validates from the UI; the future validation step creates ord_orders.

DISARM CONVENTION (mirrors ingest_bc_sales_orders.py):
  --dry-run is the DEFAULT.  Prints a report, writes nothing.
  --apply performs DB writes (INSERT/UPDATE doc_email_messages).

Usage:
  # Offline dev mode (dry-run default):
  python3 scripts/python/ingest_email_orders.py --fixtures-dir /tmp/eml-samples

  # Offline dev mode with DB write:
  python3 scripts/python/ingest_email_orders.py --fixtures-dir /tmp/eml-samples --apply

  # Live Gmail API mode (requires config/gmail.env + DWD grant):
  python3 scripts/python/ingest_email_orders.py --apply

  # Reprocess already-seen message IDs:
  python3 scripts/python/ingest_email_orders.py --fixtures-dir /tmp/eml-samples --force --apply

  # Limit for smoke testing:
  python3 scripts/python/ingest_email_orders.py --fixtures-dir /tmp/eml-samples --limit 5
"""

from __future__ import annotations

import argparse
import json
import logging
import sys
from datetime import datetime, timezone
from email import message_from_bytes, policy as email_policy
from email.message import EmailMessage
from pathlib import Path
from typing import Any

# Allow running from /var/www/maltytask (same pattern as ingest_bc_sales_orders.py)
_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

import pymysql  # noqa: E402 — after sys.path fix
from pymysql.cursors import DictCursor

from lib_config import load as load_config  # noqa: E402
from email_parsers import dispatch  # noqa: E402
from email_parsers.base import EmailContext, ParsedOrder, ParserEnv  # noqa: E402
from email_parsers.generic_vocab import load_vocab  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

SCRIPT_VERSION = "2.0.0"  # Model B: parsed_json; no ord_orders write; env dispatch
ACTOR = "email-ingest"

_GMAIL_ENV_PATH = Path("/var/www/maltytask/config/gmail.env")

# ── Logging ───────────────────────────────────────────────────────────────────

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    stream=sys.stdout,
)
log = logging.getLogger(__name__)


# ── Gmail config loader ────────────────────────────────────────────────────────

def _load_gmail_env() -> dict[str, str]:
    """
    Load config/gmail.env.  Expected keys:
      GMAIL_DELEGATED_USER=commandes@lanebuleuse.ch
      GMAIL_SA_KEYFILE=/var/www/maltytask/config/gmail-sa.json
      GMAIL_QUERY=is:unread label:inbox
    """
    if not _GMAIL_ENV_PATH.exists():
        raise RuntimeError(
            f"Gmail API credentials not found at {_GMAIL_ENV_PATH}.\n"
            "Expected keys: GMAIL_DELEGATED_USER, GMAIL_SA_KEYFILE, GMAIL_QUERY.\n"
            "Run with --fixtures-dir for offline mode, or configure config/gmail.env "
            "after completing the domain-wide delegation (DWD) grant in Google Workspace."
        )
    cfg: dict[str, str] = {}
    for line in _GMAIL_ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()
    for key in ("GMAIL_DELEGATED_USER", "GMAIL_SA_KEYFILE", "GMAIL_QUERY"):
        if key not in cfg:
            raise RuntimeError(f"Missing key in config/gmail.env: {key}")
    return cfg


# ── EML parser (fixtures mode) ────────────────────────────────────────────────

def _parse_eml(path: Path) -> EmailContext:
    """
    Parse a .eml file into an EmailContext using Python stdlib `email`.
    Handles multipart messages; extracts text/plain and text/html parts.
    """
    raw = path.read_bytes()
    msg: EmailMessage = message_from_bytes(raw, policy=email_policy.default)  # type: ignore[arg-type]

    # Message-ID: strip angle brackets; fall back to filename hash if absent
    raw_mid = msg.get("Message-ID", "") or ""
    message_id = raw_mid.strip().strip("<>") or f"fixture:{path.stem}"

    from_address = str(msg.get("From", "") or "")
    to_address   = str(msg.get("To", "") or "")
    subject      = str(msg.get("Subject", "") or "")

    # received_at: prefer Date header
    received_at: datetime | None = None
    date_str = str(msg.get("Date", "") or "")
    if date_str:
        try:
            from email.utils import parsedate_to_datetime
            received_at = parsedate_to_datetime(date_str)
        except Exception:
            received_at = None

    # Body extraction
    body_text = ""
    body_html = ""
    attachments: list[dict[str, Any]] = []

    if msg.is_multipart():
        for part in msg.walk():
            ct = part.get_content_type()
            cd = str(part.get_content_disposition() or "")
            if "attachment" in cd:
                attachments.append({
                    "filename":     part.get_filename() or "",
                    "content_type": ct,
                    "size_bytes":   None,
                    "content":      None,
                })
                continue
            if ct == "text/plain" and not body_text:
                try:
                    body_text = part.get_content()
                except Exception:
                    body_text = ""
            elif ct == "text/html" and not body_html:
                try:
                    body_html = part.get_content()
                except Exception:
                    body_html = ""
    else:
        ct = msg.get_content_type()
        try:
            content = msg.get_content()
        except Exception:
            content = ""
        if ct == "text/html":
            body_html = content
        else:
            body_text = content

    return EmailContext(
        message_id=message_id,
        from_address=from_address,
        to_address=to_address,
        subject=subject,
        received_at=received_at,
        body_text=body_text or "",
        body_html=body_html or "",
        attachments=attachments,
    )


# ── Gmail API fetcher (live mode — lazy import) ───────────────────────────────

def _fetch_gmail_messages(gmail_cfg: dict[str, str]) -> list[EmailContext]:
    """
    Fetch unread messages from Gmail via the Gmail API with domain-wide delegation.
    google-api-python-client is imported lazily so fixtures mode runs without it.
    """
    try:
        from google.oauth2 import service_account  # type: ignore[import]
        from googleapiclient.discovery import build  # type: ignore[import]
        import base64
    except ImportError:
        raise RuntimeError(
            "google-api-python-client is not installed.  Install it with:\n"
            "  pip install google-api-python-client google-auth\n"
            "Or use --fixtures-dir for offline mode."
        )

    delegated_user = gmail_cfg["GMAIL_DELEGATED_USER"]
    sa_keyfile     = gmail_cfg["GMAIL_SA_KEYFILE"]
    query          = gmail_cfg.get("GMAIL_QUERY", "is:unread label:inbox")

    scopes = ["https://www.googleapis.com/auth/gmail.readonly"]
    creds  = service_account.Credentials.from_service_account_file(
        sa_keyfile, scopes=scopes, subject=delegated_user
    )
    service = build("gmail", "v1", credentials=creds, cache_discovery=False)

    results  = service.users().messages().list(userId="me", q=query).execute()
    messages = results.get("messages", [])
    log.info("Gmail API: %d message(s) matched query %r", len(messages), query)

    contexts: list[EmailContext] = []
    for msg_stub in messages:
        msg_id  = msg_stub["id"]
        raw_msg = (
            service.users().messages()
            .get(userId="me", id=msg_id, format="raw")
            .execute()
        )
        raw_bytes = base64.urlsafe_b64decode(raw_msg["raw"])
        email_msg: EmailMessage = message_from_bytes(raw_bytes, policy=email_policy.default)  # type: ignore[arg-type]

        raw_mid = email_msg.get("Message-ID", "") or ""
        message_id = raw_mid.strip().strip("<>") or f"gmail:{msg_id}"

        from_address = str(email_msg.get("From", "") or "")
        to_address   = str(email_msg.get("To", "") or "")
        subject      = str(email_msg.get("Subject", "") or "")

        received_at: datetime | None = None
        date_str = str(email_msg.get("Date", "") or "")
        if date_str:
            try:
                from email.utils import parsedate_to_datetime
                received_at = parsedate_to_datetime(date_str)
            except Exception:
                received_at = None

        body_text = ""
        body_html = ""
        attachments: list[dict[str, Any]] = []

        if email_msg.is_multipart():
            for part in email_msg.walk():
                ct = part.get_content_type()
                cd = str(part.get_content_disposition() or "")
                if "attachment" in cd:
                    attachments.append({
                        "filename":     part.get_filename() or "",
                        "content_type": ct,
                        "size_bytes":   None,
                        "content":      None,
                    })
                    continue
                if ct == "text/plain" and not body_text:
                    try:
                        body_text = part.get_content()
                    except Exception:
                        body_text = ""
                elif ct == "text/html" and not body_html:
                    try:
                        body_html = part.get_content()
                    except Exception:
                        body_html = ""
        else:
            ct = email_msg.get_content_type()
            try:
                content = email_msg.get_content()
            except Exception:
                content = ""
            if ct == "text/html":
                body_html = content
            else:
                body_text = content

        contexts.append(EmailContext(
            message_id=message_id,
            from_address=from_address,
            to_address=to_address,
            subject=subject,
            received_at=received_at,
            body_text=body_text or "",
            body_html=body_html or "",
            attachments=attachments,
        ))

    return contexts


# ── Environment builder ────────────────────────────────────────────────────────

def _build_env(conn: pymysql.connections.Connection | None) -> ParserEnv:
    """
    Build a ParserEnv for this run.

    Reads (READ-ONLY, allowed even in dry-run):
      - system_settings for dayfirst preference
      - canonical product vocab tables for VocabIndex

    When conn is None (DB unreachable), returns env with vocab=None and
    dayfirst=True (Swiss default).  The generic_vocab parser will decline
    gracefully when vocab is None.
    """
    if conn is None:
        log.warning(
            "DB connection unavailable — env.vocab=None; "
            "generic_vocab parser will decline all messages."
        )
        return ParserEnv(vocab=None, dayfirst=True)

    # Read dayfirst from system_settings
    dayfirst = True
    try:
        with conn.cursor(DictCursor) as c:
            c.execute(
                "SELECT value_num FROM system_settings "
                "WHERE section = 'general' AND key_name = 'date_parse_dayfirst' "
                "LIMIT 1"
            )
            row = c.fetchone()
            if row and row.get("value_num") is not None:
                dayfirst = bool(float(row["value_num"]))
    except Exception as e:
        log.warning("Could not read dayfirst from system_settings: %s — defaulting to True", e)

    # Load product vocab (read-only)
    try:
        vocab = load_vocab(conn)
        log.info(
            "Vocab loaded: %d terms from canonical product tables "
            "(ref_skus, ref_recipes, ref_beer_types, ref_recipe_aliases, ref_sku_aliases).",
            len(vocab.terms),
        )
    except Exception as e:
        log.warning("Could not load product vocab: %s — generic_vocab will decline.", e)
        vocab = None

    return ParserEnv(vocab=vocab, dayfirst=dayfirst)


# ── DB helpers ────────────────────────────────────────────────────────────────

def _already_seen(conn: pymysql.connections.Connection, message_id: str) -> int | None:
    """Return the existing doc_email_messages.id for message_id, or None."""
    with conn.cursor(DictCursor) as c:
        c.execute(
            "SELECT id FROM doc_email_messages WHERE message_id = %s LIMIT 1",
            (message_id,),
        )
        row = c.fetchone()
        return int(row["id"]) if row else None


def _serialise_parsed_order(order: ParsedOrder) -> str:
    """
    Serialise a ParsedOrder to JSON for storage in doc_email_messages.parsed_json.

    Shape:
    {
      "_kind": "parsed_order_hints",
      "_schema_version": 1,
      "customer_hint": "<raw sender string>",
      "requested_date": "<YYYY-MM-DD>" | null,
      "notes": "<free text>",
      "lines": [
        {"sku_hint": "<product hint>", "qty": <float>, "raw": "<verbatim>"},
        ...
      ]
    }
    """
    return json.dumps(
        {
            "_kind":           "parsed_order_hints",
            "_schema_version": 1,
            "customer_hint":   order.customer_hint,
            "requested_date":  order.requested_date.isoformat() if order.requested_date else None,
            "notes":           order.notes,
            "lines": [
                {"sku_hint": ln.sku_hint, "qty": ln.qty, "raw": ln.raw}
                for ln in order.lines
            ],
        },
        ensure_ascii=False,
    )


def _upsert_email_message(
    conn: pymysql.connections.Connection,
    ctx: EmailContext,
    parse_status: str,
    parser_matched: str | None,
    parse_error: str | None,
    parsed_json: str | None,
    apply_mode: bool,
) -> int | None:
    """
    INSERT doc_email_messages row.  Returns the new id (or None in dry-run).

    parse_status  : 'unparsed' | 'parsed' | 'no_match' | 'error' | 'order_created'
    parse_error   : Error message string — ONLY set for parse_status='error'.
                    NULL for all other statuses (not repurposed for hints).
    parsed_json   : Serialised ParsedOrder — set for parse_status='parsed'.
                    NULL for all other statuses.
    """
    received_at_str: str | None = None
    if ctx.received_at is not None:
        try:
            received_at_str = ctx.received_at.strftime("%Y-%m-%d %H:%M:%S")
        except Exception:
            received_at_str = None

    body_format = "html" if (not ctx.body_text and ctx.body_html) else "text"
    raw_body    = ctx.body_text or ctx.body_html or ""

    attachments_json: str | None = None
    if ctx.attachments:
        attachments_json = json.dumps(
            [
                {
                    "filename":     a.get("filename", ""),
                    "content_type": a.get("content_type", ""),
                    "size_bytes":   a.get("size_bytes"),
                }
                for a in ctx.attachments
            ],
            ensure_ascii=False,
        )

    if not apply_mode:
        return None

    with conn.cursor() as c:
        c.execute(
            """
            INSERT INTO doc_email_messages
                (message_id, from_address, to_address, subject, received_at,
                 body_format, raw_body, attachments_json,
                 parser_matched, parse_status, parse_error, parsed_json)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                ctx.message_id,
                ctx.from_address or None,
                ctx.to_address   or None,
                ctx.subject      or None,
                received_at_str,
                body_format,
                raw_body or None,
                attachments_json,
                parser_matched,
                parse_status,
                parse_error,
                parsed_json,
            ),
        )
        conn.commit()
        return c.lastrowid


def _update_email_message(
    conn: pymysql.connections.Connection,
    email_id: int,
    parse_status: str,
    parser_matched: str | None,
    parse_error: str | None,
    parsed_json: str | None,
    apply_mode: bool,
) -> None:
    """Update parse fields on an existing doc_email_messages row (--force path)."""
    if not apply_mode:
        return
    with conn.cursor() as c:
        c.execute(
            """
            UPDATE doc_email_messages
               SET parser_matched = %s,
                   parse_status   = %s,
                   parse_error    = %s,
                   parsed_json    = %s,
                   updated_at     = CURRENT_TIMESTAMP
             WHERE id = %s
            """,
            (parser_matched, parse_status, parse_error, parsed_json, email_id),
        )
    conn.commit()


# ── Per-message processing ─────────────────────────────────────────────────────

def _resolve_parser_name(ctx: EmailContext, env: ParserEnv) -> str | None:
    """
    Re-walk the registry (same first-match logic as dispatch) to recover the
    winning parser's name attribute.  Called only after a successful dispatch.
    """
    from email_parsers import REGISTRY
    for p in REGISTRY:
        if p.matches(ctx, env):
            return p.name
    return None


def process_message(
    ctx: EmailContext,
    conn: pymysql.connections.Connection | None,
    env: ParserEnv,
    apply_mode: bool,
    force: bool,
) -> dict[str, Any]:
    """
    Process one EmailContext through the full pipeline (Model B).

    Returns a result dict describing what happened (for the end-of-run summary).

    Outcomes:
      skipped     — message_id already in DB and --force not set
      no_match    — no parser matched / all matched parsers declined
      parsed      — parser produced a ParsedOrder → parsed_json written
      error       — parser matched but raised an exception
    """
    message_id = ctx.message_id

    # ── Idempotency check ──────────────────────────────────────────────────────
    existing_id = _already_seen(conn, message_id) if (apply_mode and conn is not None) else None
    if existing_id is not None and not force:
        log.debug("  [skip] %s — already in DB (id=%d)", message_id[:60], existing_id)
        return {"outcome": "skipped", "message_id": message_id}

    # ── Dispatch (Model B: decline-fall-through) ───────────────────────────────
    parse_status:   str           = "no_match"
    parser_matched: str | None    = None
    parse_error:    str | None    = None   # ONLY for parse_status='error'
    parsed_json:    str | None    = None   # ONLY for parse_status='parsed'
    parsed_order:   ParsedOrder | None = None

    try:
        result = dispatch(ctx, env)
    except Exception as exc:
        parse_status = "error"
        parse_error  = f"{type(exc).__name__}: {exc}"
        log.warning("  [error] %s — parser raised: %s", message_id[:60], parse_error)
    else:
        if result is None:
            parse_status = "no_match"
            log.info("  [no_match] %s — no parser matched or all declined", message_id[:60])
        else:
            parsed_order   = result
            parse_status   = "parsed"
            parser_matched = _resolve_parser_name(ctx, env)
            parsed_json    = _serialise_parsed_order(result)
            log.info(
                "  [parsed] %s — parser=%s customer_hint=%r lines=%d",
                message_id[:60],
                parser_matched,
                result.customer_hint,
                len(result.lines),
            )

    # ── DB write (--apply only) ────────────────────────────────────────────────
    if apply_mode and conn is not None:
        if existing_id is not None and force:
            _update_email_message(
                conn, existing_id, parse_status, parser_matched,
                parse_error, parsed_json, apply_mode
            )
            email_db_id = existing_id
        else:
            email_db_id = _upsert_email_message(
                conn, ctx, parse_status, parser_matched,
                parse_error, parsed_json, apply_mode
            )
        log.debug("  → doc_email_messages.id=%s", email_db_id)
    else:
        action = "UPDATE" if (existing_id is not None and force) else "INSERT"
        log.info(
            "  [dry-run] Would %s doc_email_messages: message_id=%s parse_status=%s parser=%s",
            action, message_id[:60], parse_status, parser_matched or "NULL",
        )

    return {
        "outcome":        parse_status if parse_status != "unparsed" else "no_match",
        "message_id":     message_id,
        "parser_matched": parser_matched,
        "parse_error":    parse_error if parse_status == "error" else None,
        "parsed_json":    parsed_json,
    }


# ── Report ────────────────────────────────────────────────────────────────────

def _print_report(
    results:    list[dict[str, Any]],
    apply_mode: bool,
    source:     str,
    env:        ParserEnv,
) -> None:
    n_total    = len(results)
    n_skipped  = sum(1 for r in results if r["outcome"] == "skipped")
    n_parsed   = sum(1 for r in results if r["outcome"] == "parsed")
    n_no_match = sum(1 for r in results if r["outcome"] == "no_match")
    n_error    = sum(1 for r in results if r["outcome"] == "error")

    vocab_size = len(env.vocab.terms) if env.vocab else 0

    mode_label = "** APPLY **" if apply_mode else "DRY-RUN"
    print()
    print("=" * 72)
    print("  EMAIL ORDER INGEST REPORT  (Model B)")
    print("=" * 72)
    print(f"  Script version : {SCRIPT_VERSION}")
    print(f"  Mode           : {mode_label}")
    print(f"  Source         : {source}")
    print(f"  Timestamp      : {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S UTC')}")
    print(f"  Vocab terms    : {vocab_size}  (live read from canonical tables)")
    print(f"  Day-first      : {env.dayfirst}")
    print()
    print("── SUMMARY ──────────────────────────────────────────────────────────")
    print(f"  Total messages : {n_total}")
    print(f"  Skipped        : {n_skipped}  (already in DB, --force not set)")
    print(f"  Parsed         : {n_parsed}   (ParsedOrder → parsed_json; needs validation)")
    print(f"  No match       : {n_no_match} (no parser matched/all declined → review)")
    print(f"  Error          : {n_error}    (parser raised → review bucket)")
    print()
    if n_parsed:
        print("── PARSED (parsed_json written — logistics validation required) ──────")
        for r in results:
            if r["outcome"] == "parsed":
                # Pretty-print the parsed hints
                print(f"  msg : {r['message_id'][:60]}")
                print(f"  prsr: {r['parser_matched']}")
                if r.get("parsed_json"):
                    try:
                        hints = json.loads(r["parsed_json"])
                        print(f"  cust: {hints.get('customer_hint','')}")
                        print(f"  date: {hints.get('requested_date','(none)')}")
                        for ln in hints.get("lines", []):
                            print(f"    L  sku={ln['sku_hint']}  qty={ln['qty']}  raw={ln['raw'][:60]}")
                        if hints.get("notes"):
                            print(f"  note: {hints['notes'][:120]}")
                    except Exception:
                        print(f"  json: {r['parsed_json'][:120]}")
                print()
    if n_no_match:
        print("── NO MATCH (no parser matched / all declined → review bucket) ───────")
        for r in results:
            if r["outcome"] == "no_match":
                print(f"  {r['message_id'][:60]}")
        print()
    if n_error:
        print("── ERRORS (parser raised — inspect parse_error field) ───────────────")
        for r in results:
            if r["outcome"] == "error":
                print(f"  {r['message_id'][:60]}  {r['parse_error']}")
        print()
    print("── NO LLM FALLBACK — no-match emails go to review, never to LLM ────")
    print("── NO ORD_ORDERS WRITE — ord_orders created at logistics validation ──")
    print("── NO GUESSED FKs — parsed_json holds hints only; IDs resolved later ─")
    print("=" * 72)
    print()


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Email orders (commandes@lanebuleuse.ch) → doc_email_messages (Model B).\n"
            "Dry-run by default — use --apply to write to the database.\n"
            "Parsed hints go to parsed_json; parse_error is reserved for real errors only.\n"
            "ord_orders rows are NEVER written at parse time."
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--apply", action="store_true",
        help="Write to database (default: dry-run, no writes).",
    )
    parser.add_argument(
        "--fixtures-dir", metavar="PATH",
        help=(
            "Offline/dev mode: read *.eml files from PATH instead of the Gmail API. "
            "Works without Gmail credentials.  Vocab is still loaded from DB (read-only)."
        ),
    )
    parser.add_argument(
        "--force", action="store_true",
        help=(
            "Re-process messages already present in doc_email_messages "
            "(default: skip existing message_ids)."
        ),
    )
    parser.add_argument(
        "--limit", type=int, default=0,
        help="Process at most N messages (0 = all).",
    )
    args = parser.parse_args()

    apply_mode   = args.apply
    fixtures_dir = Path(args.fixtures_dir) if args.fixtures_dir else None
    force        = args.force
    limit        = args.limit
    mode_label   = "** APPLY **" if apply_mode else "DRY-RUN"

    print(f"\nEmail Order Ingest — v{SCRIPT_VERSION}  (Model B)")
    print(f"Mode: {mode_label}")
    print()

    # ── [1/4] Load email messages ─────────────────────────────────────────────
    if fixtures_dir is not None:
        source_label = f"fixtures-dir:{fixtures_dir}"
        log.info("[1/4] Loading .eml files from %s …", fixtures_dir)
        if not fixtures_dir.exists():
            log.error("fixtures-dir does not exist: %s", fixtures_dir)
            sys.exit(1)
        eml_files = sorted(fixtures_dir.glob("*.eml"))
        if not eml_files:
            log.warning("No .eml files found in %s — nothing to do.", fixtures_dir)
            return
        log.info("  Found %d .eml file(s).", len(eml_files))
        if limit:
            eml_files = eml_files[:limit]
            log.info("  --limit %d applied: processing %d file(s).", limit, len(eml_files))
        contexts: list[EmailContext] = []
        for f in eml_files:
            try:
                ctx = _parse_eml(f)
                contexts.append(ctx)
                log.info("  Parsed: %s → message_id=%s", f.name, ctx.message_id[:60])
            except Exception as exc:
                log.error("  Failed to parse %s: %s", f.name, exc)
    else:
        source_label = "gmail-api"
        log.info("[1/4] Live Gmail API mode …")
        try:
            gmail_cfg = _load_gmail_env()
        except RuntimeError as exc:
            print(f"\nERROR: Gmail API not yet authorized.\n{exc}\n", file=sys.stderr)
            sys.exit(1)
        log.info("  Fetching messages (query=%r) …", gmail_cfg.get("GMAIL_QUERY"))
        try:
            contexts = _fetch_gmail_messages(gmail_cfg)
        except RuntimeError as exc:
            print(f"\nERROR: {exc}\n", file=sys.stderr)
            sys.exit(1)
        if limit:
            contexts = contexts[:limit]

    # ── [2/4] Open DB connection for vocab + (if apply) writes ───────────────
    # Vocab read is READ-ONLY and always attempted (even in dry-run) because
    # reads are allowed at all times; only writes are gated by --apply.
    conn: pymysql.connections.Connection | None = None
    try:
        log.info("[2/4] Connecting to DB for vocab read …")
        cfg  = load_config()
        conn = pymysql.connect(
            host=cfg.db_host, port=cfg.db_port,
            user=cfg.db_user, password=cfg.db_password,
            database=cfg.db_name,
            charset="utf8mb4", cursorclass=DictCursor, autocommit=False,
        )
    except Exception as exc:
        log.warning(
            "DB connection failed (%s) — vocab unavailable; "
            "generic_vocab parser will decline all messages.", exc
        )
        conn = None

    # ── [3/4] Build parser environment (vocab + dayfirst) ────────────────────
    log.info("[3/4] Building parser environment …")
    env = _build_env(conn)

    # In dry-run without --apply, we opened a DB connection only for vocab.
    # Wrap up cleanly: process messages using the env, then close.
    if not apply_mode and conn is not None:
        # We'll keep conn open for potential _already_seen checks in process_message,
        # but no INSERT/UPDATE will be called (apply_mode=False guards all writes).
        pass

    # ── [4/4] Process each message ────────────────────────────────────────────
    log.info("[4/4] Processing %d message(s) …", len(contexts))
    results: list[dict[str, Any]] = []
    try:
        for ctx in contexts:
            result = process_message(ctx, conn, env, apply_mode, force)
            results.append(result)
    except Exception as exc:
        if conn is not None:
            conn.rollback()
        log.error("Unexpected error during processing: %s", exc)
        raise
    finally:
        if conn is not None:
            conn.close()

    # ── End-of-run report ─────────────────────────────────────────────────────
    _print_report(results, apply_mode, source_label, env)


if __name__ == "__main__":
    main()
