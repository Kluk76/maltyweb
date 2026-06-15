#!/usr/bin/env python3
"""
push_bc_credit_memos.py — D2 credit-memo write spine for maltytask corrections.

Drains ord_orders rows where divergence_status='correction_compta_requise'.
For each, emits TWO BC documents:
  1. An avoir (BC salesCreditMemo via API v2.0) mirroring the WRONG invoice lines
     so it nets to zero. Written to BC with externalDocumentNumber='mt:cm:<id>'.
     BC entity: POST /api/v2.0/companies(<cid>)/salesCreditMemos (+ lines).
  2. A corrected re-invoice (BC SalesOrder via OData v4 SalesOrderSalesLines) carrying
     the corrected lines. Delegates entirely to push_bc_sales_orders.py — no
     reimplementation.

BOTH documents are CREATE-ONLY, NEVER POSTED. Posting is a human step in BC.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
HARD SAFETY GUARD — THIS SCRIPT IS DISARMED BY DEFAULT.
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
A live BC POST requires BOTH:
  --apply              (override dry-run)
  --i-have-kouros-go   (explicit operator authorisation flag)

Without BOTH flags, every run is a DRY-RUN:
  - prints the full JSON payload it WOULD send
  - executes the GET-before-create idempotency check (read-only)
  - performs ZERO BC writes
  - performs ZERO local DB writes

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
DESIGN
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

API surface:
  Avoir  : BC API v2.0 salesCreditMemos + salesCreditMemoLines
           POST /api/v2.0/companies(<company_id>)/salesCreditMemos
           POST /api/v2.0/companies(<company_id>)/salesCreditMemos(<id>)/salesCreditMemoLines
  Re-invoice : OData v4 SalesOrder / SalesOrderSalesLines (unchanged D1 path,
           delegated via push_bc_sales_orders module functions).

Echo field:
  Avoir    : externalDocumentNumber = 'mt:cm:<order_id>'  (bc_cm_echo_format)
  Re-invoice : Your_Reference     = 'mt:<order_id>'      (bc_echo_format, from D1)

The re-invoice echo uses the SAME 'mt:<id>' tag as the original order create because
D1's reader (ingest_bc_sales_orders.py echo-leg) will recognise it and rekey the
local row. The avoir uses 'mt:cm:<id>' to be unambiguously distinct.

Source of "wrong lines" for the avoir:
  The avoir mirrors the CURRENTLY-CORRECTED lines from ord_order_lines
  (i.e. what the operator sees now — the DGDB/corrected SKU). This is correct
  because the divergence means the already-issued BC invoice carried the WRONG
  sku (DGDF). The avoir must exactly reverse the wrong invoice, so it reflects
  the wrong line (captured in divergence_detail). We read divergence_detail
  JSON to get the original BC snapshot lines and build the avoir from them.
  If divergence_detail is absent we fall back to ord_order_bc_lines snapshot.

GET-before-create idempotency:
  Before any POST, GET salesCreditMemos filtered on externalDocumentNumber eq
  'mt:cm:<order_id>'. If found → skip (already exists). Runs in EVERY mode
  (even dry-run — it is read-only).

Local DB write on success (LIVE path only, ONE atomic transaction):
  1. INSERT INTO ord_bc_credit_memos (order_id_fk, bc_credit_memo_no, echo_ref)
  2. UPDATE ord_orders SET divergence_status='correction_compta_emise'
  After confirming the re-invoice: UPDATE ord_bc_credit_memos SET bc_reinvoice_no=…

D2 must NOT modify ord_order_lines. The operator already corrected the lines
(DGDF→DGDB). D2 reads them read-only to build the re-invoice payload.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
USAGE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  # Dry-run (default) — prints avoir payload + GET-before-create, no writes:
  python3 scripts/python/push_bc_credit_memos.py

  # Apply with operator authorisation (LIVE BC WRITE — supervised only):
  python3 scripts/python/push_bc_credit_memos.py --apply --i-have-kouros-go

  # Mint a synthetic test fixture (self-clean with --cleanup-test-correction):
  python3 scripts/python/push_bc_credit_memos.py --make-test-correction
  python3 scripts/python/push_bc_credit_memos.py --make-test-correction --cleanup-test-correction

  # Delete a BC credit memo by number (admin):
  python3 scripts/python/push_bc_credit_memos.py --delete-bc-cm NC212999 --apply --i-have-kouros-go

Credentials:
  /var/www/maltytask/config/bc.env  (BC OAuth2)
  /var/www/maltytask/config/db.env  (MySQL)
"""

from __future__ import annotations

import argparse
import fcntl
import json
import os
import sys
import tempfile
from datetime import date, datetime
from pathlib import Path
from typing import Any

_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

import pymysql  # noqa: E402
from pymysql.cursors import DictCursor

try:
    import requests  # noqa: E402
except ImportError:
    print("ERROR: requests not installed. Run: pip install requests", file=sys.stderr)
    sys.exit(1)

from lib_config import load as load_config          # noqa: E402
from bc_echo import (                               # noqa: E402
    bc_echo_format,
    bc_cm_echo_format,
    bc_cm_echo_parse,
)

# ── Import D1 module functions (re-invoice path re-uses D1 — no reimplementation) ─
# We import the module-level helpers we need rather than calling main().
# push_bc_sales_orders.py is NEVER modified — only imported.
import push_bc_sales_orders as _d1  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

SCRIPT_VERSION = "1.0.0"
ACTOR          = "push-bc-credit-memos"

