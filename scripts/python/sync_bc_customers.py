#!/usr/bin/env python3
"""
sync_bc_customers.py — Daily BC → ref_customers drift-reconciliation connector.

Reads the full BC standard-API customer list
  …/api/v2.0/companies({id})/customers
and reconciles it against ref_customers using a 5-bucket classification:

  MATCH        — bc_customer_no matches exactly one live row; would UPDATE
                 BC-OWNED fields only (address, phone, email, is_active,
                 bc_last_synced_at). Curated fields (name, trade_channel,
                 sale_class, is_private, needs_review, notes) are NEVER touched.

  NEW          — bc_customer_no absent from ref_customers, no name candidate
                 either. Would INSERT with needs_review=1.

  DRIFT-AUTO   — bc_customer_no absent from ref_customers, BUT a candidate
                 exists whose CURRENT bc_customer_no is provably absent from BC
                 AND whose name + (postalCode OR city) matches this BC customer
                 EXACTLY (case-insensitive).  This is the "phantom bc number"
                 pattern (e.g. Stoked Mountain 3954 absent from BC but row exists).
                 STRICT gate: old number must genuinely return 0 results from BC;
                 name + geo must match exactly. Would update bc_customer_no on the
                 candidate row to the new BC value.

  DRIFT-REVIEW — bc_customer_no absent, a plausible candidate exists but the
                 DRIFT-AUTO criteria are not fully met, OR the old number still
                 exists in BC. Would emit a doc_review_queue row of type
                 'bc-customer-identity-drift'.

  maltytask-only — ref_customers rows with NULL bc_customer_no. Ignored by this
                 sync (no bc_customer_no key to match on). Reported by count only.

Field ownership contract:
  BC-OWNED   : address_line1, address_line2, city, postal_code, country_code,
               phone (BC phoneNumber), email, is_active (BC blocked→is_active=0),
               bc_last_synced_at.
  CURATED    : name, trade_channel, sale_class, is_private, needs_review, notes,
               bc_customer_no (except via DRIFT-AUTO path).

Tombstone / merge resolver:
  A tombstoned row has is_active=0 AND notes LIKE '%merged_into:%'.
  The sync NEVER touches tombstoned rows and NEVER resurrects them.
  When looking for DRIFT-AUTO / DRIFT-REVIEW candidates, only live rows
  (is_active=1) that are NOT tombstoned are considered.

Usage:
  # Dry-run (default) — drift report, no writes:
  python3 scripts/python/sync_bc_customers.py
  python3 scripts/python/sync_bc_customers.py --dry-run

  # Apply — write BC-OWNED fields, INSERT NEWs, correct DRIFT-AUTO numbers,
  #          emit DRIFT-REVIEW RQ rows:
  python3 scripts/python/sync_bc_customers.py --apply

  # Limit number of BC customers processed (for smoke-testing):
  python3 scripts/python/sync_bc_customers.py --limit 100

Credentials:
  /var/www/maltytask/config/bc.env   (BC OAuth2)
  /var/www/maltytask/config/db.env   (MySQL)
"""

from __future__ import annotations

import argparse
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import quote

_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

import pymysql  # noqa: E402

try:
    import requests  # noqa: E402
except ImportError:
    print("ERROR: requests not installed. Run: pip install requests", file=sys.stderr)
    sys.exit(1)

from lib_config import load as load_config  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

_BC_ENV_PATH = Path("/var/www/maltytask/config/bc.env")

# BC standard-API company GUID (from api/v2.0/companies endpoint).
# ODataV4 service uses Company('NEBULEUSE') by name; the standard REST API
# uses the GUID form: /api/v2.0/companies({guid})/customers.
BC_COMPANY_ID = "e2b691c6-8393-ed11-bff5-002248f307ee"

# Confidence threshold for name-normalised fuzzy match to promote to DRIFT-REVIEW
# (below DRIFT-AUTO strict criteria).
# We use exact string comparison for DRIFT-AUTO; anything that doesn't meet exact
# match goes to DRIFT-REVIEW or is ignored if there's no candidate at all.

# ── BC env loader (mirrors ingest_bc_sales_ledger pattern) ────────────────────

def _load_bc_env() -> dict[str, str]:
    path = _BC_ENV_PATH
    override = os.environ.get("MALTYTASK_BC_ENV")
    if override:
        path = Path(override)
    if not path.exists():
        raise RuntimeError(
            f"BC credentials not found at {path}.\n"
            f"Expected keys: BC_TENANT_ID, BC_CLIENT_ID, BC_CLIENT_SECRET, BC_ENVIRONMENT.\n"
            f"See: config/bc.env.example"
        )
    cfg: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            continue
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()
    for key in ("BC_TENANT_ID", "BC_CLIENT_ID", "BC_CLIENT_SECRET", "BC_ENVIRONMENT"):
        if key not in cfg:
            raise RuntimeError(f"Missing key in bc.env: {key}")
    return cfg


# ── OAuth2 token ──────────────────────────────────────────────────────────────

