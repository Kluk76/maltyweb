#!/usr/bin/env python3
"""
ingest_email_comm.py — Gmail comm inbox → comm_threads / comm_messages / comm_message_docs.

Reads inbound and outbound emails from the comm Gmail inbox (e.g. info@lanebuleuse.ch),
applies a privacy filter (drops internal-only and bulk/newsletter messages), resolves
counterparty identity via comm_address_pins + ref_entity_email_domains, threads by Gmail
threadId, and persists raw messages (with any attachments) to the comm_* tables.

GATE POLICY (since 2026-06-21):
  Inbound messages are stored ONLY when the counterparty resolves to a registered entity
  in comm_address_pins or ref_entity_email_domains.  Unresolved inbound messages are
  DROPPED without storage; their domain is upserted into comm_unknown_domain_seen for
  operator review.  Outbound (sent) messages are ALWAYS stored — operator replies belong
  to threads we already own.

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

  # Cap backfill rows per run (default 25):
  python3 scripts/python/ingest_email_comm.py --max-backfill 5
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
from comm_domains import domain_of  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

SCRIPT_VERSION = "2.0.0"
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


# ── Gmail API service builder + stub fetcher ──────────────────────────────────

def _build_gmail_service(gmail_cfg: dict[str, str]) -> Any:
    """
    Authenticate and return a Gmail API service object.
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

    scopes = ["https://www.googleapis.com/auth/gmail.readonly"]
    creds = service_account.Credentials.from_service_account_file(
        sa_keyfile, scopes=scopes, subject=delegated_user
    )
    return build("gmail", "v1", credentials=creds, cache_discovery=False)


def _list_gmail_stubs(service: Any, query: str, limit: int) -> list[dict[str, Any]]:
    """
    Fetch message stubs (id + threadId) from an already-authenticated Gmail
    service with pagination.  Returns stubs_list.
    """
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

    return stubs


def _fetch_gmail_message_stubs(
    gmail_cfg: dict[str, str],
    limit: int,
    query_override: str | None = None,
) -> tuple[Any, list[dict[str, Any]]]:
    """
    Build Gmail service and fetch message stubs.
    Returns (service, stubs_list).
    Kept for backward-compatible call sites.
    """
    service = _build_gmail_service(gmail_cfg)
    query = query_override or gmail_cfg.get("GMAIL_COMM_QUERY", "is:unread label:inbox")
    stubs = _list_gmail_stubs(service, query, limit)
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


# ── Registry cache ─────────────────────────────────────────────────────────────

# Per-run in-memory cache for ref_entity_email_domains and comm_address_pins lookups.
# Key: (match_type, match_value) → (supplier_id, customer_id, is_shared)
# Populated lazily on first query; avoids repeated DB round-trips for the same
# domain/address across many messages in one run.
_registry_cache: dict[tuple[str, str], tuple[int | None, int | None, bool]] | None = None
# Key: email → (supplier_id, customer_id)
_pins_cache: dict[str, tuple[int | None, int | None]] | None = None


def _load_registry_cache(cur: Any) -> None:
    """Bulk-load ref_entity_email_domains into _registry_cache (once per run)."""
    global _registry_cache
    if _registry_cache is not None:
        return
    _registry_cache = {}
    try:
        cur.execute(
            """
            SELECT match_type, match_value, supplier_id_fk, customer_id_fk, is_shared
              FROM ref_entity_email_domains
             WHERE is_active = 1
            """
        )
        rows = cur.fetchall()
        for row in rows:
            key = (row["match_type"], row["match_value"].lower())
            sup_id = int(row["supplier_id_fk"]) if row["supplier_id_fk"] else None
            cust_id = int(row["customer_id_fk"]) if row["customer_id_fk"] else None
            shared = bool(row["is_shared"])
            _registry_cache[key] = (sup_id, cust_id, shared)
        log.info("Registry cache loaded: %d ref_entity_email_domains rows.", len(rows))
    except Exception as exc:
        log.warning("Could not load registry cache: %s — will fall back to per-query lookups", exc)
        _registry_cache = {}  # empty but not None — don't retry