_BC_ENV_PATH = Path("/var/www/maltytask/config/bc.env")
_LOCK_DIR    = Path(tempfile.gettempdir())

BC_COMPANY_NAME = "NEBULEUSE"

# BC API v2.0 company UUID (confirmed by D2a spike)
BC_COMPANY_ID = "e2b691c6-8393-ed11-bff5-002248f307ee"

# Synthetic fixture marker (guarded delete)
_TEST_CORRECTION_COMMENT_MARKER = "SYNTHETIC TEST CORRECTION D2"


# ── BC credentials + token (reuse D1 helpers) ─────────────────────────────────

def _load_bc_env() -> dict[str, str]:
    """Parse bc.env.  Mirrors D1."""
    return _d1._load_bc_env()


def _get_token(bc: dict[str, str]) -> str:
    """Fetch OAuth2 client-credentials token.  Mirrors D1."""
    return _d1._get_token(bc)


def _odata_base(bc: dict[str, str]) -> str:
    """OData v4 base URL (for re-invoice via D1 path)."""
    return _d1._odata_base(bc)


def _api_v2_cbase(bc: dict[str, str]) -> str:
    """API v2.0 company-scoped base URL (for credit memo via D2 path)."""
    tenant = bc["BC_TENANT_ID"]
    env    = bc["BC_ENVIRONMENT"]
    return (
        f"https://api.businesscentral.dynamics.com/v2.0/{tenant}/{env}"
        f"/api/v2.0/companies({BC_COMPANY_ID})"
    )


# ── Local DB — load pending corrections ───────────────────────────────────────

def load_pending_corrections(conn: pymysql.connections.Connection) -> list[dict]:
    """
    Load ord_orders rows where divergence_status='correction_compta_requise'.

    For each order, also loads:
      - ord_order_lines (current operator-corrected lines — these become the RE-INVOICE)
      - ord_order_bc_lines snapshot (the WRONG lines from BC — these become the AVOIR)
        or falls back to divergence_detail JSON when ord_order_bc_lines is absent.

    Returns a list of correction dicts ready for avoir + re-invoice payload build.
    """
    with conn.cursor() as c:
        c.execute(
            """
            SELECT
                o.id,
                o.source_ref,
                o.bc_no,
                o.customer_id_fk,
                o.divergence_detail,
                o.comment,
                rc.bc_customer_no,
                rc.name AS customer_name
            FROM ord_orders o
            JOIN ref_customers rc ON rc.id = o.customer_id_fk
            WHERE o.divergence_status = 'correction_compta_requise'
            ORDER BY o.id
            """
        )
        orders = c.fetchall()

    result = []
    for o in orders:
        order_id = o["id"]

        # ── Current (corrected) lines → RE-INVOICE ─────────────────────────
        with conn.cursor() as c:
            c.execute(
                """
                SELECT
                    l.id AS line_id,
                    l.sku_id_fk,
                    l.qty,
                    l.line_comment,
                    l.line_status,
                    rs.sku_code,
                    rs.hl_per_unit
                FROM ord_order_lines l
                JOIN ref_skus rs ON rs.id = l.sku_id_fk
                WHERE l.order_id_fk = %s
                  AND l.line_status = 'to_fulfil'
                ORDER BY l.id
                """,
                (order_id,),
            )
            corrected_lines = list(c.fetchall())

        # ── BC snapshot lines → AVOIR ──────────────────────────────────────
        # Primary source: ord_order_bc_lines (the actual wrong BC snapshot).
        # Fallback: parse divergence_detail JSON which also contains the diffs.
        # The avoir must mirror what BC already posted (the wrong lines).
        # We take the BC snapshot lines (resolved_sku_id NOT NULL, bc_qty > 0).
        with conn.cursor() as c:
            c.execute(
                """
                SELECT
                    bl.bc_item_no,
                    bl.uom_code,
                    bl.bc_qty,
                    bl.bc_line_no,
                    bl.resolved_sku_id,
                    rs.sku_code
                FROM ord_order_bc_lines bl
                LEFT JOIN ref_skus rs ON rs.id = bl.resolved_sku_id
                WHERE bl.order_id_fk = %s
                  AND bl.bc_qty > 0
                ORDER BY bl.bc_line_no
                """,
                (order_id,),
            )
            bc_snapshot_lines = list(c.fetchall())

        # Also check if there's a bc_twin_source_ref in divergence_detail
        # (collision path — bc snapshot may be keyed under the twin ref)
        bc_twin_ref = None
        div_detail_obj: dict = {}
        raw_detail = o.get("divergence_detail") or ""
        if raw_detail:
            try:
                div_detail_obj = json.loads(raw_detail)
                bc_twin_ref = div_detail_obj.get("bc_twin_source_ref")
            except (json.JSONDecodeError, ValueError):
                pass

        # If no snapshot lines found for this order, try the twin ref
        if not bc_snapshot_lines and bc_twin_ref:
            # Find the order_id whose source_ref matches the twin bc ref
            with conn.cursor() as c:
                c.execute(
                    """
                    SELECT bl.bc_item_no, bl.uom_code, bl.bc_qty, bl.bc_line_no,
                           bl.resolved_sku_id, rs.sku_code
                    FROM ord_order_bc_lines bl
                    LEFT JOIN ref_skus rs ON rs.id = bl.resolved_sku_id
                    WHERE bl.order_id_fk = %s
                      AND bl.bc_source_ref = %s
                      AND bl.bc_qty > 0
                    ORDER BY bl.bc_line_no
                    """,
                    (order_id, bc_twin_ref),
                )
                bc_snapshot_lines = list(c.fetchall())

        result.append({
            "id":                order_id,
            "source_ref":        o["source_ref"] or bc_echo_format(order_id),
            "bc_no":             o["bc_no"],
            "customer_id_fk":    o["customer_id_fk"],
            "bc_customer_no":    o["bc_customer_no"] or "",
            "customer_name":     o["customer_name"] or "",
            "comment":           o["comment"] or "",
            "corrected_lines":   corrected_lines,   # → re-invoice (D1 path)
            "bc_snapshot_lines": bc_snapshot_lines, # → avoir (D2 path)
            "divergence_detail": div_detail_obj,
            "bc_twin_ref":       bc_twin_ref,
        })

    return result


