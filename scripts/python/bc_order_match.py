"""
bc_order_match — dedup-vs-BC match engine for the maltyweb email-order pipeline.

Matches parsed email orders (doc_email_messages) against existing BC orders
(ord_orders, source='bc') to detect duplicates before promotion.

READ-ONLY: no INSERT/UPDATE/DELETE is performed anywhere in this module.

Usage (module):
    from bc_order_match import match_email_order_to_bc, MatchResult

Usage (CLI):
    python3 scripts/python/bc_order_match.py --report
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from dataclasses import dataclass
from typing import Optional

import pymysql
import pymysql.connections


# ---------------------------------------------------------------------------
# Data classes
# ---------------------------------------------------------------------------

@dataclass
class MatchResult:
    email_id: int
    subject: str
    from_addr: str
    extracted_order_ref: Optional[str]
    resolved_customer_id: Optional[int]
    resolved_customer_name: Optional[str]
    match_found: bool
    bc_order_id: Optional[int]
    bc_external_doc_no: Optional[str]
    bc_requested_date: Optional[str]
    match_method: Optional[str]
    confidence: float
    unmatched_reason: Optional[str]


# ---------------------------------------------------------------------------
# Regex patterns for order-ref extraction (applied in priority order)
# ---------------------------------------------------------------------------

_ORDER_REF_PATTERNS = [
    # "Commande No 32529", "Commande N° 32503", "commande no. 32529"
    re.compile(r"Commande\s*N[o°]?\.?\s*(\w+)", re.IGNORECASE),
    # bare "N° 1234" or "No. 5678" with >=4 digits
    re.compile(r"N[o°][\s.]*(\d{4,6})", re.IGNORECASE),
]


def _extract_order_ref(notes: str, subject: str, raw_body: str) -> Optional[str]:
    """
    Search for an order reference number in priority order:
      1. notes field
      2. subject line
      3. first 500 chars of raw_body

    Returns the captured group (stripped), or None if nothing matches.
    """
    sources = [
        notes or "",
        subject or "",
        (raw_body or "")[:500],
    ]
    for source in sources:
        for pattern in _ORDER_REF_PATTERNS:
            m = pattern.search(source)
            if m:
                ref = m.group(1).strip()
                if ref:
                    return ref
    return None


# ---------------------------------------------------------------------------
# Customer resolution helpers
# ---------------------------------------------------------------------------

def _strip_accents(text: str) -> str:
    """Fold diacritics: 'Bévanar' -> 'Bevanar'."""
    nfkd = unicodedata.normalize("NFKD", text)
    return "".join(c for c in nfkd if not unicodedata.combining(c))


def _normalize_name(text: str) -> str:
    return _strip_accents(text.lower()).strip()


def _bare_email(addr: str) -> str:
    """Extract the bare email from 'Name <email>' or return the string as-is."""
    addr = (addr or "").strip()
    m = re.search(r"<([^>]+)>", addr)
    if m:
        return m.group(1).strip().lower()
    return addr.lower()


def _token_sort_ratio(a: str, b: str) -> float:
    """
    Simple token-sort ratio: sort tokens of both strings, compare word overlap.
    Returns 0.0–1.0. Not as sophisticated as python-Levenshtein but avoids the
    extra dependency.
    """
    if not a or not b:
        return 0.0
    ta = sorted(_normalize_name(a).split())
    tb = sorted(_normalize_name(b).split())
    # Longest common subsequence of tokens (greedy set intersection proxy)
    set_a = set(ta)
    set_b = set(tb)
    common = set_a & set_b
    total = len(set_a | set_b)
    if total == 0:
        return 0.0
    return len(common) / total


def _load_customers(conn: pymysql.connections.Connection) -> list[dict]:
    """Load all ref_customers rows that have a name (email may be NULL)."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, name, email FROM ref_customers WHERE name IS NOT NULL ORDER BY id"
        )
        return list(cur.fetchall())


