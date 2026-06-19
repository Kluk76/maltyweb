#!/usr/bin/env python3
"""
ingest_bc_sales_orders.py — BC open sales orders → ord_orders / ord_order_lines.

Pulls ALL uninvoiced (open) sales orders from the BC OData SalesOrder / SalesOrderSalesLines
endpoints and upserts them into the maltytask operational tables.  Designed as a SEPARATE
script from ingest_bc_sales_ledger.py — does NOT touch inv_sales_ledger.

SOURCE:
  /ODataV4/Company('NEBULEUSE')/SalesOrder          — order headers (29 rows, naturally scoped)
  /ODataV4/Company('NEBULEUSE')/SalesOrderSalesLines — order lines (134 Item-type rows)

  BC deletes invoiced orders from SalesOrder automatically, so this endpoint is always
  scoped to open/uninvoiced orders with no filter needed.  Every pull is a FULL snapshot.

EXCLUSIONS (XOR / no-double-deplete guard):
  - Orders with non-empty ShpfyOrderNo → Shopify lane (inv_sales_orders); skip.
  - Sell_to_Customer_No IN ('1080','3822') → eshop/taproom system accounts; skip.
  - Lines where Type ≠ 'Item' → comment/charge lines; skip.

STATUS MODEL (operator-confirmed):
  - New BC order  → maltytask status 'entered'
  - Header + lines refreshed while status IN ('entered','confirmed')
  - Once 'picked'+ → FREEZE lines (operator started physical work); header commercial fields
    may still refresh but lines are not touched
  - BC Completely_Shipped=True + current status below 'bl_printed' → advance to 'bl_printed'
    (BL was printed for physical loading day; NOT a depletion trigger)
  - Status 'shipped' is set ONLY by the operator; the pull NEVER writes it
  - A BC order leaves the active set when BC invoices it (disappears from SalesOrder)

DIVERGENCE DETECTION (build B):
  - On every pull, BC lines are upserted into ord_order_bc_lines (snapshot)
  - The snapshot is diffed against ord_order_lines (operator-maintained truth)
  - When they diverge (sku changed, qty changed, line added/removed):
    → ord_orders.divergence_status = 'correction_compta_requise'
    → doc_review_queue row emitted (type='bc-order-correction-required', dedup per order)
    → UI shows "⚠ correction compta requise" badge on the order card
  - When re-aligned (subsequent pull finds no diff):
    → divergence_status reset to 'none', RQ row auto-resolved

UPSERT KEY:
  ord_orders.source_ref = 'bc:<No>' (e.g. 'bc:ORD210064')
  Unique index uniq_ord_source_ref ensures idempotency.

SKU RESOLUTION:
  BC Item code alone is NOT sufficient — the same Item appears in multiple UoM formats.
  However, Item code DIRECTLY maps to sku_code in ref_skus (BC item codes are the canonical
  SKU codes), so UoM is used for validation only (not routing).  Unresolved → review CSV.

  Known non-beer items that are ALWAYS skipped (deposits, CO2 charges — no hl_per_unit):
    CAUF   (Caution - Fût Inox 20L)
    CAUAL  (Caution - Bouteille Aligal)
    CO2- FO (Forfait CO2)
    V25    (Box 6 Verres 0.25/0.33 — glassware)
    V50    (Box 6 Verres 0.50 — glassware)
    EPH1021 (seasonal variant not in ref_skus — needs alias added)

COLLISION REPORT:
  Checks existing ord_orders rows (source IN ('web','email','import')) for potential
  double-entry vs incoming BC orders using heuristic: same customer_id_fk AND overlapping
  resolved SKU set. Reports for operator review before --apply.

Credentials:
  /var/www/maltytask/config/bc.env  (BC OAuth2)
  /var/www/maltytask/config/db.env  (MySQL)

Usage:
  # Dry-run (default) — full reconciliation + collision report, no writes:
  python3 scripts/python/ingest_bc_sales_orders.py

  # Apply — write to ord_orders / ord_order_lines / ord_order_status_events:
  python3 scripts/python/ingest_bc_sales_orders.py --apply

  # Limit (for smoke-testing):
  python3 scripts/python/ingest_bc_sales_orders.py --limit 5
  python3 scripts/python/ingest_bc_sales_orders.py --limit 5 --apply
"""

from __future__ import annotations

import argparse
import csv
import io
import json
import os
import sys
from datetime import datetime
from decimal import Decimal
from pathlib import Path
from typing import Any
from urllib.parse import quote

# Allow running from /var/www/maltytask (adds scripts/python to path for lib_*)
_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

import pymysql  # noqa: E402 — after sys.path fix
from pymysql.cursors import DictCursor

try:
    import requests  # noqa: E402
except ImportError:
    print("ERROR: requests not installed. Run: pip install requests", file=sys.stderr)
    sys.exit(1)

from lib_config import load as load_config  # noqa: E402
from bc_echo import bc_echo_format, bc_echo_parse  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

SCRIPT_VERSION = "1.6.0"
ACTOR = "bc-sync"  # used in RQ context / audit fields

# BC OData service name (É encoded — Python urllib raises InvalidURL on raw non-ASCII)
_BC_ENV_PATH = Path("/var/www/maltytask/config/bc.env")

# System customers to exclude (eshop / taproom — their orders live in inv_sales_orders)
SYSTEM_CUSTOMER_NOS = frozenset({"1080", "3822"})

# Items that are not beer SKUs and must always be skipped (deposits, CO2 charges, glassware).
# These are non-inventoried items with no hl_per_unit.  The caller cannot add them to
# ord_order_lines (FK → ref_skus requires a real sku_id, and they have no beer stock impact).
# Include them in the unresolved-line log but do NOT fail the order over them.
NON_BEER_BC_ITEMS = frozenset({"CAUF", "CAUAL", "CO2- FO", "V25", "V50"})

# ── Cutover / standing-import constants ───────────────────────────────────────
#
# WeeklyOrders sheet was retired on CUTOVER_DATE; BC is now the sole order source.
# CUTOVER_DATE drives two standing-import rules:
#
#   1. RECENCY JUNK FLOOR — orders with Order_Date < RECENCY_FLOOR are always
#      ignored regardless of any other criterion (kills stale lingering orders
#      like ORD201126 that were placed in 2020–2025 and never invoiced in BC).
#
#   2. BACKLOG SKIP — orders with Order_Date < CUTOVER_DATE AND
#      Completely_Shipped=True are skipped (historical orders shipped before
#      cutover; already done / captured in the sales ledger — not active
#      logistics work; importing would clutter the queue at bl_printed forever).
#      Orders with Order_Date < CUTOVER_DATE AND Completely_Shipped=False ARE
#      imported (genuine active pre-cutover non-collision work-in-progress).
#
# These two rules are permanent — no special "first-run" mode.  The logic is
# fully deterministic and self-maintaining:
#   - New BC orders → born not-shipped post-cutover → INSERT.
#   - Historical shipped backlog → pre-cutover+shipped → permanently SKIP.
#   - 12 WeeklyOrders collisions → SKIP (operator keeps their in-progress row).
#   - Stale/ancient orders → RECENCY FLOOR → permanently SKIP.
CUTOVER_DATE   = "2026-06-15"   # WeeklyOrders retirement date; BC is sole source from here
RECENCY_FLOOR  = "2026-01-01"   # Absolute floor: orders older than this are always ignored

# Status ordering for advance-guard logic (must not regress)
_STATUS_RANK: dict[str, int] = {
    "entered":    0,
    "confirmed":  1,
    "picked":     2,
    "bl_printed": 3,
    "shipped":    4,
    "cancelled":  5,
}

# Statuses where line refresh is still safe (operator hasn't started physical work)
_REFRESH_LINE_STATUSES = frozenset({"entered", "confirmed"})


# ── Status advance helpers ────────────────────────────────────────────────────

def should_advance_to_bl_printed(
    current_status: str,
    completely_shipped: bool,
) -> bool:
    """
    Return True iff BC says Completely_Shipped=True AND maltytask status is below bl_printed.
    Never regresses; never touches 'shipped' or 'cancelled'.
    """
    if not completely_shipped:
        return False
    rank_current = _STATUS_RANK.get(current_status, 99)
    rank_target  = _STATUS_RANK["bl_printed"]
    return rank_current < rank_target


# INVARIANT: NEVER extend this auto-advance to 'shipped'. fg-stock.php depletes
# FG/COGS/WAC/beer-tax ONLY on status='shipped' — bl_printed is pre-depletion.
# Auto-advancing to shipped would burn canonical facts without operator confirmation.
def advance_status(
    conn: "pymysql.connections.Connection",
    order: dict,
    existing_bc: dict[str, dict],
    apply_mode: bool,
) -> "str | None":
    """
    If BC says Completely_Shipped=True and current status is below bl_printed,
    advance to bl_printed by emitting one ord_order_status_events row per crossed
    intermediate stage (entered→confirmed→picked→bl_printed as needed), then
    updating ord_orders.status = 'bl_printed'.

    In dry-run mode: no DB writes, but runs a read-only SELECT for existing events
    so the preview is accurate.  Returns a human-readable summary string, or None
    if no advance is needed.
    """
    source_ref = order["source_ref"]
    existing   = existing_bc.get(source_ref)
    if existing is None:
        return None  # new order just inserted as 'entered'

    current_status = existing.get("status", "entered")
    if not should_advance_to_bl_printed(current_status, order["completely_shipped"]):
        return None

    order_id     = existing["id"]
    rank_current = _STATUS_RANK[current_status]
    rank_target  = _STATUS_RANK["bl_printed"]

    # Build ordered list of stages to cross (strictly between current and bl_printed, inclusive)
    stages_ordered = sorted(
        [s for s, r in _STATUS_RANK.items() if rank_current < r <= rank_target],
        key=lambda s: _STATUS_RANK[s],
    )

    # Read existing events (read-only SELECT — safe in both apply and dry-run)
    with conn.cursor() as c:
        c.execute(
            "SELECT status FROM ord_order_status_events WHERE order_id_fk = %s",
            (order_id,),
        )
        existing_event_statuses = {row["status"] for row in c.fetchall()}

    stages_to_emit = [s for s in stages_ordered if s not in existing_event_statuses]

    if apply_mode:
        with conn.cursor() as c:
            for stage in stages_to_emit:
                c.execute(
                    """
                    INSERT INTO ord_order_status_events
                        (order_id_fk, status, occurred_at, user_id_fk, comment)
                    VALUES (%s, %s, NOW(), NULL, 'auto:bc-shipped')
                    """,
                    (order_id, stage),
                )
            c.execute(
                """
                UPDATE ord_orders
                   SET status     = 'bl_printed',
                       updated_at = CURRENT_TIMESTAMP
                 WHERE id = %s
                """,
                (order_id,),
            )

    stages_label = ",".join(stages_to_emit) if stages_to_emit else "(all already present)"
    return (
        f"{order['bc_no']}: {current_status} -> bl_printed [auto:bc-shipped]"
        f" (events: {stages_label})"
    )