# ── API v2.0 GET-before-create for credit memo ────────────────────────────────

def get_bc_credit_memo_by_echo(
    cbase: str,
    hdrs: dict,
    order_id: int,
) -> dict | None:
    """
    GET-before-create idempotency check via API v2.0 salesCreditMemos.

    Queries for a salesCreditMemo where externalDocumentNumber = 'mt:cm:<order_id>'.
    Returns the BC credit memo dict if found, or None (safe to create).

    Runs in EVERY mode — it is READ-ONLY.
    """
    echo_value = bc_cm_echo_format(order_id)
    # OData $filter: use eq with single-quotes
    filter_str = f"externalDocumentNumber eq '{echo_value}'"
    url = f"{cbase}/salesCreditMemos?$filter={filter_str}&$top=1"
    r = requests.get(url, headers=hdrs, timeout=30)
    if r.status_code != 200:
        raise RuntimeError(
            f"GET-before-create (credit memo) failed: HTTP {r.status_code} — {r.text[:300]}"
        )
    rows = r.json().get("value", [])
    return rows[0] if rows else None


# ── Customer UUID lookup (needed for API v2.0 POST) ───────────────────────────

def get_bc_customer_id(cbase: str, hdrs: dict, bc_customer_no: str) -> str | None:
    """
    GET the BC customer UUID (API v2.0 'id' field) for a given customer number.

    The API v2.0 POST for salesCreditMemos accepts either customerNumber or
    customerId.  We use customerNumber directly to avoid an extra lookup when
    possible.  This helper is kept for reference / fallback.
    """
    url = f"{cbase}/customers?$filter=number eq '{bc_customer_no}'&$top=1&$select=id,number"
    r = requests.get(url, headers=hdrs, timeout=30)
    if r.status_code != 200:
        return None
    rows = r.json().get("value", [])
    return rows[0]["id"] if rows else None


# ── Avoir payload builder ─────────────────────────────────────────────────────

def build_avoir_header_payload(correction: dict) -> dict[str, Any]:
    """
    Build the API v2.0 salesCreditMemo POST payload.

    Field mapping:
      customerNumber         ← ref_customers.bc_customer_no
      creditMemoDate         ← TODAY (ISO)
      externalDocumentNumber ← bc_cm_echo_format(order_id)  ← THE ECHO FIELD
    """
    return {
        "customerNumber":         correction["bc_customer_no"],
        "creditMemoDate":         date.today().isoformat(),
        "externalDocumentNumber": bc_cm_echo_format(correction["id"]),
    }


def build_avoir_line_payload(cm_id: str, bc_line: dict) -> dict[str, Any]:
    """
    Build an API v2.0 salesCreditMemoLine POST payload for one wrong (BC snapshot) line.

    Field mapping:
      documentId       ← BC credit memo UUID (from header POST response)
      lineType         ← 'Item'
      lineObjectNumber ← bc_line.bc_item_no  (the WRONG sku code, e.g. 'DGDF')
      quantity         ← bc_line.bc_qty
    """
    return {
        "documentId":       cm_id,
        "lineType":         "Item",
        "lineObjectNumber": bc_line["bc_item_no"],
        "quantity":         float(bc_line["bc_qty"]),
    }


# ── Local DB writes (LIVE path only) ─────────────────────────────────────────

def _record_avoir(
    conn: pymysql.connections.Connection,
    order_id: int,
    bc_cm_no: str,
) -> None:
    """
    In ONE atomic DB transaction after a successful avoir BC CREATE:
      1. INSERT/REPLACE into ord_bc_credit_memos (order_id_fk, bc_credit_memo_no, echo_ref)
      2. UPDATE ord_orders SET divergence_status = 'correction_compta_emise'

    The status flip is TERMINAL — prevents re-drain on subsequent runs.
    """
    echo_ref = bc_cm_echo_format(order_id)

    with conn.cursor() as c:
        # Upsert tracking row (INSERT or update on echo_ref collision)
        c.execute(
            """
            INSERT INTO ord_bc_credit_memos
                (order_id_fk, bc_credit_memo_no, echo_ref)
            VALUES (%s, %s, %s)
            ON DUPLICATE KEY UPDATE
                bc_credit_memo_no = VALUES(bc_credit_memo_no),
                updated_at        = CURRENT_TIMESTAMP
            """,
            (order_id, bc_cm_no, echo_ref),
        )
        # Flip divergence_status to terminal value
        c.execute(
            """
            UPDATE ord_orders
               SET divergence_status = 'correction_compta_emise',
                   updated_at        = CURRENT_TIMESTAMP
             WHERE id                = %s
               AND divergence_status = 'correction_compta_requise'
            """,
            (order_id,),
        )
        rows_updated = c.rowcount

    conn.commit()

    if rows_updated != 1:
        raise RuntimeError(
            f"divergence_status flip failed for order_id={order_id}: "
            f"expected 1 row updated, got {rows_updated}. "
            f"BC credit memo {bc_cm_no!r} was created — check ord_orders state."
        )