def _resolve_customer(
    from_address: str,
    customer_hint: str,
    is_internal_rep: bool,
    customers: list[dict],
) -> tuple[Optional[int], Optional[str]]:
    """
    Resolve to a ref_customers row. Returns (id, name) or (None, None).

    Strategy (stop at first hit):
      1. Bare email from from_address vs ref_customers.email (semicolon-split,
         case-insensitive)
      2. customer_hint fuzzy name match (token sort ratio >= 0.85)
      3. Internal reps: always (None, None)
    """
    if is_internal_rep:
        return None, None

    bare = _bare_email(from_address)

    # Step 1: email match
    if bare:
        for c in customers:
            raw_emails = c.get("email") or ""
            for e in raw_emails.split(";"):
                if e.strip().lower() == bare:
                    return c["id"], c["name"]

    # Step 2: customer_hint fuzzy name match
    hint = (customer_hint or "").strip()
    if hint:
        best_ratio = 0.0
        best_cust = None
        for c in customers:
            ratio = _token_sort_ratio(hint, c["name"])
            if ratio > best_ratio:
                best_ratio = ratio
                best_cust = c
        if best_cust and best_ratio >= 0.85:
            return best_cust["id"], best_cust["name"]

    return None, None


# ---------------------------------------------------------------------------
# Core match function
# ---------------------------------------------------------------------------