def _get_token(bc: dict[str, str]) -> str:
    """Fetch an OAuth2 client-credentials token. Token never logged."""
    tenant = bc["BC_TENANT_ID"]
    url = f"https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token"
    data = {
        "grant_type":    "client_credentials",
        "client_id":     bc["BC_CLIENT_ID"],
        "client_secret": bc["BC_CLIENT_SECRET"],
        "scope":         "https://api.businesscentral.dynamics.com/.default",
    }
    resp = requests.post(url, data=data, timeout=30)
    if resp.status_code != 200:
        raise RuntimeError(
            f"Token request failed: HTTP {resp.status_code} — {resp.text[:200]}"
        )
    j = resp.json()
    print(f"  [OAuth2] token acquired, expires_in={j.get('expires_in', '?')}s", flush=True)
    return j["access_token"]


# ── BC standard-API customers fetch ──────────────────────────────────────────

_CUSTOMER_FIELDS = ",".join([
    "number", "displayName",
    "addressLine1", "addressLine2", "city", "postalCode", "country",
    "phoneNumber", "email",
    "blocked",
    "taxRegistrationNumber", "lastModifiedDateTime",
])


def _customers_base_url(bc: dict[str, str]) -> str:
    tenant = bc["BC_TENANT_ID"]
    env    = bc["BC_ENVIRONMENT"]
    # Standard REST API uses the GUID directly (no quoting needed for GUIDs).
    return (
        f"https://api.businesscentral.dynamics.com/v2.0/{tenant}/{env}"
        f"/api/v2.0/companies({BC_COMPANY_ID})/customers"
    )


def fetch_bc_customers(bc: dict[str, str], limit: int | None = None) -> list[dict[str, Any]]:
    """
    Full scan of BC standard-API /customers endpoint.
    Follows @odata.nextLink for paging — no $top to avoid silent truncation.
    Returns list of raw BC customer dicts.
    Token never logged.
    """
    token = _get_token(bc)
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept":        "application/json",
    }

    select_enc = quote(_CUSTOMER_FIELDS, safe="")
    base_url = _customers_base_url(bc)
    url: str | None = f"{base_url}?$select={select_enc}"

    all_customers: list[dict[str, Any]] = []
    page = 0

    print(f"  [BC API] fetching customers (full scan, paged) …", flush=True)
    while url:
        resp = requests.get(url, headers=headers, timeout=120)
        if resp.status_code != 200:
            raise RuntimeError(
                f"BC customers API failed: HTTP {resp.status_code} — {resp.text[:300]}"
            )
        j = resp.json()
        rows = j.get("value", [])
        all_customers.extend(rows)
        page += 1
        print(
            f"  [BC API] page {page}: {len(rows)} customers "
            f"(total so far: {len(all_customers):,})",
            flush=True,
        )
        if limit and len(all_customers) >= limit:
            all_customers = all_customers[:limit]
            print(f"  [BC API] --limit {limit} reached, stopping.", flush=True)
            break
        url = j.get("@odata.nextLink")

    print(f"  [BC API] fetch complete: {len(all_customers):,} customers total.", flush=True)
    return all_customers


def check_bc_number_exists(bc: dict[str, str], token: str, number: str) -> bool:
    """
    Phantom-absence test: returns True if BC returns ≥1 customer row for this
    number, False if the response is empty.
    Used exclusively in DRIFT-AUTO to prove old_bc_no is absent from BC.
    Token passed in (reuse the same token from the main fetch call).
    """
    base_url = _customers_base_url(bc)
    filter_enc = quote(f"number eq '{number}'", safe="'_=")
    select_enc = quote("number", safe="")
    url = f"{base_url}?$filter={filter_enc}&$select={select_enc}"
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept":        "application/json",
    }
    resp = requests.get(url, headers=headers, timeout=30)
    if resp.status_code != 200:
        raise RuntimeError(
            f"BC phantom-absence check failed for number={number!r}: "
            f"HTTP {resp.status_code} — {resp.text[:200]}"
        )
    j = resp.json()
    return len(j.get("value", [])) > 0


# ── ref_customers loader ───────────────────────────────────────────────────────

class CustomerRow:
    """Lightweight wrapper for a ref_customers row."""
    __slots__ = (
        "id", "name", "bc_customer_no", "is_active", "notes",
        "address_line1", "address_line2", "city", "postal_code", "country_code",
        "phone", "email",
    )

    def __init__(self, row: dict[str, Any]) -> None:
        self.id              = int(row["id"])
        self.name            = str(row["name"] or "").strip()
        self.bc_customer_no  = str(row["bc_customer_no"]).strip() if row["bc_customer_no"] else None
        self.is_active       = bool(row["is_active"])
        self.notes           = str(row["notes"] or "").strip()
        self.address_line1   = str(row["address_line1"] or "").strip() if row["address_line1"] else None
        self.address_line2   = str(row["address_line2"] or "").strip() if row["address_line2"] else None
        self.city            = str(row["city"] or "").strip() if row["city"] else None
        self.postal_code     = str(row["postal_code"] or "").strip() if row["postal_code"] else None
        self.country_code    = str(row["country_code"] or "").strip() if row["country_code"] else None
        self.phone           = str(row["phone"] or "").strip() if row.get("phone") else None
        self.email           = str(row["email"] or "").strip() if row["email"] else None

    @property
    def is_tombstoned(self) -> bool:
        """A tombstoned row has is_active=0 AND notes contains 'merged_into:'."""
        return not self.is_active and "merged_into:" in self.notes

    @property
    def is_live(self) -> bool:
        """A live row: is_active=1 AND not tombstoned."""
        return self.is_active and not self.is_tombstoned


