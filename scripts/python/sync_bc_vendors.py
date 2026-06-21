#!/usr/bin/env python3
"""
sync_bc_vendors.py — Daily BC → ref_suppliers enrichment connector.

Reads the full BC standard-API vendor list
  …/api/v2.0/companies({id})/vendors
and reconciles it against ref_suppliers using a 5-bucket classification:

  MATCH           — bc_vendor_no already set on a ref_suppliers row AND equals
                    a BC vendor's number. Would UPDATE BC-OWNED fields +
                    bc_last_synced_at. (Won't fire on first run; all NULL today.)
                    Curated fields (name, gl_account, category, currency,
                    vat_regime, hors_perimetre_cogs, commissioning_state,
                    criticality, parser_key, notes, supplier_id, sporadique,
                    row_hash) are NEVER touched.

  NAME_SEED_HIGH  — ref_suppliers row with NULL bc_vendor_no, normalized name
                    matches EXACTLY one BC vendor. On --apply: writes
                    bc_vendor_no + BC-owned fields (address, phone, email,
                    country via COALESCE, bc_last_synced_at). On dry-run:
                    reported in reconciliation list.

  NAME_SEED_REVIEW— Normalized name fuzzy match (token-sort ratio ≥ 0.92) to
                    exactly one BC vendor, but NOT exact. On --apply with
                    --apply-review: write same fields. On --apply without
                    --apply-review: skip writes, report only. Dry-run: report.

  BC_ONLY         — BC vendor matches no ref_suppliers row (neither bc_vendor_no
                    nor name match). Report only, no writes.

  MALTYTASK_ONLY  — ref_suppliers row with NULL bc_vendor_no and no BC name
                    match at all. Report count only.

  BLOCKED         — BC vendor with blocked field other than '' / '_x0020_' / ' '.
                    Not considered for matching; reported by count.

Field ownership contract:
  BC-OWNED   : bc_vendor_no, email, phone, address_line1, address_line2,
               postal_code, city, bc_last_synced_at.
               country (existing column): written via COALESCE — only fills
               if currently NULL; a manually-set value is preserved.
  CURATED    : name, gl_account, category, currency, vat_regime,
               hors_perimetre_cogs, commissioning_state, criticality,
               parser_key, notes, supplier_id, sporadique, row_hash.

Usage:
  # Dry-run (default) — reconciliation report, no writes:
  python3 scripts/python/sync_bc_vendors.py
  python3 scripts/python/sync_bc_vendors.py --dry-run

  # Apply — write HIGH seeds (exact name matches) + update MATCH rows:
  python3 scripts/python/sync_bc_vendors.py --apply

  # Apply HIGH + REVIEW tier fuzzy matches:
  python3 scripts/python/sync_bc_vendors.py --apply --apply-review

  # Limit number of BC vendors processed (smoke-testing):
  python3 scripts/python/sync_bc_vendors.py --limit 100

Credentials:
  /var/www/maltytask/config/bc.env   (BC OAuth2)
  /var/www/maltytask/config/db.env   (MySQL)
"""

from __future__ import annotations

import argparse
import difflib
import json
import os
import re
import sys
import unicodedata
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

# BC standard-API company GUID (same as sync_bc_customers.py).
BC_COMPANY_ID = "e2b691c6-8393-ed11-bff5-002248f307ee"

# Fuzzy match threshold for NAME_SEED_REVIEW tier.
_REVIEW_THRESHOLD = 0.92

# Path for the reconciliation report JSON.
# Written to /var/log/maltytask/ (maltytask user has write access there).
# Fallback to /tmp/ if that path isn't writable (e.g. local dev).
_LOG_DIR = Path("/var/log/maltytask")

# ── BC env loader ─────────────────────────────────────────────────────────────

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


# ── BC standard-API vendors fetch ─────────────────────────────────────────────

_VENDOR_FIELDS = ",".join([
    "number", "displayName",
    "addressLine1", "addressLine2", "city", "postalCode", "country",
    "phoneNumber", "email",
    "blocked",
    "taxRegistrationNumber", "currencyCode", "lastModifiedDateTime",
])


def _vendors_base_url(bc: dict[str, str]) -> str:
    tenant = bc["BC_TENANT_ID"]
    env    = bc["BC_ENVIRONMENT"]
    return (
        f"https://api.businesscentral.dynamics.com/v2.0/{tenant}/{env}"
        f"/api/v2.0/companies({BC_COMPANY_ID})/vendors"
    )