def match_email_order_to_bc(
    conn: pymysql.connections.Connection,
    parsed_email: dict,
    customer_id_override: Optional[int] = None,
    order_index: Optional[int] = None,
) -> MatchResult:
    """
    Match one parsed email order against BC orders.

    parsed_email keys: id, from_address, subject, raw_body, parsed_json
    parsed_json is already decoded as a dict.

    For multi-order emails (_kind='parsed_order_hints_multi'), supply order_index
    (0-based) to select the sub-order whose lines/requested_date are used.

    Returns a MatchResult. READ-ONLY — no DB writes.
    """
    email_id = parsed_email["id"]
    from_address = parsed_email.get("from_address") or ""
    subject = parsed_email.get("subject") or ""
    raw_body = parsed_email.get("raw_body") or ""
    pj = parsed_email.get("parsed_json") or {}

    is_internal_rep = bool(pj.get("_internal_rep"))
    notes = pj.get("notes") or ""
    customer_hint = pj.get("customer_hint") or ""

    kind = pj.get("_kind", "parsed_order_hints")
    if kind == "parsed_order_hints_multi":
        if order_index is None:
            raise ValueError(
                "match_email_order_to_bc: order_index is required for _kind='parsed_order_hints_multi'"
            )
        orders = pj.get("orders") or []
        if order_index < 0 or order_index >= len(orders):
            raise ValueError(
                f"match_email_order_to_bc: order_index={order_index} out of range "
                f"(len={len(orders)}) for email id={parsed_email.get('id')}"
            )
        sub = orders[order_index]
        requested_date = sub.get("requested_date")
        lines = sub.get("lines") or []
        # customer_hint and notes come from the sub-order if present, else top-level
        customer_hint = sub.get("customer_hint") or customer_hint
        notes = sub.get("notes") or notes
    else:
        # Single-order or legacy shape — order_index is ignored if passed
        requested_date = pj.get("requested_date")
        lines = pj.get("lines") or []

    # Early exit for internal reps
    if is_internal_rep:
        return MatchResult(
            email_id=email_id,
            subject=subject,
            from_addr=from_address,
            extracted_order_ref=None,
            resolved_customer_id=None,
            resolved_customer_name=None,
            match_found=False,
            bc_order_id=None,
            bc_external_doc_no=None,
            bc_requested_date=None,
            match_method=None,
            confidence=0.0,
            unmatched_reason="internal_rep",
        )

    # Step 1 — Extract order ref
    order_ref = _extract_order_ref(notes, subject, raw_body)

    # Step 2 — Resolve customer
    if customer_id_override is not None:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, name FROM ref_customers WHERE id = %s LIMIT 1",
                (customer_id_override,)
            )
            override_row = cur.fetchone()
        if not override_row:
            raise ValueError(f"Customer id={customer_id_override} not found in ref_customers")
        cust_id = override_row["id"]
        cust_name = override_row["name"]
    else:
        customers = _load_customers(conn)
        cust_id, cust_name = _resolve_customer(
            from_address, customer_hint, is_internal_rep, customers
        )

    # Step 3 — PRIMARY match
    bc_id: Optional[int] = None
    bc_ext_doc: Optional[str] = None
    bc_date: Optional[str] = None
    method: Optional[str] = None
    confidence = 0.0

    if order_ref is not None:
        if cust_id is not None:
            # Exact: ref + customer
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT id, external_document_no, requested_date "
                    "FROM ord_orders "
                    "WHERE source = %s "
                    "  AND external_document_no = %s "
                    "  AND customer_id_fk = %s "
                    "LIMIT 1",
                    ("bc", order_ref, cust_id),
                )
                row = cur.fetchone()
            if row:
                bc_id = row["id"]
                bc_ext_doc = row["external_document_no"]
                bc_date = (
                    row["requested_date"].strftime("%Y-%m-%d")
                    if row["requested_date"]
                    else None
                )
                method = "order_ref_exact"
                confidence = 0.95
        else:
            # Ref only — try to find unambiguous BC match
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT id, external_document_no, requested_date, customer_id_fk "
                    "FROM ord_orders "
                    "WHERE source = %s "
                    "  AND external_document_no = %s "
                    "LIMIT 2",
                    ("bc", order_ref),
                )
                rows = cur.fetchall()
            if len(rows) == 1:
                row = rows[0]
                bc_id = row["id"]
                bc_ext_doc = row["external_document_no"]
                bc_date = (
                    row["requested_date"].strftime("%Y-%m-%d")
                    if row["requested_date"]
                    else None
                )
                method = "order_ref_no_customer"
                confidence = 0.80
            elif len(rows) >= 2:
                # Ambiguous — multiple customers have this ref
                return MatchResult(
                    email_id=email_id,
                    subject=subject,
                    from_addr=from_address,
                    extracted_order_ref=order_ref,
                    resolved_customer_id=None,
                    resolved_customer_name=None,
                    match_found=False,
                    bc_order_id=None,
                    bc_external_doc_no=None,
                    bc_requested_date=None,
                    match_method=None,
                    confidence=0.0,
                    unmatched_reason="order_ref_ambiguous_customer_unresolved",
                )

    # If primary matched with sufficient confidence, we're done
    if confidence >= 0.75 and bc_id is not None:
        return MatchResult(
            email_id=email_id,
            subject=subject,
            from_addr=from_address,
            extracted_order_ref=order_ref,
            resolved_customer_id=cust_id,
            resolved_customer_name=cust_name,
            match_found=True,
            bc_order_id=bc_id,
            bc_external_doc_no=bc_ext_doc,
            bc_requested_date=bc_date,
            match_method=method,
            confidence=confidence,
            unmatched_reason=None,
        )

    # Step 4 — FALLBACK fuzzy (date + SKU overlap), only when customer resolved
    # Track fuzzy-path state for unmatched-reason reporting
    fuzzy_attempted = False
    fuzzy_had_candidates = False
    fuzzy_had_overlap = False

    if cust_id is not None and requested_date is not None:
        email_skus = set()
        for line in lines:
            hint = (line.get("sku_hint") or "").strip().upper().replace(" ", "")
            # Only use hints that look like a short SKU code (<=12 chars, no spaces)
            # Long hints like "Alternative Légère..." are product descriptions, not SKU codes
            if hint and len(hint) <= 12 and re.match(r"^[A-Z0-9]+$", hint):
                email_skus.add(hint)

        if email_skus:
            fuzzy_attempted = True
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT o.id, o.external_document_no, o.requested_date, "
                    "       GROUP_CONCAT(s.sku_code ORDER BY s.sku_code SEPARATOR ',') AS sku_codes "
                    "FROM ord_orders o "
                    "JOIN ord_order_lines ol ON ol.order_id_fk = o.id "
                    "JOIN ref_skus s ON s.id = ol.sku_id_fk "
                    "WHERE o.source = %s "
                    "  AND o.customer_id_fk = %s "
                    "  AND o.requested_date BETWEEN DATE_SUB(%s, INTERVAL 4 DAY) "
                    "                           AND DATE_ADD(%s, INTERVAL 4 DAY) "
                    "GROUP BY o.id, o.external_document_no, o.requested_date",
                    ("bc", cust_id, requested_date, requested_date),
                )
                fuzzy_candidates = cur.fetchall()

            fuzzy_had_candidates = len(fuzzy_candidates) > 0
            best_overlap = 0.0
            best_candidate = None
            for cand in fuzzy_candidates:
                bc_skus = set(
                    s.strip().upper()
                    for s in (cand["sku_codes"] or "").split(",")
                    if s.strip()
                )
                denom = max(len(email_skus), len(bc_skus), 1)
                overlap_ratio = len(email_skus & bc_skus) / denom
                if overlap_ratio > best_overlap:
                    best_overlap = overlap_ratio
                    best_candidate = cand

            if best_candidate is not None and best_overlap >= 0.5:
                fuzzy_had_overlap = True
                cand_confidence = 0.70 * best_overlap
                if cand_confidence >= 0.35:
                    bc_id = best_candidate["id"]
                    bc_ext_doc = best_candidate["external_document_no"]
                    bc_date = (
                        best_candidate["requested_date"].strftime("%Y-%m-%d")
                        if best_candidate["requested_date"]
                        else None
                    )
                    method = "fuzzy_date_sku"
                    confidence = cand_confidence

    # Step 5 — Confidence gate
    if confidence >= 0.75 and bc_id is not None:
        return MatchResult(
            email_id=email_id,
            subject=subject,
            from_addr=from_address,
            extracted_order_ref=order_ref,
            resolved_customer_id=cust_id,
            resolved_customer_name=cust_name,
            match_found=True,
            bc_order_id=bc_id,
            bc_external_doc_no=bc_ext_doc,
            bc_requested_date=bc_date,
            match_method=method,
            confidence=confidence,
            unmatched_reason=None,
        )

    # Determine unmatched reason
    reason: str
    if order_ref is not None and bc_id is None:
        reason = "order_ref_extracted_no_bc_match"
    elif order_ref is None and cust_id is None:
        if requested_date is None:
            reason = "no_order_ref_and_no_date"
        else:
            reason = "no_order_ref_customer_unresolved"
    elif order_ref is None and cust_id is not None:
        if requested_date is None:
            reason = "no_order_ref_and_no_date"
        elif fuzzy_attempted and not fuzzy_had_candidates:
            reason = "no_order_ref_customer_resolved_date_no_bc_candidate"
        elif fuzzy_attempted and fuzzy_had_candidates and not fuzzy_had_overlap:
            reason = "no_order_ref_customer_resolved_low_sku_overlap"
        else:
            reason = "no_order_ref_customer_resolved_date_no_bc_candidate"
    else:
        reason = "no_order_ref_customer_unresolved"

    return MatchResult(
        email_id=email_id,
        subject=subject,
        from_addr=from_address,
        extracted_order_ref=order_ref,
        resolved_customer_id=cust_id,
        resolved_customer_name=cust_name,
        match_found=False,
        bc_order_id=None,
        bc_external_doc_no=None,
        bc_requested_date=None,
        match_method=None,
        confidence=confidence,
        unmatched_reason=reason,
    )


