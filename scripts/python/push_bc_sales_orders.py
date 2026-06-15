#!/usr/bin/env python3
"""
push_bc_sales_orders.py — maltytask-native orders → BC ORDER-CREATE write spine.

Phase-2 D1 of the BC integration.  Publishes orders born inside maltytask
(source='maltytask', bc_no IS NULL) to Business Central via the OData v4
web-service surface — the SAME surface the reader (ingest_bc_sales_orders.py)
already uses.

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
DESIGN DECISIONS (post-PM ruling)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Echo field  : Your_Reference  (OData field on SalesOrder entity).
              Set to bc_echo_format(local_id) = 'mt:<id>' on BC CREATE.
              External_Document_No is RESERVED (Kouros's split-order key) —
              NEVER written by this script.

API surface : OData v4 web services — same base URL as the reader:
              /ODataV4/Company('NEBULEUSE')/SalesOrder          (header)
              /ODataV4/Company('NEBULEUSE')/SalesOrderSalesLines (lines)
              Token acquisition reuses the same OAuth2 client-credentials flow.

Line filter : Only ord_order_lines WHERE line_status = 'to_fulfil' are pushed.
              Lines with line_status IN ('non_livre', 'rupture') are EXCLUDED.

GET-before-create : filters on Your_Reference eq 'mt:<id>' — runs in EVERY mode
              (even dry-run, because it is read-only).

Rekey       : On a successful BC CREATE, ONE local DB transaction does:
              1. ord_orders.bc_no = BC-assigned 'No'
              2. ord_orders.source_ref  'mt:<id>' → 'bc:<No>'
              If the rekey fails after a successful BC POST, the reader's echo-leg
              (ingest_bc_sales_orders.py, Ruling 2) recovers on the next pull:
              it matches by Your_Reference='mt:<id>' and rewrites bc_no / source_ref.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
USAGE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  # Dry-run (default) — prints payloads + GET-before-create result, no writes:
  python3 scripts/python/push_bc_sales_orders.py

  # Apply with operator authorisation (LIVE BC WRITE — supervised only):
  python3 scripts/python/push_bc_sales_orders.py --apply --i-have-kouros-go

  # Limit (smoke-test dry-run):
  python3 scripts/python/push_bc_sales_orders.py --limit 1

  # Delete a BC order by No (admin / clean-up of a supervised test order):
  python3 scripts/python/push_bc_sales_orders.py --delete-bc ORD210099 --apply --i-have-kouros-go

  # Mint a synthetic test order (dry-run safe; self-clean with --cleanup-test-order):
  python3 scripts/python/push_bc_sales_orders.py --make-test-order
  python3 scripts/python/push_bc_sales_orders.py --make-test-order --cleanup-test-order

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

# Allow running from /var/www/maltytask
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

from lib_config import load as load_config  # noqa: E402
from bc_echo import bc_echo_format, bc_echo_parse  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

SCRIPT_VERSION = "2.0.0"
ACTOR          = "push-bc-orders"

_BC_ENV_PATH = Path("/var/www/maltytask/config/bc.env")

# Lock file — prevents concurrent invocations (no hardcoded /tmp name — mkstemp).
_LOCK_DIR = Path(tempfile.gettempdir())

# BC company name (used in OData URL; must match the OData endpoint used by the reader)
BC_COMPANY_NAME = "NEBULEUSE"

# line_status values to INCLUDE in the BC push.
# non_livre / rupture are EXCLUDED (undeliverable lines must not reach BC accounting).
PUSH_LINE_STATUSES = frozenset({"to_fulfil"})

# Synthetic test order marker (used by --make-test-order / --cleanup-test-order)
_TEST_ORDER_SOURCE_REF_PREFIX = "mt:TEST-"


# ── BC config + OAuth2 (mirrors ingest_bc_sales_orders.py) ────────────────────

def _load_bc_env() -> dict[str, str]:
    """Parse bc.env.  Mirrors _load_bc_env() in the reader connector."""
    path = _BC_ENV_PATH
    override = Path(os.environ.get("MALTYTASK_BC_ENV", ""))
    if override.name:
        path = override
    if not path.exists():
        raise RuntimeError(
            f"BC credentials not found at {path}.\n"
            "Expected keys: BC_TENANT_ID, BC_CLIENT_ID, BC_CLIENT_SECRET, BC_ENVIRONMENT.\n"
            "See: config/bc.env.example"
        )
    cfg: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()
    for key in ("BC_TENANT_ID", "BC_CLIENT_ID", "BC_CLIENT_SECRET", "BC_ENVIRONMENT"):
        if key not in cfg:
            raise RuntimeError(f"Missing key in bc.env: {key}")
    return cfg


def _get_token(bc: dict[str, str]) -> str:
    """Fetch OAuth2 client-credentials token.  Token is never logged."""
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


def _odata_base(bc: dict[str, str]) -> str:
    """OData v4 base URL — same surface as the reader (ingest_bc_sales_orders.py)."""
    tenant = bc["BC_TENANT_ID"]
    env    = bc["BC_ENVIRONMENT"]
    return (
        f"https://api.businesscentral.dynamics.com/v2.0/{tenant}/{env}"
        f"/ODataV4/Company('{BC_COMPANY_NAME}')"
    )


# ── Local DB loaders ───────────────────────────────────────────────────────────

def load_pending_orders(conn: pymysql.connections.Connection) -> list[dict]:
    """
    Load all maltytask-native orders pending BC publication.
    Selection: source='maltytask' AND bc_no IS NULL.

    Also loads the associated ord_order_lines, filtered to line_status IN
    PUSH_LINE_STATUSES ('to_fulfil').  Lines with non_livre / rupture are
    excluded at the query level — they must never reach BC accounting.
    """
    with conn.cursor() as c:
        c.execute(
            """
            SELECT
                o.id,
                o.source_ref,
                o.requested_date,
                o.customer_id_fk,
                o.comment,
                o.status,
                rc.bc_customer_no,
                rc.name   AS customer_name
            FROM ord_orders o
            JOIN ref_customers rc ON rc.id = o.customer_id_fk
            WHERE o.source    = 'maltytask'
              AND o.bc_no     IS NULL
            ORDER BY o.id
            """
        )
        orders = c.fetchall()

    result = []
    for o in orders:
        order_id = o["id"]
        with conn.cursor() as c:
            c.execute(
                """
                SELECT
                    l.id    AS line_id,
                    l.sku_id_fk,
                    l.qty,
                    l.line_comment,
                    l.line_status,
                    rs.sku_code,
                    rs.hl_per_unit
                FROM ord_order_lines l
                JOIN ref_skus rs ON rs.id = l.sku_id_fk
                WHERE l.order_id_fk  = %s
                  AND l.line_status IN ('to_fulfil')
                ORDER BY l.id
                """,
                (order_id,),
            )
            lines = c.fetchall()

        # Count excluded lines (for the report)
        with conn.cursor() as c:
            c.execute(
                """
                SELECT COUNT(*) AS n
                FROM ord_order_lines
                WHERE order_id_fk = %s
                  AND line_status NOT IN ('to_fulfil')
                """,
                (order_id,),
            )
            excluded_count = (c.fetchone() or {}).get("n", 0)

        result.append({
            "id":             order_id,
            "source_ref":     o["source_ref"] or bc_echo_format(order_id),
            "requested_date": str(o["requested_date"]) if o["requested_date"] else None,
            "customer_id_fk": o["customer_id_fk"],
            "bc_customer_no": o["bc_customer_no"] or "",
            "customer_name":  o["customer_name"] or "",
            "comment":        o["comment"] or "",
            "status":         o["status"],
            "lines":          list(lines),
            "excluded_lines": int(excluded_count),
        })

    return result


# ── OData GET-before-create idempotency check ──────────────────────────────────

def get_bc_order_by_your_reference(
    odata_base: str,
    hdrs: dict,
    local_id: int,
) -> dict | None:
    """
    GET-before-create idempotency check via OData SalesOrder entity.

    Queries for a SalesOrder where Your_Reference = 'mt:<local_id>'.
    Returns the BC order dict if found, or None if absent (safe to create).

    This GET always runs — even in dry-run mode — because it is READ-ONLY.
    The result drives "would create" vs "already exists in BC, would skip".

    Note: External_Document_No is NEVER queried or written — it is reserved.
    """
    echo_value  = bc_echo_format(local_id)
    # OData $filter string — single-quotes around the value (OData string literal)
    filter_str  = f"Your_Reference eq '{echo_value}'"
    url = f"{odata_base}/SalesOrder?$filter={filter_str}&$top=1"
    r = requests.get(url, headers=hdrs, timeout=30)
    if r.status_code != 200:
        raise RuntimeError(
            f"GET-before-create probe failed: HTTP {r.status_code} — {r.text[:300]}"
        )
    rows = r.json().get("value", [])
    return rows[0] if rows else None


# ── OData payload builder ──────────────────────────────────────────────────────

def build_odata_header_payload(order: dict) -> dict[str, Any]:
    """
    Build the OData SalesOrder header POST payload.

    Field mapping (OData SalesOrder entity):
      Sell_to_Customer_No   ← ref_customers.bc_customer_no
      Order_Date            ← TODAY (ISO)
      Requested_Delivery_Date ← ord_orders.requested_date (if set)
      Your_Reference        ← bc_echo_format(local_id)  ← THE ECHO FIELD

    External_Document_No is deliberately OMITTED — it is Kouros's split-order
    key and must never be written by this script.
    """
    today    = date.today().isoformat()
    local_id = order["id"]

    payload: dict[str, Any] = {
        "Sell_to_Customer_No": order["bc_customer_no"],
        "Order_Date":          today,
        "Your_Reference":      bc_echo_format(local_id),
    }

    if order["requested_date"]:
        payload["Requested_Delivery_Date"] = order["requested_date"]

    # NOTE: BC's NAV.SalesOrder 'Comment' is a BOOLEAN flag (comments-exist),
    # not a free-text field — text comments live in a separate Comment-Lines
    # entity. Do NOT map order.comment here (HTTP 400 'property does not exist').

    return payload


def build_odata_line_payload(bc_order_no: str, ln: dict) -> dict[str, Any]:
    """
    Build an OData SalesOrderSalesLines POST payload for one order line.

    Field mapping (OData SalesOrderSalesLines entity):
      Document_No  ← BC-assigned order No (from the header POST response)
      Type         ← 'Item'
      No           ← ref_skus.sku_code
      Quantity     ← qty (float)
    """
    # Description is intentionally NOT set: for an Item line BC auto-fills it
    # from the item card. line_comment is operational notes, not the item
    # description — overriding it would corrupt the BC line text.
    line: dict[str, Any] = {
        "Document_No": bc_order_no,
        "Type":        "Item",
        "No":          ln["sku_code"],
        "Quantity":    float(ln["qty"]),
    }
    return line


# ── Rekey helper (apply path) ─────────────────────────────────────────────────

def _rekey_local_order(
    conn: pymysql.connections.Connection,
    local_id: int,
    bc_no: str,
) -> None:
    """
    In ONE atomic DB transaction, after a successful BC CREATE:
      1. Set ord_orders.bc_no = bc_no
      2. Rekey ord_orders.source_ref from 'mt:<id>' → 'bc:<bc_no>'

    This rekey makes the order visible to ingest_bc_sales_orders.py as a
    standard BC-pulled order on the next pull.  The echo field
    (Your_Reference = 'mt:<id>') persists on the BC side as an idempotency
    guard; the reader's echo-leg recovers from a failed rekey automatically.

    Called ONLY after a confirmed BC HTTP 201/200 response.
    """
    mt_ref = bc_echo_format(local_id)
    bc_ref = f"bc:{bc_no}"

    with conn.cursor() as c:
        c.execute(
            """
            UPDATE ord_orders
               SET bc_no      = %s,
                   source_ref = %s,
                   updated_at = CURRENT_TIMESTAMP
             WHERE id         = %s
               AND source     = 'maltytask'
               AND bc_no      IS NULL
               AND source_ref = %s
            """,
            (bc_no, bc_ref, local_id, mt_ref),
        )
        rows_updated = c.rowcount
    conn.commit()

    if rows_updated != 1:
        raise RuntimeError(
            f"Rekey failed for local_id={local_id}: expected 1 row updated, got "
            f"{rows_updated}. BC order {bc_no} was created but local state was NOT "
            "rekeyed — check for concurrent modification or already-rekeyed row."
        )


# ── Admin helpers ──────────────────────────────────────────────────────────────

def delete_bc_order(odata_base: str, hdrs: dict, bc_no: str) -> None:
    """
    DELETE a BC SalesOrder (and its lines) by No.

    OData SalesOrder DELETE cascades to SalesOrderSalesLines automatically on
    the NEBULEUSE BC tenant (confirmed per OData v4 cascade semantics for child
    entities).  If the tenant requires explicit line deletion first, delete each
    line via SalesOrderSalesLines(Document_No='<No>',Line_No=<n>) then DELETE
    the header.

    This is an admin helper for cleaning up supervised test orders.
    Requires --apply --i-have-kouros-go (same guard as the write path).
    """
    # First, GET the order to confirm it exists and retrieve the OData etag/id
    filter_str = f"No eq '{bc_no}'"
    url_get = f"{odata_base}/SalesOrder?$filter={filter_str}&$top=1"
    r = requests.get(url_get, headers=hdrs, timeout=30)
    if r.status_code != 200:
        raise RuntimeError(
            f"GET for delete failed: HTTP {r.status_code} — {r.text[:300]}"
        )
    rows = r.json().get("value", [])
    if not rows:
        print(f"  [delete-bc] Order {bc_no!r} not found in BC — nothing to delete.")
        return

    order_row = rows[0]
    order_key = order_row.get("No", bc_no)
    doc_type  = order_row.get("Document_Type", "Order")
    # NAV.SalesOrder / SalesOrderSalesLines have a COMPOSITE key (Document_Type, No
    # / Document_No, Line_No). BC OData also requires If-Match on DELETE; '*' matches
    # any etag (safe for our throwaway-cleanup case).
    del_hdrs = {**hdrs, "If-Match": "*"}

    # Delete lines first (some BC OData tenants require this before header DELETE)
    lines_url = (
        f"{odata_base}/SalesOrderSalesLines"
        f"?$filter=Document_Type eq '{doc_type}' and Document_No eq '{order_key}'"
        f"&$select=Document_Type,Document_No,Line_No"
    )
    lr = requests.get(lines_url, headers=hdrs, timeout=30)
    if lr.status_code == 200:
        for line in lr.json().get("value", []):
            line_no = line.get("Line_No")
            del_line_url = (
                f"{odata_base}/SalesOrderSalesLines"
                f"(Document_Type='{doc_type}',Document_No='{order_key}',Line_No={line_no})"
            )
            dr = requests.delete(del_line_url, headers=del_hdrs, timeout=30)
            if dr.status_code not in (200, 204):
                print(
                    f"  [delete-bc] WARNING: line {line_no} DELETE returned "
                    f"HTTP {dr.status_code} — {dr.text[:200]}"
                )
            else:
                print(f"  [delete-bc] Line {line_no} deleted.")

    # Delete the header (composite key)
    del_url = f"{odata_base}/SalesOrder(Document_Type='{doc_type}',No='{order_key}')"
    dr = requests.delete(del_url, headers=del_hdrs, timeout=30)
    if dr.status_code not in (200, 204):
        raise RuntimeError(
            f"DELETE SalesOrder(Document_Type='{doc_type}',No='{order_key}') failed: "
            f"HTTP {dr.status_code} — {dr.text[:300]}"
        )
    print(f"  [delete-bc] Order {order_key!r} deleted from BC.")


def make_test_order(conn: pymysql.connections.Connection) -> int:
    """
    Mint a synthetic maltytask-native ord_orders row for offline testing.

    Inserts:
      - 1 ord_orders row (source='maltytask', source_ref='mt:<id>', bc_no=NULL)
      - 1 ord_order_lines row with line_status='to_fulfil'  (the pushable line)
      - 1 ord_order_lines row with line_status='rupture'    (the excluded line)

    Uses the FIRST customer with a bc_customer_no and the FIRST two active SKUs.
    Returns the new ord_orders.id.

    Self-clean: call cleanup_test_order(conn, order_id) or use --cleanup-test-order.
    """
    with conn.cursor() as c:
        c.execute(
            "SELECT id FROM ref_customers WHERE bc_customer_no IS NOT NULL LIMIT 1"
        )
        cust_row = c.fetchone()
        if not cust_row:
            raise RuntimeError(
                "No ref_customers row with bc_customer_no — cannot create test order."
            )
        customer_id = cust_row["id"]

        c.execute("SELECT id, sku_code FROM ref_skus WHERE is_active=1 ORDER BY id LIMIT 2")
        sku_rows = c.fetchall()
        if len(sku_rows) < 2:
            raise RuntimeError("Need at least 2 active ref_skus for test order.")

        sku_a = sku_rows[0]
        sku_b = sku_rows[1]

        # Insert header
        c.execute(
            """
            INSERT INTO ord_orders
                (order_type, customer_id_fk, requested_date, status,
                 source, source_ref, created_by_user_id, comment)
            VALUES ('customer', %s, CURDATE(), 'entered',
                    'maltytask', 'mt:TEST-PLACEHOLDER', NULL,
                    'SYNTHETIC TEST ORDER — auto-clean with --cleanup-test-order')
            """,
            (customer_id,),
        )
        order_id = conn.insert_id()

        # Rekey source_ref to 'mt:<real_id>'
        real_echo = bc_echo_format(order_id)
        c.execute(
            "UPDATE ord_orders SET source_ref = %s WHERE id = %s",
            (real_echo, order_id),
        )

        # Line A — to_fulfil (pushable)
        c.execute(
            """
            INSERT INTO ord_order_lines
                (order_id_fk, sku_id_fk, qty, line_comment, line_status)
            VALUES (%s, %s, 2, 'TEST LINE A — to_fulfil', 'to_fulfil')
            """,
            (order_id, sku_a["id"]),
        )

        # Line B — rupture (excluded from push)
        c.execute(
            """
            INSERT INTO ord_order_lines
                (order_id_fk, sku_id_fk, qty, line_comment, line_status)
            VALUES (%s, %s, 1, 'TEST LINE B — rupture (excluded)', 'rupture')
            """,
            (order_id, sku_b["id"]),
        )

    conn.commit()
    print(
        f"  [make-test-order] Created synthetic order id={order_id} "
        f"source_ref={real_echo!r} "
        f"(sku_a={sku_a['sku_code']!r} to_fulfil, sku_b={sku_b['sku_code']!r} rupture)"
    )
    return order_id


def cleanup_test_order(conn: pymysql.connections.Connection, order_id: int) -> None:
    """
    Self-clean: DELETE a synthetic test order and its lines.
    Only removes rows where comment contains 'SYNTHETIC TEST ORDER'.
    """
    with conn.cursor() as c:
        c.execute(
            "SELECT id, comment FROM ord_orders WHERE id = %s AND source = 'maltytask'",
            (order_id,),
        )
        row = c.fetchone()
        if not row:
            print(f"  [cleanup] Order id={order_id} not found — nothing to clean.")
            return
        if "SYNTHETIC TEST ORDER" not in (row.get("comment") or ""):
            raise RuntimeError(
                f"Order id={order_id} is NOT a synthetic test order — refusing to delete."
            )
        c.execute("DELETE FROM ord_order_lines WHERE order_id_fk = %s", (order_id,))
        c.execute("DELETE FROM ord_orders WHERE id = %s", (order_id,))
    conn.commit()
    print(f"  [cleanup] Synthetic order id={order_id} deleted (0 residue).")


# ── Dry-run summary printer ────────────────────────────────────────────────────

def _print_order_summary(order: dict, header_payload: dict, existing_bc: dict | None) -> None:
    """Print the dry-run per-order summary."""
    local_id  = order["id"]
    echo_val  = bc_echo_format(local_id)

    print()
    print(f"  ── Order local_id={local_id} source_ref={order['source_ref']!r} ──")
    print(f"  Customer : {order['customer_name']} ({order['bc_customer_no']})")
    print(f"  Status   : {order['status']} | requested_date: {order['requested_date'] or '(none)'}")
    print(f"  Echo field (Your_Reference) = {echo_val!r}")
    print(f"  Lines to push ({len(order['lines'])} to_fulfil, {order['excluded_lines']} excluded):")
    for ln in order["lines"]:
        print(
            f"    sku={ln['sku_code']!r:12s}  qty={float(ln['qty']):<6}  "
            f"line_status={ln['line_status']}"
        )

    if existing_bc is not None:
        bc_no = existing_bc.get("No", "?")
        print()
        print(f"  GET-before-create → ALREADY EXISTS in BC as {bc_no!r}")
        print("  Action            → WOULD SKIP (already published)")
    else:
        print()
        print(
            f"  GET-before-create → absent in BC "
            f"(Your_Reference={echo_val!r} not found)"
        )
        print("  Action            → WOULD CREATE")
        print()
        print("  OData SalesOrder header payload that would be POSTed:")
        print(json.dumps(header_payload, indent=4, default=str))
        if order["lines"]:
            print()
            print("  OData SalesOrderSalesLines payloads that would be POSTed:")
            for ln in order["lines"]:
                line_pl = build_odata_line_payload("<<BC_No_from_header_response>>", ln)
                print(json.dumps(line_pl, indent=4, default=str))


# ── Main loop ──────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Push maltytask-native orders to BC via OData SalesOrder entity. "
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
        help=(
            "Operator authorisation flag. A live BC write requires BOTH "
            "--apply and --i-have-kouros-go. Neither flag alone is sufficient."
        ),
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=0,
        help="Process at most N orders (0 = all).",
    )
    parser.add_argument(
        "--delete-bc",
        metavar="BC_NO",
        default=None,
        help=(
            "Admin helper: DELETE a BC SalesOrder by No (and its lines). "
            "Requires --apply --i-have-kouros-go. Use to clean up supervised test orders."
        ),
    )
    parser.add_argument(
        "--make-test-order",
        action="store_true",
        help=(
            "Mint a synthetic maltytask-native ord_orders row (2 lines: 1 to_fulfil, "
            "1 rupture) for offline testing.  Safe in dry-run — creates only local DB rows."
        ),
    )
    parser.add_argument(
        "--cleanup-test-order",
        action="store_true",
        help=(
            "Combined with --make-test-order: after running the dry-run proof, DELETE "
            "the synthetic test order (self-clean, verifies 0 residue)."
        ),
    )
    args = parser.parse_args()

    # ── Determine effective mode ───────────────────────────────────────────────
    live_write = args.apply and args.kouros_go

    if args.apply and not args.kouros_go:
        print(
            "\nWARNING: --apply passed without --i-have-kouros-go.\n"
            "Live BC writes require BOTH flags. Running in dry-run mode.\n",
            file=sys.stderr,
        )
    if args.kouros_go and not args.apply:
        print(
            "\nWARNING: --i-have-kouros-go passed without --apply.\n"
            "No effect — still running in dry-run mode.\n",
            file=sys.stderr,
        )

    mode_label = "** LIVE APPLY **" if live_write else "DRY-RUN"
    print(f"\npush_bc_sales_orders.py — v{SCRIPT_VERSION}")
    print(f"Mode: {mode_label}")
    if live_write:
        print("WARNING: Live BC OData POSTs will be sent. Operator authorisation confirmed.")
    print()

    # ── Per-invocation lock (no hardcoded /tmp path) ───────────────────────────
    lock_fd, lock_path = tempfile.mkstemp(
        prefix="push_bc_orders_", suffix=".lock", dir=str(_LOCK_DIR)
    )
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        os.close(lock_fd)
        os.unlink(lock_path)
        print(
            "ERROR: Another invocation of push_bc_sales_orders.py is already running.",
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
    limit      = args.limit
    mode_label = "** LIVE APPLY **" if live_write else "DRY-RUN"

    # ── Handle --delete-bc (admin helper) ─────────────────────────────────────
    if args.delete_bc:
        if not live_write:
            print(
                "ERROR: --delete-bc requires BOTH --apply AND --i-have-kouros-go.\n"
                "Without both flags this is a no-op (safety guard).",
                file=sys.stderr,
            )
            sys.exit(1)
        print(f"[delete-bc] Deleting BC order {args.delete_bc!r} …")
        bc = _load_bc_env()
        token = _get_token(bc)
        hdrs = {
            "Authorization": f"Bearer {token}",
            "Accept":        "application/json",
            "Content-Type":  "application/json",
        }
        odata_base = _odata_base(bc)
        delete_bc_order(odata_base, hdrs, args.delete_bc)
        return

    # ── Load BC credentials + token ───────────────────────────────────────────
    print("[1/5] Loading BC credentials …")
    bc    = _load_bc_env()
    token = _get_token(bc)
    hdrs  = {
        "Authorization": f"Bearer {token}",
        "Accept":        "application/json",
        "Content-Type":  "application/json",
    }
    odata_base = _odata_base(bc)

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
        # ── Handle --make-test-order ──────────────────────────────────────────
        test_order_id: int | None = None
        if args.make_test_order:
            print("[make-test-order] Minting synthetic test order …")
            test_order_id = make_test_order(conn)

        # ── Load pending orders from local DB ─────────────────────────────────
        print("[3/5] Loading pending maltytask orders (source='maltytask', bc_no IS NULL) …")
        orders = load_pending_orders(conn)
        print(f"  Pending orders found: {len(orders)}")

        if not orders:
            print()
            print("  0 orders to push — nothing to do.")
            print()
            if args.cleanup_test_order and test_order_id is not None:
                cleanup_test_order(conn, test_order_id)
            return

        if limit > 0:
            orders = orders[:limit]
            print(f"  --limit {limit}: processing {len(orders)} orders")

        # ── GET-before-create + payload build (read-only phase) ───────────────
        print("[4/5] Running GET-before-create probes via OData (read-only) …")
        print()

        results: list[dict] = []
        for order in orders:
            local_id      = order["id"]
            header_payload = build_odata_header_payload(order)

            # GET-before-create runs ALWAYS — read-only, safe in dry-run
            existing_bc = get_bc_order_by_your_reference(
                odata_base = odata_base,
                hdrs       = hdrs,
                local_id   = local_id,
            )

            results.append({
                "order":          order,
                "header_payload": header_payload,
                "existing_bc":    existing_bc,
            })

            _print_order_summary(order, header_payload, existing_bc)

        # ── Write phase (disarmed unless both --apply + --i-have-kouros-go) ───
        print()
        print("[5/5] Write phase …")

        if not live_write:
            print()
            print("  DRY-RUN — no BC OData writes performed.")
            if args.apply:
                print("  (pass --i-have-kouros-go alongside --apply to arm the writer)")
            else:
                print("  (pass --apply --i-have-kouros-go to arm the writer)")

        else:
            # ── LIVE APPLY PATH ────────────────────────────────────────────────
            # Reached ONLY when both --apply AND --i-have-kouros-go are passed.
            # DO NOT execute this branch manually — Kouros runs the supervised test.
            created = 0
            skipped = 0

            for r in results:
                order          = r["order"]
                local_id       = order["id"]
                header_payload = r["header_payload"]
                existing_bc    = r["existing_bc"]

                if existing_bc is not None:
                    print(
                        f"  SKIP order {local_id}: already in BC as "
                        f"{existing_bc.get('No', '?')!r}"
                    )
                    skipped += 1
                    continue

                if not order["lines"]:
                    print(f"  SKIP order {local_id}: no to_fulfil lines to push")
                    skipped += 1
                    continue

                # POST the SalesOrder header
                print(f"  POST SalesOrder header for local_id={local_id} …", end=" ", flush=True)
                url_header = f"{odata_base}/SalesOrder"
                rh = requests.post(url_header, headers=hdrs, json=header_payload, timeout=30)
                if rh.status_code not in (200, 201):
                    raise RuntimeError(
                        f"OData POST SalesOrder failed for local_id={local_id}: "
                        f"HTTP {rh.status_code} — {rh.text[:300]}"
                    )
                bc_order     = rh.json()
                bc_no        = bc_order.get("No") or bc_order.get("number", "")
                if not bc_no:
                    raise RuntimeError(
                        f"BC response did not return a 'No' field: {bc_order}"
                    )
                print(f"→ BC No={bc_no!r}")

                # POST each to_fulfil line to SalesOrderSalesLines
                url_lines = f"{odata_base}/SalesOrderSalesLines"
                for ln in order["lines"]:
                    line_payload = build_odata_line_payload(bc_no, ln)
                    rl = requests.post(url_lines, headers=hdrs, json=line_payload, timeout=30)
                    if rl.status_code not in (200, 201):
                        raise RuntimeError(
                            f"OData POST SalesOrderSalesLines failed for "
                            f"local_id={local_id} sku={ln['sku_code']!r}: "
                            f"HTTP {rl.status_code} — {rl.text[:300]}"
                        )
                    print(f"    → line sku={ln['sku_code']!r} qty={float(ln['qty'])} posted")

                # Rekey local order atomically
                _rekey_local_order(conn, local_id, bc_no)
                print(f"  → local order {local_id} rekeyed to bc_no={bc_no!r}, source_ref='bc:{bc_no}'")
                created += 1

            print()
            print(f"  Live apply: {created} created, {skipped} skipped.")

        # ── Summary ───────────────────────────────────────────────────────────
        would_create   = sum(1 for r in results if r["existing_bc"] is None)
        would_skip     = sum(1 for r in results if r["existing_bc"] is not None)
        excluded_total = sum(r["order"]["excluded_lines"] for r in results)

        print()
        print("═" * 60)
        print("  SUMMARY")
        print("═" * 60)
        print(f"  Mode                     : {mode_label}")
        print(f"  API surface              : OData v4 SalesOrder / SalesOrderSalesLines")
        print(f"  Echo field               : Your_Reference (NOT External_Document_No)")
        print(f"  Orders evaluated         : {len(results)}")
        print(f"  Would CREATE             : {would_create}")
        print(f"  Would SKIP (exists in BC): {would_skip}")
        print(f"  Lines excluded           : {excluded_total} (non_livre/rupture — not pushed)")
        if not live_write:
            print("  BC writes performed      : 0 (disarmed)")
        print("═" * 60)
        print()

        # ── Self-clean test order if requested ────────────────────────────────
        if args.cleanup_test_order and test_order_id is not None:
            print("[cleanup-test-order] Removing synthetic test order …")
            cleanup_test_order(conn, test_order_id)

    except Exception as exc:
        conn.rollback()
        print(f"\nERROR: {exc}", file=sys.stderr)
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