def load_ref_customers(conn: pymysql.connections.Connection) -> dict[str, CustomerRow]:
    """
    Load ALL ref_customers rows (active and inactive).
    Returns two indexes:
      by_bc_no  : {bc_customer_no: CustomerRow}  (only rows with non-NULL bc_customer_no)
    Also returns the full list for name-based candidate search.
    """
    with conn.cursor() as c:
        c.execute(
            """SELECT id, name, bc_customer_no, is_active, notes,
                      address_line1, address_line2, city, postal_code, country_code,
                      phone, email
                 FROM ref_customers"""
        )
        rows = c.fetchall()

    all_rows = [CustomerRow(r) for r in rows]
    by_bc_no: dict[str, CustomerRow] = {}
    for row in all_rows:
        if row.bc_customer_no is not None:
            by_bc_no[row.bc_customer_no] = row
    return by_bc_no, all_rows


# ── BC customer normalisation ─────────────────────────────────────────────────

def _normalise(s: str | None) -> str:
    """Lowercase, strip, collapse whitespace. Used for name/city comparison."""
    if not s:
        return ""
    return " ".join(s.lower().split())


def _bc_to_db_fields(bc_cust: dict[str, Any]) -> dict[str, Any]:
    """Map BC customer dict → BC-OWNED DB field values.

    BC standard-API 'blocked' is a string enum, not a boolean:
      '_x0020_'  (space / XML-encoded blank) = not blocked → is_active=1
      'All'      = fully blocked → is_active=0
      'Ship'     = shipping-blocked (not invoicing) → is_active=1 (partial restriction)
      '' / None  = not blocked → is_active=1
    """
    blocked_raw = str(bc_cust.get("blocked") or "").strip()
    # Only 'All' (full block) maps to is_active=0.
    is_active = 0 if blocked_raw == "All" else 1

    # BC country is a 2-char ISO code in standard API (confirmed pattern from BC OData).
    country_raw = bc_cust.get("country")
    country_code = str(country_raw).strip()[:2].upper() if country_raw else None

    phone_raw = bc_cust.get("phoneNumber")
    phone = str(phone_raw).strip()[:32] if phone_raw else None

    email_raw = bc_cust.get("email")
    email = str(email_raw).strip().lower() if email_raw else None

    return {
        "address_line1": str(bc_cust.get("addressLine1") or "").strip() or None,
        "address_line2": str(bc_cust.get("addressLine2") or "").strip() or None,
        "city":          str(bc_cust.get("city") or "").strip() or None,
        "postal_code":   str(bc_cust.get("postalCode") or "").strip() or None,
        "country_code":  country_code,
        "phone":         phone,
        "email":         email,
        "is_active":     is_active,
    }


def _field_diff(db_row: CustomerRow, bc_fields: dict[str, Any]) -> dict[str, tuple[Any, Any]]:
    """
    Returns {field_name: (db_value, bc_value)} for BC-OWNED fields that differ.
    bc_last_synced_at is always updated on MATCH — not included in diff output
    (it would always show as changed; it's informational only).
    """
    diffs: dict[str, tuple[Any, Any]] = {}
    field_map = {
        "address_line1": db_row.address_line1,
        "address_line2": db_row.address_line2,
        "city":          db_row.city,
        "postal_code":   db_row.postal_code,
        "country_code":  db_row.country_code,
        "phone":         db_row.phone,
        "email":         db_row.email,
        "is_active":     int(db_row.is_active),
    }
    for field, db_val in field_map.items():
        bc_val = bc_fields.get(field)
        # Normalise None vs empty string to avoid spurious diffs.
        db_norm = db_val if db_val not in (None, "") else None
        bc_norm = bc_val if bc_val not in (None, "") else None
        if db_norm != bc_norm:
            diffs[field] = (db_val, bc_val)
    return diffs


# ── Bucket types ──────────────────────────────────────────────────────────────

class MatchResult:
    bucket = "MATCH"
    def __init__(self, bc_no: str, db_id: int, db_name: str, diffs: dict, bc_fields: dict):
        self.bc_no    = bc_no
        self.db_id    = db_id
        self.db_name  = db_name
        self.diffs    = diffs      # {field: (db_val, bc_val)}
        self.bc_fields = bc_fields  # full BC-OWNED field values for --apply