# ---------------------------------------------------------------------------
# Spot-check helper
# ---------------------------------------------------------------------------

def _fetch_bc_lines(conn: pymysql.connections.Connection, order_id: int) -> list[str]:
    """Return list of 'SKU×qty' strings for a BC order."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT s.sku_code, ol.qty "
            "FROM ord_order_lines ol "
            "JOIN ref_skus s ON s.id = ol.sku_id_fk "
            "WHERE ol.order_id_fk = %s "
            "ORDER BY s.sku_code",
            (order_id,),
        )
        rows = cur.fetchall()
    return ["%s×%g" % (r["sku_code"], float(r["qty"])) for r in rows]


def _format_email_lines(lines: list[dict]) -> list[str]:
    """Return list of 'sku_hint×qty' strings from parsed_json lines."""
    out = []
    for line in lines:
        hint = (line.get("sku_hint") or "?").strip()
        qty = line.get("qty")
        out.append("%s×%g" % (hint, float(qty) if qty is not None else 0.0))
    return out


# ---------------------------------------------------------------------------
# CLI --report
# ---------------------------------------------------------------------------

def _run_report(conn: pymysql.connections.Connection) -> None:
    """Read all parsed emails, match each, print reconciliation table + summary."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id, from_address, subject, raw_body, parsed_json "
            "FROM doc_email_messages "
            "WHERE parse_status = %s "
            "ORDER BY id",
            ("parsed",),
        )
        email_rows = cur.fetchall()

    results: list[MatchResult] = []
    email_pj_map: dict[int, dict] = {}

    for row in email_rows:
        pj = json.loads(row["parsed_json"]) if row["parsed_json"] else {}
        email_pj_map[row["id"]] = pj
        parsed_email = {
            "id": row["id"],
            "from_address": row["from_address"],
            "subject": row["subject"],
            "raw_body": row["raw_body"],
            "parsed_json": pj,
        }
        if pj.get("_kind") == "parsed_order_hints_multi":
            # Multi-order: match each sub-order individually.
            for idx in range(len(pj.get("orders") or [])):
                result = match_email_order_to_bc(conn, parsed_email, order_index=idx)
                results.append(result)
        else:
            result = match_email_order_to_bc(conn, parsed_email)
            results.append(result)

    # --- Table header ---
    col_widths = [8, 30, 50, 12, 20, 9, 5, 22, 4, 40]
    headers = ["email_id", "from", "subject", "order_ref", "customer",
               "STATUS", "bc_id", "method", "conf", "reason"]

    def pad(s: str, w: int) -> str:
        s = s[:w]
        return s.ljust(w)

    sep = "-" * (sum(col_widths) + len(col_widths) * 3 - 1)
    header_row = " | ".join(pad(h, col_widths[i]) for i, h in enumerate(headers))

    print()
    print("=" * 80)
    print("  BC ORDER DEDUP — EMAIL RECONCILIATION REPORT")
    print("=" * 80)
    print()
    print(header_row)
    print(sep)

    matched_results = []
    unmatched_results = []

    for r in results:
        status = "MATCHED" if r.match_found else "UNMATCHED"
        from_trim = _bare_email(r.from_addr)
        row_cells = [
            str(r.email_id),
            from_trim,
            r.subject or "",
            r.extracted_order_ref or "",
            r.resolved_customer_name or "",
            status,
            str(r.bc_order_id) if r.bc_order_id else "",
            r.match_method or "",
            "%.2f" % r.confidence,
            r.unmatched_reason or "",
        ]
        print(" | ".join(pad(row_cells[i], col_widths[i]) for i in range(len(headers))))

        if r.match_found:
            matched_results.append(r)
        else:
            unmatched_results.append(r)

    print(sep)
    print()
    print("SUMMARY: %d parsed → %d matched-to-BC / %d unmatched" % (
        len(results), len(matched_results), len(unmatched_results)
    ))
    print()

    # Unmatched breakdown
    if unmatched_results:
        reason_counts: dict[str, int] = {}
        for r in unmatched_results:
            reason_counts[r.unmatched_reason or "unknown"] = (
                reason_counts.get(r.unmatched_reason or "unknown", 0) + 1
            )
        print("UNMATCHED REASONS:")
        for reason, count in sorted(reason_counts.items(), key=lambda x: -x[1]):
            print("  %-50s  %d" % (reason, count))
        print()

    # --- Spot-check section ---
    if matched_results:
        print("=" * 80)
        print("  SPOT-CHECKS (matched orders)")
        print("=" * 80)
        print()
        for r in matched_results:
            pj = email_pj_map.get(r.email_id, {})
            email_lines = _format_email_lines(pj.get("lines") or [])
            bc_lines = _fetch_bc_lines(conn, r.bc_order_id)

            # Build a short label
            ref_label = ("Commande %s" % r.extracted_order_ref) if r.extracted_order_ref else "no-ref"
            cust_label = r.resolved_customer_name or "unknown-customer"

            print("SPOT-CHECK: email id=%d (%s %s) vs BC ord id=%d" % (
                r.email_id, cust_label, ref_label, r.bc_order_id
            ))
            print("  Email subject:  %s" % r.subject)
            print("  Email lines:    %s" % ", ".join(email_lines) if email_lines else "  Email lines:    (none)")
            print("  BC lines:       %s" % ", ".join(bc_lines) if bc_lines else "  BC lines:       (none)")
            print("  Method:         %s  (confidence=%.2f)" % (r.match_method, r.confidence))

            # Compute simple overlap for verdict
            email_skus = set()
            for line in (pj.get("lines") or []):
                hint = (line.get("sku_hint") or "").strip().upper().replace(" ", "")
                if hint and len(hint) <= 12 and re.match(r"^[A-Z0-9]+$", hint):
                    email_skus.add(hint)
            bc_skus = set(s.split("×")[0] for s in bc_lines)
            overlap = email_skus & bc_skus

            if r.match_method == "order_ref_exact":
                verdict = "REAL MATCH (order_ref exact"
                if overlap:
                    verdict += ", SKUs overlap on %s" % "/".join(sorted(overlap))
                    not_in_bc = email_skus - bc_skus
                    if not_in_bc:
                        verdict += " — email has extra SKUs not in BC: %s (may be a dépannage add-on)" % "/".join(sorted(not_in_bc))
                else:
                    verdict += ", no SKU overlap — email may be a partial/amendment"
                verdict += ")"
            elif r.match_method == "order_ref_no_customer":
                verdict = "PROBABLE MATCH (order_ref found, customer unresolved — verify manually)"
            elif r.match_method == "fuzzy_date_sku":
                verdict = "FUZZY MATCH (date+SKU overlap only — review before promoting)"
            else:
                verdict = "MATCH (confidence=%.2f)" % r.confidence

            print("  Verdict:        %s" % verdict)
            print()


