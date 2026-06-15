#!/usr/bin/env python3
"""
ingest_bc_sales_invoice_lines.py — BC Standard API v2.0 invoice-line discounts connector.

Reads salesInvoices/$expand=salesInvoiceLines and
       salesCreditMemos/$expand=salesCreditMemoLines
from the BC Standard API v2.0 and upserts into inv_sales_invoice_lines.

NK: bc_line_id (line-level systemId GUID — idempotent upsert).

Credit-memo discount components are stored NEGATIVE so SUM(discount_amount_chf
+ invoice_disc_alloc_chf) nets correctly across invoices + credits.

Incremental strategy: floor = MIN(posting_date) in table if table non-empty,
else --floor argument (default '2025-01-01'). Pulls all headers on/after the
floor date; upserts all lines idempotently via bc_line_id UNIQUE KEY.

Usage:
  # Dry-run (default):
  python3 scripts/python/ingest_bc_sales_invoice_lines.py

  # Apply (writes to DB):
  python3 scripts/python/ingest_bc_sales_invoice_lines.py --apply

  # Override floor date:
  python3 scripts/python/ingest_bc_sales_invoice_lines.py --apply --floor 2025-01-01

  # Verify a specific month (read-only):
  python3 scripts/python/ingest_bc_sales_invoice_lines.py --verify 2026-05

Credentials:
  DB:  /var/www/maltytask/config/db.env  (via lib_config)
  BC:  /var/www/maltytask/config/bc.env  (BC_TENANT_ID, BC_CLIENT_ID,
       BC_CLIENT_SECRET, BC_ENVIRONMENT)
"""

from __future__ import annotations

import argparse
import os
import sys
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any

# Allow running from /var/www/maltytask (adds scripts/python to path for lib_*)
_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

import pymysql  # noqa: E402 — after sys.path fix

try:
    import requests  # noqa: E402
except ImportError:
    print("ERROR: requests not installed. Run: pip install requests", file=sys.stderr)
    sys.exit(1)

from lib_config import load as load_config  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

DEFAULT_FLOOR = "2025-01-01"
BATCH_SIZE = 500

# ── BC config loader (verbatim from ingest_bc_sales_ledger.py) ────────────────

_BC_ENV_PATH = Path("/var/www/maltytask/config/bc.env")


def _load_bc_env() -> dict[str, str]:
    """
    Parse /var/www/maltytask/config/bc.env.
    Format: KEY=VALUE, one per line; blank lines and # comments ignored.
    Raises RuntimeError if the file is missing or a required key is absent.
    """
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


# ── OAuth2 token (verbatim from ingest_bc_sales_ledger.py) ───────────────────

def _get_token(bc: dict[str, str]) -> str:
    """
    Fetch an OAuth2 client-credentials access token from Azure AD.
    Logs only HTTP status and expires_in — never logs the secret or the token.
    """
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
            f"Token request failed: HTTP {resp.status_code} — "
            f"{resp.text[:200]}"
        )
    j = resp.json()
    expires_in = j.get("expires_in", "?")
    print(f"  [OAuth2] token acquired, expires_in={expires_in}s", flush=True)
    return j["access_token"]


# ── Number parser (verbatim from ingest_bc_sales_ledger.py) ──────────────────

def parse_swiss_number(v: Any, default: Decimal | None = None) -> Decimal | None:
    """
    Safe numeric parser for BC cells (OData string/int/float).
    Handles int/float (native), str with trailing dot ("-1."), str with
    thousands separator (",").  Returns Decimal for precision, or default.
    """
    if v is None:
        return default
    if isinstance(v, (int, float)):
        try:
            return Decimal(str(v))
        except InvalidOperation:
            return default
    s = str(v).strip()
    if s in ("", "-"):
        return default
    s = s.replace(",", "")
    # Strip trailing dot (some BC exports have "-1.")
    if s.endswith("."):
        s = s[:-1]
    try:
        return Decimal(s)
    except InvalidOperation:
        return default


# ── Standard API helpers ───────────────────────────────────────────────────────

def _api_base(bc: dict[str, str]) -> str:
    """Return BC Standard API v2.0 base URL."""
    return (
        f"https://api.businesscentral.dynamics.com/v2.0/"
        f"{bc['BC_TENANT_ID']}/{bc['BC_ENVIRONMENT']}/api/v2.0"
    )