class NewResult:
    bucket = "NEW"
    def __init__(self, bc_no: str, bc_display_name: str, bc_fields: dict):
        self.bc_no            = bc_no
        self.bc_display_name  = bc_display_name
        self.bc_fields        = bc_fields


class DriftAutoResult:
    bucket = "DRIFT-AUTO"
    def __init__(self, bc_no: str, bc_display_name: str,
                 candidate_id: int, candidate_name: str,
                 old_bc_no: str, bc_fields: dict,
                 match_evidence: str):
        self.bc_no            = bc_no
        self.bc_display_name  = bc_display_name
        self.candidate_id     = candidate_id
        self.candidate_name   = candidate_name
        self.old_bc_no        = old_bc_no
        self.bc_fields        = bc_fields
        self.match_evidence   = match_evidence  # human-readable evidence string


class DriftReviewResult:
    bucket = "DRIFT-REVIEW"
    def __init__(self, bc_no: str, bc_display_name: str,
                 candidate_id: int | None, candidate_name: str | None,
                 old_bc_no: str | None, reason: str):
        self.bc_no            = bc_no
        self.bc_display_name  = bc_display_name
        self.candidate_id     = candidate_id
        self.candidate_name   = candidate_name
        self.old_bc_no        = old_bc_no
        self.reason           = reason


# ── Classifier ────────────────────────────────────────────────────────────────