def _load_pins_cache(cur: Any) -> None:
    """Bulk-load comm_address_pins into _pins_cache (once per run)."""
    global _pins_cache
    if _pins_cache is not None:
        return
    _pins_cache = {}
    try:
        cur.execute(
            "SELECT email, supplier_id_fk, customer_id_fk FROM comm_address_pins"
        )
        rows = cur.fetchall()
        for row in rows:
            email = row["email"].lower()
            sup_id = int(row["supplier_id_fk"]) if row["supplier_id_fk"] else None
            cust_id = int(row["customer_id_fk"]) if row["customer_id_fk"] else None
            _pins_cache[email] = (sup_id, cust_id)
        log.info("Pins cache loaded: %d comm_address_pins rows.", len(rows))
    except Exception as exc:
        log.warning("Could not load pins cache: %s", exc)
        _pins_cache = {}


# ── Counterparty resolution ────────────────────────────────────────────────────

def resolve_counterparty(
    conn: pymysql.connections.Connection,
    email: str,
) -> tuple[int | None, int | None, str]:
    """
    Resolve an external email address against the entity registry.

    Precedence (most-specific first, all comparisons lowercase):
      (a) exact comm_address_pins.email  → AUTO-LINK, match_kind='pin'
      (b) ref_entity_email_domains WHERE match_type='address' AND match_value=email
                                    AND is_active=1             → match_kind='address'
      (c) ref_entity_email_domains WHERE match_type='domain'  AND match_value=domain
                                    AND is_active=1             → match_kind='domain'
      (d) no match                                              → match_kind='none'
          (returns supplier_id=None, customer_id=None)

    is_shared=1 rows: still return the FK (the CHECK constraint guarantees exactly
    one party is set), but match_kind is suffixed with '+shared' so callers can
    flag these for review without blocking storage.

    Returns (supplier_id, customer_id, match_kind).
    """
    email_lc = email.lower().strip()
    dom = domain_of(email_lc)

    with conn.cursor(DictCursor) as cur:
        _load_registry_cache(cur)
        _load_pins_cache(cur)

    # (a) Address pin — most specific
    if _pins_cache is not None and email_lc in _pins_cache:
        sup_id, cust_id = _pins_cache[email_lc]
        if sup_id or cust_id:
            return sup_id, cust_id, "pin"

    # (b) Registry: match_type='address', exact email
    if _registry_cache is not None:
        key_addr = ("address", email_lc)
        if key_addr in _registry_cache:
            sup_id, cust_id, shared = _registry_cache[key_addr]
            kind = "address+shared" if shared else "address"
            return sup_id, cust_id, kind

        # (c) Registry: match_type='domain', bare domain
        if dom:
            key_dom = ("domain", dom)
            if key_dom in _registry_cache:
                sup_id, cust_id, shared = _registry_cache[key_dom]
                kind = "domain+shared" if shared else "domain"
                return sup_id, cust_id, kind

    # (d) No match
    return None, None, "none"


# ── Unknown-domain drop logger ─────────────────────────────────────────────────

def _log_unknown_domain(
    conn: pymysql.connections.Connection,
    email: str,
    apply_mode: bool,
    dry_run_domain_counts: dict[str, dict[str, Any]] | None = None,
) -> None:
    """
    Upsert into comm_unknown_domain_seen for a dropped inbound message.
    In dry-run mode, accumulates counts in dry_run_domain_counts instead of writing.
    """
    dom = domain_of(email)
    if not dom:
        dom = "_no_domain"

    if not apply_mode:
        # Accumulate for summary report
        if dry_run_domain_counts is not None:
            if dom not in dry_run_domain_counts:
                dry_run_domain_counts[dom] = {"count": 0, "sample": email}
            dry_run_domain_counts[dom]["count"] += 1
        return

    try:
        with conn.cursor() as c:
            c.execute(
                """
                INSERT INTO comm_unknown_domain_seen
                    (domain, sample_address, first_seen_at, last_seen_at, hit_count)
                VALUES (%s, %s, NOW(), NOW(), 1)
                ON DUPLICATE KEY UPDATE
                    hit_count = hit_count + 1,
                    last_seen_at = NOW(),
                    sample_address = COALESCE(sample_address, VALUES(sample_address))
                """,
                (dom, email),
            )
        conn.commit()
    except Exception as exc:
        log.warning("comm_unknown_domain_seen upsert failed for domain=%r: %s", dom, exc)
        try:
            conn.rollback()
        except Exception:
            pass