def _resolve_company_id(api_base: str, headers: dict[str, str]) -> str:
    """
    GET {api_base}/companies → pick the company whose name starts with 'NEB' → return its id.
    Raises RuntimeError if no matching company found.
    """
    url = f"{api_base}/companies"
    resp = requests.get(url, headers=headers, timeout=30)
    if resp.status_code != 200:
        raise RuntimeError(
            f"GET /companies failed: HTTP {resp.status_code} — {resp.text[:300]}"
        )
    companies = resp.json().get("value", [])
    for c in companies:
        name = (c.get("displayName") or c.get("name") or "").strip()
        if "NEB" in name.upper():
            print(f"  [API] resolved company: {name!r} → id={c['id']}", flush=True)
            return c["id"]
    names = [c.get("displayName") or c.get("name") for c in companies]
    raise RuntimeError(
        f"No company starting with 'NEB' found. Available: {names}"
    )


def _fetch_paged(url: str, headers: dict[str, str], label: str) -> list[dict[str, Any]]:
    """
    Follow @odata.nextLink paging. Returns all collected items.
    NO $top — relies on server paging. Prints page progress.
    """
    all_items: list[dict[str, Any]] = []
    page = 0
    while url:
        resp = requests.get(url, headers=headers, timeout=120)
        if resp.status_code != 200:
            raise RuntimeError(
                f"API request failed ({label}): HTTP {resp.status_code} — "
                f"{resp.text[:300]}"
            )
        j = resp.json()
        items = j.get("value", [])
        all_items.extend(items)
        page += 1
        print(
            f"  [API] {label} page {page}: {len(items)} items "
            f"(total so far: {len(all_items):,})",
            flush=True,
        )
        url = j.get("@odata.nextLink")
    return all_items


# ── DB helpers ─────────────────────────────────────────────────────────────────

def _get_floor(conn: pymysql.connections.Connection, cli_floor: str) -> str:
    """
    Incremental floor: MIN(posting_date) from table if non-empty, else cli_floor.
    This re-pulls the entire tracked window on each run — upsert on bc_line_id
    handles idempotency so re-processing existing rows is cheap.
    """
    with conn.cursor() as c:
        c.execute("SELECT MIN(posting_date) AS mn FROM inv_sales_invoice_lines")
        row = c.fetchone()
        mn = row["mn"] if row else None
        if mn is None:
            return cli_floor
        return mn.isoformat() if hasattr(mn, "isoformat") else str(mn)


# ── Line-row builders ──────────────────────────────────────────────────────────

def _build_invoice_line_row(header: dict[str, Any], line: dict[str, Any]) -> dict[str, Any]:
    """Map one salesInvoiceLine → row dict for upsert. Discounts POSITIVE."""
    sku_code = None
    if str(line.get("lineType", "")).strip() == "Item":
        raw = line.get("lineObjectNumber")
        if raw:
            sku_code = str(raw).strip() or None

    # Posting date: take from header; strip T-suffix if present
    pd_raw = str(header.get("postingDate", "")).strip()
    if "T" in pd_raw:
        pd_raw = pd_raw.split("T")[0]

    disc_amt    = parse_swiss_number(line.get("discountAmount"),            Decimal("0"))
    disc_alloc  = parse_swiss_number(line.get("invoiceDiscountAllocation"),  Decimal("0"))
    net_amount  = parse_swiss_number(line.get("netAmount"))
    quantity    = parse_swiss_number(line.get("quantity"))

    return {
        "bc_line_id":               str(line["id"]).strip(),
        "document_type":            "invoice",
        "document_no":              str(header.get("number", "")).strip(),
        "posting_date":             pd_raw,
        "customer_no":              str(header.get("customerNumber", "")).strip() or None,
        "sku_code":                 sku_code,
        "line_type":                str(line.get("lineType", "")).strip() or None,
        "quantity":                 quantity,
        "line_amount_excl_tax_chf": net_amount,
        "discount_amount_chf":      disc_amt   if disc_amt   is not None else Decimal("0"),
        "invoice_disc_alloc_chf":   disc_alloc if disc_alloc is not None else Decimal("0"),
        "source":                   "bc-api:salesInvoiceLines",
    }


