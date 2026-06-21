#!/usr/bin/env python3
"""
purge_comm_backlog.py — Phase 1: classify the comm_threads review-bucket
                         (supplier_id_fk IS NULL AND customer_id_fk IS NULL
                          AND purge_status='live') into 4 buckets and
                         (on --apply) soft-act on them.

                         Phase 2 (--hard-purge): permanently delete the rows
                         that Phase 1 soft-purged (purge_status='soft_purged'),
                         with careful attachment-byte preservation logic.

DISARM CONVENTION:
  --dry-run is the DEFAULT for BOTH modes.  Prints counts + details, no writes.
  Emits a full reconcile-report JSON to /var/log/maltytask/comm-backlog-purge-report.json.
  --apply performs the FK sets + purge_status updates (each through audit_row_revisions).

Buckets (Phase 1):
  KEEP         — resolves to exactly 1 supplier, no customer.
                 Sets supplier_id_fk on the thread; purge_status stays 'live'.
  MIGRATE      — resolves to exactly 1 customer, no supplier.
                 Sets customer_id_fk + purge_status='migrated_customer'.
  DELETE-NOISE — no entity resolution at all.
                 Sets purge_status='soft_purged' + purge_reason.
  AMBIGUOUS    — conflicting resolution (supplier+customer, or ≥2 distinct
                 suppliers, or ≥2 distinct customers).
                 Left 'live'; surfaced for manual review.

Phase 2 (--hard-purge) sole-referrer logic:
  A doc_files row + its physical bytes are deleted ONLY when:
    (a) every comm_message_docs row pointing at it belongs to a message in the
        purge set (no surviving comm_message_docs reference outside the set), AND
    (b) no other table holds a FK reference to that doc_files.id.
        Tables checked: doc_ambiguous, doc_delivery_notes, doc_invoices,
        doc_review_queue, inv_deliveries, ord_orders, supplier_cert_documents.
    (c) the file is physically inside /var/www/maltytask/data/email-attachments/
        (verified via the local_path prefix check).
  When in doubt, PRESERVE the doc_file (delete only the comm_message_docs link).

CARDINAL RULE — NON-FISCAL: this is a CRM/correspondence-layer purge.
Nothing here feeds COGS, COP, WAC, BOM, beer-tax, stock, or any financial
computation.

Usage:
  # Phase 1 dry-run (default) — bucket counts + samples, no writes:
  python3 scripts/python/purge_comm_backlog.py

  # Phase 1 apply (operator-approved only):
  python3 scripts/python/purge_comm_backlog.py --apply

  # Phase 2 dry-run (default) — hard-purge report, no writes:
  python3 scripts/python/purge_comm_backlog.py --hard-purge

  # Phase 2 apply (operator-approved only — IRREVERSIBLE):
  python3 scripts/python/purge_comm_backlog.py --hard-purge --apply
"""

from __future__ import annotations

import argparse
import json
import sys
from collections import defaultdict
from datetime import datetime, timezone
from email.utils import getaddresses
from pathlib import Path
from typing import Any

_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

import pymysql  # noqa: E402
from pymysql.cursors import DictCursor

from lib_config import load as load_config  # noqa: E402
from comm_domains import domain_of  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

SCRIPT_VERSION = "2.0.0"
ACTOR = "purge-comm-backlog"
INTERNAL_DOMAIN = "lanebuleuse.ch"
_LOG_DIR = Path("/var/log/maltytask")
_REPORT_FILENAME = "comm-backlog-purge-report.json"
SAMPLE_SIZE = 15  # rows printed per bucket in dry-run

# audit_row_revisions.user_id for automated scripts (system user id=1)
AUDIT_USER_ID = 1
AUDIT_USERNAME = "purge_comm_backlog"


# ── Address parsing ────────────────────────────────────────────────────────────

def _parse_addresses(raw: str) -> list[str]:
    """
    Parse a raw RFC 5322 address header value (may contain multiple comma-separated
    addresses with display names) into a list of bare lowercase email strings.
    Empty or unparseable inputs return [].
    """
    if not raw:
        return []
    pairs = getaddresses([raw])
    result: list[str] = []
    for _, addr in pairs:
        addr = addr.strip().lower()
        if addr and "@" in addr:
            result.append(addr)
    return result


def _is_internal(addr: str) -> bool:
    return addr.endswith(f"@{INTERNAL_DOMAIN}")


# ── Registry / pins caches ─────────────────────────────────────────────────────
# Built once per run from the DB to avoid per-row queries on 1100+ threads.

# (match_type, match_value) → (supplier_id | None, customer_id | None, is_shared)
_registry_cache: dict[tuple[str, str], tuple[int | None, int | None, bool]] | None = None
# email → (supplier_id | None, customer_id | None)
_pins_cache: dict[str, tuple[int | None, int | None]] | None = None
# email_lc / semicolon-split → set of customer_ids
_customer_email_cache: dict[str, int] | None = None
# supplier id → name (for display in report)
_supplier_names: dict[int, str] | None = None
# customer id → name
_customer_names: dict[int, str] | None = None


def _load_caches(cur: Any) -> None:
    """Bulk-load all resolution tables into module-level caches. Called once."""
    global _registry_cache, _pins_cache, _customer_email_cache
    global _supplier_names, _customer_names

    # ref_entity_email_domains
    cur.execute(
        """
        SELECT match_type, match_value, supplier_id_fk, customer_id_fk, is_shared
          FROM ref_entity_email_domains
         WHERE is_active = 1
        """
    )
    _registry_cache = {}
    for row in cur.fetchall():
        key = (row["match_type"], row["match_value"].lower())
        sup_id = int(row["supplier_id_fk"]) if row["supplier_id_fk"] else None
        cust_id = int(row["customer_id_fk"]) if row["customer_id_fk"] else None
        shared = bool(row["is_shared"])
        _registry_cache[key] = (sup_id, cust_id, shared)

    # comm_address_pins
    cur.execute("SELECT email, supplier_id_fk, customer_id_fk FROM comm_address_pins")
    _pins_cache = {}
    for row in cur.fetchall():
        email = row["email"].lower()
        sup_id = int(row["supplier_id_fk"]) if row["supplier_id_fk"] else None
        cust_id = int(row["customer_id_fk"]) if row["customer_id_fk"] else None
        _pins_cache[email] = (sup_id, cust_id)

    # ref_customers — split multi-email (semicolon-separated) rows
    cur.execute("SELECT id, name, email FROM ref_customers WHERE email IS NOT NULL AND email != ''")
    _customer_email_cache = {}
    _customer_names = {}
    for row in cur.fetchall():
        cust_id = int(row["id"])
        _customer_names[cust_id] = str(row["name"] or "")
        raw_emails = str(row["email"] or "")
        for part in raw_emails.split(";"):
            part = part.strip().lower()
            if part and "@" in part:
                _customer_email_cache[part] = cust_id

    # ref_suppliers names
    cur.execute("SELECT id, name FROM ref_suppliers")
    _supplier_names = {int(r["id"]): str(r["name"] or "") for r in cur.fetchall()}