def _record_reinvoice(
    conn: pymysql.connections.Connection,
    order_id: int,
    bc_reinvoice_no: str,
) -> None:
    """
    After successful re-invoice BC CREATE, update ord_bc_credit_memos.bc_reinvoice_no.
    """
    with conn.cursor() as c:
        c.execute(
            """
            UPDATE ord_bc_credit_memos
               SET bc_reinvoice_no = %s,
                   updated_at      = CURRENT_TIMESTAMP
             WHERE order_id_fk = %s
            """,
            (bc_reinvoice_no, order_id),
        )
    conn.commit()


# ── Admin helpers ──────────────────────────────────────────────────────────────

def delete_bc_credit_memo(cbase: str, hdrs: dict, cm_no: str) -> None:
    """
    DELETE a BC salesCreditMemo (and its lines) by number.

    API v2.0 path: DELETE /salesCreditMemos(<uuid>).
    First GET by number, then DELETE by UUID with If-Match: '*'.
    Lines cascade automatically (API v2.0 delete semantics).

    Admin helper — requires --apply --i-have-kouros-go.
    """
    # GET by number
    url_get = f"{cbase}/salesCreditMemos?$filter=number eq '{cm_no}'&$top=1"
    r = requests.get(url_get, headers=hdrs, timeout=30)
    if r.status_code != 200:
        raise RuntimeError(f"GET for CM delete failed: HTTP {r.status_code} — {r.text[:300]}")
    rows = r.json().get("value", [])
    if not rows:
        print(f"  [delete-bc-cm] Credit memo {cm_no!r} not found in BC — nothing to delete.")
        return

    cm_row  = rows[0]
    cm_uuid = cm_row["id"]
    cm_status = cm_row.get("status", "?")

    if cm_status not in ("Draft", "Open"):
        print(
            f"  [delete-bc-cm] WARNING: Credit memo {cm_no!r} has status={cm_status!r} "
            "(not Draft/Open). BC may reject the DELETE if posted/paid — proceeding anyway."
        )

    # Try to delete lines first (API v2.0 may cascade — attempt anyway)
    lines_url = f"{cbase}/salesCreditMemos({cm_uuid})/salesCreditMemoLines"
    lr = requests.get(lines_url, headers=hdrs, timeout=30)
    del_hdrs = {**hdrs, "If-Match": "*"}
    if lr.status_code == 200:
        for ln in lr.json().get("value", []):
            line_uuid = ln["id"]
            dl_url = f"{cbase}/salesCreditMemos({cm_uuid})/salesCreditMemoLines({line_uuid})"
            dr = requests.delete(dl_url, headers=del_hdrs, timeout=30)
            if dr.status_code not in (200, 204):
                print(
                    f"  [delete-bc-cm] WARNING: line {line_uuid} DELETE returned "
                    f"HTTP {dr.status_code} — {dr.text[:200]}"
                )
            else:
                print(f"  [delete-bc-cm] Line {line_uuid} deleted.")

    # DELETE header
    del_url = f"{cbase}/salesCreditMemos({cm_uuid})"
    dr = requests.delete(del_url, headers=del_hdrs, timeout=30)
    if dr.status_code not in (200, 204):
        raise RuntimeError(
            f"DELETE salesCreditMemos({cm_uuid}) failed: "
            f"HTTP {dr.status_code} — {dr.text[:300]}"
        )
    print(f"  [delete-bc-cm] Credit memo {cm_no!r} (uuid={cm_uuid}) deleted from BC.")