def _build_credit_line_row(header: dict[str, Any], line: dict[str, Any]) -> dict[str, Any]:
    """Map one salesCreditMemoLine → row dict. Discounts NEGATIVE (credits net correctly)."""
    sku_code = None
    if str(line.get("lineType", "")).strip() == "Item":
        raw = line.get("lineObjectNumber")
        if raw:
            sku_code = str(raw).strip() or None

    pd_raw = str(header.get("postingDate", "")).strip()
    if "T" in pd_raw:
        pd_raw = pd_raw.split("T")[0]

    disc_amt   = parse_swiss_number(line.get("discountAmount"),            Decimal("0"))
    disc_alloc = parse_swiss_number(line.get("invoiceDiscountAllocation"),  Decimal("0"))
    net_amount = parse_swiss_number(line.get("netAmount"))
    quantity   = parse_swiss_number(line.get("quantity"))

    # Credit-memo discount components stored NEGATIVE so SUM nets correctly.
    neg_disc_amt   = -(disc_amt   if disc_amt   is not None else Decimal("0"))
    neg_disc_alloc = -(disc_alloc if disc_alloc is not None else Decimal("0"))

    return {
        "bc_line_id":               str(line["id"]).strip(),
        "document_type":            "credit",
        "document_no":              str(header.get("number", "")).strip(),
        "posting_date":             pd_raw,
        "customer_no":              str(header.get("customerNumber", "")).strip() or None,
        "sku_code":                 sku_code,
        "line_type":                str(line.get("lineType", "")).strip() or None,
        "quantity":                 quantity,
        "line_amount_excl_tax_chf": net_amount,
        "discount_amount_chf":      neg_disc_amt,
        "invoice_disc_alloc_chf":   neg_disc_alloc,
        "source":                   "bc-api:salesCreditMemoLines",
    }


# ── DB upsert ──────────────────────────────────────────────────────────────────

_UPSERT_SQL = """
INSERT INTO inv_sales_invoice_lines
    (bc_line_id, document_type, document_no, posting_date, customer_no,
     sku_code, line_type, quantity, line_amount_excl_tax_chf,
     discount_amount_chf, invoice_disc_alloc_chf, source)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE
    document_type            = VALUES(document_type),
    document_no              = VALUES(document_no),
    posting_date             = VALUES(posting_date),
    customer_no              = VALUES(customer_no),
    sku_code                 = VALUES(sku_code),
    line_type                = VALUES(line_type),
    quantity                 = VALUES(quantity),
    line_amount_excl_tax_chf = VALUES(line_amount_excl_tax_chf),
    discount_amount_chf      = VALUES(discount_amount_chf),
    invoice_disc_alloc_chf   = VALUES(invoice_disc_alloc_chf),
    source                   = VALUES(source)
"""


def _upsert_rows(
    conn: pymysql.connections.Connection,
    rows: list[dict[str, Any]],
) -> int:
    """Upsert rows in batches of BATCH_SIZE. Returns aggregate MySQL rowcount."""
    total_rc = 0
    with conn.cursor() as c:
        for start in range(0, len(rows), BATCH_SIZE):
            batch = rows[start: start + BATCH_SIZE]
            params = [
                (
                    r["bc_line_id"],
                    r["document_type"],
                    r["document_no"],
                    r["posting_date"],
                    r["customer_no"],
                    r["sku_code"],
                    r["line_type"],
                    str(r["quantity"])                 if r["quantity"]                 is not None else None,
                    str(r["line_amount_excl_tax_chf"]) if r["line_amount_excl_tax_chf"] is not None else None,
                    str(r["discount_amount_chf"]),
                    str(r["invoice_disc_alloc_chf"]),
                    r["source"],
                )
                for r in batch
            ]
            c.executemany(_UPSERT_SQL, params)
            total_rc += c.rowcount
        conn.commit()
    return total_rc


# ── --verify helper ────────────────────────────────────────────────────────────