def classify_bc_customer(
    bc_cust: dict[str, Any],
    by_bc_no: dict[str, CustomerRow],
    all_rows: list[CustomerRow],
    bc_all_numbers: set[str],
    bc_token: str,
    bc_cfg: dict[str, str],
    phantom_cache: dict[str, bool],
) -> MatchResult | NewResult | DriftAutoResult | DriftReviewResult | None:
    """
    Classify one BC customer into a bucket.

    phantom_cache: {bc_number: is_present_in_bc} — shared across calls to avoid
    redundant API round-trips for the same old_bc_no.
    """
    bc_no           = str(bc_cust.get("number") or "").strip()
    bc_display_name = str(bc_cust.get("displayName") or "").strip()
    bc_city         = str(bc_cust.get("city") or "").strip()
    bc_postal       = str(bc_cust.get("postalCode") or "").strip()
    bc_fields       = _bc_to_db_fields(bc_cust)

    if not bc_no:
        # BC returned a customer with no number — skip silently.
        return NewResult(bc_no="(empty)", bc_display_name=bc_display_name, bc_fields=bc_fields)

    # ── Bucket 1: MATCH ───────────────────────────────────────────────────────
    existing = by_bc_no.get(bc_no)
    if existing is not None:
        if existing.is_tombstoned:
            # Tombstoned row carries this bc_no — do NOT resurrect.
            # Treat as DRIFT-REVIEW so the operator is alerted.
            return DriftReviewResult(
                bc_no=bc_no,
                bc_display_name=bc_display_name,
                candidate_id=existing.id,
                candidate_name=existing.name,
                old_bc_no=bc_no,
                reason=(
                    f"bc_no={bc_no!r} matches a TOMBSTONED row "
                    f"(id={existing.id}, is_active=0, notes contains 'merged_into:'). "
                    f"Never resurrect tombstones — operator must decide."
                ),
            )
        if not existing.is_active:
            # Inactive but not tombstoned.
            # Check whether BC also considers this customer retired.
            # BC retired = blocked=='All' OR displayName contains "ne plus utiliser" (case-insensitive).
            blocked_raw = str(bc_cust.get("blocked") or "").strip()
            bc_retired = (
                blocked_raw == "All"
                or "ne plus utiliser" in bc_display_name.lower()
            )
            if bc_retired:
                # Both sides retired — agreed, no conflict, skip silently.
                # Return None sentinel to signal "skip this entry entirely".
                return None
            # BC says active/not-retired but maltytask is inactive — real conflict.
            return DriftReviewResult(
                bc_no=bc_no,
                bc_display_name=bc_display_name,
                candidate_id=existing.id,
                candidate_name=existing.name,
                old_bc_no=bc_no,
                reason=(
                    f"bc_no={bc_no!r} matches an INACTIVE maltytask row "
                    f"(id={existing.id}, is_active=0) but BC shows this customer as ACTIVE "
                    f"(blocked={blocked_raw!r}, displayName={bc_display_name!r}). "
                    f"Operator should confirm whether to reactivate or leave inactive."
                ),
            )
        # Normal live MATCH.
        diffs = _field_diff(existing, bc_fields)
        return MatchResult(
            bc_no=bc_no,
            db_id=existing.id,
            db_name=existing.name,
            diffs=diffs,
            bc_fields=bc_fields,
        )

    # bc_no is absent from ref_customers — look for DRIFT candidates.

    # Build name-normalised lookup for live rows.
    bc_name_norm   = _normalise(bc_display_name)
    bc_city_norm   = _normalise(bc_city)
    bc_postal_norm = _normalise(bc_postal)

    # Find live candidate rows whose name matches exactly (normalised) AND
    # whose CURRENT bc_customer_no is a "phantom" (absent from BC).
    drift_auto_candidates: list[CustomerRow] = []
    drift_review_candidates: list[CustomerRow] = []

    for row in all_rows:
        if not row.is_live:
            # Skip dead / tombstoned rows — never use them as candidates.
            continue
        if row.bc_customer_no is None:
            # maltytask-only row — not considered for drift reconciliation.
            continue

        row_name_norm = _normalise(row.name)
        if row_name_norm != bc_name_norm:
            # Name doesn't match at all — not a candidate.
            continue

        # Name matches. Now check if the row's CURRENT bc_no is absent from BC.
        # Use the pre-built full-scan set first (cheap).
        old_no = row.bc_customer_no
        if old_no in bc_all_numbers:
            # Old number IS in BC — this is NOT a phantom. Could be a genuine
            # near-duplicate or an error. Route to DRIFT-REVIEW.
            drift_review_candidates.append(row)
            continue

        # Old number NOT in the full-scan set — perform the targeted phantom-
        # absence check (API call) to confirm. Cache results to avoid re-checking.
        if old_no not in phantom_cache:
            phantom_cache[old_no] = check_bc_number_exists(bc_cfg, bc_token, old_no)
        if phantom_cache[old_no]:
            # Old number IS in BC (full-scan may have missed it if --limit active
            # or if BC paging is inconsistent). Route to DRIFT-REVIEW.
            drift_review_candidates.append(row)
            continue

        # Old number confirmed absent from BC. Name matches. Now check geo.
        row_city_norm   = _normalise(row.city)
        row_postal_norm = _normalise(row.postal_code)

        geo_match = (
            (bc_city_norm and bc_city_norm == row_city_norm) or
            (bc_postal_norm and bc_postal_norm == row_postal_norm)
        )
        if geo_match:
            drift_auto_candidates.append(row)
        else:
            # Name match + phantom old_no, but geo doesn't match — DRIFT-REVIEW.
            drift_review_candidates.append(row)

    # ── Bucket 3: DRIFT-AUTO ──────────────────────────────────────────────────
    if len(drift_auto_candidates) == 1:
        cand = drift_auto_candidates[0]
        cand_city_norm   = _normalise(cand.city)
        cand_postal_norm = _normalise(cand.postal_code)

        # Build evidence string.
        geo_evidence_parts = []
        if bc_city_norm and bc_city_norm == cand_city_norm:
            geo_evidence_parts.append(f"city='{bc_city}'")
        if bc_postal_norm and bc_postal_norm == cand_postal_norm:
            geo_evidence_parts.append(f"postalCode='{bc_postal}'")
        geo_evidence = " + ".join(geo_evidence_parts) or "(no geo)"

        evidence = (
            f"name EXACT match: BC='{bc_display_name}' vs DB='{cand.name}'; "
            f"geo match: {geo_evidence}; "
            f"old bc_no='{cand.bc_customer_no}' confirmed ABSENT from BC "
            f"(phantom-absence test passed)"
        )
        return DriftAutoResult(
            bc_no=bc_no,
            bc_display_name=bc_display_name,
            candidate_id=cand.id,
            candidate_name=cand.name,
            old_bc_no=cand.bc_customer_no,
            bc_fields=bc_fields,
            match_evidence=evidence,
        )

    if len(drift_auto_candidates) > 1:
        # Multiple DRIFT-AUTO candidates — ambiguous, route to DRIFT-REVIEW.
        ids = [c.id for c in drift_auto_candidates]
        drift_review_candidates = drift_auto_candidates  # re-route
        drift_auto_candidates = []
        reason = (
            f"Multiple DRIFT-AUTO candidates found for bc_no={bc_no!r} "
            f"(name='{bc_display_name}'): ids={ids}. "
            f"Ambiguous — operator must decide."
        )
        return DriftReviewResult(
            bc_no=bc_no,
            bc_display_name=bc_display_name,
            candidate_id=None,
            candidate_name=None,
            old_bc_no=None,
            reason=reason,
        )

    # ── Bucket 4: DRIFT-REVIEW ────────────────────────────────────────────────
    if drift_review_candidates:
        cand = drift_review_candidates[0]
        reason_parts = []
        if cand.bc_customer_no in bc_all_numbers:
            reason_parts.append(
                f"old bc_no='{cand.bc_customer_no}' STILL EXISTS in BC "
                f"(not a phantom — may be a genuine near-dup or BC error)"
            )
        else:
            reason_parts.append(
                f"old bc_no='{cand.bc_customer_no}' absent from BC "
                f"but geo (city/postalCode) does not match "
                f"(BC city='{bc_city}', postalCode='{bc_postal}' vs "
                f"DB city='{cand.city}', postalCode='{cand.postal_code}')"
            )
        all_cand_ids = [c.id for c in drift_review_candidates]
        if len(drift_review_candidates) > 1:
            reason_parts.append(f"multiple candidates: ids={all_cand_ids}")
        return DriftReviewResult(
            bc_no=bc_no,
            bc_display_name=bc_display_name,
            candidate_id=cand.id,
            candidate_name=cand.name,
            old_bc_no=cand.bc_customer_no,
            reason="; ".join(reason_parts),
        )

    # ── Bucket 2: NEW ─────────────────────────────────────────────────────────
    return NewResult(
        bc_no=bc_no,
        bc_display_name=bc_display_name,
        bc_fields=bc_fields,
    )


