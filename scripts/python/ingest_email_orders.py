#!/usr/bin/env python3
"""
ingest_email_orders.py — Email orders (commandes@lanebuleuse.ch) → doc_email_messages.

Reads inbound order emails, classifies them via deterministic per-sender parsers
(scripts/python/email_parsers/), and persists raw messages + parse results to the
doc_email_messages table.  Logistics validates before any ord_orders write or BC push.

SOURCE MODES
────────────
  --fixtures-dir PATH
      Offline / dev mode.  Reads *.eml files from PATH using Python stdlib `email`.
      Works NOW without any Gmail API credentials.  Use this for development and
      regression testing.

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
  2. Upsert doc_email_messages (idempotent on message_id UNIQUE; skip if present
     unless --force).
  3. Dispatch through the parser registry:
       None (no match) → parse_status='no_match', parser_matched=NULL.
       ParsedOrder     → parse_status='parsed', parser_matched=<parser.name>.
                         Parsed order hints stored as JSON in parse_error (temp
                         staging field — see NOTE ON LINE HINTS below).
       Exception       → parse_status='error', parse_error=<message>.

NOTE ON LINE HINTS (customer_hint / sku_hint / lines)
──────────────────────────────────────────────────────
  The ord_orders table requires customer_id_fk IS NOT NULL (when order_type='customer')
  and requested_date NOT NULL.  A parsed email carries only HINTS — raw customer name
  and SKU strings that are NOT yet resolved to database IDs.  Inserting a candidate
  ord_orders row without a resolved customer_id_fk would violate the schema CHECK
  constraint.

  Therefore this script does NOT write ord_orders rows.  Instead, parsed hints
  are stored as a JSON blob in doc_email_messages.parse_error (which is NULL for
  successful parses and free to repurpose here).  The operator UI will read this
  column (when parse_status='parsed') to surface the hints for manual validation
  before an ord_orders row is created.

  TODO (future migration): add a dedicated `parsed_order_json` JSON column to
  doc_email_messages to store hints without repurposing parse_error.  The column
  rename is a one-line schema change; the poller and UI adapt in the same PR.

NO LLM FALLBACK — EVER.
  A no-match email → parse_status='no_match' → operator review.
  A parse error    → parse_status='error'    → operator review.
  Neither path calls a language model.  This is a hard architectural constraint.

NO BC PUSH.
  This script does not write to ord_orders and does not push anything to Business
  Central.  It is the raw-ingestion step only.  Logistics validates from the UI.

DISARM CONVENTION (mirrors ingest_bc_sales_orders.py):
  --dry-run is the DEFAULT.  Prints a report, writes nothing.
  --apply performs DB writes.

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
from email.headerregistry import Address
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
from email_parsers.base import EmailContext, ParsedOrder  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

SCRIPT_VERSION = "1.0.0"
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

    # received_at: prefer Received header date; fall back to Date header
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
                    "content":      None,  # not downloaded in fixtures mode
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

    # List matching messages
    results  = service.users().messages().list(userId="me", q=query).execute()
    messages = results.get("messages", [])
    log.info("Gmail API: %d message(s) matched query %r", len(messages), query)

    contexts: list[EmailContext] = []
    for msg_stub in messages:
        msg_id = msg_stub["id"]
        raw_msg = (
            service.users().messages()
            .get(userId="me", id=msg_id, format="raw")
            .execute()
        )
        raw_bytes = base64.urlsafe_b64decode(raw_msg["raw"])
        # Reuse the EML parser path — Gmail raw format is RFC 5322 bytes
        # Write to a temp path in memory via BytesIO-backed fake path is not
        # needed — message_from_bytes handles bytes directly.
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


# ── DB helpers ────────────────────────────────────────────────────────────────

def _already_seen(conn: pymysql.connections.Connection, message_id: str) -> int | None:
    """Return the existing doc_email_messages.id for message_id, or None."""
    with conn.cursor() as c:
        c.execute(
            "SELECT id FROM doc_email_messages WHERE message_id = %s LIMIT 1",
            (message_id,),
        )
        row = c.fetchone()
        return int(row["id"]) if row else None


def _upsert_email_message(
    conn: pymysql.connections.Connection,
    ctx: EmailContext,
    parse_status: str,
    parser_matched: str | None,
    parse_error: str | None,
    apply_mode: bool,
) -> int | None:
    """
    INSERT doc_email_messages row.  Returns the new id (or None in dry-run).

    parse_status: 'unparsed' | 'parsed' | 'no_match' | 'error'
    parse_error : For parse_status='parsed' this holds the ParsedOrder hints as JSON
                  (dual-use of the column; see NOTE ON LINE HINTS in module docstring).
                  For parse_status='error' this holds the error message string.
                  NULL for 'no_match' and 'unparsed'.
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
                 parser_matched, parse_status, parse_error)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
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
                   updated_at     = CURRENT_TIMESTAMP
             WHERE id = %s
            """,
            (parser_matched, parse_status, parse_error, email_id),
        )
    conn.commit()


# ── Per-message processing ─────────────────────────────────────────────────────

def _hints_to_json(order: ParsedOrder) -> str:
    """
    Serialise a ParsedOrder to the JSON blob stored in doc_email_messages.parse_error
    when parse_status='parsed'.

    This is a temporary measure — see NOTE ON LINE HINTS in the module docstring.
    The JSON shape is documented here so the UI can read it:

    {
      "_kind": "parsed_order_hints",
      "customer_hint": "<raw sender name>",
      "requested_date": "<YYYY-MM-DD>" | null,
      "notes": "<free text>",
      "lines": [
        {"sku_hint": "<raw sku string>", "qty": <float>, "raw": "<verbatim>"},
        ...
      ]
    }
    """
    return json.dumps(
        {
            "_kind":          "parsed_order_hints",
            "customer_hint":  order.customer_hint,
            "requested_date": order.requested_date.isoformat() if order.requested_date else None,
            "notes":          order.notes,
            "lines": [
                {"sku_hint": ln.sku_hint, "qty": ln.qty, "raw": ln.raw}
                for ln in order.lines
            ],
        },
        ensure_ascii=False,
    )


def process_message(
    ctx: EmailContext,
    conn: pymysql.connections.Connection | None,
    apply_mode: bool,
    force: bool,
) -> dict[str, Any]:
    """
    Process one EmailContext through the full pipeline.
    Returns a result dict describing what happened (for the end-of-run summary).

    Outcomes:
      skipped     — message_id already in DB and --force not set
      no_match    — no parser matched
      parsed      — parser matched and produced a ParsedOrder
      error       — parser matched but parse() raised
    """
    message_id = ctx.message_id

    # ── Idempotency check ──────────────────────────────────────────────────────
    existing_id = _already_seen(conn, message_id) if (apply_mode and conn is not None) else None
    if existing_id is not None and not force:
        log.debug("  [skip] %s — already in DB (id=%d)", message_id[:60], existing_id)
        return {"outcome": "skipped", "message_id": message_id}

    # ── Dispatch ───────────────────────────────────────────────────────────────
    parse_status:   str          = "no_match"
    parser_matched: str | None   = None
    parse_error:    str | None   = None
    parsed_order:   ParsedOrder | None = None

    try:
        result = dispatch(ctx)
    except Exception as exc:
        parse_status   = "error"
        parse_error    = f"{type(exc).__name__}: {exc}"
        log.warning("  [error] %s — parser raised: %s", message_id[:60], parse_error)
    else:
        if result is None:
            parse_status = "no_match"
            log.info("  [no_match] %s — no parser matched", message_id[:60])
        else:
            parsed_order   = result
            parse_status   = "parsed"
            parser_matched = result.__class__.__module__.split(".")[-1]  # fallback
            # The registry parsers are instances of SenderParser subclasses;
            # parser.name is the canonical name set on the class.
            # dispatch() doesn't return the parser name directly, so we recover it
            # from the registry by re-checking matches() (idempotent, cheap).
            # This is the same "first match wins" logic as the dispatcher.
            from email_parsers import REGISTRY
            for p in REGISTRY:
                if p.matches(ctx):
                    parser_matched = p.name
                    break
            parse_error = _hints_to_json(result)
            log.info(
                "  [parsed] %s — parser=%s customer_hint=%r lines=%d",
                message_id[:60],
                parser_matched,
                result.customer_hint,
                len(result.lines),
            )

    # ── DB write ───────────────────────────────────────────────────────────────
    if apply_mode and conn is not None:
        if existing_id is not None and force:
            # --force: update existing row
            _update_email_message(
                conn, existing_id, parse_status, parser_matched, parse_error, apply_mode
            )
            email_db_id = existing_id
        else:
            email_db_id = _upsert_email_message(
                conn, ctx, parse_status, parser_matched, parse_error, apply_mode
            )
        log.debug("  → doc_email_messages.id=%s", email_db_id)
    else:
        # Dry-run: report what would happen
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
    }


# ── Report ────────────────────────────────────────────────────────────────────

def _print_report(
    results:    list[dict[str, Any]],
    apply_mode: bool,
    source:     str,
) -> None:
    n_total    = len(results)
    n_skipped  = sum(1 for r in results if r["outcome"] == "skipped")
    n_parsed   = sum(1 for r in results if r["outcome"] == "parsed")
    n_no_match = sum(1 for r in results if r["outcome"] == "no_match")
    n_error    = sum(1 for r in results if r["outcome"] == "error")

    mode_label = "** APPLY **" if apply_mode else "DRY-RUN"
    print()
    print("=" * 72)
    print("  EMAIL ORDER INGEST REPORT")
    print("=" * 72)
    print(f"  Script version : {SCRIPT_VERSION}")
    print(f"  Mode           : {mode_label}")
    print(f"  Source         : {source}")
    print(f"  Timestamp      : {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S UTC')}")
    print()
    print("── SUMMARY ──────────────────────────────────────────────────────────")
    print(f"  Total messages : {n_total}")
    print(f"  Skipped        : {n_skipped}  (already in DB, --force not set)")
    print(f"  Parsed         : {n_parsed}   (parser matched → hints stored, needs validation)")
    print(f"  No match       : {n_no_match} (no parser matched → review bucket)")
    print(f"  Error          : {n_error}    (parser raised → review bucket)")
    print()
    if n_parsed:
        print("── PARSED (hints stored — logistics validation required) ────────────")
        for r in results:
            if r["outcome"] == "parsed":
                print(f"  {r['message_id'][:60]}  parser={r['parser_matched']}")
        print()
    if n_no_match:
        print("── NO MATCH (no parser registered for sender) ───────────────────────")
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
    print("── NO BC PUSH — ord_orders writes gated on logistics validation ──────")
    print("=" * 72)
    print()


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Email orders (commandes@lanebuleuse.ch) → doc_email_messages.\n"
            "Dry-run by default — use --apply to write to the database."
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
            "Works without any Gmail credentials."
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

    apply_mode    = args.apply
    fixtures_dir  = Path(args.fixtures_dir) if args.fixtures_dir else None
    force         = args.force
    limit         = args.limit
    mode_label    = "** APPLY **" if apply_mode else "DRY-RUN"

    print(f"\nEmail Order Ingest — v{SCRIPT_VERSION}")
    print(f"Mode: {mode_label}")
    print()

    # ── [1/3] Load email messages ─────────────────────────────────────────────
    if fixtures_dir is not None:
        # Offline / dev mode
        source_label = f"fixtures-dir:{fixtures_dir}"
        log.info("[1/3] Loading .eml files from %s …", fixtures_dir)
        if not fixtures_dir.exists():
            log.error("fixtures-dir does not exist: %s", fixtures_dir)
            sys.exit(1)
        eml_files = sorted(fixtures_dir.glob("*.eml"))
        if not eml_files:
            log.warning("No .eml files found in %s — nothing to do.", fixtures_dir)
            _print_report([], apply_mode, source_label)
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
        # Live Gmail API mode (gated on config/gmail.env)
        source_label = "gmail-api"
        log.info("[1/3] Live Gmail API mode …")
        try:
            gmail_cfg = _load_gmail_env()
        except RuntimeError as exc:
            print(
                f"\nERROR: Gmail API not yet authorized.\n{exc}\n",
                file=sys.stderr,
            )
            sys.exit(1)
        log.info("  Fetching messages (query=%r) …", gmail_cfg.get("GMAIL_QUERY"))
        try:
            contexts = _fetch_gmail_messages(gmail_cfg)
        except RuntimeError as exc:
            print(f"\nERROR: {exc}\n", file=sys.stderr)
            sys.exit(1)
        if limit:
            contexts = contexts[:limit]

    # ── [2/3] Connect to DB (only when --apply; dry-run needs no DB) ─────────
    conn: pymysql.connections.Connection | None = None
    if apply_mode:
        log.info("[2/3] Connecting to DB …")
        cfg  = load_config()
        conn = pymysql.connect(
            host=cfg.db_host, port=cfg.db_port,
            user=cfg.db_user, password=cfg.db_password,
            database=cfg.db_name,
            charset="utf8mb4", cursorclass=DictCursor, autocommit=False,
        )
    else:
        log.info("[2/3] Dry-run — skipping DB connection.")

    # ── [3/3] Process each message ────────────────────────────────────────────
    log.info("[3/3] Processing %d message(s) …", len(contexts))
    results: list[dict[str, Any]] = []
    try:
        for ctx in contexts:
            result = process_message(ctx, conn, apply_mode, force)
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
    _print_report(results, apply_mode, source_label)


if __name__ == "__main__":
    main()