def _run_verify(conn: pymysql.connections.Connection, month: str) -> None:
    """
    Read-only: for the given month (YYYY-MM) print 4 decomposition sums from DB.
    """
    print(f"\n── VERIFY {month} ──────────────────────────────────────────────────────", flush=True)
    with conn.cursor() as c:
        # 1. Σ discountAmount (invoices only)
        c.execute(
            "SELECT SUM(discount_amount_chf) AS s, COUNT(DISTINCT document_no) AS n"
            " FROM inv_sales_invoice_lines"
            " WHERE DATE_FORMAT(posting_date,'%%Y-%%m') = %s AND document_type='invoice'",
            (month,),
        )
        r = c.fetchone()
        print(f"  1. Σ discount_amount_chf         (invoices only) = {r['s']!r}  n_docs={r['n']}")

        # 2. Σ (discountAmount + invoiceDiscountAllocation) (invoices only)
        c.execute(
            "SELECT SUM(discount_amount_chf + invoice_disc_alloc_chf) AS s"
            " FROM inv_sales_invoice_lines"
            " WHERE DATE_FORMAT(posting_date,'%%Y-%%m') = %s AND document_type='invoice'",
            (month,),
        )
        r = c.fetchone()
        print(f"  2. Σ (disc + alloc)              (invoices only) = {r['s']!r}")

        # 3a. Net Σ discountAmount (invoices + credits)
        c.execute(
            "SELECT SUM(discount_amount_chf) AS s, COUNT(DISTINCT document_no) AS n"
            " FROM inv_sales_invoice_lines"
            " WHERE DATE_FORMAT(posting_date,'%%Y-%%m') = %s",
            (month,),
        )
        r = c.fetchone()
        print(f"  3. Σ discount_amount_chf         (net incl. credits) = {r['s']!r}  n_docs={r['n']}")

        # 3b. Net Σ (disc + alloc) (invoices + credits)
        c.execute(
            "SELECT SUM(discount_amount_chf + invoice_disc_alloc_chf) AS s"
            " FROM inv_sales_invoice_lines"
            " WHERE DATE_FORMAT(posting_date,'%%Y-%%m') = %s",
            (month,),
        )
        r = c.fetchone()
        print(f"  4. Σ (disc + alloc)              (net incl. credits) = {r['s']!r}")
    print("─" * 70, flush=True)


# ── Dry-run report ─────────────────────────────────────────────────────────────

def _dry_run_report(rows: list[dict[str, Any]], floor: str) -> None:
    inv_rows  = [r for r in rows if r["document_type"] == "invoice"]
    cred_rows = [r for r in rows if r["document_type"] == "credit"]
    n_inv_docs  = len({r["document_no"] for r in inv_rows})
    n_cred_docs = len({r["document_no"] for r in cred_rows})

    total_disc_inv  = sum(r["discount_amount_chf"]    for r in inv_rows)
    total_alloc_inv = sum(r["invoice_disc_alloc_chf"] for r in inv_rows)
    total_disc_net  = sum(r["discount_amount_chf"]    for r in rows)
    total_alloc_net = sum(r["invoice_disc_alloc_chf"] for r in rows)

    print()
    print("=" * 70)
    print("DRY-RUN REPORT — ingest_bc_sales_invoice_lines.py")
    print("=" * 70)
    print(f"  Floor date              : {floor}")
    print(f"  Total lines             : {len(rows):,}")
    print(f"  Invoice lines           : {len(inv_rows):,}  ({n_inv_docs} documents)")
    print(f"  Credit-memo lines       : {len(cred_rows):,}  ({n_cred_docs} documents)")
    print()
    print("  Discount sums (invoices only):")
    print(f"    Σ discount_amount_chf      = {float(total_disc_inv):,.4f}")
    print(f"    Σ invoice_disc_alloc_chf   = {float(total_alloc_inv):,.4f}")
    print(f"    Σ (disc + alloc)           = {float(total_disc_inv + total_alloc_inv):,.4f}")
    print()
    print("  Discount sums (net incl. credits):")
    print(f"    Σ discount_amount_chf      = {float(total_disc_net):,.4f}")
    print(f"    Σ (disc + alloc)           = {float(total_disc_net + total_alloc_net):,.4f}")
    if rows:
        dates = [r["posting_date"] for r in rows if r["posting_date"]]
        print()
        print(f"  Date range: {min(dates)} → {max(dates)}")
    print()
    print("  Line-type histogram (invoices):")
    lt_count: dict[str, int] = {}
    for r in inv_rows:
        lt = r["line_type"] or "(null)"
        lt_count[lt] = lt_count.get(lt, 0) + 1
    for lt, cnt in sorted(lt_count.items(), key=lambda x: -x[1]):
        print(f"    {lt:<20} {cnt:>6,}")
    print("=" * 70)
    print()