# ── Entity resolution via comm_address_pins (legacy helper, kept for outbound) ─

def _get_counterparty_address(
    headers: list[dict[str, str]],
    direction: str,
) -> str | None:
    """
    Extract the primary external counterparty email from message headers.
    Inbound  → external From address.
    Outbound → first external To address.
    Returns None if no external address found.
    """
    if direction == "in":
        from_raw = _get_header(headers, "From")
        _, from_email = parseaddr(from_raw)
        from_email_lower = from_email.lower().strip()
        if from_email_lower and not _is_internal(from_email_lower):
            return from_email_lower
        # Fallback: first external in all addresses
        all_ext = [a for a in _extract_all_addresses(headers) if not _is_internal(a)]
        return all_ext[0] if all_ext else None
    else:
        to_raw = _get_header(headers, "To")
        to_addrs = [addr.lower().strip() for _, addr in getaddresses([to_raw]) if addr.strip()]
        to_external = [a for a in to_addrs if not _is_internal(a)]
        if to_external:
            return to_external[0]
        all_ext = [a for a in _extract_all_addresses(headers) if not _is_internal(a)]
        return all_ext[0] if all_ext else None


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
    dry_run_domain_counts: dict[str, dict[str, Any]] | None = None,
    seen_message_ids: set[str] | None = None,
) -> None:
    """
    Process one Gmail message through the full pipeline.
    Mutates `counts` in place.

    seen_message_ids: set of message_ids already processed this run (both
    stored and dropped) — used to avoid double-counting drops when the same
    message appears in multiple query result sets (e.g. backfill + incremental).
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

    # ── GATE 1: Privacy filter (AHEAD of everything — bulk/internal dropped without
    #            touching registry or unknown-domain log) ─────────────────────────
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

    # ── GATE 2: Dedup check against already-seen in this run + DB ───────────────
    # Track in-run set to avoid double-processing across backfill + incremental.
    if seen_message_ids is not None:
        if message_id in seen_message_ids:
            counts["already_seen"] += 1
            log.debug("SKIP (already seen this run): %s", message_id)
            return
        seen_message_ids.add(message_id)

    if conn is not None:
        if _already_seen(conn, message_id):
            counts["already_seen"] += 1
            log.debug("SKIP (already seen in DB): %s", message_id)
            return

    # ── GATE 3: Entity registry gate (inbound only) ───────────────────────────
    # Outbound 'out' messages are ALWAYS stored — operator replies belong to
    # threads we already own; do not gate them on the registry.
    counterparty_email = _get_counterparty_address(headers, direction)

    resolved_supplier_id: int | None = None
    resolved_customer_id: int | None = None
    match_kind = "none"

    if conn is not None and counterparty_email:
        resolved_supplier_id, resolved_customer_id, match_kind = resolve_counterparty(
            conn, counterparty_email
        )

    if direction == "in" and match_kind == "none":
        # DROP UNSTORED: inbound from unregistered domain
        counts["dropped_unregistered"] += 1
        log.debug(
            "DROP (unregistered): %s / from=%s / domain=%s",
            message_id, counterparty_email or "?", domain_of(counterparty_email or ""),
        )
        # Log domain (dry-run: accumulate counts; apply: upsert DB)
        if counterparty_email and conn is not None:
            _log_unknown_domain(conn, counterparty_email, apply_mode, dry_run_domain_counts)
        return

    # Determine resolved label for logging
    if resolved_supplier_id:
        resolved_label = "supplier"
    elif resolved_customer_id:
        resolved_label = "customer"
    else:
        # Outbound with no counterparty match: keep but flag as review
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
            "[dry-run] Would INSERT comm_messages: message_id=%s direction=%s resolved=%s match_kind=%s",
            message_id, direction, resolved_label, match_kind,
        )
        counts["new_to_insert"] += 1
        if resolved_supplier_id:
            counts["resolved_supplier"] += 1
        elif resolved_customer_id:
            counts["resolved_customer"] += 1
        else:
            counts["review_bucket"] += 1
        # Track by match_kind
        mk_bucket = match_kind.split("+")[0]  # strip +shared suffix for bucketing
        counts[f"match_{mk_bucket}"] = counts.get(f"match_{mk_bucket}", 0) + 1
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
        mk_bucket = match_kind.split("+")[0]
        counts[f"match_{mk_bucket}"] = counts.get(f"match_{mk_bucket}", 0) + 1
        counts["attachments"] += att_count

        log.info(
            "INSERT comm_messages.id=%d message_id=%s direction=%s resolved=%s match_kind=%s thread=%d att=%d",
            comm_message_db_id, message_id, direction, resolved_label, match_kind, thread_db_id, att_count,
        )

    except Exception as exc:
        try:
            conn.rollback()
        except Exception:
            pass
        log.error("Transaction failed for message_id=%s: %s — rolled back", message_id, exc)
        counts["error"] += 1


# ── Backfill pass ──────────────────────────────────────────────────────────────

def _run_backfill_pass(
    service: Any,
    conn: pymysql.connections.Connection,
    apply_mode: bool,
    max_backfill: int,
    counts: dict[str, int],
    dry_run_domain_counts: dict[str, dict[str, Any]] | None,
    seen_message_ids: set[str],
) -> int:
    """
    Retroactive backfill: for each ref_entity_email_domains row with is_active=1
    and backfilled_at IS NULL, run a wide Gmail query scoped to that entity and
    feed results through the standard message-processing path (same dedup,
    same resolve→store, same attachment handling).

    On success for each row (apply mode), sets backfilled_at=NOW().
    Returns count of registry rows that still need backfill after this pass.
    """
    try:
        with conn.cursor(DictCursor) as c:
            c.execute(
                """
                SELECT id, match_type, match_value, supplier_id_fk, customer_id_fk
                  FROM ref_entity_email_domains
                 WHERE is_active = 1 AND backfilled_at IS NULL
                 ORDER BY id
                """
            )
            pending_rows = c.fetchall()
    except Exception as exc:
        log.warning("Backfill: could not query ref_entity_email_domains: %s", exc)
        return 0

    total_pending = len(pending_rows)
    log.info(
        "Backfill: %d registry row(s) pending (backfilled_at IS NULL); will process up to %d this run.",
        total_pending, max_backfill,
    )

    rows_to_process = pending_rows[:max_backfill]
    remaining_after = total_pending - len(rows_to_process)

    for reg_row in rows_to_process:
        reg_id = reg_row["id"]
        match_type = reg_row["match_type"]
        match_value = reg_row["match_value"].lower()

        # Build Gmail query
        if match_type == "domain":
            gmail_query = f"(from:{match_value} OR to:{match_value}) newer_than:2y"
        else:
            # match_type = 'address'
            gmail_query = f"(from:{match_value} OR to:{match_value}) newer_than:2y"

        log.info(
            "Backfill [id=%d, %s=%r]: querying Gmail with %r",
            reg_id, match_type, match_value, gmail_query,
        )

        try:
            bf_stubs = _list_gmail_stubs(service, gmail_query, limit=0)
        except Exception as exc:
            log.warning(
                "Backfill [id=%d]: Gmail query failed: %s — skipping row (will retry next run)",
                reg_id, exc,
            )
            continue

        log.info(
            "Backfill [id=%d]: %d message stub(s) found — processing …",
            reg_id, len(bf_stubs),
        )

        row_ok = True
        for stub in bf_stubs:
            gmail_msg_id = stub["id"]
            gmail_thread_id = stub.get("threadId", gmail_msg_id)
            try:
                _process_message(
                    service=service,
                    gmail_msg_id=gmail_msg_id,
                    gmail_thread_id=gmail_thread_id,
                    conn=conn,
                    apply_mode=apply_mode,
                    counts=counts,
                    dry_run_domain_counts=dry_run_domain_counts,
                    seen_message_ids=seen_message_ids,
                )
            except Exception as exc:
                log.warning(
                    "Backfill [id=%d]: message %s failed: %s — row will retry next run",
                    reg_id, gmail_msg_id, exc,
                )
                row_ok = False
                break

        if row_ok and apply_mode:
            try:
                with conn.cursor() as c:
                    c.execute(
                        "UPDATE ref_entity_email_domains SET backfilled_at = NOW() WHERE id = %s",
                        (reg_id,),
                    )
                conn.commit()
                log.info("Backfill [id=%d]: backfilled_at set.", reg_id)
            except Exception as exc:
                log.warning("Backfill [id=%d]: could not set backfilled_at: %s", reg_id, exc)

        if row_ok and not apply_mode:
            log.info("[dry-run] Backfill [id=%d]: would set backfilled_at (not writing).", reg_id)

    return remaining_after


# ── Dry-run summary ────────────────────────────────────────────────────────────

def _print_dry_run_summary(
    fetched: int,
    counts: dict[str, int],
    dry_run_domain_counts: dict[str, dict[str, Any]],
    backfill_remaining: int,
) -> None:
    dropped_total = counts["dropped_internal"] + counts["dropped_bulk"]
    print()
    print("─" * 60)
    print("DRY RUN SUMMARY (no writes)")
    print("─" * 60)
    print(f"Fetched (incl. backfill):  {fetched}")
    print(f"Already seen:              {counts['already_seen']} (would skip)")
    print(f"Dropped (privacy):         {dropped_total}")
    print(f"  → internal-only:           {counts['dropped_internal']}")
    print(f"  → bulk/newsletter:         {counts['dropped_bulk']}")
    print(f"Dropped (unregistered):    {counts['dropped_unregistered']}")
    print(f"Would store:               {counts['new_to_insert']}")
    print(f"  → match_kind=pin:          {counts.get('match_pin', 0)}")
    print(f"  → match_kind=address:      {counts.get('match_address', 0)}")
    print(f"  → match_kind=domain:       {counts.get('match_domain', 0)}")
    print(f"  → outbound (review):       {counts['review_bucket']}")
    print(f"  → resolved (supplier):     {counts['resolved_supplier']}")
    print(f"  → resolved (customer):     {counts['resolved_customer']}")
    print(f"  Attachments:               {counts['attachments']}")
    print(f"Backfill rows remaining:   {backfill_remaining} (would set backfilled_at=NOW() on success)")
    if dry_run_domain_counts:
        print()
        print("Unknown domains (would log to comm_unknown_domain_seen):")
        sorted_domains = sorted(
            dry_run_domain_counts.items(),
            key=lambda kv: kv[1]["count"],
            reverse=True,
        )
        for dom, info in sorted_domains[:20]:
            print(f"  {dom:<40}  hits={info['count']}  sample={info['sample']}")
        if len(sorted_domains) > 20:
            print(f"  … and {len(sorted_domains) - 20} more domains")
    else:
        print("Unknown domains (would log): (none)")
    print("─" * 60)
    print()


def _print_apply_summary(fetched: int, counts: dict[str, int]) -> None:
    dropped_total = counts["dropped_internal"] + counts["dropped_bulk"]
    print()
    print("─" * 60)
    print("APPLY SUMMARY")
    print("─" * 60)
    print(f"Fetched (incl. backfill):  {fetched}")
    print(f"Already seen:              {counts['already_seen']} (skipped)")
    print(f"Dropped (privacy):         {dropped_total}")
    print(f"  → internal-only:           {counts['dropped_internal']}")
    print(f"  → bulk/newsletter:         {counts['dropped_bulk']}")
    print(f"Dropped (unregistered):    {counts['dropped_unregistered']}")
    print(f"Inserted:                  {counts['inserted']}")
    print(f"  → match_kind=pin:          {counts.get('match_pin', 0)}")
    print(f"  → match_kind=address:      {counts.get('match_address', 0)}")
    print(f"  → match_kind=domain:       {counts.get('match_domain', 0)}")
    print(f"  → outbound (review):       {counts['review_bucket']}")
    print(f"  → resolved (supplier):     {counts['resolved_supplier']}")
    print(f"  → resolved (customer):     {counts['resolved_customer']}")
    print(f"  Attachments:              {counts['attachments']}")
    print(f"Errors:                    {counts['error']}")
    print("─" * 60)
    print()


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Gmail comm inbox → comm_threads / comm_messages / comm_message_docs.\n"
            "Dry-run by default — use --apply to write to the database.\n"
            "\n"
            "Gate policy: inbound messages are stored ONLY when the counterparty\n"
            "resolves to a registered entity (comm_address_pins or\n"
            "ref_entity_email_domains).  Outbound messages are always stored.\n"
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--apply", action="store_true",
        help="Write to database and download attachments (default: dry-run, no writes).",
    )
    parser.add_argument(
        "--limit", type=int, default=0,
        help="Process at most N messages per Gmail query (0 = unlimited, default 0).",
    )
    parser.add_argument(
        "--query", default=None, metavar="Q",
        help="Override GMAIL_COMM_QUERY from env (applies to incremental pull only).",
    )
    parser.add_argument(
        "--max-backfill", type=int, default=25, metavar="N",
        help="Max ref_entity_email_domains rows to backfill per run (default 25).",
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
    max_backfill = args.max_backfill
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

    # Shared across backfill + incremental to avoid double-processing
    seen_message_ids: set[str] = set()

    counts: dict[str, int] = {
        "dropped_internal": 0,
        "dropped_bulk": 0,
        "dropped_unregistered": 0,
        "already_seen": 0,
        "new_to_insert": 0,       # dry-run
        "inserted": 0,             # apply
        "resolved_supplier": 0,
        "resolved_customer": 0,
        "review_bucket": 0,
        "attachments": 0,
        "error": 0,
    }

    # Per-run dry-run domain accumulator
    dry_run_domain_counts: dict[str, dict[str, Any]] = {}

    # ── [3] Authenticate Gmail service (needed by both backfill + incremental) ─
    log.info("[3] Authenticating Gmail service …")
    try:
        service = _build_gmail_service(comm_cfg)
        log.info("[3] Gmail service authenticated.")
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

    # ── [4] Backfill pass ─────────────────────────────────────────────────────
    backfill_remaining = 0
    if conn is not None and max_backfill > 0:
        log.info("[4] Running backfill pass (max_backfill=%d) …", max_backfill)
        backfill_remaining = _run_backfill_pass(
            service=service,
            conn=conn,
            apply_mode=apply_mode,
            max_backfill=max_backfill,
            counts=counts,
            dry_run_domain_counts=dry_run_domain_counts if not apply_mode else None,
            seen_message_ids=seen_message_ids,
        )
        log.info("[4] Backfill pass complete. Rows remaining after this tick: %d", backfill_remaining)
    else:
        log.info("[4] Backfill skipped (max_backfill=0 or no DB).")

    # ── [5] Incremental pull ───────────────────────────────────────────────────
    incremental_query = args.query or comm_cfg.get("GMAIL_COMM_QUERY", "is:unread label:inbox")
    log.info("[5] Fetching incremental Gmail message stubs (query=%r) …", incremental_query)
    try:
        stubs = _list_gmail_stubs(service, incremental_query, limit)
    except RuntimeError as exc:
        print(f"\nERROR: {exc}\n", file=sys.stderr)
        if conn is not None:
            conn.close()
        sys.exit(1)

    fetched_count = len(stubs)
    log.info("[5] Will process %d incremental message(s).", fetched_count)

    # ── [6] Process incremental messages ──────────────────────────────────────
    if fetched_count > 0:
        log.info("[6] Processing %d incremental message(s) …", fetched_count)
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
                    dry_run_domain_counts=dry_run_domain_counts if not apply_mode else None,
                    seen_message_ids=seen_message_ids,
                )
        finally:
            if conn is not None:
                conn.close()
    else:
        log.info("[6] No incremental messages to process.")
        if conn is not None:
            conn.close()

    # ── [7] Summary ────────────────────────────────────────────────────────────
    if apply_mode:
        _print_apply_summary(fetched_count, counts)
    else:
        _print_dry_run_summary(
            fetched_count,
            counts,
            dry_run_domain_counts,
            backfill_remaining,
        )


if __name__ == "__main__":
    main()