# ── maltytask-only counter ────────────────────────────────────────────────────

def count_maltytask_only(all_rows: list[CustomerRow]) -> int:
    return sum(1 for r in all_rows if r.bc_customer_no is None)


# ── Apply: DB writes ──────────────────────────────────────────────────────────

def _apply_match_full(
    conn: pymysql.connections.Connection,
    result: MatchResult,
    bc_fields: dict[str, Any],
) -> None:
    """Apply full BC-OWNED field set to a MATCH row (always writes all BC-owned cols)."""
    now_utc = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    with conn.cursor() as c:
        c.execute(
            """UPDATE ref_customers
                  SET address_line1     = %s,
                      address_line2     = %s,
                      city              = %s,
                      postal_code       = %s,
                      country_code      = %s,
                      phone             = %s,
                      email             = %s,
                      is_active         = %s,
                      bc_last_synced_at = %s,
                      updated_by        = 'sync_bc_customers',
                      updated_at        = CURRENT_TIMESTAMP
               WHERE id = %s""",
            (
                bc_fields["address_line1"],
                bc_fields["address_line2"],
                bc_fields["city"],
                bc_fields["postal_code"],
                bc_fields["country_code"],
                bc_fields["phone"],
                bc_fields["email"],
                bc_fields["is_active"],
                now_utc,
                result.db_id,
            ),
        )


def apply_new(conn: pymysql.connections.Connection, result: NewResult) -> int:
    """INSERT a new ref_customers row with needs_review=1. Returns new id."""
    now_utc = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    with conn.cursor() as c:
        c.execute(
            """INSERT INTO ref_customers
                   (name, bc_customer_no, is_active, needs_review,
                    address_line1, address_line2, city, postal_code,
                    country_code, phone, email, bc_last_synced_at, updated_by)
               VALUES (%s, %s, %s, 1, %s, %s, %s, %s, %s, %s, %s, %s, 'sync_bc_customers')""",
            (
                result.bc_display_name,
                result.bc_no,
                result.bc_fields["is_active"],
                result.bc_fields["address_line1"],
                result.bc_fields["address_line2"],
                result.bc_fields["city"],
                result.bc_fields["postal_code"],
                result.bc_fields["country_code"],
                result.bc_fields["phone"],
                result.bc_fields["email"],
                now_utc,
            ),
        )
        return c.lastrowid


def apply_drift_auto(
    conn: pymysql.connections.Connection,
    result: DriftAutoResult,
) -> None:
    """
    Correct bc_customer_no on the candidate row AND update BC-OWNED fields.
    NEVER touches curated fields (name, trade_channel, etc.).
    """
    now_utc = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    with conn.cursor() as c:
        c.execute(
            """UPDATE ref_customers
                  SET bc_customer_no    = %s,
                      address_line1     = %s,
                      address_line2     = %s,
                      city              = %s,
                      postal_code       = %s,
                      country_code      = %s,
                      phone             = %s,
                      email             = %s,
                      is_active         = %s,
                      bc_last_synced_at = %s,
                      updated_by        = 'sync_bc_customers',
                      updated_at        = CURRENT_TIMESTAMP
               WHERE id = %s""",
            (
                result.bc_no,
                result.bc_fields["address_line1"],
                result.bc_fields["address_line2"],
                result.bc_fields["city"],
                result.bc_fields["postal_code"],
                result.bc_fields["country_code"],
                result.bc_fields["phone"],
                result.bc_fields["email"],
                result.bc_fields["is_active"],
                now_utc,
                result.candidate_id,
            ),
        )


def apply_drift_review(
    conn: pymysql.connections.Connection,
    result: DriftReviewResult,
) -> None:
    """Emit a doc_review_queue row of type 'bc-customer-identity-drift'."""
    value = (
        f"BC customer {result.bc_no!r} ({result.bc_display_name!r}) "
        f"has no matching row in ref_customers. "
        f"Candidate: id={result.candidate_id}, name={result.candidate_name!r}, "
        f"old_bc_no={result.old_bc_no!r}. "
        f"Reason: {result.reason}"
    )[:512]

    with conn.cursor() as c:
        c.execute(
            """INSERT INTO doc_review_queue
                   (type, value, status, created_at, updated_at)
               VALUES ('bc-customer-identity-drift', %s, 'open', NOW(), NOW())
               ON DUPLICATE KEY UPDATE
                   value      = VALUES(value),
                   status     = 'open',
                   updated_at = NOW()""",
            (value,),
        )


# ── Dry-run report ─────────────────────────────────────────────────────────────