def fetch_bc_vendors(bc: dict[str, str], limit: int | None = None) -> list[dict[str, Any]]:
    """
    Full scan of BC standard-API /vendors endpoint.
    Follows @odata.nextLink for paging — no $top to avoid silent truncation.
    Returns list of raw BC vendor dicts.
    Token never logged.
    """
    token = _get_token(bc)
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept":        "application/json",
    }

    select_enc = quote(_VENDOR_FIELDS, safe="")
    base_url = _vendors_base_url(bc)
    url: str | None = f"{base_url}?$select={select_enc}"

    all_vendors: list[dict[str, Any]] = []
    page = 0

    print("  [BC API] fetching vendors (full scan, paged) …", flush=True)
    while url:
        resp = requests.get(url, headers=headers, timeout=120)
        if resp.status_code != 200:
            raise RuntimeError(
                f"BC vendors API failed: HTTP {resp.status_code} — {resp.text[:300]}"
            )
        j = resp.json()
        rows = j.get("value", [])
        all_vendors.extend(rows)
        page += 1
        print(
            f"  [BC API] page {page}: {len(rows)} vendors "
            f"(total so far: {len(all_vendors):,})",
            flush=True,
        )
        if limit and len(all_vendors) >= limit:
            all_vendors = all_vendors[:limit]
            print(f"  [BC API] --limit {limit} reached, stopping.", flush=True)
            break
        url = j.get("@odata.nextLink")

    print(f"  [BC API] fetch complete: {len(all_vendors):,} vendors total.", flush=True)
    return all_vendors


# ── Name normalisation & fuzzy matching ───────────────────────────────────────