def _resolve_address(email_lc: str) -> tuple[int | None, int | None, str]:
    """
    Resolve a single external email against the entity registry.

    Precedence (most-specific first):
      (a) exact comm_address_pins.email
      (b) ref_entity_email_domains match_type='address', is_active=1
      (c) ref_entity_email_domains match_type='domain',  is_active=1
      (d) ref_customers.email exact match (or semicolon-split)
          — OR — ref_entity_email_domains with customer_id_fk (same a/b/c precedence)
      (e) no match → (None, None, 'none')

    Returns (supplier_id | None, customer_id | None, match_kind).
    Note: is_shared=1 registry rows still return the FK, but caller can see
    the match_kind. We treat them identically to non-shared for bucketing
    (a shared domain still definitively identifies the entity if both it and
    the supplier_id_fk are set — the registry CHECK enforces exactly one party).
    """
    dom = domain_of(email_lc)

    assert _pins_cache is not None
    assert _registry_cache is not None
    assert _customer_email_cache is not None

    # (a) Exact address pin
    if email_lc in _pins_cache:
        sup_id, cust_id = _pins_cache[email_lc]
        if sup_id or cust_id:
            return sup_id, cust_id, "pin"

    # (b) Registry: exact address
    key_addr = ("address", email_lc)
    if key_addr in _registry_cache:
        sup_id, cust_id, shared = _registry_cache[key_addr]
        if sup_id or cust_id:
            kind = "address+shared" if shared else "address"
            return sup_id, cust_id, kind

    # (c) Registry: domain
    if dom:
        key_dom = ("domain", dom)
        if key_dom in _registry_cache:
            sup_id, cust_id, shared = _registry_cache[key_dom]
            if sup_id or cust_id:
                kind = "domain+shared" if shared else "domain"
                return sup_id, cust_id, kind

    # (d) ref_customers.email exact match
    if email_lc in _customer_email_cache:
        cust_id = _customer_email_cache[email_lc]
        return None, cust_id, "customer_email"

    return None, None, "none"


def _get_thread_counterparties(messages: list[dict]) -> list[str]:
    """
    Extract all DISTINCT external counterparty email addresses from a thread's
    messages.  For each message:
      direction='in'  → external from_address
      direction='out' → external addresses in to_address (comma/semicolon list)
    Internal @lanebuleuse.ch addresses are excluded.
    Returns a sorted deduplicated list (lowercase).
    """
    seen: set[str] = set()
    for msg in messages:
        direction = msg["direction"]
        if direction == "in":
            addrs = _parse_addresses(msg.get("from_address") or "")
        else:
            addrs = _parse_addresses(msg.get("to_address") or "")

        for addr in addrs:
            if addr and not _is_internal(addr):
                seen.add(addr)

    return sorted(seen)


def _classify_thread(
    thread_id: int,
    subject: str,
    messages: list[dict],
) -> dict[str, Any]:
    """
    Classify a single review-bucket thread.

    Returns a dict with keys:
      thread_id, subject, counterparties (list), bucket,
      resolved_supplier_ids (set→list), resolved_customer_ids (set→list),
      reason (str), per_address_resolution (list of dicts)
    """
    counterparties = _get_thread_counterparties(messages)

    # For each counterparty, resolve
    supplier_ids: set[int] = set()
    customer_ids: set[int] = set()
    per_addr: list[dict] = []
    noise_domains: list[str] = []

    for addr in counterparties:
        sup_id, cust_id, match_kind = _resolve_address(addr)
        per_addr.append({
            "address":     addr,
            "supplier_id": sup_id,
            "customer_id": cust_id,
            "match_kind":  match_kind,
        })
        if sup_id:
            supplier_ids.add(sup_id)
        if cust_id:
            customer_ids.add(cust_id)
        if not sup_id and not cust_id:
            dom = domain_of(addr)
            if dom:
                noise_domains.append(dom)

    # ── Bucket decision ────────────────────────────────────────────────────────
    has_supplier = len(supplier_ids) > 0
    has_customer = len(customer_ids) > 0

    if not counterparties:
        # Thread with no external counterparty at all (e.g. all-internal messages
        # or empty thread) — treat as noise
        bucket = "DELETE-NOISE"
        reason = "backlog-purge: no external counterparty found"
        resolved_supplier_id: int | None = None
        resolved_customer_id: int | None = None

    elif has_supplier and has_customer:
        bucket = "AMBIGUOUS"
        reason = (
            f"supplier_ids={sorted(supplier_ids)} AND customer_ids={sorted(customer_ids)}"
            " — both entity types present; manual review required"
        )
        resolved_supplier_id = None
        resolved_customer_id = None

    elif has_supplier and len(supplier_ids) > 1:
        bucket = "AMBIGUOUS"
        reason = (
            f"multiple distinct suppliers: {sorted(supplier_ids)}"
            " — cannot auto-assign; manual review required"
        )
        resolved_supplier_id = None
        resolved_customer_id = None

    elif has_customer and len(customer_ids) > 1:
        bucket = "AMBIGUOUS"
        reason = (
            f"multiple distinct customers: {sorted(customer_ids)}"
            " — cannot auto-assign; manual review required"
        )
        resolved_supplier_id = None
        resolved_customer_id = None

    elif has_supplier and not has_customer:
        # Single supplier, no customer → KEEP
        bucket = "KEEP"
        resolved_supplier_id = next(iter(supplier_ids))
        resolved_customer_id = None
        sup_name = (_supplier_names or {}).get(resolved_supplier_id, "?")
        reason = f"supplier_id={resolved_supplier_id} ({sup_name})"

    elif has_customer and not has_supplier:
        # Single customer, no supplier → MIGRATE
        bucket = "MIGRATE"
        resolved_supplier_id = None
        resolved_customer_id = next(iter(customer_ids))
        cust_name = (_customer_names or {}).get(resolved_customer_id, "?")
        reason = f"customer_id={resolved_customer_id} ({cust_name})"

    else:
        # No supplier, no customer → DELETE-NOISE
        bucket = "DELETE-NOISE"
        unique_noise_domains = sorted(set(noise_domains))
        dom_str = ", ".join(unique_noise_domains) if unique_noise_domains else "(no domain)"
        reason = f"backlog-purge: unregistered counterparty {dom_str}"
        resolved_supplier_id = None
        resolved_customer_id = None

    return {
        "thread_id":             thread_id,
        "subject":               subject,
        "counterparties":        counterparties,
        "bucket":                bucket,
        "resolved_supplier_id":  resolved_supplier_id,
        "resolved_customer_id":  resolved_customer_id,
        "reason":                reason,
        "per_address_resolution": per_addr,
    }