def print_dry_run_report(
    results: list[MatchResult | NewResult | DriftAutoResult | DriftReviewResult],
    maltytask_only_count: int,
    bc_total: int,
    both_sides_retired_count: int = 0,
) -> None:
    matches       = [r for r in results if isinstance(r, MatchResult)]
    news          = [r for r in results if isinstance(r, NewResult)]
    drift_autos   = [r for r in results if isinstance(r, DriftAutoResult)]
    drift_reviews = [r for r in results if isinstance(r, DriftReviewResult)]

    matches_with_diffs = [r for r in matches if r.diffs]
    matches_clean      = [r for r in matches if not r.diffs]

    print()
    print("=" * 72)
    print("DRY-RUN DRIFT REPORT — sync_bc_customers.py")
    print("=" * 72)
    print()
    print("BUCKET SUMMARY")
    print(f"  BC customers fetched          : {bc_total:,}")
    print(f"  MATCH (live row found)        : {len(matches):,}")
    print(f"    — with field changes        : {len(matches_with_diffs):,}")
    print(f"    — already in sync           : {len(matches_clean):,}")
    print(f"  NEW (would INSERT w/ needs_review=1) : {len(news):,}")
    print(f"  DRIFT-AUTO (bc_no correction) : {len(drift_autos):,}")
    print(f"  DRIFT-REVIEW (RQ emit)        : {len(drift_reviews):,}")
    print(f"  SKIPPED (both-sides-retired)  : {both_sides_retired_count:,}")
    print(f"  maltytask-only (NULL bc_no, untouched) : {maltytask_only_count:,}")
    print()

    # ── DRIFT-AUTO detail (full, every case) ─────────────────────────────────
    print("─" * 72)
    print(f"DRIFT-AUTO DETAIL ({len(drift_autos)} cases — operator review before --apply)")
    print("─" * 72)
    if not drift_autos:
        print("  (none)")
    for i, r in enumerate(drift_autos, 1):
        print(f"\n  [{i}] BC number   : {r.bc_no!r}  ('{r.bc_display_name}')")
        print(f"       DB candidate : id={r.candidate_id}  name='{r.candidate_name}'")
        print(f"       Old bc_no    : {r.old_bc_no!r}  → CONFIRMED absent from BC")
        print(f"       Evidence     : {r.match_evidence}")
        print(f"       Action if --apply: UPDATE ref_customers SET bc_customer_no='{r.bc_no}' + BC-owned fields WHERE id={r.candidate_id}")
    print()

    # ── DRIFT-REVIEW detail ───────────────────────────────────────────────────
    print("─" * 72)
    print(f"DRIFT-REVIEW DETAIL ({len(drift_reviews)} cases — will emit RQ rows on --apply)")
    print("─" * 72)
    if not drift_reviews:
        print("  (none)")
    for i, r in enumerate(drift_reviews, 1):
        print(f"\n  [{i}] BC number   : {r.bc_no!r}  ('{r.bc_display_name}')")
        if r.candidate_id:
            print(f"       Candidate   : id={r.candidate_id}  name='{r.candidate_name}'  old_bc_no={r.old_bc_no!r}")
        else:
            print(f"       Candidate   : none (or ambiguous)")
        print(f"       Reason      : {r.reason}")
    print()

    # ── MATCH field-change sample (first 20 with diffs) ───────────────────────
    print("─" * 72)
    print(f"MATCH FIELD-CHANGE SAMPLE (first 20 of {len(matches_with_diffs)} rows with diffs)")
    print("─" * 72)
    if not matches_with_diffs:
        print("  (no field changes — all matched rows are already in sync)")
    for r in matches_with_diffs[:20]:
        print(f"\n  bc_no={r.bc_no!r}  DB id={r.db_id}  name='{r.db_name}'")
        for field, (db_val, bc_val) in sorted(r.diffs.items()):
            print(f"    {field:<18}: DB={db_val!r:30} → BC={bc_val!r}")
    if len(matches_with_diffs) > 20:
        print(f"\n  … and {len(matches_with_diffs) - 20} more rows with diffs (truncated).")
    print()

    # ── NEW sample ────────────────────────────────────────────────────────────
    print("─" * 72)
    print(f"NEW DETAIL (first 20 of {len(news)} — would INSERT with needs_review=1)")
    print("─" * 72)
    if not news:
        print("  (none)")
    for r in news[:20]:
        print(f"  bc_no={r.bc_no!r}  displayName='{r.bc_display_name}'  "
              f"city={r.bc_fields.get('city')!r}  blocked→is_active={r.bc_fields.get('is_active')}")
    if len(news) > 20:
        print(f"  … and {len(news) - 20} more NEW rows (truncated).")
    print()

    print("=" * 72)
    print("DRY-RUN COMPLETE — no writes made.")
    print("Review DRIFT-AUTO cases above, then run with --apply to write.")
    print("=" * 72)