def _normalize_name(s: str | None) -> str:
    """Lowercase, strip accents, remove legal suffixes, collapse non-alnum to space."""
    if not s:
        return ""
    # NFC normalization then strip diacritics
    s = unicodedata.normalize("NFD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    s = s.lower()
    # Remove common legal suffixes (word-boundary aware)
    legal = r"\b(sa|sarl|ag|gmbh|s.?rl|srl|ltd|e\.?v\.?|inc|llc|cie|co)\b"
    s = re.sub(legal, " ", s)
    # Collapse non-alnum to space
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return s.strip()


def _token_sort_ratio(a: str, b: str) -> float:
    """Compute a Levenshtein-based similarity after sorting tokens."""
    ta = " ".join(sorted(a.split()))
    tb = " ".join(sorted(b.split()))
    return difflib.SequenceMatcher(None, ta, tb).ratio()


# ── BC vendor field mapping ────────────────────────────────────────────────────

def _bc_vendor_to_db_fields(bc_v: dict[str, Any]) -> dict[str, Any]:
    """Map BC vendor dict to BC-OWNED DB columns."""
    blocked_raw = str(bc_v.get("blocked") or "").strip()
    # '_x0020_' = space-encoded = not blocked; 'All' = fully blocked
    is_blocked = blocked_raw not in ("", "_x0020_", " ")

    country_raw = bc_v.get("country")
    country = str(country_raw).strip()[:2].upper() if country_raw else None

    phone_raw = bc_v.get("phoneNumber")
    phone = str(phone_raw).strip()[:64] if phone_raw else None

    email_raw = bc_v.get("email")
    email = str(email_raw).strip().lower() if email_raw else None

    return {
        "email":         email,
        "phone":         phone,
        "address_line1": str(bc_v.get("addressLine1") or "").strip() or None,
        "address_line2": str(bc_v.get("addressLine2") or "").strip() or None,
        "postal_code":   str(bc_v.get("postalCode") or "").strip() or None,
        "city":          str(bc_v.get("city") or "").strip() or None,
        "country":       country,
        "is_blocked":    is_blocked,
    }


# ── ref_suppliers loader ───────────────────────────────────────────────────────

class SupplierRow:
    __slots__ = (
        "id", "supplier_id", "name", "bc_vendor_no", "gl_account",
        "email", "phone", "address_line1", "address_line2",
        "postal_code", "city", "country", "is_active",
    )

    def __init__(self, row: dict[str, Any]) -> None:
        self.id            = int(row["id"])
        self.supplier_id   = str(row["supplier_id"] or "").strip()
        self.name          = str(row["name"] or "").strip()
        self.bc_vendor_no  = str(row["bc_vendor_no"]).strip() if row.get("bc_vendor_no") else None
        self.gl_account    = str(row.get("gl_account") or "").strip() or None
        self.email         = str(row["email"]).strip() if row.get("email") else None
        self.phone         = str(row["phone"]).strip() if row.get("phone") else None
        self.address_line1 = str(row["address_line1"]).strip() if row.get("address_line1") else None
        self.address_line2 = str(row["address_line2"]).strip() if row.get("address_line2") else None
        self.postal_code   = str(row["postal_code"]).strip() if row.get("postal_code") else None
        self.city          = str(row["city"]).strip() if row.get("city") else None
        self.country       = str(row["country"]).strip() if row.get("country") else None
        self.is_active     = bool(row.get("is_active", 1))


def load_ref_suppliers(conn: pymysql.connections.Connection) -> tuple[dict[str, SupplierRow], list[SupplierRow]]:
    """
    Load ALL ref_suppliers rows.
    Returns:
      by_bc_no  : {bc_vendor_no: SupplierRow}  (only rows with non-NULL bc_vendor_no)
      all_rows  : full list
    """
    with conn.cursor() as c:
        c.execute(
            """SELECT id, supplier_id, name, bc_vendor_no, gl_account,
                      email, phone, address_line1, address_line2,
                      postal_code, city, country, is_active
                 FROM ref_suppliers"""
        )
        rows = c.fetchall()
    all_rows = [SupplierRow(r) for r in rows]
    by_bc_no: dict[str, SupplierRow] = {
        r.bc_vendor_no: r for r in all_rows if r.bc_vendor_no is not None
    }
    return by_bc_no, all_rows


# ── Reconcile ─────────────────────────────────────────────────────────────────

def reconcile(
    bc_vendors: list[dict[str, Any]],
    by_bc_no: dict[str, SupplierRow],
    all_rows: list[SupplierRow],
) -> tuple[list, list, list, list, int, int]:
    """
    Classify every BC vendor into one of: MATCH, NAME_SEED_HIGH,
    NAME_SEED_REVIEW, BC_ONLY. Also counts BLOCKED and maltytask-only.

    Returns:
      matches        : list of (bc_vendor, SupplierRow, bc_fields)
      high_seeds     : list of (bc_vendor, SupplierRow, bc_fields, score=1.0)
      review_seeds   : list of (bc_vendor, SupplierRow, bc_fields, score)
      bc_only        : list of bc_vendor dicts
      blocked_count  : int
      maltytask_only_count : int
    """
    # Name index for unlinked active rows only
    unlinked = [r for r in all_rows if r.bc_vendor_no is None and r.is_active]
    norm_index: dict[str, list[SupplierRow]] = {}
    for r in unlinked:
        k = _normalize_name(r.name)
        norm_index.setdefault(k, []).append(r)

    matches: list[tuple[dict, SupplierRow, dict]] = []
    high_seeds: list[tuple[dict, SupplierRow, dict, float]] = []
    review_seeds: list[tuple[dict, SupplierRow, dict, float]] = []
    bc_only: list[dict[str, Any]] = []
    blocked_count = 0

    for bc_v in bc_vendors:
        bc_no      = str(bc_v.get("number") or "").strip()
        bc_display = str(bc_v.get("displayName") or "").strip()
        bc_fields  = _bc_vendor_to_db_fields(bc_v)

        if bc_fields["is_blocked"]:
            blocked_count += 1
            continue

        # ── MATCH bucket: bc_vendor_no already set ────────────────────────────
        if bc_no in by_bc_no:
            matches.append((bc_v, by_bc_no[bc_no], bc_fields))
            continue

        # ── Name-seed matching for unlinked rows ──────────────────────────────
        bc_norm = _normalize_name(bc_display)

        # Exact normalized match
        if bc_norm in norm_index:
            candidates = norm_index[bc_norm]
            if len(candidates) == 1:
                high_seeds.append((bc_v, candidates[0], bc_fields, 1.0))
                continue
            # Multiple exact matches → ambiguous → BC_ONLY (report)
            bc_only.append(bc_v)
            continue

        # Fuzzy match against all unlinked rows
        best_score = 0.0
        best_cand: SupplierRow | None = None
        for r in unlinked:
            rn = _normalize_name(r.name)
            score = _token_sort_ratio(bc_norm, rn)
            if score > best_score:
                best_score = score
                best_cand = r

        if best_score >= _REVIEW_THRESHOLD and best_cand is not None:
            # Confirm uniqueness: only one unlinked row at this score level
            near_best = [
                r for r in unlinked
                if _token_sort_ratio(bc_norm, _normalize_name(r.name)) >= _REVIEW_THRESHOLD
            ]
            if len(near_best) == 1:
                review_seeds.append((bc_v, best_cand, bc_fields, best_score))
                continue

        bc_only.append(bc_v)

    maltytask_only_count = len([r for r in all_rows if r.bc_vendor_no is None and r.is_active])
    return matches, high_seeds, review_seeds, bc_only, blocked_count, maltytask_only_count


# ── Apply helpers ─────────────────────────────────────────────────────────────

def _apply_seed(
    conn: pymysql.connections.Connection,
    supplier_row: SupplierRow,
    bc_no: str,
    bc_fields: dict[str, Any],
) -> None:
    """Write bc_vendor_no + BC-owned enrichment fields. NEVER touches row_hash."""
    now_utc = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    with conn.cursor() as c:
        c.execute(
            """UPDATE ref_suppliers
                  SET bc_vendor_no      = %s,
                      email             = %s,
                      phone             = %s,
                      address_line1     = %s,
                      address_line2     = %s,
                      postal_code       = %s,
                      city              = %s,
                      country           = COALESCE(country, %s),
                      bc_last_synced_at = %s
               WHERE id = %s AND bc_vendor_no IS NULL""",
            (
                bc_no,
                bc_fields["email"],
                bc_fields["phone"],
                bc_fields["address_line1"],
                bc_fields["address_line2"],
                bc_fields["postal_code"],
                bc_fields["city"],
                bc_fields["country"],  # COALESCE: only fills if currently NULL
                now_utc,
                supplier_row.id,
            ),
        )


def _apply_match(
    conn: pymysql.connections.Connection,
    supplier_row: SupplierRow,
    bc_fields: dict[str, Any],
) -> None:
    """Update BC-owned enrichment fields for already-linked row. Never touches row_hash."""
    now_utc = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    with conn.cursor() as c:
        c.execute(
            """UPDATE ref_suppliers
                  SET email             = %s,
                      phone             = %s,
                      address_line1     = %s,
                      address_line2     = %s,
                      postal_code       = %s,
                      city              = %s,
                      bc_last_synced_at = %s
               WHERE id = %s""",
            (
                bc_fields["email"],
                bc_fields["phone"],
                bc_fields["address_line1"],
                bc_fields["address_line2"],
                bc_fields["postal_code"],
                bc_fields["city"],
                now_utc,
                supplier_row.id,
            ),
        )
        # Do NOT update country for MATCH — it's an existing column that may
        # carry a manually-curated value. COALESCE only applies to seed writes.


# ── Reconciliation report ─────────────────────────────────────────────────────

def _write_reconcile_report(
    dry_run: bool,
    matches: list,
    high_seeds: list,
    review_seeds: list,
    bc_only: list,
    blocked_count: int,
    maltytask_only_count: int,
) -> Path:
    """Write JSON reconciliation report to data/bc-vendor-reconcile-report.json."""
    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "dry_run": dry_run,
        "buckets": {
            "match":            len(matches),
            "name_seed_high":   len(high_seeds),
            "name_seed_review": len(review_seeds),
            "bc_only":          len(bc_only),
            "maltytask_only":   maltytask_only_count,
            "blocked":          blocked_count,
        },
        "high_seeds": [
            {
                "ref_supplier_id":   row.id,
                "ref_supplier_name": row.name,
                "bc_vendor_no":      str(bc_v.get("number") or "").strip(),
                "bc_display_name":   str(bc_v.get("displayName") or "").strip(),
                "score":             score,
                "tier":              "HIGH",
            }
            for bc_v, row, _fields, score in high_seeds
        ],
        "review_seeds": [
            {
                "ref_supplier_id":   row.id,
                "ref_supplier_name": row.name,
                "bc_vendor_no":      str(bc_v.get("number") or "").strip(),
                "bc_display_name":   str(bc_v.get("displayName") or "").strip(),
                "score":             round(score, 4),
                "tier":              "REVIEW",
            }
            for bc_v, row, _fields, score in review_seeds
        ],
        "bc_only_sample": [
            {
                "number":      str(v.get("number") or "").strip(),
                "displayName": str(v.get("displayName") or "").strip(),
                "city":        str(v.get("city") or "").strip() or None,
                "country":     str(v.get("country") or "").strip() or None,
            }
            for v in bc_only[:50]
        ],
        "bc_only_total": len(bc_only),
    }

    # Write to /var/log/maltytask/ (maltytask cron user has write access);
    # fall back to /tmp/ for local dev environments.
    if _LOG_DIR.is_dir() and os.access(str(_LOG_DIR), os.W_OK):
        out_path = _LOG_DIR / "bc-vendor-reconcile-report.json"
    else:
        out_path = Path("/tmp/bc-vendor-reconcile-report.json")
    out_path.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    return out_path


