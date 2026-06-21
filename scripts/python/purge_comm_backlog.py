#!/usr/bin/env python3
"""
purge_comm_backlog.py — Phase 1: classify the comm_threads review-bucket
                         (supplier_id_fk IS NULL AND customer_id_fk IS NULL
                          AND purge_status='live') into 4 buckets and
                         (on --apply) soft-act on them.

DISARM CONVENTION:
  --dry-run is the DEFAULT.  Prints bucket counts + sample rows.
  Emits a full reconcile-report JSON to /var/log/maltytask/comm-backlog-purge-report.json.
  --apply performs the FK sets + purge_status updates (each through audit_row_revisions).

Buckets:
  KEEP         — resolves to exactly 1 supplier, no customer.
                 Sets supplier_id_fk on the thread; purge_status stays 'live'.
  MIGRATE      — resolves to exactly 1 customer, no supplier.
                 Sets customer_id_fk + purge_status='migrated_customer'.
  DELETE-NOISE — no entity resolution at all.
                 Sets purge_status='soft_purged' + purge_reason.
  AMBIGUOUS    — conflicting resolution (supplier+customer, or ≥2 distinct
                 suppliers, or ≥2 distinct customers).
                 Left 'live'; surfaced for manual review.

CARDINAL RULE — NON-FISCAL: this is a CRM/correspondence-layer purge.
Nothing here feeds COGS, COP, WAC, BOM, beer-tax, stock, or any financial
computation.

Usage:
  # Dry-run (default) — bucket counts + samples, no writes:
  python3 scripts/python/purge_comm_backlog.py

  # Apply (operator-approved only) — FK sets + purge_status updates:
  python3 scripts/python/purge_comm_backlog.py --apply
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

SCRIPT_VERSION = "1.0.0"
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


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(
        description=(
            "Phase 1 comm-backlog purge: classify review-bucket threads into\n"
            "KEEP / MIGRATE / DELETE-NOISE / AMBIGUOUS.\n"
            "Dry-run by default — use --apply only after operator approval.\n"
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    ap.add_argument(
        "--apply", action="store_true", default=False,
        help=(
            "Perform FK sets + purge_status updates (each audited via "
            "audit_row_revisions). Default: dry-run only."
        ),
    )
    args = ap.parse_args()

    dry_run = not args.apply
    mode_label = "DRY-RUN (no writes)" if dry_run else "** APPLY **"

    print(f"\npurge_comm_backlog.py v{SCRIPT_VERSION} — {mode_label}", flush=True)
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
        # ── Classify ───────────────────────────────────────────────────────────
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
            # ── Apply ──────────────────────────────────────────────────────────
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

        # ── Write JSON report ──────────────────────────────────────────────────
        report_path = _write_report(dry_run, classified, apply_counters)
        print(f"Full reconcile report written to: {report_path}", flush=True)

    finally:
        try:
            conn.close()
        except Exception:
            pass


if __name__ == "__main__":
    main()