# ── Main classification pass ───────────────────────────────────────────────────

def _load_and_classify(conn: pymysql.connections.Connection) -> list[dict[str, Any]]:
    """
    Fetch all target threads + their messages; classify each.
    Returns list of classification result dicts (one per thread).
    """
    with conn.cursor(DictCursor) as cur:
        # Load caches first
        _load_caches(cur)

        # Fetch target threads
        cur.execute(
            """
            SELECT id, subject
              FROM comm_threads
             WHERE supplier_id_fk IS NULL
               AND customer_id_fk IS NULL
               AND purge_status = 'live'
             ORDER BY id
            """
        )
        threads = cur.fetchall()

    if not threads:
        return []

    # Fetch ALL messages for target threads in one query (avoid N+1)
    thread_ids = [int(t["id"]) for t in threads]
    # MySQL IN clause: safe to build since all are ints
    id_placeholders = ",".join(["%s"] * len(thread_ids))

    with conn.cursor(DictCursor) as cur:
        cur.execute(
            f"""
            SELECT thread_id_fk, direction, from_address, to_address
              FROM comm_messages
             WHERE thread_id_fk IN ({id_placeholders})
             ORDER BY thread_id_fk, sent_at
            """,
            thread_ids,
        )
        all_messages = cur.fetchall()

    # Group messages by thread_id
    msgs_by_thread: dict[int, list[dict]] = defaultdict(list)
    for msg in all_messages:
        msgs_by_thread[int(msg["thread_id_fk"])].append(dict(msg))

    results: list[dict[str, Any]] = []
    for thread in threads:
        tid = int(thread["id"])
        messages = msgs_by_thread.get(tid, [])
        classification = _classify_thread(
            thread_id=tid,
            subject=str(thread.get("subject") or ""),
            messages=messages,
        )
        results.append(classification)

    return results


# ── Audit helper ───────────────────────────────────────────────────────────────

def _write_audit(
    cur: Any,
    table: str,
    pk: int,
    before: dict,
    after: dict,
    comment: str,
) -> None:
    """Insert one audit_row_revisions row (action='update')."""
    cur.execute(
        """
        INSERT INTO audit_row_revisions
            (user_id, username, target_table, target_pk,
             action, before_json, after_json, comment)
        VALUES (%s, %s, %s, %s, 'update', %s, %s, %s)
        """,
        (
            AUDIT_USER_ID,
            AUDIT_USERNAME,
            table,
            pk,
            json.dumps(before, default=str, ensure_ascii=False),
            json.dumps(after, default=str, ensure_ascii=False),
            comment,
        ),
    )


# ── Apply ──────────────────────────────────────────────────────────────────────

def _apply(
    conn: pymysql.connections.Connection,
    classified: list[dict[str, Any]],
) -> dict[str, int]:
    """
    Perform FK sets + purge_status updates inside a single transaction.
    Each comm_threads update is audited via audit_row_revisions.
    Idempotent: WHERE clause scopes to threads still supplier_id_fk IS NULL
                AND customer_id_fk IS NULL AND purge_status='live'.

    Returns counters dict with keys: kept, migrated, soft_purged, skipped_ambiguous,
    skipped_already_handled, errors.
    """
    counters: dict[str, int] = {
        "kept": 0,
        "migrated": 0,
        "soft_purged": 0,
        "skipped_ambiguous": 0,
        "skipped_already_handled": 0,
        "errors": 0,
    }

    conn.begin()
    try:
        with conn.cursor(DictCursor) as cur:
            for cl in classified:
                tid = cl["thread_id"]
                bucket = cl["bucket"]

                if bucket == "AMBIGUOUS":
                    counters["skipped_ambiguous"] += 1
                    continue

                # Read current state for idempotency check + audit snapshot
                cur.execute(
                    "SELECT id, supplier_id_fk, customer_id_fk, purge_status, purge_reason"
                    " FROM comm_threads WHERE id = %s",
                    (tid,),
                )
                row = cur.fetchone()
                if not row:
                    counters["errors"] += 1
                    continue

                # Idempotency: skip if already re-assigned or purged
                if row["supplier_id_fk"] is not None or row["customer_id_fk"] is not None:
                    counters["skipped_already_handled"] += 1
                    continue
                if row["purge_status"] != "live":
                    counters["skipped_already_handled"] += 1
                    continue

                before = {
                    "supplier_id_fk": row["supplier_id_fk"],
                    "customer_id_fk": row["customer_id_fk"],
                    "purge_status":   row["purge_status"],
                    "purge_reason":   row["purge_reason"],
                }

                try:
                    if bucket == "KEEP":
                        sup_id = cl["resolved_supplier_id"]
                        cur.execute(
                            """
                            UPDATE comm_threads
                               SET supplier_id_fk = %s
                             WHERE id = %s
                               AND supplier_id_fk IS NULL
                               AND customer_id_fk IS NULL
                               AND purge_status = 'live'
                            """,
                            (sup_id, tid),
                        )
                        after = {**before, "supplier_id_fk": sup_id}
                        _write_audit(
                            cur, "comm_threads", tid, before, after,
                            f"purge_comm_backlog Phase1 KEEP: {cl['reason']}",
                        )
                        counters["kept"] += 1

                    elif bucket == "MIGRATE":
                        cust_id = cl["resolved_customer_id"]
                        cur.execute(
                            """
                            UPDATE comm_threads
                               SET customer_id_fk = %s,
                                   purge_status   = 'migrated_customer'
                             WHERE id = %s
                               AND supplier_id_fk IS NULL
                               AND customer_id_fk IS NULL
                               AND purge_status = 'live'
                            """,
                            (cust_id, tid),
                        )
                        after = {
                            **before,
                            "customer_id_fk": cust_id,
                            "purge_status":   "migrated_customer",
                        }
                        _write_audit(
                            cur, "comm_threads", tid, before, after,
                            f"purge_comm_backlog Phase1 MIGRATE: {cl['reason']}",
                        )
                        counters["migrated"] += 1

                    elif bucket == "DELETE-NOISE":
                        purge_reason = cl["reason"][:255]  # column VARCHAR(255)
                        cur.execute(
                            """
                            UPDATE comm_threads
                               SET purge_status = 'soft_purged',
                                   purge_reason = %s
                             WHERE id = %s
                               AND supplier_id_fk IS NULL
                               AND customer_id_fk IS NULL
                               AND purge_status = 'live'
                            """,
                            (purge_reason, tid),
                        )
                        # Also soft-purge child messages in same transaction
                        cur.execute(
                            """
                            UPDATE comm_messages
                               SET purge_status = 'soft_purged'
                             WHERE thread_id_fk = %s
                               AND purge_status = 'live'
                            """,
                            (tid,),
                        )
                        after = {
                            **before,
                            "purge_status": "soft_purged",
                            "purge_reason": purge_reason,
                        }
                        _write_audit(
                            cur, "comm_threads", tid, before, after,
                            f"purge_comm_backlog Phase1 DELETE-NOISE: {cl['reason']}",
                        )
                        counters["soft_purged"] += 1

                except Exception as exc:
                    # Per-row error: bubble up to rollback the whole transaction
                    raise RuntimeError(
                        f"Error processing thread_id={tid} bucket={bucket}: {exc}"
                    ) from exc

        conn.commit()

    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass
        raise

    return counters