# ── Dry-run report (stdout) ───────────────────────────────────────────────────

def print_dry_run_report(
    matches: list,
    high_seeds: list,
    review_seeds: list,
    bc_only: list,
    blocked_count: int,
    maltytask_only_count: int,
    bc_total: int,
    report_path: Path,
) -> None:
    print()
    print("=" * 72)
    print("DRY-RUN RECONCILIATION REPORT — sync_bc_vendors.py")
    print("=" * 72)
    print()
    print("BUCKET SUMMARY")
    print(f"  BC vendors fetched              : {bc_total:,}")
    print(f"  BLOCKED (skipped)               : {blocked_count:,}")
    print(f"  MATCH (bc_vendor_no linked)     : {len(matches):,}")
    print(f"  NAME_SEED_HIGH (exact match)    : {len(high_seeds):,}")
    print(f"  NAME_SEED_REVIEW (fuzzy ≥0.92)  : {len(review_seeds):,}")
    print(f"  BC_ONLY (no ref_suppliers match): {len(bc_only):,}")
    print(f"  MALTYTASK_ONLY (NULL bc_vendor_no, active): {maltytask_only_count:,}")
    print()

    # ── HIGH seeds (full list) ────────────────────────────────────────────────
    print("─" * 72)
    print(f"NAME_SEED_HIGH — {len(high_seeds)} exact matches (would write on --apply)")
    print("─" * 72)
    if not high_seeds:
        print("  (none)")
    for i, (bc_v, row, _fields, score) in enumerate(high_seeds, 1):
        bc_no      = str(bc_v.get("number") or "").strip()
        bc_display = str(bc_v.get("displayName") or "").strip()
        print(
            f"  [{i:3d}] ref_supplier id={row.id:<4}  '{row.name}'"
            f"\n         → BC {bc_no}  '{bc_display}'  score=1.0  tier=HIGH"
        )
    print()

    # ── REVIEW seeds (full list) ──────────────────────────────────────────────
    print("─" * 72)
    print(f"NAME_SEED_REVIEW — {len(review_seeds)} fuzzy matches (need --apply-review to write)")
    print("─" * 72)
    if not review_seeds:
        print("  (none)")
    for i, (bc_v, row, _fields, score) in enumerate(review_seeds, 1):
        bc_no      = str(bc_v.get("number") or "").strip()
        bc_display = str(bc_v.get("displayName") or "").strip()
        print(
            f"  [{i:3d}] ref_supplier id={row.id:<4}  '{row.name}'"
            f"\n         → BC {bc_no}  '{bc_display}'  score={score:.4f}  tier=REVIEW"
        )
    print()

    # ── BC-only sample ────────────────────────────────────────────────────────
    print("─" * 72)
    print(f"BC_ONLY — {len(bc_only)} vendors with no ref_suppliers match (first 30 shown)")
    print("─" * 72)
    if not bc_only:
        print("  (none)")
    for v in bc_only[:30]:
        bc_no      = str(v.get("number") or "").strip()
        bc_display = str(v.get("displayName") or "").strip()
        city       = str(v.get("city") or "").strip() or "—"
        country    = str(v.get("country") or "").strip() or "—"
        print(f"  {bc_no:<18}  '{bc_display}'  city={city}  country={country}")
    if len(bc_only) > 30:
        print(f"  … and {len(bc_only) - 30} more (see report JSON).")
    print()

    print("─" * 72)
    print(f"MALTYTASK_ONLY count (active, NULL bc_vendor_no): {maltytask_only_count:,}")
    print("─" * 72)
    print()

    print("=" * 72)
    print("DRY-RUN COMPLETE — no writes made.")
    print(f"Reconciliation report written to: {report_path}")
    print("=" * 72)