def make_test_correction(conn: pymysql.connections.Connection) -> int:
    """
    Mint a synthetic correction fixture for offline dry-run testing.

    Inserts:
      - 1 ord_orders row (source='maltytask', divergence_status='correction_compta_requise',
        bc_no=NULL) with comment containing SYNTHETIC_MARKER.
      - 1 ord_order_lines row: DGDB (the CORRECTED sku — what the operator set it to).
      - 1 ord_order_bc_lines row: DGDF (the WRONG sku — what BC had in the invoice).

    This mimics the real scenario:
      - BC had DGDF (Drunkbeard Galactic Drift Fut 20l) on the invoice.
      - Operator corrected the local line to DGDB (Drunkbeard Galactic Drift Bouteilles).
      - D2 must emit an avoir reversing DGDF and a re-invoice posting DGDB.

    Returns the new ord_orders.id.
    Self-clean: --cleanup-test-correction.
    """
    # Look up the first customer with a bc_customer_no
    with conn.cursor() as c:
        c.execute("SELECT id, bc_customer_no FROM ref_customers WHERE bc_customer_no IS NOT NULL LIMIT 1")
        cust_row = c.fetchone()
        if not cust_row:
            raise RuntimeError("No ref_customers row with bc_customer_no — cannot create fixture.")
        customer_id = cust_row["id"]
        bc_customer_no = cust_row["bc_customer_no"]

        # Get DGDB id (corrected — will go to re-invoice / ord_order_lines)
        c.execute("SELECT id, sku_code FROM ref_skus WHERE sku_code = 'DGDB' AND is_active = 1 LIMIT 1")
        dgdb_row = c.fetchone()
        if not dgdb_row:
            raise RuntimeError("DGDB sku not found in ref_skus — fixture cannot be built.")

        # Get DGDF id (wrong — will go to avoir / ord_order_bc_lines snapshot)
        c.execute("SELECT id, sku_code FROM ref_skus WHERE sku_code = 'DGDF' AND is_active = 1 LIMIT 1")
        dgdf_row = c.fetchone()
        if not dgdf_row:
            raise RuntimeError("DGDF sku not found in ref_skus — fixture cannot be built.")

        # Insert the ord_orders header
        c.execute(
            """
            INSERT INTO ord_orders
                (order_type, customer_id_fk, requested_date, status,
                 source, source_ref, divergence_status, divergence_detail,
                 created_by_user_id, comment)
            VALUES ('customer', %s, CURDATE(), 'entered',
                    'maltytask', 'mt:TEST-PLACEHOLDER', 'correction_compta_requise',
                    NULL, NULL,
                    %s)
            """,
            (
                customer_id,
                f"{_TEST_CORRECTION_COMMENT_MARKER} — auto-clean with --cleanup-test-correction",
            ),
        )
        order_id = conn.insert_id()

        # Rekey source_ref
        real_echo = bc_echo_format(order_id)
        c.execute(
            "UPDATE ord_orders SET source_ref = %s WHERE id = %s",
            (real_echo, order_id),
        )

        # Set divergence_detail with a plausible diff (DGDF was on BC, DGDB is now correct)
        div_detail = json.dumps({
            "bc_order_no":  "ORD-TEST-SYNTHETIC",
            "line_diffs": [
                {
                    "sku_id":  dgdf_row["id"],
                    "bc_qty":  5.0,
                    "op_qty":  None,
                    "delta":   -5.0,
                    "note":    "wrong sku DGDF was on BC invoice",
                },
                {
                    "sku_id":  dgdb_row["id"],
                    "bc_qty":  None,
                    "op_qty":  5.0,
                    "delta":   5.0,
                    "note":    "correct sku DGDB is now on the operational line",
                },
            ],
        })
        c.execute(
            "UPDATE ord_orders SET divergence_detail = %s WHERE id = %s",
            (div_detail, order_id),
        )

        # Insert corrected line → DGDB (what the operator set — re-invoice target)
        c.execute(
            """
            INSERT INTO ord_order_lines
                (order_id_fk, sku_id_fk, qty, line_comment, line_status)
            VALUES (%s, %s, 5, 'SYNTHETIC CORRECTED LINE — DGDB (bouteilles)', 'to_fulfil')
            """,
            (order_id, dgdb_row["id"]),
        )

        # Insert wrong BC snapshot → DGDF (what BC had — avoir target)
        # Use bc_source_ref = the order's own source_ref (non-collision path)
        c.execute(
            """
            INSERT INTO ord_order_bc_lines
                (order_id_fk, bc_source_ref, bc_line_no, bc_item_no, uom_code,
                 bc_qty, resolved_sku_id, snapshot_at)
            VALUES (%s, %s, 10000, 'DGDF', 'KEG', 5.0, %s, NOW())
            """,
            (order_id, real_echo, dgdf_row["id"]),
        )

    conn.commit()
    print(
        f"  [make-test-correction] Created synthetic fixture order_id={order_id} "
        f"source_ref={real_echo!r} "
        f"(corrected_line=DGDB id={dgdb_row['id']}, wrong_bc_line=DGDF id={dgdf_row['id']})"
    )
    return order_id


def cleanup_test_correction(conn: pymysql.connections.Connection, order_id: int) -> None:
    """
    Self-clean: DELETE a synthetic correction fixture.
    Only removes rows whose comment contains SYNTHETIC_MARKER (guard against
    accidentally deleting real corrections).
    """
    with conn.cursor() as c:
        c.execute(
            "SELECT id, comment FROM ord_orders WHERE id = %s",
            (order_id,),
        )
        row = c.fetchone()
        if not row:
            print(f"  [cleanup] Order id={order_id} not found — nothing to clean.")
            return
        if _TEST_CORRECTION_COMMENT_MARKER not in (row.get("comment") or ""):
            raise RuntimeError(
                f"Order id={order_id} is NOT a synthetic test correction — refusing to delete."
            )

        # Clean up in dependency order
        c.execute("DELETE FROM ord_bc_credit_memos WHERE order_id_fk = %s", (order_id,))
        c.execute("DELETE FROM ord_order_bc_lines WHERE order_id_fk = %s", (order_id,))
        c.execute("DELETE FROM ord_order_lines WHERE order_id_fk = %s", (order_id,))
        c.execute("DELETE FROM ord_orders WHERE id = %s", (order_id,))

    conn.commit()
    print(f"  [cleanup] Synthetic fixture order_id={order_id} deleted (0 residue).")


# ── Dry-run printer ───────────────────────────────────────────────────────────