# ── Report + print helpers ─────────────────────────────────────────────────────

def _bucket_summary(classified: list[dict]) -> dict[str, int]:
    counts: dict[str, int] = {
        "KEEP":         0,
        "MIGRATE":      0,
        "DELETE-NOISE": 0,
        "AMBIGUOUS":    0,
    }
    for cl in classified:
        counts[cl["bucket"]] = counts.get(cl["bucket"], 0) + 1
    return counts


def _print_dry_run_report(classified: list[dict[str, Any]]) -> None:
    counts = _bucket_summary(classified)
    total = len(classified)
    w = 72

    print()
    print("=" * w)
    print(f"DRY-RUN — purge_comm_backlog.py v{SCRIPT_VERSION}")
    print("=" * w)
    print(f"  Target threads (review bucket):  {total:>5}")
    print()
    print("  Bucket counts:")
    print(f"    KEEP           (rescue → supplier):    {counts['KEEP']:>5}")
    print(f"    MIGRATE        (assign → customer):    {counts['MIGRATE']:>5}")
    print(f"    DELETE-NOISE   (soft-purge):           {counts['DELETE-NOISE']:>5}")
    print(f"    AMBIGUOUS      (manual review needed): {counts['AMBIGUOUS']:>5}")
    print()

    by_bucket: dict[str, list[dict]] = defaultdict(list)
    for cl in classified:
        by_bucket[cl["bucket"]].append(cl)

    for bucket in ("KEEP", "MIGRATE", "DELETE-NOISE", "AMBIGUOUS"):
        rows = by_bucket[bucket]
        if not rows:
            continue
        print("-" * w)
        print(f"  {bucket} — {len(rows)} thread(s)  (showing up to {SAMPLE_SIZE})")
        print("-" * w)
        for cl in rows[:SAMPLE_SIZE]:
            subj = cl["subject"][:60] if cl["subject"] else "(no subject)"
            cps = ", ".join(cl["counterparties"][:4])
            if len(cl["counterparties"]) > 4:
                cps += f" … +{len(cl['counterparties']) - 4} more"
            print(f"  thread_id={cl['thread_id']}")
            print(f"    subject     : {subj!r}")
            print(f"    counterparty: {cps}")
            print(f"    reason      : {cl['reason']}")
            print()
        if len(rows) > SAMPLE_SIZE:
            print(f"    … and {len(rows) - SAMPLE_SIZE} more — see full report JSON.")
            print()

    print("=" * w)
    print("DRY-RUN COMPLETE — no writes made.")
    print("=" * w)
    print()


def _write_report(
    dry_run: bool,
    classified: list[dict[str, Any]],
    apply_counters: dict[str, int] | None,
) -> Path:
    counts = _bucket_summary(classified)

    # Build serialisable per-thread list (sets → lists for JSON)
    report_rows = []
    for cl in classified:
        report_rows.append({
            "thread_id":             cl["thread_id"],
            "subject":               cl["subject"],
            "bucket":                cl["bucket"],
            "counterparties":        cl["counterparties"],
            "resolved_supplier_id":  cl["resolved_supplier_id"],
            "resolved_customer_id":  cl["resolved_customer_id"],
            "reason":                cl["reason"],
            "per_address_resolution": cl["per_address_resolution"],
        })

    report: dict[str, Any] = {
        "generated_at":   datetime.now(timezone.utc).isoformat(),
        "script_version": SCRIPT_VERSION,
        "dry_run":        dry_run,
        "total_target":   len(classified),
        "bucket_counts":  counts,
        "threads":        report_rows,
    }
    if apply_counters is not None:
        report["apply_counters"] = apply_counters

    if _LOG_DIR.is_dir() and _LOG_DIR.stat().st_mode & 0o200:  # group/owner writable
        out_path = _LOG_DIR / _REPORT_FILENAME
    else:
        out_path = Path("/tmp") / _REPORT_FILENAME

    try:
        out_path.write_text(
            json.dumps(report, indent=2, ensure_ascii=False),
            encoding="utf-8",
        )
    except PermissionError:
        out_path = Path("/tmp") / _REPORT_FILENAME
        out_path.write_text(
            json.dumps(report, indent=2, ensure_ascii=False),
            encoding="utf-8",
        )

    return out_path


# ── Phase 2: hard-purge constants ─────────────────────────────────────────────

# Physical root for email attachment files (sole-referrer bytes will be deleted
# from here).  Files outside this tree are NEVER touched.
_EMAIL_ATTACH_ROOT = "/var/www/maltytask/data/email-attachments"

# Snapshot path for the hard-purge before-state.
_HARDPURGE_SNAPSHOT_PATH = _LOG_DIR / "comm-hardpurge-snapshot.json"
_HARDPURGE_REPORT_PATH   = _LOG_DIR / "comm-hardpurge-report.json"

# All non-comm_message_docs FK tables that reference doc_files.id.
# (verified via information_schema.KEY_COLUMN_USAGE on 2026-06-21)
_NON_COMM_REFERRER_TABLES: list[tuple[str, str]] = [
    ("doc_ambiguous",           "file_id"),
    ("doc_delivery_notes",      "file_id"),
    ("doc_invoices",            "file_id"),
    ("doc_review_queue",        "file_id_fk"),
    ("inv_deliveries",          "file_id_fk"),
    ("ord_orders",              "source_file_id_fk"),
    ("supplier_cert_documents", "doc_file_id_fk"),
]


