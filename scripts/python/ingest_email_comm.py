#!/usr/bin/env python3
"""
ingest_email_comm.py — Gmail comm inbox → comm_threads / comm_messages / comm_message_docs.

Reads inbound and outbound emails from the comm Gmail inbox (e.g. info@lanebuleuse.ch),
applies a privacy filter (drops internal-only and bulk/newsletter messages), resolves
counterparty identity via comm_address_pins, threads by Gmail threadId, and persists
raw messages (with any attachments) to the comm_* tables.

DISARM CONVENTION:
  --dry-run is the DEFAULT.  Prints a plan, writes nothing.
  --apply performs DB writes and attachment downloads.

Usage:
  # Dry-run (safe preview):
  python3 scripts/python/ingest_email_comm.py

  # Apply (write to DB + download attachments):
  python3 scripts/python/ingest_email_comm.py --apply

  # Limit for smoke testing:
  python3 scripts/python/ingest_email_comm.py --limit 10

  # Override query:
  python3 scripts/python/ingest_email_comm.py --query "is:unread after:2026/01/01"
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import logging
import os
import re
import sys
import uuid
from datetime import datetime, timezone
from email.utils import parseaddr, getaddresses, parsedate_to_datetime
from pathlib import Path
from typing import Any

# Allow running from /var/www/maltytask (same pattern as ingest_email_orders.py)
_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

import pymysql  # noqa: E402 — after sys.path fix
from pymysql.cursors import DictCursor

from lib_config import load as load_config  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

SCRIPT_VERSION = "1.0.0"
ACTOR = "email-comm-ingest"
_COMM_ENV_PATH = Path("/var/www/maltytask/config/gmail-comm.env")
INTERNAL_DOMAIN = "lanebuleuse.ch"
ATTACHMENTS_BASE = Path("/var/www/maltytask/data/email-attachments")

# ── Logging ───────────────────────────────────────────────────────────────────

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    stream=sys.stdout,
)
log = logging.getLogger(__name__)


# ── Config loader ──────────────────────────────────────────────────────────────

def _load_comm_env(path: Path) -> dict[str, str]:
    """Load KEY=VALUE env file; raise RuntimeError on missing required keys."""
    if not path.exists():
        raise RuntimeError(
            f"Gmail comm credentials not found at {path}.\n"
            "Expected keys: GMAIL_COMM_DELEGATED_USER, GMAIL_SA_KEYFILE, GMAIL_COMM_QUERY.\n"
            "Create config/gmail-comm.env with these keys after completing the\n"
            "domain-wide delegation (DWD) grant in Google Workspace."
        )
    cfg: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()
    for key in ("GMAIL_COMM_DELEGATED_USER", "GMAIL_SA_KEYFILE", "GMAIL_COMM_QUERY"):
        if key not in cfg:
            raise RuntimeError(f"Missing key in config/gmail-comm.env: {key}")
    return cfg


# ── Gmail API fetcher (lazy import) ───────────────────────────────────────────

def _fetch_gmail_message_stubs(
    gmail_cfg: dict[str, str],
    limit: int,
) -> tuple[Any, list[dict[str, Any]]]:
    """
    Fetch message stubs (id + threadId) from Gmail with pagination.
    Returns (service, stubs_list).
    google-api-python-client is imported lazily.
    """
    try:
        from google.oauth2 import service_account  # type: ignore[import]
        from googleapiclient.discovery import build  # type: ignore[import]
    except ImportError:
        raise RuntimeError(
            "google-api-python-client is not installed.  Install it with:\n"
            "  pip install google-api-python-client google-auth\n"
        )

    delegated_user = gmail_cfg["GMAIL_COMM_DELEGATED_USER"]
    sa_keyfile = gmail_cfg["GMAIL_SA_KEYFILE"]
    query = gmail_cfg.get("GMAIL_COMM_QUERY", "is:unread label:inbox")

    scopes = ["https://www.googleapis.com/auth/gmail.readonly"]
    creds = service_account.Credentials.from_service_account_file(
        sa_keyfile, scopes=scopes, subject=delegated_user
    )
    service = build("gmail", "v1", credentials=creds, cache_discovery=False)

    stubs: list[dict[str, Any]] = []
    page_token: str | None = None

    while True:
        kwargs: dict[str, Any] = {"userId": "me", "q": query}
        if page_token:
            kwargs["pageToken"] = page_token
        response = service.users().messages().list(**kwargs).execute()
        page_stubs = response.get("messages", [])
        stubs.extend(page_stubs)
        page_token = response.get("nextPageToken")
        if not page_token:
            break
        # Early exit if limit already reached (avoid fetching more pages)
        if limit > 0 and len(stubs) >= limit:
            break

    log.info("Gmail API: %d message stub(s) matched query %r", len(stubs), query)

    # Apply limit to stub list BEFORE fetching full message details
    if limit > 0 and len(stubs) > limit:
        stubs = stubs[:limit]
        log.info("--limit %d applied: will fetch details for %d message(s).", limit, len(stubs))

    return service, stubs


def _fetch_message_full(service: Any, gmail_msg_id: str) -> dict[str, Any]:
    """Fetch full message payload via Gmail API."""
    return service.users().messages().get(
        userId="me", id=gmail_msg_id, format="full"
    ).execute()


def _get_header(headers: list[dict[str, str]], name: str) -> str:
    """Extract a header value by name (case-insensitive), or empty string.

    Gmail API returns headers with lowercase keys ('name', 'value').
    Handle both lowercase and title-case for safety.
    """
    name_lower = name.lower()
    for h in headers:
        # Gmail API uses lowercase 'name'/'value'; tolerate 'Name'/'Value' too
        key = h.get("name") or h.get("Name") or ""
        if key.lower() == name_lower:
            return (h.get("value") or h.get("Value") or "") or ""
    return ""


def _decode_body_part(data: str) -> str:
    """Decode base64url-encoded Gmail body data to string."""
    try:
        return base64.urlsafe_b64decode(data + "==").decode("utf-8", errors="replace")
    except Exception:
        return ""


def _walk_parts(payload: dict[str, Any]) -> tuple[str, str, list[dict[str, Any]]]:
    """
    Recursively walk payload parts to extract:
      - body_text (text/plain)
      - body_html (text/html)
      - attachments (list of {filename, mime_type, attachment_id})
    Prefers text/html for body, falls back to text/plain.
    """
    body_text = ""
    body_html = ""
    attachments: list[dict[str, Any]] = []

    def _recurse(part: dict[str, Any]) -> None:
        nonlocal body_text, body_html

        mime_type = part.get("mimeType", "")
        filename = part.get("filename", "") or ""
        body = part.get("body", {}) or {}
        attachment_id = body.get("attachmentId")
        body_data = body.get("data", "")

        # Attachment: has filename AND attachmentId
        if filename and attachment_id:
            attachments.append({
                "filename": filename,
                "mime_type": mime_type,
                "attachment_id": attachment_id,
            })
            return

        # Recurse into sub-parts
        sub_parts = part.get("parts", [])
        if sub_parts:
            for sub in sub_parts:
                _recurse(sub)
            return

        # Leaf part with inline body data
        if body_data:
            decoded = _decode_body_part(body_data)
            if mime_type == "text/html" and not body_html:
                body_html = decoded
            elif mime_type == "text/plain" and not body_text:
                body_text = decoded

    # Handle top-level body directly (no parts)
    top_parts = payload.get("parts", [])
    if top_parts:
        for part in top_parts:
            _recurse(part)
    else:
        top_body = payload.get("body", {}) or {}
        top_data = top_body.get("data", "")
        if top_data:
            mime_type = payload.get("mimeType", "")
            decoded = _decode_body_part(top_data)
            if mime_type == "text/html":
                body_html = decoded
            else:
                body_text = decoded

    return body_text, body_html, attachments


def _parse_sent_at(date_header: str) -> datetime:
    """Parse RFC 2822 Date header to UTC datetime. Falls back to now() with a warning."""
    if not date_header:
        log.warning("Missing Date header — using current UTC time as sent_at")
        return datetime.now(timezone.utc)
    try:
        dt = parsedate_to_datetime(date_header)
        # Ensure timezone-aware
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return dt
    except Exception as exc:
        log.warning("Unparseable Date header %r: %s — using current UTC time", date_header, exc)
        return datetime.now(timezone.utc)


# ── Privacy filter ─────────────────────────────────────────────────────────────

def _extract_all_addresses(headers: list[dict[str, str]]) -> list[str]:
    """
    Parse all email addresses from From, To, Cc headers.
    Returns list of lowercase email strings.
    """
    raw_parts: list[str] = []
    for hname in ("From", "To", "Cc"):
        val = _get_header(headers, hname)
        if val:
            raw_parts.append(val)
    # getaddresses handles comma-separated lists and "Name <email>" form
    pairs = getaddresses(raw_parts)
    return [addr.lower().strip() for _, addr in pairs if addr.strip()]


def _is_internal(addr: str) -> bool:
    return f"@{INTERNAL_DOMAIN}" in addr.lower()


def _privacy_check(
    headers: list[dict[str, str]],
) -> tuple[str, str]:
    """
    Apply privacy filter.
    Returns (decision, reason):
      decision = 'keep' | 'drop'
      reason   = '' | 'internal' | 'bulk'
    """
    all_addresses = _extract_all_addresses(headers)
    external = [a for a in all_addresses if not _is_internal(a)]

    if not external:
        return "drop", "internal"

    # Check List-Unsubscribe header
    list_unsub = _get_header(headers, "List-Unsubscribe")
    try:
        if list_unsub and list_unsub.strip():
            return "drop", "bulk"
    except Exception:
        pass  # parse failure → treat as absent

    return "keep", ""


# ── Direction detection ────────────────────────────────────────────────────────

def _detect_direction(headers: list[dict[str, str]]) -> str:
    """
    Detect message direction.
    'out' if From address ends with @lanebuleuse.ch, 'in' otherwise.
    """
    from_raw = _get_header(headers, "From")
    _, from_email = parseaddr(from_raw)
    if from_email and from_email.lower().endswith(f"@{INTERNAL_DOMAIN}"):
        return "out"
    return "in"


# ── Subject cleaning (strip Re:/Fwd: for thread subject) ─────────────────────

def _clean_subject(subject: str) -> str:
    """Strip Re:/Fwd:/AW:/RE:/FW: prefixes for thread-level subject storage."""
    pattern = re.compile(r"^(re|fwd?|aw|fw)\s*:\s*", re.IGNORECASE)
    cleaned = subject.strip()
    while pattern.match(cleaned):
        cleaned = pattern.sub("", cleaned).strip()
    return cleaned


# ── Entity resolution via comm_address_pins ───────────────────────────────────

def _resolve_entity(
    conn: pymysql.connections.Connection,
    headers: list[dict[str, str]],
    direction: str,
) -> tuple[int | None, int | None, str | None]:
    """
    Resolve counterparty identity via comm_address_pins.
    Returns (resolved_supplier_id, resolved_customer_id, primary_email).
    No domain-based fallback, no fuzzy matching — only exact pin lookup.
    """
    all_addresses = _extract_all_addresses(headers)
    external = [a for a in all_addresses if not _is_internal(a)]

    if not external:
        return None, None, None

    # Primary counterparty: first external in From (if inbound), first in To (if outbound)
    if direction == "in":
        from_raw = _get_header(headers, "From")
        _, from_email = parseaddr(from_raw)
        from_email_lower = from_email.lower().strip()
        primary = from_email_lower if (from_email_lower and not _is_internal(from_email_lower)) else (external[0] if external else None)
    else:
        to_raw = _get_header(headers, "To")
        to_addrs = [addr.lower().strip() for _, addr in getaddresses([to_raw]) if addr.strip()]
        to_external = [a for a in to_addrs if not _is_internal(a)]
        primary = to_external[0] if to_external else (external[0] if external else None)

    if not primary:
        return None, None, None

    try:
        with conn.cursor(DictCursor) as c:
            c.execute(
                "SELECT supplier_id_fk, customer_id_fk FROM comm_address_pins WHERE email = %s LIMIT 1",
                (primary,),
            )
            row = c.fetchone()
        if row:
            sup_id = row["supplier_id_fk"] if row["supplier_id_fk"] else None
            cust_id = row["customer_id_fk"] if row["customer_id_fk"] else None
            if sup_id:
                return int(sup_id), None, primary
            if cust_id:
                return None, int(cust_id), primary
    except Exception as exc:
        log.warning("comm_address_pins lookup failed for %r: %s", primary, exc)

    return None, None, primary


# ── Thread management ──────────────────────────────────────────────────────────

def _get_or_create_thread(
    conn: pymysql.connections.Connection,
    gmail_thread_id: str,
    subject: str,
    resolved_supplier_id: int | None,
    resolved_customer_id: int | None,
    sent_at: datetime,
    apply_mode: bool,
) -> int | None:
    """
    Get existing or create new comm_threads row.
    Returns thread DB id (int), or None in dry-run mode.
    """
    sent_at_str = sent_at.strftime("%Y-%m-%d %H:%M:%S")
    cleaned_subject = _clean_subject(subject)

    if apply_mode:
        with conn.cursor(DictCursor) as c:
            # INSERT IGNORE — won't overwrite if thread already exists
            c.execute(
                """
                INSERT IGNORE INTO comm_threads
                    (supplier_id_fk, customer_id_fk, subject, gmail_thread_id, last_message_at)
                VALUES (%s, %s, %s, %s, %s)
                """,
                (resolved_supplier_id, resolved_customer_id, cleaned_subject, gmail_thread_id, sent_at_str),
            )
            # Get the thread id regardless of whether INSERT happened
            c.execute(
                "SELECT id FROM comm_threads WHERE gmail_thread_id = %s",
                (gmail_thread_id,),
            )
            row = c.fetchone()
            thread_db_id = int(row["id"]) if row else None

        if thread_db_id is None:
            log.warning("Could not get comm_threads.id for gmail_thread_id=%r", gmail_thread_id)
            return None

        # Update last_message_at if this message is newer
        with conn.cursor() as c:
            c.execute(
                """
                UPDATE comm_threads
                   SET last_message_at = %s
                 WHERE gmail_thread_id = %s
                   AND (last_message_at IS NULL OR last_message_at < %s)
                """,
                (sent_at_str, gmail_thread_id, sent_at_str),
            )

        return thread_db_id
    else:
        return None


# ── Attachment handling ────────────────────────────────────────────────────────

def _download_attachment(
    service: Any,
    gmail_msg_id: str,
    attachment_id: str,
    filename: str,
    mime_type: str | None,
    direction: str,
    sent_at: datetime,
    comm_message_db_id: int,
    conn: pymysql.connections.Connection,
) -> bool:
    """
    Download attachment, upsert doc_files, insert comm_message_docs.
    Returns True on success, False on failure (caller logs warning and skips).
    All writes are inside the outer transaction — no nested commit.
    """
    try:
        # Download attachment bytes
        att_response = service.users().messages().attachments().get(
            userId="me", messageId=gmail_msg_id, id=attachment_id
        ).execute()
        att_data = att_response.get("data", "")
        if not att_data:
            log.warning("Attachment %r: empty data field — skipping", filename)
            return False
        file_bytes = base64.urlsafe_b64decode(att_data + "==")
    except Exception as exc:
        log.warning("Attachment download failed for %r (msg %s): %s — skipping", filename, gmail_msg_id, exc)
        return False

    try:
        file_hash = hashlib.sha256(file_bytes).hexdigest()

        # Check for existing doc_files row by content hash
        with conn.cursor(DictCursor) as c:
            c.execute("SELECT id FROM doc_files WHERE file_hash = %s LIMIT 1", (file_hash,))
            existing = c.fetchone()

        if existing:
            doc_file_bigint_id = int(existing["id"])
            log.debug("Attachment %r: reusing existing doc_files.id=%d (same hash)", filename, doc_file_bigint_id)
        else:
            # Build storage path: /var/www/maltytask/data/email-attachments/YYYY-MM/<uuid>_<safe>
            ym = sent_at.strftime("%Y-%m")
            safe_filename = re.sub(r"[^a-zA-Z0-9._-]", "_", filename)
            file_id_str = str(uuid.uuid4())
            dir_path = ATTACHMENTS_BASE / ym
            os.makedirs(str(dir_path), exist_ok=True)
            local_path = dir_path / f"{file_id_str}_{safe_filename}"

            # Write real bytes (not a symlink)
            local_path.write_bytes(file_bytes)

            row_hash = hashlib.sha256(
                f"{file_id_str}:{filename}:{file_hash}:email-comm".encode()
            ).hexdigest()

            with conn.cursor() as c:
                c.execute(
                    """
                    INSERT INTO doc_files
                        (file_id, file_name, local_path, file_hash, mime_type,
                         source_folder, file_size_bytes, downloaded_at, row_hash)
                    VALUES (%s, %s, %s, %s, %s, 'email-comm', %s, NOW(), %s)
                    """,
                    (
                        file_id_str,
                        filename,
                        str(local_path),
                        file_hash,
                        mime_type,
                        len(file_bytes),
                        row_hash,
                    ),
                )
                doc_file_bigint_id = c.lastrowid

        # Insert comm_message_docs linking message to doc_file (BIGINT FK)
        with conn.cursor() as c:
            c.execute(
                """
                INSERT INTO comm_message_docs
                    (message_id_fk, doc_file_id_fk, attachment_filename, mime_type, direction)
                VALUES (%s, %s, %s, %s, %s)
                """,
                (comm_message_db_id, doc_file_bigint_id, filename, mime_type, direction),
            )

        log.debug("Attachment %r: doc_files.id=%d linked to comm_messages.id=%d",
                  filename, doc_file_bigint_id, comm_message_db_id)
        return True

    except Exception as exc:
        log.warning("Attachment processing failed for %r: %s — skipping", filename, exc)
        return False


# ── Dedup check ───────────────────────────────────────────────────────────────

def _already_seen(conn: pymysql.connections.Connection, message_id: str) -> bool:
    """Return True if message_id already exists in comm_messages."""
    with conn.cursor(DictCursor) as c:
        c.execute(
            "SELECT id FROM comm_messages WHERE message_id = %s LIMIT 1",
            (message_id,),
        )
        return c.fetchone() is not None


# ── Per-message processing ─────────────────────────────────────────────────────

def _process_message(
    service: Any,
    gmail_msg_id: str,
    gmail_thread_id: str,
    conn: pymysql.connections.Connection | None,
    apply_mode: bool,
    counts: dict[str, int],
) -> None:
    """
    Process one Gmail message through the full pipeline.
    Mutates `counts` in place.
    """
    # Fetch full message
    try:
        msg_full = _fetch_message_full(service, gmail_msg_id)
    except Exception as exc:
        log.warning("Could not fetch message %s: %s — skipping", gmail_msg_id, exc)
        counts["error"] += 1
        return

    payload = msg_full.get("payload", {}) or {}
    headers: list[dict[str, str]] = payload.get("headers", []) or []

    # Parse core headers
    from_raw = _get_header(headers, "From")
    to_raw = _get_header(headers, "To")
    cc_raw = _get_header(headers, "Cc")
    subject_raw = _get_header(headers, "Subject")
    date_raw = _get_header(headers, "Date")
    raw_message_id = _get_header(headers, "Message-ID")

    # Normalise RFC 2822 Message-ID: strip angle brackets
    message_id = raw_message_id.strip().strip("<>").strip()
    if not message_id:
        message_id = f"no-id-{gmail_msg_id}"
        log.warning("Message %s has no Message-ID header — using fallback %r", gmail_msg_id, message_id)

    sent_at = _parse_sent_at(date_raw)

    # Privacy filter
    decision, drop_reason = _privacy_check(headers)
    if decision == "drop":
        if drop_reason == "internal":
            counts["dropped_internal"] += 1
            log.debug("DROP (internal-only): %s / subject=%r", message_id, subject_raw)
        else:
            counts["dropped_bulk"] += 1
            log.debug("DROP (bulk/newsletter): %s / subject=%r", message_id, subject_raw)
        return

    # Direction
    direction = _detect_direction(headers)

    # Dedup check (DB read, allowed in dry-run)
    if conn is not None:
        if _already_seen(conn, message_id):
            counts["already_seen"] += 1
            log.debug("SKIP (already seen): %s", message_id)
            return

    # Entity resolution (DB read)
    resolved_supplier_id: int | None = None
    resolved_customer_id: int | None = None
    primary_email: str | None = None
    if conn is not None:
        resolved_supplier_id, resolved_customer_id, primary_email = _resolve_entity(
            conn, headers, direction
        )

    if resolved_supplier_id:
        resolved_label = "supplier"
    elif resolved_customer_id:
        resolved_label = "customer"
    else:
        resolved_label = "review"

    # Parse body + attachments
    body_text, body_html, attachments = _walk_parts(payload)

    # Choose body format: prefer html
    if body_html:
        body_format = "html"
        body_content = body_html
    else:
        body_format = "text"
        body_content = body_text

    # Snippet: first 512 chars of plain text body (or html fallback)
    snippet_source = body_text or body_html or ""
    body_snippet = snippet_source[:512] if snippet_source else None

    sent_at_str = sent_at.strftime("%Y-%m-%d %H:%M:%S")

    if not apply_mode:
        log.info(
            "[dry-run] Would INSERT comm_messages: message_id=%s direction=%s resolved=%s",
            message_id, direction, resolved_label,
        )
        counts["new_to_insert"] += 1
        if resolved_supplier_id:
            counts["resolved_supplier"] += 1
        elif resolved_customer_id:
            counts["resolved_customer"] += 1
        else:
            counts["review_bucket"] += 1
        counts["attachments"] += len(attachments)
        return

    # ── Apply mode: one transaction per message ────────────────────────────────
    assert conn is not None
    try:
        conn.begin()

        # 1. Get or create thread
        thread_db_id = _get_or_create_thread(
            conn, gmail_thread_id, subject_raw,
            resolved_supplier_id, resolved_customer_id,
            sent_at, apply_mode=True,
        )
        if thread_db_id is None:
            raise RuntimeError(f"Could not get/create comm_threads row for gmail_thread_id={gmail_thread_id!r}")

        # 2. INSERT comm_messages (INSERT IGNORE on message_id UNIQUE)
        with conn.cursor() as c:
            c.execute(
                """
                INSERT IGNORE INTO comm_messages
                    (thread_id_fk, direction, from_address, to_address, cc_address,
                     subject, body_format, body, body_snippet,
                     sent_at, message_id, gmail_message_id, source)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'gmail')
                """,
                (
                    thread_db_id,
                    direction,
                    from_raw or "",
                    to_raw or "",
                    cc_raw or None,
                    subject_raw or "",
                    body_format,
                    body_content or None,
                    body_snippet,
                    sent_at_str,
                    message_id,
                    gmail_msg_id,
                ),
            )
            rowcount = c.rowcount
            comm_message_db_id = c.lastrowid

        if rowcount == 0:
            # Race: another process inserted between our dedup check and now — skip
            log.debug("comm_messages INSERT IGNORE skipped (race): %s", message_id)
            conn.commit()
            counts["already_seen"] += 1
            return

        # 3. Process attachments (inside same transaction)
        att_count = 0
        for att in attachments:
            att_filename = att.get("filename", "")
            att_mime = att.get("mime_type")
            att_id = att.get("attachment_id")
            if not att_id:
                continue
            ok = _download_attachment(
                service, gmail_msg_id, att_id,
                att_filename, att_mime, direction,
                sent_at, comm_message_db_id, conn,
            )
            if ok:
                att_count += 1

        conn.commit()

        counts["inserted"] += 1
        if resolved_supplier_id:
            counts["resolved_supplier"] += 1
        elif resolved_customer_id:
            counts["resolved_customer"] += 1
        else:
            counts["review_bucket"] += 1
        counts["attachments"] += att_count

        log.info(
            "INSERT comm_messages.id=%d message_id=%s direction=%s resolved=%s thread=%d att=%d",
            comm_message_db_id, message_id, direction, resolved_label, thread_db_id, att_count,
        )

    except Exception as exc:
        try:
            conn.rollback()
        except Exception:
            pass
        log.error("Transaction failed for message_id=%s: %s — rolled back", message_id, exc)
        counts["error"] += 1


# ── Dry-run summary ────────────────────────────────────────────────────────────

def _print_dry_run_summary(fetched: int, counts: dict[str, int]) -> None:
    dropped_total = counts["dropped_internal"] + counts["dropped_bulk"]
    print()
    print("--- DRY RUN SUMMARY (no writes) ---")
    print(f"Fetched:           {fetched} messages")
    print(f"Already seen:       {counts['already_seen']} (would skip)")
    print(f"Dropped (privacy): {dropped_total} (internal/bulk)")
    print(f"New to insert:     {counts['new_to_insert']}")
    print(f"  → Resolved (supplier):  {counts['resolved_supplier']}")
    print(f"  → Resolved (customer):   {counts['resolved_customer']}")
    print(f"  → Review bucket:        {counts['review_bucket']}")
    print(f"  Attachments:            {counts['attachments']}")
    print("---")
    print()


def _print_apply_summary(fetched: int, counts: dict[str, int]) -> None:
    dropped_total = counts["dropped_internal"] + counts["dropped_bulk"]
    print()
    print("--- APPLY SUMMARY ---")
    print(f"Fetched:           {fetched} messages")
    print(f"Already seen:       {counts['already_seen']} (skipped)")
    print(f"Dropped (privacy): {dropped_total} (internal/bulk)")
    print(f"Inserted:          {counts['inserted']}")
    print(f"  → Resolved (supplier):  {counts['resolved_supplier']}")
    print(f"  → Resolved (customer):   {counts['resolved_customer']}")
    print(f"  → Review bucket:        {counts['review_bucket']}")
    print(f"  Attachments:            {counts['attachments']}")
    print(f"Errors:            {counts['error']}")
    print("---")
    print()


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Gmail comm inbox → comm_threads / comm_messages / comm_message_docs.\n"
            "Dry-run by default — use --apply to write to the database.\n"
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--apply", action="store_true",
        help="Write to database and download attachments (default: dry-run, no writes).",
    )
    parser.add_argument(
        "--limit", type=int, default=0,
        help="Process at most N messages (0 = unlimited, default 0).",
    )
    parser.add_argument(
        "--query", default=None, metavar="Q",
        help="Override GMAIL_COMM_QUERY from env.",
    )
    parser.add_argument(
        "--debug", action="store_true",
        help="Set log level to DEBUG.",
    )
    args = parser.parse_args()

    if args.debug:
        logging.getLogger().setLevel(logging.DEBUG)

    apply_mode = args.apply
    limit = args.limit
    mode_label = "** APPLY **" if apply_mode else "DRY-RUN"

    print(f"\nEmail Comm Ingest — v{SCRIPT_VERSION}")
    print(f"Mode: {mode_label}")
    print()

    # ── [1] Load gmail-comm.env ───────────────────────────────────────────────
    log.info("[1] Loading gmail-comm.env …")
    try:
        comm_cfg = _load_comm_env(_COMM_ENV_PATH)
    except RuntimeError as exc:
        print(f"\nERROR: Gmail comm config unavailable.\n{exc}\n", file=sys.stderr)
        sys.exit(1)

    # Apply --query override
    if args.query:
        comm_cfg = dict(comm_cfg)
        comm_cfg["GMAIL_COMM_QUERY"] = args.query
        log.info("Query overridden to: %r", args.query)

    # ── [2] Connect to DB ──────────────────────────────────────────────────────
    conn: pymysql.connections.Connection | None = None
    try:
        log.info("[2] Connecting to DB …")
        cfg = load_config()
        conn = pymysql.connect(
            host=cfg.db_host, port=cfg.db_port,
            user=cfg.db_user, password=cfg.db_password,
            database=cfg.db_name,
            charset="utf8mb4", cursorclass=DictCursor, autocommit=False,
        )
        log.info("DB connection established.")
    except Exception as exc:
        log.warning("DB connection failed (%s) — dedup and entity resolution unavailable.", exc)
        conn = None

    # ── [3] Fetch Gmail message stubs ─────────────────────────────────────────
    log.info("[3] Fetching Gmail message stubs (query=%r) …", comm_cfg.get("GMAIL_COMM_QUERY"))
    try:
        service, stubs = _fetch_gmail_message_stubs(comm_cfg, limit)
    except RuntimeError as exc:
        print(f"\nERROR: {exc}\n", file=sys.stderr)
        sys.exit(1)
    except Exception as exc:
        msg = str(exc)
        if "unauthorized_client" in msg or "not authorized for any of the scopes" in msg:
            print(
                "\nERROR: Gmail domain-wide delegation not yet active.\n"
                f"  The service account cannot yet impersonate "
                f"{comm_cfg.get('GMAIL_COMM_DELEGATED_USER')} for scope gmail.readonly.\n"
                "  → A Google Workspace super-admin must authorize this service\n"
                "    account's Client ID with scope\n"
                "      https://www.googleapis.com/auth/gmail.readonly\n"
                "    in Admin console → Security → API controls → Domain-wide delegation.\n"
                "    (Allow a few minutes for propagation after the grant.)\n",
                file=sys.stderr,
            )
            sys.exit(2)
        raise

    fetched_count = len(stubs)
    log.info("[3] Will process %d message(s).", fetched_count)

    if fetched_count == 0:
        log.info("No messages to process — exiting.")
        if conn is not None:
            conn.close()
        return

    # ── [4] Process each message ───────────────────────────────────────────────
    log.info("[4] Processing %d message(s) …", fetched_count)

    counts: dict[str, int] = {
        "dropped_internal": 0,
        "dropped_bulk": 0,
        "already_seen": 0,
        "new_to_insert": 0,       # dry-run
        "inserted": 0,             # apply
        "resolved_supplier": 0,
        "resolved_customer": 0,
        "review_bucket": 0,
        "attachments": 0,
        "error": 0,
    }

    try:
        for stub in stubs:
            gmail_msg_id = stub["id"]
            gmail_thread_id = stub.get("threadId", gmail_msg_id)
            _process_message(
                service=service,
                gmail_msg_id=gmail_msg_id,
                gmail_thread_id=gmail_thread_id,
                conn=conn,
                apply_mode=apply_mode,
                counts=counts,
            )
    finally:
        if conn is not None:
            conn.close()

    # ── [5] Summary ────────────────────────────────────────────────────────────
    if apply_mode:
        _print_apply_summary(fetched_count, counts)
    else:
        _print_dry_run_summary(fetched_count, counts)


if __name__ == "__main__":
    main()