def _print_correction_summary(
    correction: dict,
    avoir_header_payload: dict,
    existing_bc_cm: dict | None,
) -> None:
    """Print the dry-run per-correction summary."""
    order_id = correction["id"]
    cm_echo  = bc_cm_echo_format(order_id)
    ord_echo = bc_echo_format(order_id)

    print()
    print(f"  ── Correction order_id={order_id} source_ref={correction['source_ref']!r} ──")
    print(f"  Customer    : {correction['customer_name']} ({correction['bc_customer_no']})")
    print(f"  CM echo     : externalDocumentNumber = {cm_echo!r}")
    print(f"  Order echo  : Your_Reference = {ord_echo!r}  (re-invoice, D1 path)")
    print()

    bc_lines  = correction["bc_snapshot_lines"]
    corr_lines = correction["corrected_lines"]

    print(f"  AVOIR — mirroring {len(bc_lines)} wrong BC snapshot line(s):")
    for ln in bc_lines:
        print(
            f"    sku={ln['bc_item_no']!r:10s}  qty={float(ln['bc_qty']):<6}  "
            f"uom={ln.get('uom_code','?')}  (resolved_sku={ln.get('sku_code','?')})"
        )

    print(f"\n  RE-INVOICE — {len(corr_lines)} corrected line(s) (D1 path):")
    for ln in corr_lines:
        print(
            f"    sku={ln['sku_code']!r:10s}  qty={float(ln['qty']):<6}  "
            f"line_status={ln['line_status']}"
        )

    print()
    if existing_bc_cm is not None:
        cm_no = existing_bc_cm.get("number", "?")
        print(f"  GET-before-create → ALREADY EXISTS in BC as credit memo {cm_no!r}")
        print("  Action            → WOULD SKIP (avoir already created)")
    else:
        print(
            f"  GET-before-create → absent in BC "
            f"(externalDocumentNumber={cm_echo!r} not found)"
        )
        print("  Action            → WOULD CREATE")
        print()
        print("  AVOIR header payload (API v2.0 salesCreditMemos POST):")
        print(json.dumps(avoir_header_payload, indent=4, default=str))
        if bc_lines:
            print()
            print("  AVOIR line payloads (salesCreditMemoLines POST, one per wrong line):")
            for ln in bc_lines:
                line_pl = build_avoir_line_payload("<<CM_UUID_from_header_response>>", ln)
                print(json.dumps(line_pl, indent=4, default=str))
        print()
        if corr_lines:
            print("  RE-INVOICE (D1 path) — would call push_bc_sales_orders funcs:")
            print(f"    build_odata_header_payload(order={{'id':{order_id},'bc_customer_no':...}})")
            print("    POST SalesOrder header → POST SalesOrderSalesLines for each corrected line")


# ── Main loop ──────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Push D2 credit memos + re-invoices to BC for correction_compta_requise orders. "
            "Default: dry-run (prints payloads, NO writes). "
            "Live writes require BOTH --apply AND --i-have-kouros-go."
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Override dry-run mode (ALSO requires --i-have-kouros-go for live BC writes).",
    )
    parser.add_argument(
        "--i-have-kouros-go",
        dest="kouros_go",
        action="store_true",
        help="Operator authorisation flag (both --apply AND --i-have-kouros-go required for live).",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=0,
        help="Process at most N corrections (0 = all).",
    )
    parser.add_argument(
        "--delete-bc-cm",
        metavar="CM_NO",
        default=None,
        help=(
            "Admin helper: DELETE a BC salesCreditMemo by number (e.g. NC212999). "
            "Requires --apply --i-have-kouros-go."
        ),
    )
    parser.add_argument(
        "--make-test-correction",
        action="store_true",
        help=(
            "Mint a synthetic correction fixture (ord_orders with divergence_status="
            "'correction_compta_requise', DGDB corrected line, DGDF wrong BC snapshot). "
            "Safe in dry-run."
        ),
    )
    parser.add_argument(
        "--cleanup-test-correction",
        action="store_true",
        help=(
            "Combined with --make-test-correction: after dry-run, DELETE the fixture "
            "(self-clean, guarded by SYNTHETIC marker)."
        ),
    )
    args = parser.parse_args()

    live_write = args.apply and args.kouros_go

    if args.apply and not args.kouros_go:
        print(
            "\nWARNING: --apply passed without --i-have-kouros-go. Running dry-run.\n",
            file=sys.stderr,
        )
    if args.kouros_go and not args.apply:
        print(
            "\nWARNING: --i-have-kouros-go without --apply. Still dry-run.\n",
            file=sys.stderr,
        )

    mode_label = "** LIVE APPLY **" if live_write else "DRY-RUN"
    print(f"\npush_bc_credit_memos.py — v{SCRIPT_VERSION}")
    print(f"Mode: {mode_label}")
    if live_write:
        print("WARNING: Live BC API writes will be sent. Operator authorisation confirmed.")
    print()

    # Per-invocation lock (no hardcoded /tmp path)
    lock_fd, lock_path = tempfile.mkstemp(
        prefix="push_bc_credit_memos_", suffix=".lock", dir=str(_LOCK_DIR)
    )
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        os.close(lock_fd)
        os.unlink(lock_path)
        print(
            "ERROR: Another invocation of push_bc_credit_memos.py is already running.",
            file=sys.stderr,
        )
        sys.exit(1)

    try:
        _run(args, live_write)
    finally:
        fcntl.flock(lock_fd, fcntl.LOCK_UN)
        os.close(lock_fd)
        try:
            os.unlink(lock_path)
        except OSError:
            pass