# Batch size for OR-chained $filter requests (BC rejects the `in` operator — HTTP 501)
OR_CHAIN_BATCH = 50


# ── BC config + OAuth2 ────────────────────────────────────────────────────────

def _load_bc_env() -> dict[str, str]:
    path = _BC_ENV_PATH
    override = Path(os.environ.get("MALTYTASK_BC_ENV", ""))
    if override.name:
        path = override
    if not path.exists():
        raise RuntimeError(
            f"BC credentials not found at {path}.\n"
            f"Expected keys: BC_TENANT_ID, BC_CLIENT_ID, BC_CLIENT_SECRET, BC_ENVIRONMENT.\n"
            f"See: config/bc.env.example"
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
    """Fetch OAuth2 client-credentials token.  Token never logged."""
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
    tenant = bc["BC_TENANT_ID"]
    env    = bc["BC_ENVIRONMENT"]
    return (
        f"https://api.businesscentral.dynamics.com/v2.0/{tenant}/{env}"
        f"/ODataV4/Company('NEBULEUSE')"
    )


# ── OData fetchers ─────────────────────────────────────────────────────────────

def _fetch_all_pages(url: str, headers: dict, label: str) -> list[dict]:
    """Follow @odata.nextLink until exhausted.  NO $top — would suppress paging."""
    all_rows: list[dict] = []
    page = 0
    while url:
        resp = requests.get(url, headers=headers, timeout=120)
        if resp.status_code != 200:
            raise RuntimeError(
                f"OData {label} failed: HTTP {resp.status_code} — {resp.text[:300]}"
            )
        j = resp.json()
        rows = j.get("value", [])
        all_rows.extend(rows)
        page += 1
        print(
            f"  [OData] {label} page {page}: {len(rows)} rows "
            f"(total so far: {len(all_rows)})",
            flush=True,
        )
        url = j.get("@odata.nextLink", "")
    print(f"  [OData] {label} complete: {len(all_rows)} rows.", flush=True)
    return all_rows


def fetch_sales_orders(bc: dict[str, str]) -> list[dict]:
    """
    Fetch ALL rows from SalesOrder (no filter — BC naturally scopes to open/uninvoiced).
    Fields: No, Document_Type, Status, Sell_to_Customer_No, Sell_to_Customer_Name,
            Ship_to_Name, Ship_to_Address, Ship_to_City, Ship_to_Post_Code,
            Ship_to_Country_Region_Code, Shipping_Agent_Code, Shipment_Date,
            Completely_Shipped, ShpfyOrderNo, Salesperson_Code, Order_Date,
            Posting_Date (date de comptabilisation = jour de chargement effectif → requested_date),
            Document_Date (fallback for requested_date when Posting_Date is blank).
    """
    token = _get_token(bc)
    headers = {"Authorization": f"Bearer {token}", "Accept": "application/json"}
    base = _odata_base(bc)

    select_fields = ",".join([
        "No", "Document_Type", "Status",
        "Sell_to_Customer_No", "Sell_to_Customer_Name",
        "Ship_to_Name", "Ship_to_Address", "Ship_to_City",
        "Ship_to_Post_Code", "Ship_to_Country_Region_Code",
        "Shipping_Agent_Code", "Shipment_Date",
        "Completely_Shipped", "ShpfyOrderNo",
        "Salesperson_Code", "Order_Date",
        "Posting_Date",    # date de comptabilisation = jour de chargement effectif → requested_date
        "Document_Date",   # fallback for requested_date when Posting_Date is blank
        "Your_Reference",  # echo field: 'mt:<local_id>' for maltytask-native orders
        "External_Document_No",
    ])
    select_enc = quote(select_fields, safe="")
    url = f"{base}/SalesOrder?$select={select_enc}"
    return _fetch_all_pages(url, headers, "SalesOrder")


def fetch_sales_lines(bc: dict[str, str]) -> list[dict]:
    """
    Fetch ALL rows from SalesOrderSalesLines.
    Fields: Document_No, Line_No, Type, No, Description, Quantity,
            Unit_of_Measure_Code, Unit_of_Measure, Unit_Price, Line_Amount,
            Quantity_Shipped, Unit_Volume.
    """
    token = _get_token(bc)
    headers = {"Authorization": f"Bearer {token}", "Accept": "application/json"}
    base = _odata_base(bc)

    select_fields = ",".join([
        "Document_No", "Line_No", "Type", "No", "Description",
        "Quantity", "Unit_of_Measure_Code", "Unit_of_Measure",
        "Unit_Price", "Line_Amount", "Quantity_Shipped", "Unit_Volume",
    ])
    select_enc = quote(select_fields, safe="")
    url = f"{base}/SalesOrderSalesLines?$select={select_enc}"
    return _fetch_all_pages(url, headers, "SalesOrderSalesLines")


# ── Reference-data loaders ─────────────────────────────────────────────────────

def load_customers(conn: pymysql.connections.Connection) -> dict[str, int]:
    """Returns {bc_customer_no: ref_customers.id} for all customers (incl. inactive)."""
    with conn.cursor() as c:
        c.execute(
            "SELECT bc_customer_no, id FROM ref_customers "
            "WHERE bc_customer_no IS NOT NULL"
        )
        return {str(r["bc_customer_no"]).strip(): int(r["id"]) for r in c.fetchall()}


def load_skus(
    conn: pymysql.connections.Connection,
) -> tuple[dict[str, tuple[int, float | None]], dict[str, tuple[int, float | None]]]:
    """
    Returns:
      direct_map : {sku_code: (id, hl_per_unit)}   — active ref_skus
      alias_map  : {alias:    (canonical_id, hl)}   — ref_sku_aliases
    Resolution order: direct match → alias.
    """
    with conn.cursor() as c:
        c.execute("SELECT sku_code, id, hl_per_unit FROM ref_skus WHERE is_active = 1")
        direct_map: dict[str, tuple[int, float | None]] = {
            str(r["sku_code"]).strip(): (
                int(r["id"]),
                float(r["hl_per_unit"]) if r["hl_per_unit"] is not None else None,
            )
            for r in c.fetchall()
        }
        c.execute(
            "SELECT ra.alias, ra.canonical_sku_id, rs.hl_per_unit "
            "FROM ref_sku_aliases ra JOIN ref_skus rs ON rs.id = ra.canonical_sku_id"
        )
        alias_map: dict[str, tuple[int, float | None]] = {
            str(r["alias"]).strip(): (
                int(r["canonical_sku_id"]),
                float(r["hl_per_unit"]) if r["hl_per_unit"] is not None else None,
            )
            for r in c.fetchall()
        }
    return direct_map, alias_map


def load_transporters(conn: pymysql.connections.Connection) -> dict[str, int]:
    """Returns {name_upper: id} for all active transporters."""
    with conn.cursor() as c:
        c.execute("SELECT id, name FROM ref_transporters WHERE is_active = 1")
        return {str(r["name"]).upper(): int(r["id"]) for r in c.fetchall()}


def load_existing_bc_orders(
    conn: pymysql.connections.Connection,
) -> dict[str, dict]:
    """
    Returns {source_ref: {id, status, customer_id_fk, requested_date,
             bc_completely_shipped, divergence_status}}
    for all existing ord_orders where source='bc'.
    """
    with conn.cursor() as c:
        c.execute(
            "SELECT id, source_ref, status, customer_id_fk, requested_date, "
            "       bc_completely_shipped, divergence_status "
            "FROM ord_orders WHERE source = 'bc'"
        )
        return {
            str(r["source_ref"]): dict(r) for r in c.fetchall()
            if r["source_ref"]
        }


def load_maltytask_order_by_id(
    conn: pymysql.connections.Connection,
    local_id: int,
) -> dict | None:
    """
    Return the ord_orders row for a maltytask-native order by PK.
    Used by the echo-leg to confirm the local row exists before rekeying.
    Returns None if no row with that id and source='maltytask' exists.
    """
    with conn.cursor() as c:
        c.execute(
            "SELECT id, source_ref, status, bc_no, customer_id_fk, requested_date, "
            "       bc_completely_shipped, divergence_status "
            "FROM ord_orders WHERE id = %s AND source = 'maltytask'",
            (local_id,),
        )
        row = c.fetchone()
        return dict(row) if row else None


def load_existing_non_bc_active_orders(
    conn: pymysql.connections.Connection,
) -> list[dict]:
    """
    Returns non-cancelled orders from sources web/email/import (active OR shipped),
    with their sku_id_fk sets and divergence_status, for the collision report.

    Includes shipped rows so that a BC order that duplicates an already-shipped manual
    order can be caught by detect_collisions() (two-tier match: shipped rows require
    customer+date+SKU overlap; active rows require only customer+SKU overlap).

    divergence_status is needed so snapshot_and_diff_collision can check whether
    the kept row's flag has already been set.
    """
    with conn.cursor() as c:
        c.execute(
            """
            SELECT o.id, o.source, o.source_ref, o.customer_id_fk, o.status,
                   o.requested_date, o.divergence_status,
                   GROUP_CONCAT(l.sku_id_fk ORDER BY l.sku_id_fk) AS sku_ids
              FROM ord_orders o
              LEFT JOIN ord_order_lines l ON l.order_id_fk = o.id
             WHERE o.source IN ('web','email','import')
               AND o.status != 'cancelled'
             GROUP BY o.id
            """
        )
        rows = c.fetchall()
        result = []
        for r in rows:
            result.append({
                "id":               r["id"],
                "source":           r["source"],
                "source_ref":       r["source_ref"],
                "customer_id_fk":   r["customer_id_fk"],
                "status":           r["status"],
                "requested_date":   str(r["requested_date"]),
                "divergence_status": r["divergence_status"] or "none",
                "sku_ids":          set(
                    int(x) for x in (r["sku_ids"] or "").split(",") if x
                ),
            })
        return result


def load_customer_identity_map(
    conn: pymysql.connections.Connection,
) -> dict[int, int]:
    """
    Loads ref_customer_identity: {member_customer_id_fk: canonical_customer_id_fk}.
    Used by detect_collisions() to collapse geographic ship-to accounts to their
    Cobra distributor bill-to canonical account for collision-key normalisation.
    """
    with conn.cursor() as c:
        c.execute(
            "SELECT member_customer_id_fk, canonical_customer_id_fk "
            "FROM ref_customer_identity"
        )
        rows = c.fetchall()
    return {int(r["member_customer_id_fk"]): int(r["canonical_customer_id_fk"]) for r in rows}


# ── SKU resolver ──────────────────────────────────────────────────────────────

def resolve_sku(
    item_no: str,
    uom_code: str,
    direct_map: dict,
    alias_map: dict,
) -> tuple[int | None, float | None, str]:
    """
    Resolve a (BC item_no, uom_code) pair to (sku_id, hl_per_unit, resolution_method).
    Resolution: direct match on item_no → alias match on item_no.
    Returns (None, None, 'unresolved') on failure — NEVER guesses.
    """
    item = item_no.strip()
    if item in direct_map:
        sku_id, hl = direct_map[item]
        return sku_id, hl, "direct"
    if item in alias_map:
        sku_id, hl = alias_map[item]
        return sku_id, hl, "alias"
    return None, None, "unresolved"


# ── Data classification ───────────────────────────────────────────────────────

def classify_orders(
    orders: list[dict],
    customers: dict[str, int],
    direct_map: dict,
    alias_map: dict,
    lines_by_order: dict[str, list[dict]],
) -> dict:
    """
    Classify all BC orders into pull / exclude buckets and resolve customers + SKUs.

    Exclusion order (XOR — first match wins):
      1. ShpfyOrderNo non-empty                           → excluded_shopify
      2. Sell_to_Customer_No IN SYSTEM_CUSTOMER_NOS       → excluded_system
      3. Order_Date < RECENCY_FLOOR (2026-01-01)          → excluded_recency  (junk floor)
      4. Customer not in ref_customers                    → unresolved_customers (skip)
      5. Reaches pull list; upsert function applies:
           a. Existing bc source_ref → UPDATE (status-gated)
           b. Same customer + overlapping SKUs (collision) → SKIP_COLLISION
           c. Order_Date < CUTOVER_DATE AND Completely_Shipped=True → SKIP_BACKLOG
           d. Otherwise → INSERT

    Returns a dict with:
      'pull'                — list of orders that passed all pre-filters (customers resolved)
      'excluded_shopify'    — orders with non-empty ShpfyOrderNo
      'excluded_system'     — orders for system customers 1080/3822
      'excluded_recency'    — orders with Order_Date < RECENCY_FLOOR
      'unresolved_customers'— orders whose customer bc_no has no ref_customers match
      'unresolved_lines'    — all unresolved (item, uom) pairs (for review CSV)
    """
    excluded_shopify   = []
    excluded_system    = []
    excluded_recency   = []
    pull               = []
    unresolved_custs   = []
    unresolved_lines   = []

    for o in orders:
        no       = str(o.get("No", "")).strip()
        shpfy_no = str(o.get("ShpfyOrderNo", "")).strip()
        cust_no  = str(o.get("Sell_to_Customer_No", "")).strip()

        # 1. Shopify lane
        if shpfy_no:
            excluded_shopify.append({"no": no, "shpfy_no": shpfy_no, "cust_no": cust_no})
            continue

        # 2. System customers (eshop / taproom)
        if cust_no in SYSTEM_CUSTOMER_NOS:
            excluded_system.append({"no": no, "cust_no": cust_no})
            continue

        # 3. Recency junk floor — stale lingering orders placed before 2026
        order_date = str(o.get("Order_Date", "") or "").strip()
        if order_date and order_date < RECENCY_FLOOR:
            excluded_recency.append({
                "no":         no,
                "cust_no":    cust_no,
                "order_date": order_date,
            })
            continue

        # 4. Customer resolution
        customer_id = customers.get(cust_no)
        if customer_id is None:
            unresolved_custs.append({
                "bc_order_no": no,
                "bc_customer_no": cust_no,
                "bc_customer_name": str(o.get("Sell_to_Customer_Name", "")),
            })
            # refuse-don't-NULL: skip unresolved customer orders entirely
            continue

        # Transporter
        agent_code = str(o.get("Shipping_Agent_Code", "")).strip()

        # Resolve lines
        order_lines = lines_by_order.get(no, [])
        resolved_lines = []
        for ln in order_lines:
            if str(ln.get("Type", "")).strip() != "Item":
                continue
            item    = str(ln.get("No", "")).strip()
            uom     = str(ln.get("Unit_of_Measure_Code", "")).strip()
            qty_raw = ln.get("Quantity", 0)
            try:
                qty = float(qty_raw)
            except (TypeError, ValueError):
                qty = 0.0

            if qty <= 0:
                continue

            # Known non-beer items — silently categorised as skip (not an error)
            if item in NON_BEER_BC_ITEMS:
                unresolved_lines.append({
                    "bc_order_no":   no,
                    "bc_item":       item,
                    "uom":           uom,
                    "qty":           qty,
                    "description":   str(ln.get("Description", ""))[:60],
                    "reason":        "non-beer-item (deposit/co2/glassware)",
                })
                continue

            sku_id, hl_per_unit, method = resolve_sku(item, uom, direct_map, alias_map)
            if sku_id is None:
                unresolved_lines.append({
                    "bc_order_no":   no,
                    "bc_item":       item,
                    "uom":           uom,
                    "qty":           qty,
                    "description":   str(ln.get("Description", ""))[:60],
                    "reason":        "unresolved-sku",
                })
                # Don't skip the order, but flag it (lines with unresolved SKUs are omitted)
                continue

            hl = qty * hl_per_unit if hl_per_unit is not None else None
            resolved_lines.append({
                "line_no":     int(ln.get("Line_No", 0)),
                "sku_id":      sku_id,
                "qty":         qty,
                "hl":          round(hl, 5) if hl is not None else None,
                "bc_item":     item,
                "uom":         uom,
                "description": str(ln.get("Description", ""))[:60],
                "unit_price":  float(ln.get("Unit_Price", 0) or 0),
                "line_amount": float(ln.get("Line_Amount", 0) or 0),
                "method":      method,
            })

        # Parse dates
        shipment_date       = str(o.get("Shipment_Date", "") or "").strip()
        # Posting_Date = date de comptabilisation = jour de chargement effectif (operator,
        # 2026-06-17). Cascade fallback Document_Date → Shipment_Date pour ne jamais laisser
        # requested_date NULL (sinon le burn engine perd la commande de son bucket forward).
        # Posting_Date sondé peuplé 20/20 sur commandes ouvertes ; fallbacks = ceinture.
        posting_date = (
            str(o.get("Posting_Date", "") or "").strip()
            or str(o.get("Document_Date", "") or "").strip()
            or shipment_date
        )
        completely_shipped  = bool(o.get("Completely_Shipped", False))

        pull.append({
            "bc_no":            no,
            "source_ref":       f"bc:{no}",
            "bc_status":        str(o.get("Status", "")),
            "customer_id":      customer_id,
            "bc_customer_no":   cust_no,
            "bc_customer_name": str(o.get("Sell_to_Customer_Name", "")),
            "posting_date":     posting_date,    # → requested_date (real delivery/loading date)
            "shipment_date":    shipment_date,   # retained for display / collision report
            "order_date":       order_date,
            "completely_shipped": completely_shipped,
            "agent_code":       agent_code,
            "ship_to_name":     str(o.get("Ship_to_Name", "") or ""),
            "ship_to_address":  str(o.get("Ship_to_Address", "") or ""),
            "ship_to_city":     str(o.get("Ship_to_City", "") or ""),
            "ship_to_post_code":str(o.get("Ship_to_Post_Code", "") or ""),
            "ship_to_country":  str(o.get("Ship_to_Country_Region_Code", "") or ""),
            "resolved_lines":   resolved_lines,
            # Echo-tag field — 'mt:<id>' when this order was pushed by push_bc_sales_orders.py,
            # empty/None for BC-native orders.  Used by the echo-leg in the reader.
            "your_reference":   str(o.get("Your_Reference", "") or "").strip(),
            "external_document_no": (o.get("External_Document_No") or None) or None,
        })

    return {
        "pull":                   pull,
        "excluded_shopify":       excluded_shopify,
        "excluded_system":        excluded_system,
        "excluded_recency":       excluded_recency,
        "unresolved_customers":   unresolved_custs,
        "unresolved_lines":       unresolved_lines,
    }


# ── Collision detection ────────────────────────────────────────────────────────

def detect_collisions(
    classified: dict,
    existing_non_bc: list[dict],
    identity_map: dict[int, int] | None = None,
) -> list[dict]:
    """
    Find existing ord_orders (web/email/import) that likely represent the same real-world
    order as an incoming BC order.  Two-tier heuristic:

    - Active candidates (status NOT IN ('shipped','cancelled')):
        collide on customer_id_fk + SKU overlap (date NOT required).
        This preserves the existing WeeklyOrders collision-skip behaviour.

    - Shipped candidates (status == 'shipped'):
        collide ONLY when customer_id_fk + SKU overlap + requested_date == BC posting_date.
        The exact date triple is required to avoid flagging a customer's unrelated past orders.

    Cancelled candidates are excluded entirely (already excluded by load_existing_non_bc_active_orders).

    identity_map collapses geographic ship-to accounts (member) to their Cobra distributor
    bill-to canonical account so that BC bill-to orders and import ship-to rows share the
    same collision key.

    Returns a list of collision dicts for the reconciliation report.
    """
    def canon(cid: int | None) -> int | None:
        if cid is None:
            return None
        return (identity_map or {}).get(cid, cid)

    collisions = []

    # Build map: customer_id → list of (bc_no, posting_date, sku_id_set)
    # existing_date (requested_date) = Posting_Date (new primary); compare against
    # bc_ship_date (posting_date) so the date semantics are consistent on both sides.
    bc_by_cust: dict[int, list[dict]] = {}
    for po in classified["pull"]:
        cid = canon(po["customer_id"])
        if cid is None:
            continue
        bc_skus = {ln["sku_id"] for ln in po["resolved_lines"]}
        bc_by_cust.setdefault(cid, []).append({
            "bc_no":           po["bc_no"],
            "posting_date":    po["posting_date"],
            "bc_skus":         bc_skus,
        })

    for eo in existing_non_bc:
        cid = canon(eo["customer_id_fk"])
        if cid is None or cid not in bc_by_cust:
            continue
        eo_is_shipped = (eo["status"] == "shipped")
        for bc_entry in bc_by_cust[cid]:
            overlap = eo["sku_ids"] & bc_entry["bc_skus"]
            if not overlap:
                continue
            # Two-tier date gate: shipped rows require exact date match to avoid
            # flagging a customer's unrelated past shipped orders.
            if eo_is_shipped and eo["requested_date"] != bc_entry["posting_date"]:
                continue
            collisions.append({
                "existing_id":     eo["id"],
                "existing_source": eo["source"],
                "existing_ref":    eo["source_ref"],
                "existing_date":   eo["requested_date"],   # = Posting_Date (primary)
                "existing_status": eo["status"],
                "existing_skus":   sorted(eo["sku_ids"]),
                "bc_no":           bc_entry["bc_no"],
                "bc_ship_date":    bc_entry["posting_date"],   # Posting_Date — same semantic
                "bc_skus":         sorted(bc_entry["bc_skus"]),
                "sku_overlap":     sorted(overlap),
            })

    return collisions


# ── Database write helpers ─────────────────────────────────────────────────────

def echo_leg_upsert(
    conn: pymysql.connections.Connection,
    order: dict,
    apply_mode: bool,
) -> tuple[bool, str | None]:
    """
    Echo-tag leg (Ruling 2, precedence 1 — runs BEFORE the source_ref='bc:<No>' leg).

    If the pulled BC order's Your_Reference parses to 'mt:<id>' AND a local
    ord_orders row with that id (source='maltytask') exists:
      → This IS the match by invariant PK, regardless of the local row's current
        source_ref (handles the race/rekey-failure state where source_ref is still
        'mt:<id>' even though the BC order was already created).
      → In ONE transaction: set bc_no=<No>, rekey source_ref → 'bc:<No>',
        touch updated_at, and refresh header fields that are normally refreshable
        (requested_date).
      → Writes NO status and NO status_events (Ruling 4: never advance/clobber
        operator status).
      → If the local row already has a beyond-refreshable status (≥ 'picked'),
        does key-recovery only (bc_no/source_ref), not a full header refresh.
      → Returns (True, 'echo_matched') so the caller can skip the normal upsert_order
        and upsert_lines paths for this order.

    Returns (False, None) if Your_Reference does not parse to a valid local id,
    or if no matching maltytask row exists.
    """
    your_ref = order.get("your_reference", "")
    local_id = bc_echo_parse(your_ref)
    if local_id is None:
        return False, None

    local_row = load_maltytask_order_by_id(conn, local_id)
    if local_row is None:
        return False, None

    # Match confirmed by invariant PK — this BC order belongs to local_id.
    bc_no  = order["bc_no"]
    bc_ref = f"bc:{bc_no}"

    if not apply_mode:
        # Dry-run: report what WOULD happen
        print(
            f"  [echo-leg] Would rekey local_id={local_id} "
            f"(source_ref={local_row.get('source_ref')!r} → {bc_ref!r}, "
            f"bc_no=None → {bc_no!r}). NO status write."
        )
        return True, "echo_matched"

    current_status = local_row.get("status", "entered")
    key_recovery_only = current_status not in _REFRESH_LINE_STATUSES

    with conn.cursor() as c:
        if key_recovery_only:
            # Beyond-refreshable status: key-recovery only, no date/comment refresh
            c.execute(
                """
                UPDATE ord_orders
                   SET bc_no      = %s,
                       source_ref = %s,
                       updated_at = CURRENT_TIMESTAMP
                 WHERE id     = %s
                   AND source = 'maltytask'
                """,
                (bc_no, bc_ref, local_id),
            )
        else:
            # Refreshable status: recover keys + refresh header commercial fields
            # NO status write, NO status_events (Ruling 4)
            c.execute(
                """
                UPDATE ord_orders
                   SET bc_no              = %s,
                       source_ref         = %s,
                       requested_date     = %s,
                       order_created_date = %s,
                       updated_at         = CURRENT_TIMESTAMP
                 WHERE id     = %s
                   AND source = 'maltytask'
                """,
                (
                    bc_no,
                    bc_ref,
                    order.get("posting_date") or None,
                    order.get("order_date") or None,
                    local_id,
                ),
            )

        rows_updated = c.rowcount

    conn.commit()

    action_label = "key-recovery-only" if key_recovery_only else "rekey+header-refresh"
    print(
        f"  [echo-leg] Rekeyed local_id={local_id}: {action_label}. "
        f"source_ref → {bc_ref!r}, bc_no={bc_no!r}. "
        f"Rows updated: {rows_updated}. NO status written."
    )
    return True, "echo_matched"


def upsert_order(
    conn: pymysql.connections.Connection,
    order: dict,
    existing_bc: dict[str, dict],
    collision_bc_nos: set[str],
    apply_mode: bool,
) -> str:
    """
    INSERT or UPDATE one ord_orders row.
    Returns 'insert', 'update', 'skip', 'skip_collision', or 'skip_backlog'.

    Logic (XOR — first match wins):
    1. If existing bc source_ref AND status IN ('entered','confirmed'): UPDATE header.
    2. If existing bc source_ref AND status >= 'picked': skip (frozen; bl_printed handled
       separately by advance_status()).
    3. New order — collision (same customer + SKU overlap with active non-bc row): skip_collision.
    4. New order — Order_Date < CUTOVER_DATE AND Completely_Shipped=True: skip_backlog.
    5. Otherwise: INSERT (status='entered').
    """
    source_ref = order["source_ref"]
    existing   = existing_bc.get(source_ref)

    if existing is not None:
        # UPDATE — only if status is refreshable
        current_status = existing.get("status", "entered")
        if current_status not in _REFRESH_LINE_STATUSES:
            return "skip"
        if apply_mode:
            with conn.cursor() as c:
                c.execute(
                    """
                    UPDATE ord_orders
                       SET requested_date     = %s,
                           order_created_date = %s,
                           updated_at         = CURRENT_TIMESTAMP
                     WHERE source_ref = %s
                    """,
                    (
                        order["posting_date"] or None,
                        order.get("order_date") or None,
                        source_ref,
                    ),
                )
        return "update"

    # New order — apply standing import rules
    bc_no = order["bc_no"]

    # COLLISION SKIP — operator keeps their in-progress WeeklyOrders row
    if bc_no in collision_bc_nos:
        return "skip_collision"

    # BACKLOG SKIP — pre-cutover + shipped: historical, already in sales ledger
    if order["order_date"] < CUTOVER_DATE and order["completely_shipped"]:
        return "skip_backlog"

    # INSERT
    if apply_mode:
        with conn.cursor() as c:
            c.execute(
                """
                INSERT INTO ord_orders
                    (order_type, customer_id_fk, requested_date, order_created_date,
                     status, source, source_ref, created_by_user_id, comment)
                VALUES ('customer', %s, %s, %s, 'entered', 'bc', %s, NULL, %s)
                """,
                (
                    order["customer_id"],
                    order["posting_date"] or None,
                    order.get("order_date") or None,
                    source_ref,
                    f"BC {order['bc_no']} — auto-ingested from Business Central",
                ),
            )
    return "insert"


def upsert_lines(
    conn: pymysql.connections.Connection,
    order: dict,
    existing_bc: dict[str, dict],
    collision_bc_nos: set[str],
    apply_mode: bool,
) -> int:
    """
    Replace ord_order_lines for an order, but ONLY if status IN ('entered','confirmed').
    Mirrors upsert_order skip logic: returns 0 for collision/backlog/frozen orders.
    Returns count of lines written (or would-write in dry-run).
    """
    source_ref = order["source_ref"]
    existing   = existing_bc.get(source_ref)
    bc_no      = order["bc_no"]

    # Determine order_id
    if existing is not None:
        current_status = existing.get("status", "entered")
        if current_status not in _REFRESH_LINE_STATUSES:
            return 0  # freeze lines
        order_id = existing["id"]
    else:
        # Mirror the standing import skip rules from upsert_order
        if bc_no in collision_bc_nos:
            return 0  # skip_collision — no lines to write
        if order["order_date"] < CUTOVER_DATE and order["completely_shipped"]:
            return 0  # skip_backlog — no lines to write
        if not apply_mode:
            return len(order["resolved_lines"])
        # New INSERT — get the id we just created
        with conn.cursor() as c:
            c.execute("SELECT id FROM ord_orders WHERE source_ref = %s", (source_ref,))
            row = c.fetchone()
            if not row:
                return 0
            order_id = row["id"]

    if not apply_mode:
        return len(order["resolved_lines"])

    # Delete existing lines and re-insert (safe because we freeze above picked).
    # Preserve any operator-set line_status (non_livre/rupture) across the refresh:
    # snapshot existing (sku_id_fk → line_status) before deletion, reapply on INSERT.
    # New BC lines that didn't exist before default to 'to_fulfil' (column default).
    with conn.cursor() as c:
        c.execute(
            "SELECT sku_id_fk, line_status FROM ord_order_lines WHERE order_id_fk = %s",
            (order_id,),
        )
        existing_line_statuses = {
            row["sku_id_fk"]: row["line_status"] for row in c.fetchall()
        }

        c.execute("DELETE FROM ord_order_lines WHERE order_id_fk = %s", (order_id,))
        for ln in order["resolved_lines"]:
            preserved_status = existing_line_statuses.get(ln["sku_id"], "to_fulfil")
            c.execute(
                """
                INSERT INTO ord_order_lines
                    (order_id_fk, sku_id_fk, qty, line_comment, line_status)
                VALUES (%s, %s, %s, %s, %s)
                """,
                (
                    order_id,
                    ln["sku_id"],
                    ln["qty"],
                    f"BC {order['bc_no']} L{ln['line_no']} — {ln['bc_item']} {ln['uom']}",
                    preserved_status,
                ),
            )
    return len(order["resolved_lines"])


def upsert_bc_lines(
    conn: pymysql.connections.Connection,
    order: dict,
    existing_bc: dict[str, dict],
    bc_raw_lines: list[dict],
    direct_map: dict,
    alias_map: dict,
    apply_mode: bool,
    *,
    order_id_override: int | None = None,
    bc_source_ref_override: str | None = None,
) -> int:
    """
    Upsert BC-side line snapshot into ord_order_bc_lines.

    Normal path (source='bc' orders):
      order_id_override and bc_source_ref_override are None;
      order_id comes from existing_bc[order.source_ref], and bc_source_ref = order.source_ref.

    Collision path (kept non-bc row):
      order_id_override = id of the KEPT maltytask row.
      bc_source_ref_override = 'bc:<skippedBcNo>' (the twin's source_ref).
      In this case existing_bc is not consulted for the id.

    Returns the count of lines upserted (or would-upsert in dry-run).
    """
    if order_id_override is not None:
        order_id      = order_id_override
        bc_source_ref = bc_source_ref_override or order["source_ref"]
    else:
        source_ref = order["source_ref"]
        existing   = existing_bc.get(source_ref)
        if existing is None:
            # New order just INSERTed — caller must re-load order_id; handled after commit.
            return 0
        order_id      = existing["id"]
        bc_source_ref = source_ref

    if not apply_mode:
        return len(bc_raw_lines)

    with conn.cursor() as c:
        for ln in bc_raw_lines:
            bc_line_no = int(ln.get("Line_No", 0))
            bc_item    = str(ln.get("No", "")).strip()
            uom        = str(ln.get("Unit_of_Measure_Code", "")).strip()
            try:
                bc_qty = float(ln.get("Quantity", 0))
            except (TypeError, ValueError):
                bc_qty = 0.0

            # Resolve sku_id (same logic as classify_orders, but without skipping)
            sku_id, _, _ = resolve_sku(bc_item, uom, direct_map, alias_map)

            c.execute(
                """
                INSERT INTO ord_order_bc_lines
                    (order_id_fk, bc_source_ref, bc_line_no, bc_item_no, uom_code,
                     bc_qty, resolved_sku_id, snapshot_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, NOW())
                ON DUPLICATE KEY UPDATE
                    bc_item_no       = VALUES(bc_item_no),
                    uom_code         = VALUES(uom_code),
                    bc_qty           = VALUES(bc_qty),
                    resolved_sku_id  = VALUES(resolved_sku_id),
                    snapshot_at      = NOW()
                """,
                (order_id, bc_source_ref, bc_line_no, bc_item, uom, bc_qty, sku_id),
            )
    return len(bc_raw_lines)


def detect_line_divergence(
    conn: pymysql.connections.Connection,
    order: dict,
    existing_bc: dict[str, dict],
    *,
    order_id_override: int | None = None,
    bc_source_ref_override: str | None = None,
) -> tuple[bool, str | None]:
    """
    Diff BC snapshot (ord_order_bc_lines with resolved_sku_id NOT NULL, scoped to
    bc_source_ref) vs operational lines (ord_order_lines) for the given order.

    Normal path: order_id comes from existing_bc[order.source_ref], bc_source_ref = order.source_ref.
    Collision path: order_id_override = kept row id, bc_source_ref_override = skipped BC twin ref.

    Returns (diverged: bool, detail_json: str | None).
    Diverged = True when:
      - A BC sku_id is absent from ord_order_lines
      - An ord_order_lines sku_id is absent from BC snapshot
      - A shared sku_id has different total qty

    Only compares lines with resolved_sku_id (unresolved BC items are excluded
    from the diff — they were never written to ord_order_lines either).
    """
    if order_id_override is not None:
        order_id      = order_id_override
        bc_source_ref = bc_source_ref_override or order["source_ref"]
    else:
        source_ref = order["source_ref"]
        existing   = existing_bc.get(source_ref)
        if existing is None:
            return False, None
        order_id      = existing["id"]
        bc_source_ref = source_ref

    with conn.cursor() as c:
        # BC snapshot totals (resolved SKUs only; scoped to this bc_source_ref;
        # sum in case multiple bc_line_no → same sku)
        c.execute(
            """
            SELECT resolved_sku_id, SUM(bc_qty) AS total_qty
              FROM ord_order_bc_lines
             WHERE order_id_fk = %s AND bc_source_ref = %s
               AND resolved_sku_id IS NOT NULL
             GROUP BY resolved_sku_id
            """,
            (order_id, bc_source_ref),
        )
        bc_totals: dict[int, float] = {
            int(r["resolved_sku_id"]): float(r["total_qty"])
            for r in c.fetchall()
        }

        # Operational lines totals
        c.execute(
            """
            SELECT sku_id_fk, SUM(qty) AS total_qty
              FROM ord_order_lines
             WHERE order_id_fk = %s
             GROUP BY sku_id_fk
            """,
            (order_id,),
        )
        op_totals: dict[int, float] = {
            int(r["sku_id_fk"]): float(r["total_qty"])
            for r in c.fetchall()
        }

    if not bc_totals and not op_totals:
        return False, None

    all_skus = set(bc_totals.keys()) | set(op_totals.keys())
    diffs = []
    for sku_id in sorted(all_skus):
        bc_qty = bc_totals.get(sku_id)
        op_qty = op_totals.get(sku_id)
        if bc_qty is None:
            diffs.append({"sku_id": sku_id, "bc_qty": None, "op_qty": op_qty, "delta": op_qty})
        elif op_qty is None:
            diffs.append({"sku_id": sku_id, "bc_qty": bc_qty, "op_qty": None, "delta": -bc_qty})
        elif abs(bc_qty - op_qty) > 0.001:
            diffs.append({"sku_id": sku_id, "bc_qty": bc_qty, "op_qty": op_qty, "delta": op_qty - bc_qty})

    if not diffs:
        return False, None

    detail = json.dumps({"bc_order_no": order["bc_no"], "line_diffs": diffs}, default=str)
    return True, detail


def apply_divergence_flag(
    conn: pymysql.connections.Connection,
    order: dict,
    existing_bc: dict[str, dict],
    diverged: bool,
    detail_json: str | None,
    apply_mode: bool,
    *,
    order_id_override: int | None = None,
    current_div_status_override: str | None = None,
    rq_dedup_suffix: str | None = None,
) -> bool:
    """
    Write divergence_status + divergence_detail to ord_orders.
    Also emits a doc_review_queue row (dedup per order) when diverged=True.
    Clears the flag when diverged=False (re-alignment detected).
    Returns True if the flag changed.

    Normal path (source='bc' orders): order_id comes from existing_bc[order.source_ref].
    Collision path (kept non-bc row):
      order_id_override = kept row id.
      current_div_status_override = current divergence_status of the kept row.
      rq_dedup_suffix = 'bc:<skippedBcNo>' so the RQ dedup key is per (kept_row, bc_twin).
    """
    if order_id_override is not None:
        order_id           = order_id_override
        current_div_status = (current_div_status_override or "none")
    else:
        source_ref = order["source_ref"]
        existing   = existing_bc.get(source_ref)
        if existing is None:
            return False
        order_id           = existing["id"]
        current_div_status = existing.get("divergence_status", "none") or "none"

    new_status = "correction_compta_requise" if diverged else "none"

    if new_status == current_div_status:
        return False  # no change

    if not apply_mode:
        return True

    with conn.cursor() as c:
        c.execute(
            """
            UPDATE ord_orders
               SET divergence_status = %s,
                   divergence_detail = %s,
                   updated_at = CURRENT_TIMESTAMP
             WHERE id = %s
            """,
            (new_status, detail_json, order_id),
        )

        if diverged:
            # Dedup key: 'bc-order:<order_id>' for normal bc orders;
            # 'bc-order:<order_id>:<bc_twin_ref>' for collision doubles so each
            # (kept_row, bc_twin) pair gets its own RQ slot.
            suffix    = f":{rq_dedup_suffix}" if rq_dedup_suffix else ""
            dedup_key = f"bc-order:{order_id}{suffix}"
            queue_id  = f"bc-ord-corr-{order_id}{suffix.replace(':', '-')}"
            rq_value  = f"BC {order['bc_no']} → divergence lignes (correction compta requise)"
            rq_context = detail_json or ""
            c.execute(
                """
                INSERT INTO doc_review_queue
                    (queue_id, type, value, context, dedup_key, status, decision, last_seen_at)
                VALUES (%s, 'bc-order-correction-required', %s, %s, %s, 'open', 'pending', CURDATE())
                ON DUPLICATE KEY UPDATE
                    value        = VALUES(value),
                    context      = VALUES(context),
                    last_seen_at = CURDATE(),
                    status       = 'open',
                    decision     = 'pending',
                    count_obs    = count_obs + 1,
                    updated_at   = CURRENT_TIMESTAMP
                """,
                (queue_id, rq_value, rq_context, dedup_key),
            )
        else:
            # Re-alignment: auto-resolve the open RQ row for this (order, twin) pair
            suffix    = f":{rq_dedup_suffix}" if rq_dedup_suffix else ""
            dedup_key = f"bc-order:{order_id}{suffix}"
            c.execute(
                """
                UPDATE doc_review_queue
                   SET status    = 'resolved',
                       decision  = 'auto-resolved',
                       decided_at = NOW()
                 WHERE dedup_key = %s
                   AND type      = 'bc-order-correction-required'
                   AND status    = 'open'
                """,
                (dedup_key,),
            )

    return True


def write_bc_mirror_fields(
    conn: pymysql.connections.Connection,
    order: dict,
    existing_bc: dict[str, dict],
    apply_mode: bool,
) -> None:
    """
    Write BC-mirror fields that are ALWAYS allowed regardless of operational status:
      - bc_completely_shipped (TINYINT mirror of BC Completely_Shipped)
      - external_document_no  (VARCHAR mirror of BC External_Document_No)
    These are BC-mirror fields, not operational status fields.
    Called for every bc-sourced order on every pull.
    """
    source_ref = order["source_ref"]
    existing   = existing_bc.get(source_ref)
    if existing is None:
        return

    order_id           = existing["id"]
    completely_shipped = 1 if order["completely_shipped"] else 0
    external_document_no = order.get("external_document_no")

    if not apply_mode:
        return

    with conn.cursor() as c:
        c.execute(
            """
            UPDATE ord_orders
               SET bc_completely_shipped  = %s,
                   external_document_no   = %s,
                   updated_at             = CURRENT_TIMESTAMP
             WHERE id = %s
            """,
            (completely_shipped, external_document_no, order_id),
        )


# ── Collision-snapshot helper ─────────────────────────────────────────────────

def snapshot_and_diff_collision(
    conn: pymysql.connections.Connection,
    bc_order: dict,
    kept_row_id: int,
    kept_div_status: str,
    lines_by_order: dict[str, list[dict]],
    direct_map: dict,
    alias_map: dict,
    apply_mode: bool,
) -> tuple[int, bool]:
    """
    For a collision-skipped BC order, snapshot its lines against the KEPT non-bc
    maltytask row (kept_row_id) and diff them.

    - Snapshots into ord_order_bc_lines with order_id_fk=kept_row_id and
      bc_source_ref='bc:<bc_no>' (the twin's ref).
    - Diffs the snapshot against the kept row's ord_order_lines.
    - If divergent: sets divergence_status='correction_compta_requise' on the kept row
      + emits RQ deduped by (kept_row_id, bc_source_ref).
    - Does NOT insert/update ord_orders for the BC order (collision skip still applies).
    - Does NOT touch operational lines (ord_order_lines) — read-only reference only.

    Returns (n_snapshot_lines, diverged).
    """
    bc_no         = bc_order["bc_no"]
    bc_source_ref = bc_order["source_ref"]  # 'bc:<bc_no>'

    bc_raw_lines = lines_by_order.get(bc_no, [])
    bc_item_lines = [
        ln for ln in bc_raw_lines
        if str(ln.get("Type", "")).strip() == "Item"
        and float(ln.get("Quantity", 0) or 0) > 0
    ]

    if not bc_item_lines:
        return 0, False

    # Snapshot the BC twin's lines against the kept row
    n_snap = upsert_bc_lines(
        conn, bc_order, {},  # existing_bc not used (override path)
        bc_item_lines, direct_map, alias_map, apply_mode,
        order_id_override=kept_row_id,
        bc_source_ref_override=bc_source_ref,
    )

    # Diff: kept row's operational lines vs BC twin's snapshot
    diverged, detail_json = detect_line_divergence(
        conn, bc_order, {},
        order_id_override=kept_row_id,
        bc_source_ref_override=bc_source_ref,
    )

    # Embed the BC twin ref in divergence_detail so admin knows which BC order to credit-note
    if diverged and detail_json:
        detail_obj = json.loads(detail_json)
        detail_obj["bc_twin_source_ref"] = bc_source_ref
        detail_json = json.dumps(detail_obj, default=str)

    apply_divergence_flag(
        conn, bc_order, {},
        diverged, detail_json, apply_mode,
        order_id_override=kept_row_id,
        current_div_status_override=kept_div_status,
        rq_dedup_suffix=bc_source_ref,
    )

    return n_snap, diverged


# ── Review CSV writer ─────────────────────────────────────────────────────────

def _write_review_csv(path: Path, rows: list[dict], fieldnames: list[str]) -> None:
    buf = io.StringIO()
    writer = csv.DictWriter(buf, fieldnames=fieldnames, extrasaction="ignore")
    writer.writeheader()
    writer.writerows(rows)
    path.write_text(buf.getvalue(), encoding="utf-8")


# ── Reconciliation report ─────────────────────────────────────────────────────

def print_reconciliation_report(
    classified:           dict,
    existing_bc:          dict[str, dict],
    collisions:           list[dict],
    would_insert:         int,
    would_update:         int,
    would_skip:           int,
    would_skip_collision: int,
    would_skip_backlog:   int,
    insert_candidates:    list[dict],
    bc_shipped_signals:   list[str],
    bl_advances:          list[str],
    divergences_flagged:  list[str],
    divergences_cleared:  list[str],
    bc_snapshot_count:    int,
    review_csv_path:      Path,
    cust_review_csv_path: Path,
) -> None:
    pull          = classified["pull"]
    unres_lines   = classified["unresolved_lines"]
    unres_custs   = classified["unresolved_customers"]
    excluded_rec  = classified.get("excluded_recency", [])

    all_bc_count = (
        len(pull)
        + len(classified["excluded_shopify"])
        + len(classified["excluded_system"])
        + len(excluded_rec)
        + len(unres_custs)
    )

    total_items  = sum(1 for u in unres_lines if u["reason"] == "unresolved-sku")
    total_nonbeer= sum(1 for u in unres_lines if u["reason"] != "unresolved-sku")
    total_lines_resolved = sum(len(o["resolved_lines"]) for o in pull)
    total_lines_attempted = total_lines_resolved + total_items

    cust_resolved    = len(pull)
    cust_unresolved  = len(unres_custs)
    cust_total       = cust_resolved + cust_unresolved
    cust_pct = round(100.0 * cust_resolved / cust_total, 1) if cust_total else 0.0
    sku_pct  = round(100.0 * total_lines_resolved / total_lines_attempted, 1) if total_lines_attempted else 0.0

    print()
    print("=" * 72)
    print("  RECONCILIATION REPORT — BC Sales Orders (DRY-RUN)")
    print("=" * 72)
    print(f"  Script version : {SCRIPT_VERSION}")
    print(f"  Timestamp      : {datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S UTC')}")
    print(f"  CUTOVER_DATE   : {CUTOVER_DATE}  |  RECENCY_FLOOR: {RECENCY_FLOOR}")
    print()
    print("── PULL SUMMARY ─────────────────────────────────────────────────────")
    print(f"  BC open orders total           : {all_bc_count}")
    print(f"  Excluded — Shopify             : {len(classified['excluded_shopify'])}")
    print(f"  Excluded — system cust (1080/3822): {len(classified['excluded_system'])}")
    print(f"  Excluded — recency floor (<2026): {len(excluded_rec)}")
    if excluded_rec:
        for r in excluded_rec:
            print(f"    {r['no']} cust={r['cust_no']} order_date={r['order_date']}")
    print(f"  Excluded — cust unresolved     : {cust_unresolved}")
    print(f"  Orders reaching upsert logic   : {len(pull)}")
    print()
    print("── ORDER UPSERT COUNTS (standing import rule) ──────────────────────")
    print(f"  Would INSERT (new active)                : {would_insert}")
    print(f"  Would UPDATE (existing bc, refreshable)  : {would_update}")
    print(f"  Would SKIP (existing bc, frozen ≥picked) : {would_skip}")
    print(f"  Would SKIP-COLLISION (non-bc row kept)   : {would_skip_collision}")
    print(f"  Would SKIP-BACKLOG (pre-cutover+shipped) : {would_skip_backlog}")
    print()
    print("── INSERT CANDIDATES (orders that WOULD be created) ─────────────────")
    if insert_candidates:
        for ic in insert_candidates:
            shipped_flag = " [BC:Completely_Shipped=True → bc_completely_shipped=1 signal]" if ic["completely_shipped"] else ""
            print(f"  {ic['bc_no']}  cust={ic['customer_name']!r}  "
                  f"ship={ic['shipment_date']}  order_date={ic['order_date']}  "
                  f"lines={ic['n_lines']}  hl={ic['hl_total']:.2f}{shipped_flag}")
    else:
        print("  (none)")
    print()
    print("── BC MIRROR SIGNALS (Ruling-4 compliant — ZERO operational status writes) ─")
    print(f"  bc_completely_shipped signals    : {len(bc_shipped_signals)}")
    if bc_shipped_signals:
        for s in bc_shipped_signals:
            print(f"    {s}")
    print(f"  BC line snapshots (ord_order_bc_lines) : {bc_snapshot_count} line(s)")
    print()
    print("── AUTO STATUS ADVANCES (Completely_Shipped=True → bl_printed) ─────")
    print(f"  Orders advanced to bl_printed : {len(bl_advances)}")
    if bl_advances:
        for s in bl_advances:
            print(f"    {s}")
    else:
        print("  (none)")
    print()
    print("── DIVERGENCE DETECTION ─────────────────────────────────────────────")
    print(f"  Divergences flagged (correction_compta_requise): {len(divergences_flagged)}")
    if divergences_flagged:
        for d in divergences_flagged:
            print(f"    {d}")
    print(f"  Divergences cleared (re-aligned)                : {len(divergences_cleared)}")
    if divergences_cleared:
        for d in divergences_cleared:
            print(f"    {d}")
    print()
    print("── CUSTOMER RESOLUTION ──────────────────────────────────────────────")
    print(f"  Resolved   : {cust_resolved}/{cust_total} ({cust_pct}%)")
    if unres_custs:
        print(f"  Unresolved : {cust_unresolved}")
        for u in unres_custs:
            print(f"    bc_no={u['bc_customer_no']}, name={u['bc_customer_name']!r}, order={u['bc_order_no']}")
        print(f"  → Review CSV: {cust_review_csv_path}")
    print()
    print("── SKU RESOLUTION ───────────────────────────────────────────────────")
    print(f"  Resolved beer lines : {total_lines_resolved}/{total_lines_attempted} ({sku_pct}%)")
    print(f"  Non-beer items skip : {total_nonbeer} (deposits/CO2/glassware — expected)")
    if total_items:
        print(f"  Unresolved beer SKUs: {total_items}")
        seen = set()
        for u in unres_lines:
            if u["reason"] != "unresolved-sku":
                continue
            k = (u["bc_item"], u["uom"])
            if k in seen:
                continue
            seen.add(k)
            print(f"    item={u['bc_item']!r}, uom={u['uom']!r}, desc={u['description']!r}")
        print(f"  → Review CSV: {review_csv_path}")
    print()
    print("── COLLISION DETAIL (WeeklyOrders rows kept; BC orders SKIPPED + snapshotted) ─")
    print(f"  Collisions auto-skipped: {would_skip_collision}")
    print(f"  (Each collision-skipped BC order is snapshotted against its kept row")
    print(f"   and diffed — divergences appear in DIVERGENCE DETECTION above)")
    if collisions:
        for c in collisions:
            print()
            print(f"  SKIP-COLLISION: BC {c['bc_no']} (ship={c['bc_ship_date']})")
            print(f"    → kept row: ord_orders.id={c['existing_id']} "
                  f"(source={c['existing_source']}, ref={c['existing_ref']})")
            print(f"      status={c['existing_status']}, date={c['existing_date']}, "
                  f"sku_ids={c['existing_skus']}")
            print(f"    Overlapping sku_ids: {c['sku_overlap']}")
    else:
        print("  (none)")
    print()
    print("── NON-BEER LINES (informational) ──────────────────────────────────")
    nonbeer = [u for u in unres_lines if u["reason"] != "unresolved-sku"]
    if nonbeer:
        seen_nb = set()
        for u in nonbeer:
            k = (u["bc_item"], u["uom"])
            if k in seen_nb: continue
            seen_nb.add(k)
            print(f"  item={u['bc_item']!r}, uom={u['uom']!r}, "
                  f"desc={u['description']!r} — skipped (non-beer)")
    else:
        print("  None")
    print()
    print("── RECOMMENDATION ──────────────────────────────────────────────────")
    if cust_unresolved == 0 and total_items == 0:
        print("  Safe to --apply. All customers + SKUs resolved.")
        print(f"  Collisions ({would_skip_collision}) and backlog ({would_skip_backlog}) "
              f"handled deterministically by standing import rule.")
    else:
        if cust_unresolved:
            print(f"  HUMAN REVIEW REQUIRED: {cust_unresolved} unresolved customer(s).")
        if total_items:
            print(f"  HUMAN REVIEW REQUIRED: {total_items} unresolved SKU line(s).")
        print("  Resolve the above, then re-run --dry-run, then --apply.")
    print("=" * 72)
    print()


# ── Backfill: requested_date ← Posting_Date  +  order_created_date ← Order_Date ──

def backfill_dates(apply_mode: bool) -> None:
    """
    One-shot: fetch all currently-open BC SalesOrders and UPDATE ord_orders for any
    source='bc' row where requested_date or order_created_date diverges from BC truth.

    Sources of truth (single BC fetch — no double round-trip):
      requested_date     ← BC Posting_Date (date de comptabilisation = jour de chargement effectif ; fallback Document_Date → Shipment_Date)
      order_created_date ← BC Order_Date    (when the order was placed)

    Orders no longer in the BC open endpoint (invoiced/closed) cannot be fetched —
    they are reported as skipped.

    --dry-run (apply_mode=False): prints proposed diffs, no writes.
    --apply   (apply_mode=True):  applies UPDATEs.
    """
    mode_label = "** APPLY **" if apply_mode else "DRY-RUN"
    print(f"\nBC Dates Backfill (requested_date + order_created_date) — v{SCRIPT_VERSION}")
    print(f"Mode: {mode_label}")
    print()

    print("[1/3] Loading BC credentials + fetching open SalesOrders …")
    bc = _load_bc_env()
    bc_orders = fetch_sales_orders(bc)

    # Build {bc_no: (posting_date, order_date)} from BC — open orders only
    bc_dates: dict[str, tuple[str, str]] = {}
    for o in bc_orders:
        no = str(o.get("No", "")).strip()
        if not no:
            continue
        shipment_date = str(o.get("Shipment_Date", "") or "").strip()
        posting_date  = (
            str(o.get("Posting_Date", "") or "").strip()
            or str(o.get("Document_Date", "") or "").strip()
            or shipment_date
        )
        order_date    = str(o.get("Order_Date", "") or "").strip()
        bc_dates[no] = (posting_date, order_date)

    print(f"  BC open orders fetched: {len(bc_dates)}")

    print("[2/3] Loading ord_orders (source='bc') from DB …")
    cfg  = load_config()
    conn = pymysql.connect(
        host=cfg.db_host, port=cfg.db_port,
        user=cfg.db_user, password=cfg.db_password,
        database=cfg.db_name,
        charset="utf8mb4", cursorclass=DictCursor, autocommit=False,
    )
    try:
        with conn.cursor() as c:
            c.execute(
                "SELECT id, source_ref, requested_date, order_created_date "
                "FROM ord_orders WHERE source = 'bc'"
            )
            db_rows = c.fetchall()

        # Parse bc_no from source_ref = 'bc:<No>'
        updates_needed: list[dict] = []
        skipped_gone: list[str] = []

        for row in db_rows:
            source_ref = str(row["source_ref"] or "")
            if not source_ref.startswith("bc:"):
                continue
            bc_no = source_ref[3:]
            if bc_no not in bc_dates:
                # Order no longer in BC open endpoint (invoiced/gone) — skip
                skipped_gone.append(bc_no)
                continue
            bc_req_date, bc_created_date = bc_dates[bc_no]

            old_req     = str(row["requested_date"])     if row["requested_date"]     else ""
            old_created = str(row["order_created_date"]) if row["order_created_date"] else ""

            req_changed     = bc_req_date     and bc_req_date     != old_req
            created_changed = bc_created_date and bc_created_date != old_created

            if not req_changed and not created_changed:
                continue  # already correct — no update needed

            updates_needed.append({
                "id":              row["id"],
                "source_ref":      source_ref,
                "bc_no":           bc_no,
                # requested_date
                "old_req":         old_req,
                "new_req":         bc_req_date if bc_req_date else None,
                "req_changed":     req_changed,
                # order_created_date
                "old_created":     old_created,
                "new_created":     bc_created_date if bc_created_date else None,
                "created_changed": created_changed,
            })

        print(f"  DB bc-rows total      : {len(db_rows)}")
        print(f"  Updates needed        : {len(updates_needed)}")
        print(f"  Skipped (gone from BC): {len(skipped_gone)}")
        print()

        if updates_needed:
            print("── PROPOSED DIFFS ───────────────────────────────────────────────────")
            for u in updates_needed:
                parts = []
                if u["req_changed"]:
                    parts.append(
                        f"requested_date: {u['old_req'] or 'NULL'} → {u['new_req']}"
                    )
                if u["created_changed"]:
                    parts.append(
                        f"order_created_date: {u['old_created'] or 'NULL'} → {u['new_created']}"
                    )
                print(f"  {u['bc_no']}  (ord_orders.id={u['id']})  " + "  |  ".join(parts))
            print()

        print(f"[3/3] {'Applying' if apply_mode else 'Simulating'} UPDATEs …")
        updated_count = 0
        if apply_mode and updates_needed:
            with conn.cursor() as c:
                for u in updates_needed:
                    c.execute(
                        """
                        UPDATE ord_orders
                           SET requested_date     = %s,
                               order_created_date = %s,
                               updated_at         = CURRENT_TIMESTAMP
                         WHERE id = %s AND source = 'bc'
                        """,
                        (u["new_req"], u["new_created"], u["id"]),
                    )
                    updated_count += c.rowcount
            conn.commit()
            print(f"  ✓ Updated {updated_count} row(s).")
        elif not apply_mode:
            print(f"  DRY-RUN: would update {len(updates_needed)} row(s). Re-run with --apply to write.")
        else:
            print("  Nothing to update.")

        if skipped_gone:
            print(f"\n  Skipped (no longer in BC open endpoint — invoiced/closed): "
                  f"{len(skipped_gone)} order(s)")
            for s in skipped_gone:
                print(f"    {s}")

    except Exception as exc:
        conn.rollback()
        print(f"\nERROR: {exc}", file=sys.stderr)
        raise
    finally:
        conn.close()

    print()
    print("Done.")


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(
        description="BC open sales orders → ord_orders / ord_order_lines (Phase 1).",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "--apply", action="store_true",
        help="Write to database (default: dry-run, no writes).",
    )
    parser.add_argument(
        "--limit", type=int, default=0,
        help="Process at most N orders from the pull list (0 = all).",
    )
    parser.add_argument(
        "--backfill-dates", action="store_true",
        help=(
            "One-shot backfill: fetch open BC orders, update ord_orders.requested_date "
            "← Posting_Date AND order_created_date ← Order_Date for all source='bc' rows. "
            "Use --apply to write; default is dry-run."
        ),
    )
    # Legacy alias kept for backward compatibility
    parser.add_argument(
        "--backfill-requested-date", action="store_true",
        help=argparse.SUPPRESS,  # hidden — use --backfill-dates instead
    )
    args = parser.parse_args()

    # ── Backfill mode (early exit — separate code path) ──────────────────────
    if args.backfill_dates or args.backfill_requested_date:
        backfill_dates(apply_mode=args.apply)
        return

    apply_mode = args.apply
    limit      = args.limit

    mode_label = "** APPLY **" if apply_mode else "DRY-RUN"
    print(f"\nBC Sales Orders Ingest — v{SCRIPT_VERSION}")
    print(f"Mode: {mode_label}")
    print()

    # ── Load BC credentials + token ───────────────────────────────────────────
    print("[1/6] Loading BC credentials …")
    bc = _load_bc_env()

    # ── Fetch BC data ─────────────────────────────────────────────────────────
    print("[2/6] Fetching BC SalesOrder + SalesOrderSalesLines …")
    bc_orders = fetch_sales_orders(bc)
    bc_lines  = fetch_sales_lines(bc)

    # Group lines by Document_No
    lines_by_order: dict[str, list[dict]] = {}
    for ln in bc_lines:
        doc_no = str(ln.get("Document_No", "")).strip()
        lines_by_order.setdefault(doc_no, []).append(ln)

    # ── Load DB reference data ────────────────────────────────────────────────
    print("[3/6] Loading reference data from DB …")
    cfg  = load_config()
    conn = pymysql.connect(
        host=cfg.db_host, port=cfg.db_port,
        user=cfg.db_user, password=cfg.db_password,
        database=cfg.db_name,
        charset="utf8mb4", cursorclass=DictCursor, autocommit=False,
    )
    try:
        customers   = load_customers(conn)
        direct_map, alias_map = load_skus(conn)
        transporters = load_transporters(conn)
        existing_bc  = load_existing_bc_orders(conn)
        existing_non_bc = load_existing_non_bc_active_orders(conn)
        identity_map = load_customer_identity_map(conn)
        print(f"  Customer identity map loaded: {len(identity_map)} member→canonical links")

        print(f"  Customers loaded   : {len(customers)}")
        print(f"  SKUs loaded        : {len(direct_map)} direct + {len(alias_map)} aliases")
        print(f"  Transporters       : {len(transporters)}")
        print(f"  Existing BC orders : {len(existing_bc)}")
        print(f"  Non-BC active ords : {len(existing_non_bc)}")

        # ── Classify orders ───────────────────────────────────────────────────
        print("[4/6] Classifying orders …")
        classified = classify_orders(
            bc_orders, customers, direct_map, alias_map, lines_by_order
        )

        pull = classified["pull"]
        if limit > 0:
            pull = pull[:limit]
            print(f"  --limit {limit}: processing {len(pull)} orders only")

        # ── Collision detection ───────────────────────────────────────────────
        print("[5/6] Detecting collisions …")
        collisions = detect_collisions(classified, existing_non_bc, identity_map=identity_map)

        # Build collision_bc_nos set — the deterministic SKIP set for the import loop
        collision_bc_nos: set[str] = {c["bc_no"] for c in collisions}
        if collision_bc_nos:
            print(f"  Collision BC order nos ({len(collision_bc_nos)}): "
                  f"{sorted(collision_bc_nos)}")

        # Build collision_kept_row map: bc_no → {id, divergence_status} of the KEPT non-bc row
        # Used to snapshot BC twin lines against the kept row and diff.
        non_bc_by_id = {eo["id"]: eo for eo in existing_non_bc}
        collision_kept_row: dict[str, dict] = {}
        for col in collisions:
            kept_id = col["existing_id"]
            kept_eo = non_bc_by_id.get(kept_id)
            if kept_eo:
                collision_kept_row[col["bc_no"]] = {
                    "id":               kept_id,
                    "divergence_status": kept_eo.get("divergence_status", "none") or "none",
                }

        # ── Build reconciliation counters and (optionally) write ──────────────
        print("[6/6] Upserting / simulating …")
        would_insert          = 0
        would_update          = 0
        would_skip            = 0
        would_skip_collision  = 0
        would_skip_backlog    = 0
        insert_candidates: list[dict] = []
        # BC mirror signals (informational — never operational status)
        bc_shipped_signals: list[str]  = []
        # Auto bl_printed advances (BC Completely_Shipped → status advance)
        bl_advances: list[str] = []
        # Divergence tracking
        divergences_flagged:   list[str] = []
        divergences_cleared:   list[str] = []
        bc_snapshot_count = 0

        for order in pull:
            bc_no      = order["bc_no"]
            source_ref = order["source_ref"]

            # ── Ruling 2: echo-tag leg FIRST ──────────────────────────────────
            # If Your_Reference = 'mt:<id>' AND a local maltytask row id=<id> exists:
            #   → match by invariant PK; rekey bc_no + source_ref; NO status write.
            #   → skip normal upsert_order + upsert_lines (the row is now 'bc' keyed).
            # This prevents a duplicate INSERT even when the writer's own rekey failed
            # (race/rekey-failure state: source_ref='mt:<id>' not yet 'bc:<No>').
            echo_matched, _echo_action = echo_leg_upsert(conn, order, apply_mode)
            if echo_matched:
                # After echo-leg rekey, the row is now visible in the 'bc' namespace.
                # Update our in-memory existing_bc map so downstream steps (mirror
                # fields, divergence diff) can find it by the new source_ref.
                # Re-load from DB on apply; on dry-run build a synthetic entry.
                if apply_mode:
                    with conn.cursor() as c:
                        c.execute(
                            "SELECT id, source_ref, status, customer_id_fk, "
                            "       requested_date, bc_completely_shipped, divergence_status "
                            "FROM ord_orders WHERE source_ref = %s",
                            (source_ref,),
                        )
                        refreshed = c.fetchone()
                        if refreshed:
                            existing_bc[source_ref] = dict(refreshed)
                # Count as 'update' for reporting
                would_update += 1
                # Still run mirror-fields + divergence diff below (no lines refresh
                # since operator may already have set line statuses post-echo-match)
                action = "update"
                # Skip normal upsert_order + upsert_lines paths
                # (fall through to the mirror/divergence block below)
            else:
                action = upsert_order(conn, order, existing_bc, collision_bc_nos, apply_mode)
                # Count action only when not handled by echo-leg (echo-leg already
                # incremented would_update above and set action='update')
                if action == "insert":
                    would_insert += 1
                    hl_total = sum(ln["hl"] or 0.0 for ln in order["resolved_lines"])
                    insert_candidates.append({
                        "bc_no":              bc_no,
                        "customer_name":      order["bc_customer_name"],
                        "shipment_date":      order["shipment_date"],
                        "order_date":         order["order_date"],
                        "completely_shipped": order["completely_shipped"],
                        "n_lines":            len(order["resolved_lines"]),
                        "hl_total":           round(hl_total, 2),
                    })
                elif action == "update":
                    would_update += 1
                elif action == "skip_collision":
                    would_skip_collision += 1
                elif action == "skip_backlog":
                    would_skip_backlog += 1
                else:
                    would_skip += 1

            # Skip line upsert for echo-matched orders (operator already manages lines)
            if not echo_matched:
                upsert_lines(conn, order, existing_bc, collision_bc_nos, apply_mode)

            # ── Ruling-4 COMPLIANT block (no status writes): ──────────────────
            # For existing bc-sourced orders (not skipped), write BC mirror fields
            # and run the divergence diff.  New inserts are excluded from divergence
            # diff on the same pull (no ord_order_lines exist yet).
            #
            # For collision-skipped BC orders: snapshot the BC twin's lines against
            # the KEPT non-bc row and diff — so transitional doubles get flagged.
            if action == "skip_collision":
                kept = collision_kept_row.get(bc_no)
                if kept:
                    n_snap, diverged = snapshot_and_diff_collision(
                        conn, order,
                        kept_row_id     = kept["id"],
                        kept_div_status = kept["divergence_status"],
                        lines_by_order  = lines_by_order,
                        direct_map      = direct_map,
                        alias_map       = alias_map,
                        apply_mode      = apply_mode,
                    )
                    bc_snapshot_count += n_snap
                    if diverged:
                        divergences_flagged.append(
                            f"{bc_no} [collision-twin→kept id={kept['id']}]: "
                            f"correction_compta_requise"
                        )

            elif action not in ("skip_backlog",):
                # Non-skipped bc orders: mirror fields + snapshot + divergence diff

                # BC-mirror field: bc_completely_shipped (allowed, per ruling-4)
                write_bc_mirror_fields(conn, order, existing_bc, apply_mode)
                if order["completely_shipped"]:
                    bc_shipped_signals.append(
                        f"{bc_no}: BC Completely_Shipped=True → bc_completely_shipped=1 (signal only)"
                    )

                # Auto status advance: Completely_Shipped=True → bl_printed.
                # Skip brand-new inserts on this pull (no existing_bc entry yet — the
                # advance fires on the next pull); mirrors the divergence block's
                # `action != "insert"` gate below. advance_status also self-guards via
                # `existing is None`, so this is belt-and-suspenders.
                if action != "insert":
                    advance_summary = advance_status(conn, order, existing_bc, apply_mode)
                    if advance_summary is not None:
                        bl_advances.append(advance_summary)

                # BC line snapshot (for existing rows; new inserts need a second pass)
                if action != "insert":
                    bc_raw_lines = lines_by_order.get(bc_no, [])
                    # Only Item-type lines with qty > 0
                    bc_item_lines = [
                        ln for ln in bc_raw_lines
                        if str(ln.get("Type", "")).strip() == "Item"
                        and float(ln.get("Quantity", 0) or 0) > 0
                    ]
                    n_snap = upsert_bc_lines(
                        conn, order, existing_bc, bc_item_lines,
                        direct_map, alias_map, apply_mode,
                    )
                    bc_snapshot_count += n_snap

                    # Divergence detection (only after snapshot is committed for apply_mode,
                    # or against existing snapshot for dry-run)
                    diverged, detail = detect_line_divergence(conn, order, existing_bc)
                    changed = apply_divergence_flag(
                        conn, order, existing_bc, diverged, detail, apply_mode
                    )
                    if changed:
                        if diverged:
                            divergences_flagged.append(
                                f"{bc_no} (ord_orders.id={existing_bc[source_ref]['id']}): "
                                f"correction_compta_requise"
                            )
                        else:
                            divergences_cleared.append(
                                f"{bc_no}: re-aligned, flag cleared"
                            )

        if apply_mode:
            conn.commit()
            # After commit, process NEW inserts for BC line snapshot + divergence
            # (we need the order_id from the committed row)
            if would_insert > 0:
                # Reload existing_bc to get newly inserted rows' IDs
                existing_bc_updated = load_existing_bc_orders(conn)
                for order in pull:
                    source_ref = order["source_ref"]
                    if source_ref not in existing_bc and source_ref in existing_bc_updated:
                        # This was a new insert — now snapshot its BC lines
                        # Temporarily inject into existing_bc so helpers can find the id
                        existing_bc[source_ref] = existing_bc_updated[source_ref]
                        bc_raw_lines = lines_by_order.get(order["bc_no"], [])
                        bc_item_lines = [
                            ln for ln in bc_raw_lines
                            if str(ln.get("Type", "")).strip() == "Item"
                            and float(ln.get("Quantity", 0) or 0) > 0
                        ]
                        n_snap = upsert_bc_lines(
                            conn, order, existing_bc, bc_item_lines,
                            direct_map, alias_map, apply_mode,
                        )
                        bc_snapshot_count += n_snap
                conn.commit()

            print(f"  Committed: {would_insert} inserted, {would_update} updated, "
                  f"{would_skip} skipped, {would_skip_collision} skip-collision, "
                  f"{would_skip_backlog} skip-backlog")
            print(f"  BC snapshots written: {bc_snapshot_count} line(s)")
            print(f"  Divergences flagged : {len(divergences_flagged)}")
            print(f"  Divergences cleared : {len(divergences_cleared)}")
            print(f"  BC shipped signals  : {len(bc_shipped_signals)} (mirror only, no status write)")
            print(f"  bl_printed advances : {len(bl_advances)}")
        else:
            conn.rollback()

        # ── Write review CSVs ─────────────────────────────────────────────────
        # Prefer /var/www/maltytask/data/ when writable; dry-run as maltytask
        # may not have write access there (www-data-owned), so fall back to /tmp/.
        _data_dir = Path("/var/www/maltytask/data")
        csv_dir = _data_dir if _data_dir.exists() and os.access(_data_dir, os.W_OK) else Path("/tmp")
        review_csv_path      = csv_dir / "bc-orders-unresolved-skus.csv"
        cust_review_csv_path = csv_dir / "bc-orders-unresolved-customers.csv"

        if classified["unresolved_lines"]:
            _write_review_csv(
                review_csv_path,
                classified["unresolved_lines"],
                ["bc_order_no", "bc_item", "uom", "qty", "description", "reason"],
            )

        if classified["unresolved_customers"]:
            _write_review_csv(
                cust_review_csv_path,
                classified["unresolved_customers"],
                ["bc_order_no", "bc_customer_no", "bc_customer_name"],
            )

        # ── Print reconciliation report ───────────────────────────────────────
        print_reconciliation_report(
            classified            = classified,
            existing_bc           = existing_bc,
            collisions            = collisions,
            would_insert          = would_insert,
            would_update          = would_update,
            would_skip            = would_skip,
            would_skip_collision  = would_skip_collision,
            would_skip_backlog    = would_skip_backlog,
            insert_candidates     = insert_candidates,
            bc_shipped_signals    = bc_shipped_signals,
            bl_advances           = bl_advances,
            divergences_flagged   = divergences_flagged,
            divergences_cleared   = divergences_cleared,
            bc_snapshot_count     = bc_snapshot_count,
            review_csv_path       = review_csv_path,
            cust_review_csv_path  = cust_review_csv_path,
        )

    except Exception as exc:
        conn.rollback()
        print(f"\nERROR: {exc}", file=sys.stderr)
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