# ── Phase 2: data-gathering ────────────────────────────────────────────────────

def _gather_hard_purge_targets(
    conn: pymysql.connections.Connection,
) -> dict:
    """
    Gather all rows to be hard-deleted and classify each doc_file as
    SOLE_REFERRER (delete row + bytes) or PRESERVED (delete link only).

    Returns a dict with:
      threads         — list of dicts (id, subject, purge_reason, created_at)
      messages        — list of dicts (id, thread_id_fk, …)
      links           — list of dicts (id, message_id_fk, doc_file_id_fk)
      sole_ref_docs   — list of dicts (full doc_files row)
      preserved_docs  — list of dicts (doc_files row + preservation_reasons list)
      stats           — summary counters dict
    """
    with conn.cursor(DictCursor) as cur:
        # ── Target threads ─────────────────────────────────────────────────────
        cur.execute(
            """
            SELECT id, subject, purge_reason, purge_status, purge_reason,
                   created_at, updated_at
              FROM comm_threads
             WHERE purge_status = 'soft_purged'
             ORDER BY id
            """
        )
        threads = [dict(r) for r in cur.fetchall()]
        if not threads:
            return {
                "threads": [], "messages": [], "links": [],
                "sole_ref_docs": [], "preserved_docs": [],
                "stats": {"threads": 0, "messages": 0, "links": 0,
                          "sole_ref": 0, "preserved": 0,
                          "bytes_to_free": 0, "bytes_preserved": 0},
            }

        thread_ids = [int(t["id"]) for t in threads]
        id_ph = ",".join(["%s"] * len(thread_ids))

        # ── Target messages ────────────────────────────────────────────────────
        cur.execute(
            f"""
            SELECT id, thread_id_fk, from_address, subject, sent_at, purge_status
              FROM comm_messages
             WHERE thread_id_fk IN ({id_ph})
             ORDER BY id
            """,
            thread_ids,
        )
        messages = [dict(r) for r in cur.fetchall()]
        msg_ids = [int(m["id"]) for m in messages]

        # ── Target comm_message_docs links ─────────────────────────────────────
        links: list[dict] = []
        distinct_dfids: set[int] = set()
        if msg_ids:
            msg_ph = ",".join(["%s"] * len(msg_ids))
            cur.execute(
                f"""
                SELECT id, message_id_fk, doc_file_id_fk, attachment_filename,
                       mime_type, direction
                  FROM comm_message_docs
                 WHERE message_id_fk IN ({msg_ph})
                 ORDER BY id
                """,
                msg_ids,
            )
            links = [dict(r) for r in cur.fetchall()]
            distinct_dfids = {int(lnk["doc_file_id_fk"]) for lnk in links}

        # ── Per-doc sole-referrer check ────────────────────────────────────────
        # Build set of message_ids in the purge set for fast membership testing.
        purge_msg_ids: set[int] = {int(m["id"]) for m in messages}

        sole_ref_docs: list[dict] = []
        preserved_docs: list[dict] = []

        for dfid in sorted(distinct_dfids):
            preservation_reasons: list[str] = []

            # (a) comm_message_docs refs outside the purge set
            cur.execute(
                """
                SELECT COUNT(*) AS n
                  FROM comm_message_docs
                 WHERE doc_file_id_fk = %s
                   AND message_id_fk NOT IN (
                       SELECT m.id FROM comm_messages m
                       JOIN comm_threads t ON t.id = m.thread_id_fk
                       WHERE t.purge_status = 'soft_purged'
                   )
                """,
                (dfid,),
            )
            surviving_comm = int(cur.fetchone()["n"])
            if surviving_comm > 0:
                preservation_reasons.append(
                    f"comm_message_docs: {surviving_comm} surviving reference(s) "
                    f"outside the purge set"
                )

            # (b) Non-comm FK tables
            for tbl, col in _NON_COMM_REFERRER_TABLES:
                cur.execute(
                    f"SELECT COUNT(*) AS n FROM `{tbl}` WHERE `{col}` = %s",
                    (dfid,),
                )
                cnt = int(cur.fetchone()["n"])
                if cnt > 0:
                    preservation_reasons.append(
                        f"{tbl}.{col}: {cnt} reference(s)"
                    )

            # Fetch the doc_files row
            cur.execute(
                "SELECT * FROM doc_files WHERE id = %s",
                (dfid,),
            )
            df_row = dict(cur.fetchone() or {})
            if not df_row:
                # Row disappeared — skip silently (idempotent re-run)
                continue

            if preservation_reasons:
                df_row["preservation_reasons"] = preservation_reasons
                preserved_docs.append(df_row)
            else:
                # Extra safety: verify local_path is inside the email-attachments tree
                lp = str(df_row.get("local_path") or "")
                if not lp.startswith(_EMAIL_ATTACH_ROOT):
                    df_row["preservation_reasons"] = [
                        f"local_path '{lp}' is outside the email-attachments tree "
                        f"({_EMAIL_ATTACH_ROOT}) — refusing to delete"
                    ]
                    preserved_docs.append(df_row)
                else:
                    sole_ref_docs.append(df_row)

    # ── Byte tallies ───────────────────────────────────────────────────────────
    bytes_to_free = sum(
        int(d.get("file_size_bytes") or 0) for d in sole_ref_docs
    )
    bytes_preserved = sum(
        int(d.get("file_size_bytes") or 0) for d in preserved_docs
    )

    return {
        "threads":       threads,
        "messages":      messages,
        "links":         links,
        "sole_ref_docs": sole_ref_docs,
        "preserved_docs": preserved_docs,
        "stats": {
            "threads":         len(threads),
            "messages":        len(messages),
            "links":           len(links),
            "distinct_docs":   len(distinct_dfids),
            "sole_ref":        len(sole_ref_docs),
            "preserved":       len(preserved_docs),
            "bytes_to_free":   bytes_to_free,
            "bytes_preserved": bytes_preserved,
        },
    }


# ── Phase 2: dry-run report ────────────────────────────────────────────────────