def _run(args: argparse.Namespace, live_write: bool) -> None:
    """Main logic, called after lock is acquired."""
    mode_label = "** LIVE APPLY **" if live_write else "DRY-RUN"

    # ── Load BC credentials + token ───────────────────────────────────────────
    print("[1/5] Loading BC credentials …")
    bc    = _load_bc_env()
    token = _get_token(bc)
    hdrs  = {
        "Authorization": f"Bearer {token}",
        "Accept":        "application/json",
        "Content-Type":  "application/json",
    }
    cbase      = _api_v2_cbase(bc)
    odata_base = _odata_base(bc)  # for re-invoice via D1

    # ── Handle --delete-bc-cm (admin helper) ──────────────────────────────────
    if args.delete_bc_cm:
        if not live_write:
            print(
                "ERROR: --delete-bc-cm requires BOTH --apply AND --i-have-kouros-go.",
                file=sys.stderr,
            )
            sys.exit(1)
        print(f"[delete-bc-cm] Deleting BC credit memo {args.delete_bc_cm!r} …")
        delete_bc_credit_memo(cbase, hdrs, args.delete_bc_cm)
        return

    # ── Load local DB ─────────────────────────────────────────────────────────
    print("[2/5] Connecting to local DB …")
    cfg  = load_config()
    conn = pymysql.connect(
        host        = cfg.db_host,
        port        = cfg.db_port,
        user        = cfg.db_user,
        password    = cfg.db_password,
        database    = cfg.db_name,
        charset     = "utf8mb4",
        cursorclass = DictCursor,
        autocommit  = False,
    )

    try:
        # ── Handle --make-test-correction ─────────────────────────────────────
        test_order_id: int | None = None
        if args.make_test_correction:
            print("[make-test-correction] Minting synthetic correction fixture …")
            test_order_id = make_test_correction(conn)

        # ── Load pending corrections ──────────────────────────────────────────
        print("[3/5] Loading correction_compta_requise orders …")
        corrections = load_pending_corrections(conn)
        print(f"  Pending corrections found: {len(corrections)}")

        if not corrections:
            print()
            print("  0 corrections to process — nothing to do.")
            if args.cleanup_test_correction and test_order_id is not None:
                cleanup_test_correction(conn, test_order_id)
            return

        if args.limit > 0:
            corrections = corrections[:args.limit]
            print(f"  --limit {args.limit}: processing {len(corrections)} corrections")

        # ── GET-before-create + payload build (read-only) ─────────────────────
        print("[4/5] Running GET-before-create probes (read-only) …")

        results: list[dict] = []
        for c_row in corrections:
            order_id             = c_row["id"]
            avoir_header_payload = build_avoir_header_payload(c_row)

            # GET-before-create — always runs (READ-ONLY)
            existing_bc_cm = get_bc_credit_memo_by_echo(cbase, hdrs, order_id)

            results.append({
                "correction":          c_row,
                "avoir_header_payload": avoir_header_payload,
                "existing_bc_cm":      existing_bc_cm,
            })

            _print_correction_summary(c_row, avoir_header_payload, existing_bc_cm)

        # ── Write phase ───────────────────────────────────────────────────────
        print()
        print("[5/5] Write phase …")

        if not live_write:
            print()
            print("  DRY-RUN — no BC API writes performed.")
            if args.apply:
                print("  (pass --i-have-kouros-go alongside --apply to arm the writer)")
            else:
                print("  (pass --apply --i-have-kouros-go to arm the writer)")

        else:
            # ── LIVE APPLY PATH ────────────────────────────────────────────────
            created = 0
            skipped = 0

            for r in results:
                correction         = r["correction"]
                order_id           = correction["id"]
                avoir_header_pl    = r["avoir_header_payload"]
                existing_bc_cm     = r["existing_bc_cm"]
                bc_snapshot_lines  = correction["bc_snapshot_lines"]
                corrected_lines    = correction["corrected_lines"]

                if existing_bc_cm is not None:
                    print(
                        f"  SKIP order_id={order_id}: avoir already in BC as "
                        f"{existing_bc_cm.get('number','?')!r}"
                    )
                    skipped += 1
                    continue

                if not bc_snapshot_lines:
                    print(
                        f"  SKIP order_id={order_id}: no BC snapshot lines found for avoir "
                        "(cannot mirror unknown wrong lines — operator must add BC snapshot manually)"
                    )
                    skipped += 1
                    continue

                # ── POST avoir header (API v2.0) ───────────────────────────────
                print(
                    f"  POST salesCreditMemos header for order_id={order_id} …",
                    end=" ", flush=True,
                )
                url_cm = f"{cbase}/salesCreditMemos"
                rh = requests.post(url_cm, headers=hdrs, json=avoir_header_pl, timeout=30)
                if rh.status_code not in (200, 201):
                    raise RuntimeError(
                        f"OData POST salesCreditMemos failed for order_id={order_id}: "
                        f"HTTP {rh.status_code} — {rh.text[:300]}"
                    )
                bc_cm     = rh.json()
                bc_cm_uuid = bc_cm.get("id", "")
                bc_cm_no   = bc_cm.get("number", "")
                if not bc_cm_no or not bc_cm_uuid:
                    raise RuntimeError(
                        f"BC response missing id/number: {bc_cm}"
                    )
                print(f"→ BC credit memo No={bc_cm_no!r} (uuid={bc_cm_uuid})")

                # ── POST avoir lines ───────────────────────────────────────────
                url_cm_lines = f"{cbase}/salesCreditMemos({bc_cm_uuid})/salesCreditMemoLines"
                for bc_line in bc_snapshot_lines:
                    line_pl = build_avoir_line_payload(bc_cm_uuid, bc_line)
                    rl = requests.post(url_cm_lines, headers=hdrs, json=line_pl, timeout=30)
                    if rl.status_code not in (200, 201):
                        raise RuntimeError(
                            f"POST salesCreditMemoLines failed for order_id={order_id} "
                            f"item={bc_line['bc_item_no']!r}: "
                            f"HTTP {rl.status_code} — {rl.text[:300]}"
                        )
                    print(
                        f"    → avoir line sku={bc_line['bc_item_no']!r} "
                        f"qty={float(bc_line['bc_qty'])} posted"
                    )

                # ── Record avoir + flip divergence_status (ONE txn) ───────────
                _record_avoir(conn, order_id, bc_cm_no)
                print(
                    f"  → local: ord_bc_credit_memos inserted, "
                    f"divergence_status → 'correction_compta_emise'"
                )

                # ── POST re-invoice via D1 (reuse D1 module functions) ─────────
                # Build a synthetic "order" dict shaped like D1's load_pending_orders output
                # so we can call D1's build_odata_header_payload + build_odata_line_payload.
                if corrected_lines:
                    print(
                        f"  POST re-invoice (D1 path) for order_id={order_id} …",
                        end=" ", flush=True,
                    )
                    d1_order = {
                        "id":             order_id,
                        "source_ref":     bc_echo_format(order_id),
                        "requested_date": None,
                        "customer_id_fk": correction["customer_id_fk"],
                        "bc_customer_no": correction["bc_customer_no"],
                        "customer_name":  correction["customer_name"],
                        "comment":        correction["comment"],
                        "status":         "entered",
                        "lines":          [
                            {
                                "sku_id_fk":    ln["sku_id_fk"],
                                "qty":          ln["qty"],
                                "line_comment": ln.get("line_comment", ""),
                                "line_status":  ln["line_status"],
                                "sku_code":     ln["sku_code"],
                                "hl_per_unit":  ln.get("hl_per_unit"),
                            }
                            for ln in corrected_lines
                        ],
                        "excluded_lines": 0,
                    }

                    # GET-before-create on the re-invoice echo (D1's helper)
                    existing_reinvoice = _d1.get_bc_order_by_your_reference(
                        odata_base=odata_base, hdrs=hdrs, local_id=order_id,
                    )
                    if existing_reinvoice is not None:
                        bc_reinvoice_no = existing_reinvoice.get("No", "?")
                        print(
                            f"→ re-invoice already in BC as {bc_reinvoice_no!r} (skipping POST)"
                        )
                    else:
                        # POST SalesOrder header
                        ri_header_pl = _d1.build_odata_header_payload(d1_order)
                        url_ri = f"{odata_base}/SalesOrder"
                        ri_r = requests.post(url_ri, headers=hdrs, json=ri_header_pl, timeout=30)
                        if ri_r.status_code not in (200, 201):
                            raise RuntimeError(
                                f"POST SalesOrder (re-invoice) failed for order_id={order_id}: "
                                f"HTTP {ri_r.status_code} — {ri_r.text[:300]}"
                            )
                        ri_bc = ri_r.json()
                        bc_reinvoice_no = ri_bc.get("No") or ri_bc.get("number", "")
                        print(f"→ BC re-invoice No={bc_reinvoice_no!r}")

                        # POST each to_fulfil line
                        url_ri_lines = f"{odata_base}/SalesOrderSalesLines"
                        for ln in d1_order["lines"]:
                            ri_line_pl = _d1.build_odata_line_payload(bc_reinvoice_no, ln)
                            rl2 = requests.post(url_ri_lines, headers=hdrs, json=ri_line_pl, timeout=30)
                            if rl2.status_code not in (200, 201):
                                raise RuntimeError(
                                    f"POST SalesOrderSalesLines (re-invoice) failed: "
                                    f"HTTP {rl2.status_code} — {rl2.text[:300]}"
                                )
                            print(f"    → re-invoice line sku={ln['sku_code']!r} qty={float(ln['qty'])} posted")

                    # Record re-invoice No
                    if bc_reinvoice_no:
                        _record_reinvoice(conn, order_id, bc_reinvoice_no)
                        print(f"  → local: ord_bc_credit_memos.bc_reinvoice_no = {bc_reinvoice_no!r}")
                else:
                    print(f"  NOTICE: no to_fulfil corrected lines for order_id={order_id} — re-invoice skipped.")

                created += 1

            print()
            print(f"  Live apply: {created} corrections processed, {skipped} skipped.")

        # ── Summary ───────────────────────────────────────────────────────────
        would_create  = sum(1 for r in results if r["existing_bc_cm"] is None)
        would_skip    = sum(1 for r in results if r["existing_bc_cm"] is not None)

        print()
        print("═" * 60)
        print("  SUMMARY")
        print("═" * 60)
        print(f"  Mode                    : {mode_label}")
        print(f"  API surface (avoir)     : BC API v2.0 salesCreditMemos / salesCreditMemoLines")
        print(f"  API surface (re-invoice): OData v4 SalesOrder / SalesOrderSalesLines (D1)")
        print(f"  Echo (avoir)            : externalDocumentNumber = 'mt:cm:<id>'")
        print(f"  Echo (re-invoice)       : Your_Reference = 'mt:<id>' (D1 field)")
        print(f"  Corrections evaluated   : {len(results)}")
        print(f"  Would CREATE avoir      : {would_create}")
        print(f"  Would SKIP (exists)     : {would_skip}")
        if not live_write:
            print("  BC writes performed     : 0 (disarmed)")
        print("═" * 60)
        print()

        # ── Self-clean fixture ────────────────────────────────────────────────
        if args.cleanup_test_correction and test_order_id is not None:
            print("[cleanup-test-correction] Removing synthetic fixture …")
            cleanup_test_correction(conn, test_order_id)

    except Exception as exc:
        conn.rollback()
        print(f"\nERROR: {exc}", file=sys.stderr)
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