# ── Main ───────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(
        description="BC → ref_suppliers enrichment connector."
    )
    ap.add_argument(
        "--dry-run", action="store_true", default=True,
        help="Reconciliation report only, no writes (default).",
    )
    ap.add_argument(
        "--apply", action="store_true", default=False,
        help="Write BC-OWNED fields to ref_suppliers (HIGH seeds + MATCH rows).",
    )
    ap.add_argument(
        "--apply-review", action="store_true", default=False,
        help="Also write REVIEW-tier fuzzy matches on --apply (default: skip).",
    )
    ap.add_argument(
        "--limit", type=int, metavar="N",
        help="Process first N BC vendors only (smoke-testing).",
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
        # ── Load ref_suppliers ────────────────────────────────────────────────
        print("Loading ref_suppliers …", end=" ", flush=True)
        by_bc_no, all_rows = load_ref_suppliers(conn)
        n_linked       = len(by_bc_no)
        n_unlinked     = len([r for r in all_rows if r.bc_vendor_no is None and r.is_active])
        print(
            f"done — {len(all_rows):,} rows "
            f"({n_linked:,} with bc_vendor_no, "
            f"{n_unlinked:,} active/unlinked).",
            flush=True,
        )

        # ── Fetch BC vendors ──────────────────────────────────────────────────
        bc_cfg     = _load_bc_env()
        bc_vendors = fetch_bc_vendors(bc_cfg, limit=args.limit)
        bc_total   = len(bc_vendors)

        # ── Classify ──────────────────────────────────────────────────────────
        print("Reconciling …", end=" ", flush=True)
        matches, high_seeds, review_seeds, bc_only, blocked_count, maltytask_only_count = reconcile(
            bc_vendors, by_bc_no, all_rows
        )
        print("done.", flush=True)

        # ── Write reconciliation report (always, dry-run or apply) ────────────
        report_path = _write_reconcile_report(
            dry_run=dry_run,
            matches=matches,
            high_seeds=high_seeds,
            review_seeds=review_seeds,
            bc_only=bc_only,
            blocked_count=blocked_count,
            maltytask_only_count=maltytask_only_count,
        )

        # ── Dry-run: report and exit ──────────────────────────────────────────
        if dry_run:
            print_dry_run_report(
                matches=matches,
                high_seeds=high_seeds,
                review_seeds=review_seeds,
                bc_only=bc_only,
                blocked_count=blocked_count,
                maltytask_only_count=maltytask_only_count,
                bc_total=bc_total,
                report_path=report_path,
            )
            conn.close()
            return

        # ── Apply ─────────────────────────────────────────────────────────────
        n_match_updated  = 0
        n_high_seeded    = 0
        n_review_seeded  = 0
        n_review_skipped = 0

        # MATCH: update BC-owned fields on already-linked rows
        for bc_v, row, bc_fields in matches:
            _apply_match(conn, row, bc_fields)
            n_match_updated += 1

        # HIGH seeds: write bc_vendor_no + BC-owned fields
        for bc_v, row, bc_fields, _score in high_seeds:
            bc_no = str(bc_v.get("number") or "").strip()
            _apply_seed(conn, row, bc_no, bc_fields)
            n_high_seeded += 1

        # REVIEW seeds: only if --apply-review flag set
        for bc_v, row, bc_fields, _score in review_seeds:
            if args.apply_review:
                bc_no = str(bc_v.get("number") or "").strip()
                _apply_seed(conn, row, bc_no, bc_fields)
                n_review_seeded += 1
            else:
                n_review_skipped += 1

        conn.commit()
        print()
        print("─" * 60)
        print("APPLY COMPLETE")
        print(f"  MATCH rows refreshed (BC-owned fields)   : {n_match_updated:,}")
        print(f"  NAME_SEED_HIGH written (bc_vendor_no set): {n_high_seeded:,}")
        print(f"  NAME_SEED_REVIEW written (--apply-review): {n_review_seeded:,}")
        print(f"  NAME_SEED_REVIEW skipped (no --apply-review flag): {n_review_skipped:,}")
        print(f"  BC_ONLY (report only, no writes)         : {len(bc_only):,}")
        print(f"  BLOCKED (skipped)                        : {blocked_count:,}")
        print(f"  Reconciliation report written to         : {report_path}")
        print("─" * 60)
        conn.close()

    except Exception:
        conn.close()
        raise


if __name__ == "__main__":
    main()