def _print_hard_purge_dry_run(targets: dict) -> None:
    """Print the human-readable dry-run summary for --hard-purge."""
    s = targets["stats"]
    w = 72

    print()
    print("=" * w)
    print(f"DRY-RUN — purge_comm_backlog.py v{SCRIPT_VERSION} --hard-purge (Phase 2)")
    print("=" * w)
    print(f"  Threads to hard-delete:          {s['threads']:>6}")
    print(f"  Messages to hard-delete:         {s['messages']:>6}")
    print(f"  comm_message_docs links:         {s['links']:>6}")
    print(f"  Distinct doc_files referenced:   {s['distinct_docs']:>6}")
    print()
    print(f"  doc_files — SOLE-REFERRER (row + bytes deleted): {s['sole_ref']:>5}")
    print(f"  doc_files — PRESERVED-SHARED  (link only rm'd): {s['preserved']:>5}")
    print()
    mb_free = s["bytes_to_free"] / 1024 / 1024
    mb_pres = s["bytes_preserved"] / 1024 / 1024
    print(f"  Bytes to free:     {s['bytes_to_free']:>12,}  ({mb_free:.1f} MB)")
    print(f"  Bytes preserved:   {s['bytes_preserved']:>12,}  ({mb_pres:.1f} MB)")
    print()

    # Preservation reason breakdown
    if targets["preserved_docs"]:
        reason_counts: dict[str, int] = {}
        for d in targets["preserved_docs"]:
            for r in d.get("preservation_reasons", []):
                key = r.split(":")[0]
                reason_counts[key] = reason_counts.get(key, 0) + 1
        print("  Preservation reason breakdown:")
        for k, v in sorted(reason_counts.items()):
            print(f"    {k}: {v} doc_file(s)")
        print()

    # Sample threads
    print("-" * w)
    print(f"  Sample threads to delete (up to {SAMPLE_SIZE}):")
    print("-" * w)
    for t in targets["threads"][:SAMPLE_SIZE]:
        subj = str(t.get("subject") or "(no subject)")[:60]
        print(f"  thread_id={t['id']}  subject={subj!r}")
        print(f"    purge_reason: {t.get('purge_reason','')[:80]}")
    if len(targets["threads"]) > SAMPLE_SIZE:
        print(f"  … and {len(targets['threads']) - SAMPLE_SIZE} more — see full report JSON.")
    print()

    # Sample sole-referrer docs
    print("-" * w)
    print(f"  Sample SOLE-REFERRER docs (up to 5 — row + bytes will be deleted):")
    print("-" * w)
    for d in targets["sole_ref_docs"][:5]:
        lp = str(d.get("local_path") or "(null)")
        print(f"  doc_file_id={d['id']}  {int(d.get('file_size_bytes') or 0):>10,} B  {lp}")
    if len(targets["sole_ref_docs"]) > 5:
        print(f"  … and {len(targets['sole_ref_docs']) - 5} more — see full report JSON.")
    print()

    # Sample preserved docs
    if targets["preserved_docs"]:
        print("-" * w)
        print("  Sample PRESERVED docs (up to 5 — link removed, doc kept):")
        print("-" * w)
        for d in targets["preserved_docs"][:5]:
            lp = str(d.get("local_path") or "(null)")
            reasons = "; ".join(d.get("preservation_reasons", []))
            print(f"  doc_file_id={d['id']}  {lp}")
            print(f"    Reason: {reasons[:100]}")
        if len(targets["preserved_docs"]) > 5:
            print(f"  … and {len(targets['preserved_docs']) - 5} more — see full report JSON.")
        print()

    # Sole-referrer logic SQL proof
    print("-" * w)
    print("  SOLE-REFERRER SQL logic (used for each candidate doc_file_id = ?):")
    print("-" * w)
    print("""
  -- (a) No surviving comm_message_docs ref outside the purge set:
  SELECT COUNT(*) FROM comm_message_docs
   WHERE doc_file_id_fk = ?
     AND message_id_fk NOT IN (
         SELECT m.id FROM comm_messages m
         JOIN comm_threads t ON t.id = m.thread_id_fk
         WHERE t.purge_status = 'soft_purged'
     )
  -- must return 0

  -- (b) No reference in any non-comm table (checked for each):""")
    for tbl, col in _NON_COMM_REFERRER_TABLES:
        print(f"  SELECT COUNT(*) FROM `{tbl}` WHERE `{col}` = ?  -- must return 0")
    print("""
  -- (c) local_path must start with /var/www/maltytask/data/email-attachments
  -- All 3 conditions must hold; otherwise PRESERVE.
    """)

    print("=" * w)
    print("DRY-RUN COMPLETE — no writes made.")
    print("=" * w)
    print()


def _write_hard_purge_report(dry_run: bool, targets: dict, apply_stats: dict | None) -> Path:
    """Write the JSON dry-run / apply report for Phase 2."""

    # Make sole_ref_docs and preserved_docs JSON-serialisable
    def _doc_summary(d: dict) -> dict:
        return {
            "id":               d.get("id"),
            "file_name":        d.get("file_name"),
            "local_path":       d.get("local_path"),
            "source_folder":    d.get("source_folder"),
            "file_size_bytes":  d.get("file_size_bytes"),
            "preservation_reasons": d.get("preservation_reasons", []),
        }

    report: dict = {
        "generated_at":    datetime.now(timezone.utc).isoformat(),
        "script_version":  SCRIPT_VERSION,
        "mode":            "hard-purge",
        "dry_run":         dry_run,
        "stats":           targets["stats"],
        "non_comm_referrer_tables_checked": [
            f"{tbl}.{col}" for tbl, col in _NON_COMM_REFERRER_TABLES
        ],
        "threads":         [
            {"id": t["id"], "subject": t.get("subject"),
             "purge_reason": t.get("purge_reason")}
            for t in targets["threads"]
        ],
        "sole_ref_docs":   [_doc_summary(d) for d in targets["sole_ref_docs"]],
        "preserved_docs":  [_doc_summary(d) for d in targets["preserved_docs"]],
    }
    if apply_stats is not None:
        report["apply_stats"] = apply_stats

    # Try the canonical log dir first, fall back to /tmp
    for base in (_LOG_DIR, Path("/tmp")):
        if not base.is_dir():
            continue
        out = base / "comm-hardpurge-report.json"
        try:
            out.write_text(json.dumps(report, indent=2, default=str, ensure_ascii=False), encoding="utf-8")
            return out
        except PermissionError:
            continue
    raise RuntimeError("Cannot write hard-purge report — both /var/log/maltytask and /tmp failed")


# ── Phase 2: snapshot ──────────────────────────────────────────────────────────

def _write_hard_purge_snapshot(targets: dict) -> Path:
    """
    Write a full before-state snapshot of all rows that will be deleted.
    This is the irreversibility safeguard — written before any deletes.
    """
    snapshot = {
        "snapshot_at":  datetime.now(timezone.utc).isoformat(),
        "script_version": SCRIPT_VERSION,
        "threads":      targets["threads"],
        "messages":     [
            {k: str(v) if hasattr(v, "isoformat") else v
             for k, v in m.items()}
            for m in targets["messages"]
        ],
        "links":        targets["links"],
        "sole_ref_docs": targets["sole_ref_docs"],
        "preserved_docs": targets["preserved_docs"],
    }

    for base in (_LOG_DIR, Path("/tmp")):
        if not base.is_dir():
            continue
        out = base / "comm-hardpurge-snapshot.json"
        try:
            out.write_text(
                json.dumps(snapshot, indent=2, default=str, ensure_ascii=False),
                encoding="utf-8",
            )
            return out
        except PermissionError:
            continue
    raise RuntimeError("Cannot write snapshot — both /var/log/maltytask and /tmp failed")