# ---------------------------------------------------------------------------
# CLI --match-one
# ---------------------------------------------------------------------------

def _run_match_one(
    conn: pymysql.connections.Connection,
    email_id: int,
    customer_id: int,
    order_index: Optional[int] = None,
) -> None:
    """
    Match a single email against BC orders using a caller-supplied customer_id.
    For multi-order emails, supply order_index (0-based) to select the sub-order.
    Outputs a JSON object to stdout. Exits 0 on success, 1 on error.
    """
    try:
        # Load the email row
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, from_address, subject, raw_body, parsed_json, parse_status "
                "FROM doc_email_messages WHERE id = %s LIMIT 1",
                (email_id,),
            )
            row = cur.fetchone()

        if not row:
            print(f"Email id={email_id} not found", file=sys.stderr)
            sys.exit(1)

        if row["parse_status"] != "parsed":
            print(
                f"Email id={email_id} has parse_status={row['parse_status']!r}, expected 'parsed'",
                file=sys.stderr,
            )
            sys.exit(1)

        pj = json.loads(row["parsed_json"]) if row["parsed_json"] else {}

        parsed_email = {
            "id": row["id"],
            "from_address": row["from_address"],
            "subject": row["subject"],
            "raw_body": row["raw_body"],
            "parsed_json": pj,
        }

        result = match_email_order_to_bc(conn, parsed_email, customer_id_override=customer_id, order_index=order_index)

        bc_order_info = None
        if result.match_found and result.bc_order_id is not None:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT external_document_no, requested_date FROM ord_orders WHERE id = %s",
                    (result.bc_order_id,),
                )
                ord_row = cur.fetchone()
            if ord_row:
                bc_order_info = {
                    "external_document_no": ord_row["external_document_no"],
                    "order_date": (
                        ord_row["requested_date"].strftime("%Y-%m-%d")
                        if ord_row["requested_date"] is not None
                        else None
                    ),
                    "total": None,
                }

        output = {
            "status": "matched" if result.match_found else "unmatched",
            "bc_id": result.bc_order_id,
            "method": result.match_method,
            "confidence": result.confidence,
            "reason": result.unmatched_reason,
            "bc_order": bc_order_info,
        }
        print(json.dumps(output))
        sys.exit(0)

    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)


# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------

def _build_conn():
    """Build a DB connection using the standard lib_config/lib_db pattern."""
    script_dir = "/var/www/maltytask/scripts/python"
    if script_dir not in sys.path:
        sys.path.insert(0, script_dir)
    import lib_config
    import lib_db
    cfg = lib_config.load()
    return lib_db.connect(cfg)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Dedup email orders vs BC — read-only reconciliation."
    )
    parser.add_argument(
        "--report",
        action="store_true",
        help="Print full reconciliation report for all parsed emails.",
    )
    parser.add_argument(
        "--match-one",
        action="store_true",
        help="Match a single email against BC using a supplied customer id.",
    )
    parser.add_argument(
        "--email-id",
        type=int,
        default=0,
        help="Email id (doc_email_messages.id) for --match-one.",
    )
    parser.add_argument(
        "--customer-id",
        type=int,
        default=0,
        help="ref_customers.id to use for --match-one (caller has already resolved).",
    )
    parser.add_argument(
        "--order-index",
        type=int,
        default=None,
        help="Sub-order index (0-based) for multi-order emails (_kind='parsed_order_hints_multi').",
    )
    args = parser.parse_args()

    if args.report:
        conn = _build_conn()
        try:
            _run_report(conn)
        finally:
            conn.close()
    elif args.match_one:
        if args.email_id <= 0 or args.customer_id <= 0:
            print(
                "Usage: --match-one requires --email-id N and --customer-id N (both > 0)",
                file=sys.stderr,
            )
            sys.exit(1)
        conn = _build_conn()
        try:
            _run_match_one(conn, args.email_id, args.customer_id, order_index=args.order_index)
        finally:
            conn.close()
    else:
        parser.print_help()
        sys.exit(0)


if __name__ == "__main__":
    main()