# ── Main ───────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(
        description="BC → ref_customers drift-reconciliation connector."
    )
    ap.add_argument(
        "--dry-run", action="store_true", default=True,
        help="Drift report only, no writes (default).",
    )
    ap.add_argument(
        "--apply", action="store_true", default=False,
        help="Write changes to ref_customers and emit DRIFT-REVIEW RQ rows.",
    )
    ap.add_argument(
        "--limit", type=int, metavar="N",
        help="Process first N BC customers only (smoke-testing).",
    )
    args = ap.parse_args()

    dry_run = not args.apply

    # ── DB connection ─────────────────────────────────────────────────────────
    cfg  = load_config()
    conn = pymysql.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_user,
        password=cfg.db_password,
        database=cfg.db_name,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
        connect_timeout=10,
        read_timeout=120,
        write_timeout=120,
    )
    print("Connected to maltytask MySQL.", flush=True)

    try:
        # ── Load ref_customers ────────────────────────────────────────────────
        print("Loading ref_customers …", end=" ", flush=True)
        by_bc_no, all_rows = load_ref_customers(conn)
        maltytask_only_count = count_maltytask_only(all_rows)
        print(
            f"done — {len(all_rows):,} rows "
            f"({len(by_bc_no):,} with bc_customer_no, "
            f"{maltytask_only_count:,} maltytask-only).",
            flush=True,
        )

        # ── Fetch BC customers ────────────────────────────────────────────────
        bc_cfg = _load_bc_env()
        bc_customers = fetch_bc_customers(bc_cfg, limit=args.limit)
        bc_total = len(bc_customers)

        # Build full BC number set for phantom-absence pre-check.
        bc_all_numbers: set[str] = {
            str(c.get("number") or "").strip()
            for c in bc_customers
            if c.get("number")
        }
        print(
            f"BC numbers in scan: {len(bc_all_numbers):,} unique. "
            f"(Note: if --limit is set, bc_all_numbers is partial — "
            f"phantom checks will fall back to targeted API calls.)",
            flush=True,
        )

        # ── Acquire a fresh token for phantom-absence checks ──────────────────
        # The main fetch already consumed one token; acquire a fresh one for
        # per-number checks (token lifetime is 3600s, re-acquiring is cheap).
        phantom_token = _get_token(bc_cfg)
        phantom_cache: dict[str, bool] = {}

        # ── Classify each BC customer ─────────────────────────────────────────
        results: list[MatchResult | NewResult | DriftAutoResult | DriftReviewResult] = []
        n_both_sides_retired = 0
        for i, bc_cust in enumerate(bc_customers):
            result = classify_bc_customer(
                bc_cust=bc_cust,
                by_bc_no=by_bc_no,
                all_rows=all_rows,
                bc_all_numbers=bc_all_numbers,
                bc_token=phantom_token,
                bc_cfg=bc_cfg,
                phantom_cache=phantom_cache,
            )
            if result is None:
                # Both-sides-retired: BC retired + maltytask is_active=0 → skip silently.
                n_both_sides_retired += 1
                continue
            results.append(result)
            if (i + 1) % 500 == 0:
                print(f"  [classify] {i + 1:,} / {bc_total:,} classified …", flush=True)

        print(f"  [classify] {bc_total:,} / {bc_total:,} classified.", flush=True)
        print(f"  [classify] {n_both_sides_retired:,} both-sides-retired entries skipped silently.", flush=True)

        # ── Dry-run: report and exit ──────────────────────────────────────────
        if dry_run:
            print_dry_run_report(results, maltytask_only_count, bc_total, n_both_sides_retired)
            conn.close()
            return

        # ── Apply ─────────────────────────────────────────────────────────────
        n_match_updated  = 0
        n_match_synced   = 0
        n_new_inserted   = 0
        n_drift_auto     = 0
        n_drift_review   = 0

        for result in results:
            if isinstance(result, MatchResult):
                _apply_match_full(conn, result, result.bc_fields)
                if result.diffs:
                    n_match_updated += 1
                else:
                    n_match_synced += 1
            elif isinstance(result, NewResult):
                apply_new(conn, result)
                n_new_inserted += 1
            elif isinstance(result, DriftAutoResult):
                apply_drift_auto(conn, result)
                n_drift_auto += 1
            elif isinstance(result, DriftReviewResult):
                apply_drift_review(conn, result)
                n_drift_review += 1

        conn.commit()
        print()
        print("─" * 60)
        print("APPLY COMPLETE")
        print(f"  MATCH rows updated (field changes)   : {n_match_updated:,}")
        print(f"  MATCH rows already in sync (touched) : {n_match_synced:,}")
        print(f"  NEW rows inserted (needs_review=1)   : {n_new_inserted:,}")
        print(f"  DRIFT-AUTO bc_no corrections         : {n_drift_auto:,}")
        print(f"  DRIFT-REVIEW RQ rows emitted         : {n_drift_review:,}")
        print(f"  SKIPPED (both-sides-retired)         : {n_both_sides_retired:,}")
        print("─" * 60)
        conn.close()

    except Exception:
        conn.close()
        raise


if __name__ == "__main__":
    main()