# ── Main ───────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(
        description="BC Standard API v2.0 invoice lines → inv_sales_invoice_lines."
    )
    ap.add_argument(
        "--apply", action="store_true", default=False,
        help="Write rows to DB (default: dry-run).",
    )
    ap.add_argument(
        "--floor", metavar="YYYY-MM-DD", default=DEFAULT_FLOOR,
        help=f"Earliest posting_date to pull (default: {DEFAULT_FLOOR}). "
             "Overridden by MIN(posting_date) in DB when table is non-empty.",
    )
    ap.add_argument(
        "--verify", metavar="YYYY-MM",
        help="Read-only: print 4 discount sums for the given month from DB.",
    )
    args = ap.parse_args()

    dry_run = not args.apply

    # ── Connect ───────────────────────────────────────────────────────────────
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
        # ── --verify (read-only) ──────────────────────────────────────────────
        if args.verify:
            _run_verify(conn, args.verify)
            conn.close()
            return

        # ── Resolve incremental floor ─────────────────────────────────────────
        floor = _get_floor(conn, args.floor)
        print(f"Incremental floor: {floor}", flush=True)

        # ── Auth + company resolution ─────────────────────────────────────────
        bc      = _load_bc_env()
        token   = _get_token(bc)
        api_base = _api_base(bc)
        headers = {
            "Authorization": f"Bearer {token}",
            "Accept":        "application/json",
        }
        company_id = _resolve_company_id(api_base, headers)
        cid = company_id

        # ── Pull invoices ─────────────────────────────────────────────────────
        inv_url = (
            f"{api_base}/companies({cid})/salesInvoices"
            f"?$expand=salesInvoiceLines"
            f"&$filter=postingDate ge {floor}"
        )
        print(f"\nPulling salesInvoices (postingDate ge {floor}) …", flush=True)
        invoices = _fetch_paged(inv_url, headers, "salesInvoices")
        print(f"  → {len(invoices):,} invoice headers retrieved.", flush=True)

        # ── Pull credit memos ─────────────────────────────────────────────────
        cred_url = (
            f"{api_base}/companies({cid})/salesCreditMemos"
            f"?$expand=salesCreditMemoLines"
            f"&$filter=postingDate ge {floor}"
        )
        print(f"\nPulling salesCreditMemos (postingDate ge {floor}) …", flush=True)
        credits = _fetch_paged(cred_url, headers, "salesCreditMemos")
        print(f"  → {len(credits):,} credit-memo headers retrieved.", flush=True)

        # ── Build line rows ───────────────────────────────────────────────────
        rows: list[dict[str, Any]] = []
        skipped_no_id = 0

        for header in invoices:
            for line in header.get("salesInvoiceLines", []):
                if not line.get("id"):
                    skipped_no_id += 1
                    continue
                rows.append(_build_invoice_line_row(header, line))

        for header in credits:
            for line in header.get("salesCreditMemoLines", []):
                if not line.get("id"):
                    skipped_no_id += 1
                    continue
                rows.append(_build_credit_line_row(header, line))

        print(
            f"\nTotal line rows: {len(rows):,} "
            f"({skipped_no_id} skipped — no line id).",
            flush=True,
        )

        # ── Dry-run ───────────────────────────────────────────────────────────
        if dry_run:
            _dry_run_report(rows, floor)
            conn.close()
            print("Dry-run complete. Run with --apply to write to DB.")
            return

        # ── Apply ─────────────────────────────────────────────────────────────
        print(f"Writing {len(rows):,} rows to inv_sales_invoice_lines …", flush=True)
        rc = _upsert_rows(conn, rows)
        print(
            f"Done — DB rowcount signal: {rc} "
            f"(1=new, 2=updated, 0=unchanged per row; total rows: {len(rows)})"
        )
        conn.close()
        print("\nApply complete.")

    except Exception:
        conn.close()
        raise


if __name__ == "__main__":
    main()