# ── Phase 2: apply ─────────────────────────────────────────────────────────────

def _hard_purge_apply(
    conn: pymysql.connections.Connection,
    targets: dict,
) -> dict:
    """
    Perform the hard-purge in a single transaction (children first):
      1. Write audit tombstones for all rows that will be deleted.
      2. Delete comm_message_docs links for purged messages.
      3. Delete physical files for sole-referrer doc_files.
      4. Delete sole-referrer doc_files rows.
      5. Delete comm_messages rows.
      6. Delete comm_threads rows.

    Returns apply_stats dict.
    """
    import os

    threads      = targets["threads"]
    messages     = targets["messages"]
    links        = targets["links"]
    sole_ref_docs = targets["sole_ref_docs"]
    preserved_docs = targets["preserved_docs"]

    if not threads:
        return {"status": "nothing_to_do", "threads_deleted": 0}

    # Collect IDs
    thread_ids     = [int(t["id"]) for t in threads]
    message_ids    = [int(m["id"]) for m in messages]
    link_ids       = [int(lnk["id"]) for lnk in links]
    sole_ref_dfids = [int(d["id"]) for d in sole_ref_docs]
    preserved_dfids = [int(d["id"]) for d in preserved_docs]

    # Preserved-doc links: only the comm_message_docs rows for purged messages
    # referencing a preserved doc_file.
    preserved_dfid_set = set(preserved_dfids)
    preserved_link_ids = [
        int(lnk["id"]) for lnk in links
        if int(lnk["doc_file_id_fk"]) in preserved_dfid_set
    ]

    apply_stats: dict = {
        "threads_deleted":       0,
        "messages_deleted":      0,
        "links_deleted":         0,
        "doc_files_row_deleted": 0,
        "doc_files_preserved":   len(preserved_docs),
        "bytes_freed":           0,
        "files_unlinked":        0,
        "files_unlink_failed":   0,
        "audit_rows_written":    0,
        "errors":                [],
    }

    conn.begin()
    try:
        with conn.cursor(DictCursor) as cur:
            # ── 1. Audit tombstones (action='update', after_json={_tombstone:…}) ──
            purge_label = "purge_comm_backlog Phase2 hard-purge"

            for t in threads:
                before = {
                    "id": t["id"], "subject": t.get("subject"),
                    "purge_status": t.get("purge_status"),
                    "purge_reason": t.get("purge_reason"),
                }
                after = {"_tombstone": "hard_purge_phase2", "id": t["id"]}
                _write_audit(cur, "comm_threads", int(t["id"]), before, after,
                             f"{purge_label}: thread deleted")
                apply_stats["audit_rows_written"] += 1

            for m in messages:
                before = {
                    "id": m["id"], "thread_id_fk": m.get("thread_id_fk"),
                    "subject": m.get("subject"), "purge_status": m.get("purge_status"),
                }
                after = {"_tombstone": "hard_purge_phase2", "id": m["id"]}
                _write_audit(cur, "comm_messages", int(m["id"]), before, after,
                             f"{purge_label}: message deleted (thread {m.get('thread_id_fk')})")
                apply_stats["audit_rows_written"] += 1

            for d in sole_ref_docs:
                before = {
                    "id": d["id"], "file_name": d.get("file_name"),
                    "local_path": d.get("local_path"),
                    "file_size_bytes": d.get("file_size_bytes"),
                    "source_folder": d.get("source_folder"),
                }
                after = {"_tombstone": "hard_purge_phase2_sole_referrer", "id": d["id"]}
                _write_audit(cur, "doc_files", int(d["id"]), before, after,
                             f"{purge_label}: sole-referrer doc deleted")
                apply_stats["audit_rows_written"] += 1

            # ── 2. Delete comm_message_docs links ────────────────────────────────
            # All links for purged messages (both sole-ref and preserved-doc links).
            if link_ids:
                lnk_ph = ",".join(["%s"] * len(link_ids))
                cur.execute(
                    f"DELETE FROM comm_message_docs WHERE id IN ({lnk_ph})",
                    link_ids,
                )
                apply_stats["links_deleted"] = cur.rowcount

            # ── 3. Physical file deletion for sole-referrer docs ─────────────────
            for d in sole_ref_docs:
                lp = str(d.get("local_path") or "")
                if not lp:
                    apply_stats["errors"].append(
                        f"doc_file_id={d['id']}: local_path is empty, skipping unlink"
                    )
                    apply_stats["files_unlink_failed"] += 1
                    continue
                if not lp.startswith(_EMAIL_ATTACH_ROOT):
                    apply_stats["errors"].append(
                        f"doc_file_id={d['id']}: local_path '{lp}' outside "
                        f"email-attachments tree — refusing to delete"
                    )
                    apply_stats["files_unlink_failed"] += 1
                    continue
                try:
                    os.unlink(lp)
                    apply_stats["files_unlinked"] += 1
                    apply_stats["bytes_freed"] += int(d.get("file_size_bytes") or 0)
                except FileNotFoundError:
                    # Already gone — count as freed (idempotent)
                    apply_stats["files_unlinked"] += 1
                    apply_stats["bytes_freed"] += int(d.get("file_size_bytes") or 0)
                except OSError as exc:
                    apply_stats["errors"].append(
                        f"doc_file_id={d['id']}: unlink '{lp}' failed: {exc}"
                    )
                    apply_stats["files_unlink_failed"] += 1

            # ── 4. Delete sole-referrer doc_files rows ───────────────────────────
            if sole_ref_dfids:
                df_ph = ",".join(["%s"] * len(sole_ref_dfids))
                cur.execute(
                    f"DELETE FROM doc_files WHERE id IN ({df_ph})",
                    sole_ref_dfids,
                )
                apply_stats["doc_files_row_deleted"] = cur.rowcount

            # ── 5. Delete comm_messages ──────────────────────────────────────────
            if message_ids:
                msg_ph = ",".join(["%s"] * len(message_ids))
                cur.execute(
                    f"DELETE FROM comm_messages WHERE id IN ({msg_ph})",
                    message_ids,
                )
                apply_stats["messages_deleted"] = cur.rowcount

            # ── 6. Delete comm_threads ───────────────────────────────────────────
            thr_ph = ",".join(["%s"] * len(thread_ids))
            cur.execute(
                f"DELETE FROM comm_threads WHERE id IN ({thr_ph})",
                thread_ids,
            )
            apply_stats["threads_deleted"] = cur.rowcount

        conn.commit()

    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass
        raise

    return apply_stats


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(
        description=(
            "Phase 1: classify review-bucket threads (KEEP/MIGRATE/DELETE-NOISE/AMBIGUOUS).\n"
            "Phase 2 (--hard-purge): permanently delete soft_purged threads + attachments.\n"
            "Dry-run by default for both modes — use --apply only after operator approval.\n"
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    ap.add_argument(
        "--apply", action="store_true", default=False,
        help=(
            "Perform writes (FK sets, purge_status updates, or hard deletes). "
            "Default: dry-run only."
        ),
    )
    ap.add_argument(
        "--hard-purge", action="store_true", default=False,
        dest="hard_purge",
        help=(
            "Phase 2: hard-delete comm_threads WHERE purge_status='soft_purged' "
            "and their messages/attachments. IRREVERSIBLE when combined with --apply. "
            "Dry-run by default."
        ),
    )
    args = ap.parse_args()

    dry_run = not args.apply
    mode_label = "DRY-RUN (no writes)" if dry_run else "** APPLY (IRREVERSIBLE) **"

    if args.hard_purge:
        phase_label = "Phase 2 hard-purge"
    else:
        phase_label = "Phase 1 classify"

    print(f"\npurge_comm_backlog.py v{SCRIPT_VERSION} — {phase_label} — {mode_label}", flush=True)
    print()

    # ── Connect ────────────────────────────────────────────────────────────────
    print("Connecting to maltytask MySQL …", end=" ", flush=True)
    cfg = load_config()
    conn = pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_user,
        password=cfg.db_password,
        database=cfg.db_name,
        charset="utf8mb4",
        cursorclass=DictCursor,
        autocommit=False,
        connect_timeout=10,
        read_timeout=120,
        write_timeout=120,
    )
    print("connected.", flush=True)

    try:
        # ══════════════════════════════════════════════════════════════════════
        # Phase 2 — hard-purge
        # ══════════════════════════════════════════════════════════════════════
        if args.hard_purge:
            print("Gathering hard-purge targets (soft_purged threads) …", flush=True)
            targets = _gather_hard_purge_targets(conn)
            s = targets["stats"]
            print(
                f"Found {s['threads']} thread(s), {s['messages']} message(s), "
                f"{s['links']} link(s), {s['distinct_docs']} distinct doc_file(s) "
                f"({s['sole_ref']} sole-ref, {s['preserved']} preserved).",
                flush=True,
            )
            print()

            apply_stats: dict | None = None

            if dry_run:
                _print_hard_purge_dry_run(targets)
            else:
                if not targets["threads"]:
                    print("Nothing to do — no soft_purged threads found.", flush=True)
                else:
                    # Write snapshot BEFORE any deletes
                    snap_path = _write_hard_purge_snapshot(targets)
                    print(f"Before-state snapshot written to: {snap_path}", flush=True)
                    print("Executing hard-purge (single transaction) …", flush=True)
                    apply_stats = _hard_purge_apply(conn, targets)
                    print()
                    print("=" * 72)
                    print("HARD-PURGE APPLY COMPLETE")
                    print("=" * 72)
                    print(f"  Threads deleted:         {apply_stats['threads_deleted']:>6}")
                    print(f"  Messages deleted:        {apply_stats['messages_deleted']:>6}")
                    print(f"  Links deleted:           {apply_stats['links_deleted']:>6}")
                    print(f"  doc_files rows deleted:  {apply_stats['doc_files_row_deleted']:>6}")
                    print(f"  doc_files preserved:     {apply_stats['doc_files_preserved']:>6}")
                    print(f"  Physical files unlinked: {apply_stats['files_unlinked']:>6}")
                    print(f"  Unlink failures:         {apply_stats['files_unlink_failed']:>6}")
                    print(f"  Bytes freed:             {apply_stats['bytes_freed']:>12,}")
                    print(f"  Audit rows written:      {apply_stats['audit_rows_written']:>6}")
                    if apply_stats["errors"]:
                        print()
                        print(f"  ERRORS ({len(apply_stats['errors'])}):")
                        for e in apply_stats["errors"]:
                            print(f"    {e}")
                    print("=" * 72)
                    print()

            report_path = _write_hard_purge_report(dry_run, targets, apply_stats)
            print(f"Full hard-purge report written to: {report_path}", flush=True)

        # ══════════════════════════════════════════════════════════════════════
        # Phase 1 — classify
        # ══════════════════════════════════════════════════════════════════════
        else:
            # ── Classify ───────────────────────────────────────────────────────
            print("Loading caches and classifying review-bucket threads …", flush=True)
            classified = _load_and_classify(conn)
            counts = _bucket_summary(classified)
            print(
                f"Classified {len(classified)} thread(s): "
                f"KEEP={counts['KEEP']} MIGRATE={counts['MIGRATE']} "
                f"DELETE-NOISE={counts['DELETE-NOISE']} AMBIGUOUS={counts['AMBIGUOUS']}",
                flush=True,
            )
            print()

            apply_counters: dict[str, int] | None = None

            if dry_run:
                _print_dry_run_report(classified)
            else:
                # ── Apply ──────────────────────────────────────────────────────
                print("Applying writes (single transaction) …", flush=True)
                apply_counters = _apply(conn, classified)
                print()
                print("=" * 72)
                print("APPLY COMPLETE")
                print("=" * 72)
                print(f"  Kept (supplier FK set):       {apply_counters['kept']:>5}")
                print(f"  Migrated (customer FK set):   {apply_counters['migrated']:>5}")
                print(f"  Soft-purged (DELETE-NOISE):   {apply_counters['soft_purged']:>5}")
                print(f"  Skipped (AMBIGUOUS):          {apply_counters['skipped_ambiguous']:>5}")
                print(f"  Skipped (already handled):    {apply_counters['skipped_already_handled']:>5}")
                print(f"  Errors:                       {apply_counters['errors']:>5}")
                print("=" * 72)
                print()

            # ── Write JSON report ──────────────────────────────────────────────
            report_path = _write_report(dry_run, classified, apply_counters)
            print(f"Full reconcile report written to: {report_path}", flush=True)

    finally:
        try:
            conn.close()
        except Exception:
            pass


if __name__ == "__main__":
    main()
