#!/usr/bin/env python3
"""
seed_comm_registry.py — One-shot, idempotent seeder for the email→entity
                         registry (comm_address_pins + ref_entity_email_domains).

Sources:
  1. Operator-validated CSV  (--input, default /var/www/maltytask/data/comm-registry-seed-validated.csv)
  2. ref_suppliers.email     (BC-synced, ~21 non-null rows)

Resolution (per CSV row):
  Each row is resolved to a ref_suppliers.id via a 3-step cascade:
    1. proposed_supplier_id present + exists + name normalised-matches  → RESOLVED
    2. proposed_supplier_id present + exists BUT name MISMATCHES        → MISMATCH (flagged)
    3. Exact normalised-name match on ref_suppliers.name                → RESOLVED if unique
    4. Otherwise                                                        → UNRESOLVED (flagged)
  Flagged rows are never written; the dry-run report lists every one with
  the reason so the operator can fix the sheet before --apply.

Writes (RESOLVED rows only, behind --apply):
  • comm_address_pins          — one address pin per resolved email
  • ref_entity_email_domains   — address-level row; domain-level row when
                                  non-consumer domain maps to a single supplier

Usage:
  # Dry-run (default) — full plan printed, nothing written:
  python3 scripts/python/seed_comm_registry.py

  # Apply — writes inside a single transaction:
  python3 scripts/python/seed_comm_registry.py --apply

  # Override CSV path:
  python3 scripts/python/seed_comm_registry.py --input /path/to/file.csv

CARDINAL RULE — NON-FISCAL: the comm layer is CRM/correspondence capture.
Nothing here feeds COGS, COP, WAC, BOM, beer-tax, stock, or any financial
computation.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import re
import sys
import unicodedata
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

_SCRIPT_DIR = Path(__file__).parent
if str(_SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(_SCRIPT_DIR))

import pymysql  # noqa: E402

from lib_config import load as load_config  # noqa: E402
from comm_domains import CONSUMER_DOMAINS, domain_of  # noqa: E402

# ── Constants ──────────────────────────────────────────────────────────────────

_DEFAULT_INPUT = Path("/var/www/maltytask/data/comm-registry-seed-validated.csv")
_LOG_DIR = Path("/var/log/maltytask")
_REPORT_FILENAME = "comm-registry-seed-report.json"

# Source priority for ref_entity_email_domains: higher index = more authoritative.
# We NEVER downgrade an existing row to a less-authoritative source.
_SOURCE_PRIORITY: dict[str, int] = {
    "manual":    0,
    "bc-vendor": 1,
    "validated": 2,
}


# ── Name normalisation (mirrors sync_bc_vendors.py) ───────────────────────────

def _normalize_name(s: str | None) -> str:
    """Lowercase, strip diacritics, remove legal suffixes, collapse non-alnum to space."""
    if not s:
        return ""
    # NFC then strip diacritics
    s = unicodedata.normalize("NFD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    s = s.lower()
    # Remove common legal suffixes (word-boundary aware)
    legal = r"\b(sa|sarl|ag|gmbh|s\.?rl|srl|ltd|e\.?v\.?|inc|llc|cie|co)\b"
    s = re.sub(legal, " ", s)
    # Collapse non-alnum to space
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return s.strip()


# ── ref_suppliers loader ───────────────────────────────────────────────────────

def _load_suppliers(conn: pymysql.connections.Connection) -> tuple[dict[int, dict], dict[str, list[int]]]:
    """
    Load all ref_suppliers rows.

    Returns:
      by_id   : {id: {"id": int, "name": str, "email": str|None, "norm_name": str}}
      by_norm : {normalised_name: [id, …]}  — for name-fallback matching
    """
    with conn.cursor() as c:
        c.execute("SELECT id, name, email FROM ref_suppliers")
        rows = c.fetchall()

    by_id: dict[int, dict] = {}
    by_norm: dict[str, list[int]] = defaultdict(list)

    for r in rows:
        sid = int(r["id"])
        name = str(r["name"] or "").strip()
        email_raw = r.get("email")
        email = str(email_raw).strip().lower() if email_raw else None
        norm = _normalize_name(name)
        by_id[sid] = {"id": sid, "name": name, "email": email, "norm_name": norm}
        by_norm[norm].append(sid)

    return by_id, by_norm


# ── CSV resolution ─────────────────────────────────────────────────────────────

def _resolve_csv_rows(
    csv_path: Path,
    by_id: dict[int, dict],
    by_norm: dict[str, list[int]],
) -> tuple[list[dict], list[dict]]:
    """
    Read the validated CSV and attempt to resolve each row to a supplier id.

    Returns:
      resolved : list of dicts with keys:
                   email, domain, supplier_id, supplier_name, match_tier, msg_count,
                   resolution (str), resolution_detail (str)
      flagged  : list of dicts with same keys plus:
                   flag_reason, csv_proposed_id, csv_proposed_name, db_name_found
    """
    resolved: list[dict] = []
    flagged: list[dict] = []

    with csv_path.open(newline="", encoding="utf-8") as fh:
        reader = csv.DictReader(fh)
        for row in reader:
            email = str(row.get("email_address", "")).strip().lower()
            dom   = str(row.get("domain", "")).strip().lower()
            tier  = str(row.get("match_tier", "")).strip()
            msg_count = int(str(row.get("msg_count", "0") or "0").strip() or "0")
            csv_name  = str(row.get("proposed_supplier_name", "")).strip()
            csv_id_raw = str(row.get("proposed_supplier_id", "")).strip()

            base = {
                "email": email,
                "domain": dom,
                "match_tier": tier,
                "msg_count": msg_count,
                "csv_proposed_id": csv_id_raw,
                "csv_proposed_name": csv_name,
            }

            if not email:
                flagged.append({**base,
                    "flag_reason": "EMPTY_EMAIL",
                    "db_name_found": None})
                continue

            csv_id: int | None = None
            if csv_id_raw:
                try:
                    csv_id = int(csv_id_raw)
                except ValueError:
                    flagged.append({**base,
                        "flag_reason": "UNPARSEABLE_ID",
                        "db_name_found": None})
                    continue

            # ── Step 1 / Step 2: proposed_supplier_id path ────────────────────
            if csv_id is not None:
                db_row = by_id.get(csv_id)
                if db_row is None:
                    flagged.append({**base,
                        "flag_reason": "ID_NOT_IN_DB",
                        "db_name_found": None})
                    continue

                db_norm   = db_row["norm_name"]
                csv_norm  = _normalize_name(csv_name)

                if db_norm == csv_norm:
                    # Step 1 — exact normalised match: RESOLVED
                    resolved.append({**base,
                        "supplier_id":     csv_id,
                        "supplier_name":   db_row["name"],
                        "resolution":      "ID_AND_NAME_MATCH",
                        "resolution_detail": f"id={csv_id} name='{db_row['name']}'",
                    })
                else:
                    # Step 2 — id exists but name diverges: MISMATCH (flagged)
                    flagged.append({**base,
                        "flag_reason":    "MISMATCH_NAME",
                        "db_name_found":  db_row["name"],
                        "reason_detail":  (
                            f"DB name '{db_row['name']}' (norm: '{db_norm}') "
                            f"≠ CSV name '{csv_name}' (norm: '{csv_norm}')"
                        ),
                    })
                continue

            # ── Step 3: name-only fallback ────────────────────────────────────
            csv_norm = _normalize_name(csv_name)
            if not csv_norm:
                flagged.append({**base,
                    "flag_reason":   "NO_ID_AND_EMPTY_NAME",
                    "db_name_found": None})
                continue

            matches = by_norm.get(csv_norm, [])
            if len(matches) == 1:
                db_row = by_id[matches[0]]
                resolved.append({**base,
                    "supplier_id":     db_row["id"],
                    "supplier_name":   db_row["name"],
                    "resolution":      "NAME_MATCH",
                    "resolution_detail": (
                        f"norm_name='{csv_norm}' matched ref_suppliers.id={db_row['id']}"
                    ),
                })
            elif len(matches) == 0:
                flagged.append({**base,
                    "flag_reason":   "NO_NAME_MATCH",
                    "db_name_found": None})
            else:
                # Multiple DB rows with same normalised name — ambiguous
                flagged.append({**base,
                    "flag_reason":   "AMBIGUOUS_NAME_MATCH",
                    "db_name_found": ", ".join(
                        f"id={i} '{by_id[i]['name']}'" for i in matches
                    )})

    return resolved, flagged


# ── Plan builders ──────────────────────────────────────────────────────────────

def _build_plan(
    resolved: list[dict],
    by_id: dict[int, dict],
) -> dict[str, Any]:
    """
    Build the full write plan from resolved rows + BC-email rows.

    Returns a dict with keys:
      pins           : list of {email, supplier_id, supplier_name, resolution}
      address_rows   : list of {match_value, match_type, source, supplier_id, is_shared}
      domain_rows    : list of {match_value, match_type, source, supplier_id, is_shared}
      consumer_skips : list of {email, domain, supplier_id}
      ambiguous_domains : list of {domain, supplier_ids}
      bc_address_rows   : list of {match_value, match_type, source, supplier_id, is_shared}
      bc_domain_rows    : list of {match_value, match_type, source, supplier_id, is_shared}
    """
    # ── [A] Pins + address rows from validated CSV ────────────────────────────
    pins:         list[dict] = []
    address_rows: list[dict] = []
    consumer_skips: list[dict] = []

    # Track domain → set of supplier_ids (from validated CSV only)
    domain_to_suppliers: dict[str, set[int]] = defaultdict(set)

    for r in resolved:
        email     = r["email"]
        supplier_id = r["supplier_id"]
        dom       = domain_of(email)

        pins.append({
            "email":         email,
            "supplier_id":   supplier_id,
            "supplier_name": r["supplier_name"],
            "resolution":    r["resolution"],
        })

        address_rows.append({
            "match_value": email,
            "match_type":  "address",
            "source":      "validated",
            "supplier_id": supplier_id,
            "is_shared":   0,
        })

        if dom in CONSUMER_DOMAINS:
            consumer_skips.append({
                "email":       email,
                "domain":      dom,
                "supplier_id": supplier_id,
            })
        else:
            domain_to_suppliers[dom].add(supplier_id)

    # ── [B] Domain rows from validated CSV ────────────────────────────────────
    domain_rows: list[dict] = []
    ambiguous_domains: list[dict] = []

    for dom, sup_set in sorted(domain_to_suppliers.items()):
        if len(sup_set) == 1:
            sid = next(iter(sup_set))
            domain_rows.append({
                "match_value": dom,
                "match_type":  "domain",
                "source":      "validated",
                "supplier_id": sid,
                "is_shared":   0,
            })
        else:
            ambiguous_domains.append({
                "domain":       dom,
                "supplier_ids": sorted(sup_set),
            })

    # ── [C] BC supplier emails ────────────────────────────────────────────────
    bc_address_rows: list[dict] = []
    bc_domain_rows:  list[dict] = []
    bc_domain_to_suppliers: dict[str, set[int]] = defaultdict(set)

    for sid, srow in sorted(by_id.items()):
        email = srow.get("email")
        if not email:
            continue
        email = email.strip().lower()
        if not email or "@" not in email:
            continue

        bc_address_rows.append({
            "match_value": email,
            "match_type":  "address",
            "source":      "bc-vendor",
            "supplier_id": sid,
            "is_shared":   0,
        })

        dom = domain_of(email)
        if dom and dom not in CONSUMER_DOMAINS:
            bc_domain_to_suppliers[dom].add(sid)

    for dom, sup_set in sorted(bc_domain_to_suppliers.items()):
        if len(sup_set) == 1:
            sid = next(iter(sup_set))
            bc_domain_rows.append({
                "match_value": dom,
                "match_type":  "domain",
                "source":      "bc-vendor",
                "supplier_id": sid,
                "is_shared":   0,
            })
        # ambiguous BC domains: silently skip (no operator-validated mapping)

    return {
        "pins":              pins,
        "address_rows":      address_rows,
        "domain_rows":       domain_rows,
        "consumer_skips":    consumer_skips,
        "ambiguous_domains": ambiguous_domains,
        "bc_address_rows":   bc_address_rows,
        "bc_domain_rows":    bc_domain_rows,
    }


# ── Apply helpers ─────────────────────────────────────────────────────────────

def _upsert_pin(conn: pymysql.connections.Connection, pin: dict) -> tuple[str, int]:
    """
    Upsert comm_address_pins for a supplier-email pin.

    Rules:
    - If the email doesn't exist → INSERT.
    - If it already exists with the same supplier_id → no-op (ON DUPLICATE KEY
      UPDATE just re-asserts the same supplier_id_fk).
    - If it already exists pointing at a DIFFERENT supplier → CONFLICT: report
      and skip.
    - If it already exists pointing at a customer (customer_id_fk IS NOT NULL)
      → CONFLICT: never flip a customer pin to a supplier; report and skip.

    Returns (action, supplier_id) where action in {'inserted','updated','conflict','error'}.
    """
    email       = pin["email"]
    supplier_id = pin["supplier_id"]

    with conn.cursor() as c:
        # Read current state first
        c.execute(
            "SELECT supplier_id_fk, customer_id_fk FROM comm_address_pins WHERE email = %s",
            (email,),
        )
        existing = c.fetchone()

    if existing is not None:
        existing_sup  = existing["supplier_id_fk"]
        existing_cust = existing["customer_id_fk"]

        if existing_cust is not None:
            return "conflict_customer_pin", supplier_id

        if existing_sup is not None and int(existing_sup) != supplier_id:
            return f"conflict_supplier_mismatch:existing={existing_sup}", supplier_id

        # Same supplier (or logically same) — no-op
        return "already_exists", supplier_id

    # Row doesn't exist — INSERT
    with conn.cursor() as c:
        c.execute(
            """INSERT INTO comm_address_pins (email, supplier_id_fk, customer_id_fk)
               VALUES (%s, %s, NULL)""",
            (email, supplier_id),
        )
    return "inserted", supplier_id


def _upsert_registry_row(
    conn: pymysql.connections.Connection,
    row: dict,
) -> str:
    """
    Upsert a ref_entity_email_domains row.

    Source priority: validated > bc-vendor > manual.
    Rules:
    - Row doesn't exist → INSERT.
    - Row exists with the SAME supplier_id:
        * Never downgrade source (keep the more-authoritative value).
        * Otherwise UPDATE source if new source is more authoritative.
    - Row exists with a DIFFERENT supplier_id → CONFLICT: skip + report.

    Returns action string: 'inserted' | 'updated_source' | 'noop' |
                           'conflict_supplier' | 'noop_lower_source'.
    """
    match_value = row["match_value"]
    match_type  = row["match_type"]
    new_source  = row["source"]
    supplier_id = row["supplier_id"]
    is_shared   = row["is_shared"]

    with conn.cursor() as c:
        c.execute(
            """SELECT id, supplier_id_fk, customer_id_fk, source
                 FROM ref_entity_email_domains
                WHERE match_value = %s""",
            (match_value,),
        )
        existing = c.fetchone()

    if existing is None:
        with conn.cursor() as c:
            c.execute(
                """INSERT INTO ref_entity_email_domains
                       (supplier_id_fk, customer_id_fk, match_type, match_value,
                        source, is_shared)
                   VALUES (%s, NULL, %s, %s, %s, %s)""",
                (supplier_id, match_type, match_value, new_source, is_shared),
            )
        return "inserted"

    # Row already exists
    existing_sup  = existing["supplier_id_fk"]
    existing_cust = existing["customer_id_fk"]
    existing_src  = existing["source"]

    # Different supplier or customer-owned → conflict
    if existing_cust is not None:
        return "conflict_customer_owned"
    if existing_sup is not None and int(existing_sup) != supplier_id:
        return f"conflict_supplier:existing_id={existing_sup}"

    # Same supplier — check source priority
    existing_prio = _SOURCE_PRIORITY.get(str(existing_src), 0)
    new_prio      = _SOURCE_PRIORITY.get(new_source, 0)

    if new_prio > existing_prio:
        with conn.cursor() as c:
            c.execute(
                "UPDATE ref_entity_email_domains SET source = %s WHERE match_value = %s",
                (new_source, match_value),
            )
        return "updated_source"

    return "noop"


# ── Dry-run report helpers ─────────────────────────────────────────────────────

def _print_dry_run_report(
    resolved: list[dict],
    flagged:  list[dict],
    plan:     dict[str, Any],
    report_path: Path,
) -> None:
    w = 72
    print()
    print("=" * w)
    print("DRY-RUN PLAN — seed_comm_registry.py")
    print("=" * w)
    print()
    print("CSV RESOLUTION SUMMARY")
    print(f"  Resolved                   : {len(resolved):>4}")
    print(f"  Flagged (not written)      : {len(flagged):>4}")
    print()
    print("WRITE PLAN (would execute on --apply)")
    print(f"  comm_address_pins  (pins)            : {len(plan['pins']):>4}")
    print(f"  ref_entity_email_domains (address)   : {len(plan['address_rows']):>4}  [source=validated]")
    print(f"  ref_entity_email_domains (domain)    : {len(plan['domain_rows']):>4}  [source=validated]")
    print(f"  ref_entity_email_domains (BC address): {len(plan['bc_address_rows']):>4}  [source=bc-vendor]")
    print(f"  ref_entity_email_domains (BC domain) : {len(plan['bc_domain_rows']):>4}  [source=bc-vendor]")
    print(f"  Consumer-domain addresses (pin only) : {len(plan['consumer_skips']):>4}  (no domain row)")
    print(f"  Ambiguous domains (no domain row)    : {len(plan['ambiguous_domains']):>4}")
    print()

    # ── Flagged rows — full list ───────────────────────────────────────────────
    print("-" * w)
    print(f"FLAGGED ROWS ({len(flagged)}) — must be fixed in CSV before --apply")
    print("-" * w)
    if not flagged:
        print("  (none — all rows resolved cleanly)")
    for i, f in enumerate(flagged, 1):
        db_found = f.get("db_name_found") or "(none)"
        reason   = f.get("flag_reason", "?")
        detail   = f.get("reason_detail", "")
        print(
            f"  [{i:3d}] {f.get('email', '?')!r:<50}  reason={reason}"
        )
        print(f"         CSV id={f.get('csv_proposed_id')!r}  CSV name={f.get('csv_proposed_name')!r}")
        print(f"         DB name found: {db_found}")
        if detail:
            print(f"         Detail: {detail}")
    print()

    # ── Consumer-domain skips ──────────────────────────────────────────────────
    if plan["consumer_skips"]:
        print("-" * w)
        print(f"CONSUMER-DOMAIN SKIPS ({len(plan['consumer_skips'])}) — address pin only, no domain row")
        print("-" * w)
        for s in plan["consumer_skips"]:
            print(f"  {s['email']:<50}  domain={s['domain']}  supplier_id={s['supplier_id']}")
        print()

    # ── Ambiguous domains ──────────────────────────────────────────────────────
    if plan["ambiguous_domains"]:
        print("-" * w)
        print(f"AMBIGUOUS DOMAINS ({len(plan['ambiguous_domains'])}) — no domain row (address pins only)")
        print("-" * w)
        for a in plan["ambiguous_domains"]:
            print(f"  {a['domain']:<40}  supplier_ids={a['supplier_ids']}")
        print()

    # ── Domain rows planned ────────────────────────────────────────────────────
    if plan["domain_rows"]:
        print("-" * w)
        print(f"DOMAIN ROWS PLANNED — validated ({len(plan['domain_rows'])})")
        print("-" * w)
        for d in plan["domain_rows"]:
            print(f"  {d['match_value']:<40}  supplier_id={d['supplier_id']}")
        print()

    if plan["bc_domain_rows"]:
        print("-" * w)
        print(f"DOMAIN ROWS PLANNED — bc-vendor ({len(plan['bc_domain_rows'])})")
        print("-" * w)
        for d in plan["bc_domain_rows"]:
            print(f"  {d['match_value']:<40}  supplier_id={d['supplier_id']}")
        print()

    print("=" * w)
    print("DRY-RUN COMPLETE — no writes made.")
    print(f"Report written to: {report_path}")
    print("=" * w)
    print()


# ── Reconcile-report JSON ──────────────────────────────────────────────────────

def _write_report(
    dry_run:  bool,
    resolved: list[dict],
    flagged:  list[dict],
    plan:     dict[str, Any],
    apply_result: dict[str, Any] | None,
) -> Path:
    report: dict[str, Any] = {
        "generated_at":      datetime.now(timezone.utc).isoformat(),
        "dry_run":           dry_run,
        "resolved_count":    len(resolved),
        "flagged_count":     len(flagged),
        "flagged":           flagged,
        "pins_planned":          len(plan["pins"]),
        "address_rows_planned":  len(plan["address_rows"]),
        "domain_rows_planned":   len(plan["domain_rows"]),
        "bc_address_rows_planned": len(plan["bc_address_rows"]),
        "bc_domain_rows_planned":  len(plan["bc_domain_rows"]),
        "consumer_domains_skipped": len(plan["consumer_skips"]),
        "consumer_skips":    plan["consumer_skips"],
        "ambiguous_domains": plan["ambiguous_domains"],
    }
    if apply_result is not None:
        report["apply_result"] = apply_result

    if _LOG_DIR.is_dir() and os.access(str(_LOG_DIR), os.W_OK):
        out_path = _LOG_DIR / _REPORT_FILENAME
    else:
        out_path = Path("/tmp") / _REPORT_FILENAME

    out_path.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    return out_path


# ── Apply ──────────────────────────────────────────────────────────────────────

def _apply(
    conn: pymysql.connections.Connection,
    plan: dict[str, Any],
) -> dict[str, Any]:
    """
    Execute all writes inside a single transaction.
    Returns apply-result counters for the report.
    """
    result: dict[str, Any] = {
        "pins": {"inserted": 0, "already_exists": 0, "conflicts": []},
        "address_validated": {"inserted": 0, "updated_source": 0, "noop": 0, "conflicts": []},
        "domain_validated":  {"inserted": 0, "updated_source": 0, "noop": 0, "conflicts": []},
        "address_bc":        {"inserted": 0, "updated_source": 0, "noop": 0, "conflicts": []},
        "domain_bc":         {"inserted": 0, "updated_source": 0, "noop": 0, "conflicts": []},
    }

    conn.begin()
    try:
        # ── Pins ──────────────────────────────────────────────────────────────
        for pin in plan["pins"]:
            action, _ = _upsert_pin(conn, pin)
            if action == "inserted":
                result["pins"]["inserted"] += 1
            elif action == "already_exists":
                result["pins"]["already_exists"] += 1
            else:
                result["pins"]["conflicts"].append({
                    "email":  pin["email"],
                    "reason": action,
                })

        # ── Address rows (validated) ──────────────────────────────────────────
        for row in plan["address_rows"]:
            action = _upsert_registry_row(conn, row)
            bucket = result["address_validated"]
            if action == "inserted":
                bucket["inserted"] += 1
            elif action == "updated_source":
                bucket["updated_source"] += 1
            elif action.startswith("conflict"):
                bucket["conflicts"].append({"match_value": row["match_value"], "reason": action})
            else:
                bucket["noop"] += 1

        # ── Domain rows (validated) ───────────────────────────────────────────
        for row in plan["domain_rows"]:
            action = _upsert_registry_row(conn, row)
            bucket = result["domain_validated"]
            if action == "inserted":
                bucket["inserted"] += 1
            elif action == "updated_source":
                bucket["updated_source"] += 1
            elif action.startswith("conflict"):
                bucket["conflicts"].append({"match_value": row["match_value"], "reason": action})
            else:
                bucket["noop"] += 1

        # ── BC address rows ───────────────────────────────────────────────────
        for row in plan["bc_address_rows"]:
            action = _upsert_registry_row(conn, row)
            bucket = result["address_bc"]
            if action == "inserted":
                bucket["inserted"] += 1
            elif action == "updated_source":
                bucket["updated_source"] += 1
            elif action.startswith("conflict"):
                bucket["conflicts"].append({"match_value": row["match_value"], "reason": action})
            else:
                bucket["noop"] += 1

        # ── BC domain rows ────────────────────────────────────────────────────
        for row in plan["bc_domain_rows"]:
            action = _upsert_registry_row(conn, row)
            bucket = result["domain_bc"]
            if action == "inserted":
                bucket["inserted"] += 1
            elif action == "updated_source":
                bucket["updated_source"] += 1
            elif action.startswith("conflict"):
                bucket["conflicts"].append({"match_value": row["match_value"], "reason": action})
            else:
                bucket["noop"] += 1

        conn.commit()

    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass
        raise

    return result


def _print_apply_summary(result: dict[str, Any]) -> None:
    w = 72
    print()
    print("=" * w)
    print("APPLY COMPLETE — seed_comm_registry.py")
    print("=" * w)
    pins = result["pins"]
    print(f"  comm_address_pins inserted    : {pins['inserted']:>4}")
    print(f"  comm_address_pins already_set : {pins['already_exists']:>4}")
    if pins["conflicts"]:
        print(f"  comm_address_pins CONFLICTS   : {len(pins['conflicts']):>4}  ← check report JSON")
    av = result["address_validated"]
    print(f"  registry address/validated inserted     : {av['inserted']:>4}")
    print(f"  registry address/validated updated_src  : {av['updated_source']:>4}")
    print(f"  registry address/validated noop         : {av['noop']:>4}")
    if av["conflicts"]:
        print(f"  registry address/validated CONFLICTS    : {len(av['conflicts']):>4}  ← check report JSON")
    dv = result["domain_validated"]
    print(f"  registry domain/validated  inserted     : {dv['inserted']:>4}")
    print(f"  registry domain/validated  noop         : {dv['noop']:>4}")
    if dv["conflicts"]:
        print(f"  registry domain/validated  CONFLICTS    : {len(dv['conflicts']):>4}  ← check report JSON")
    ab = result["address_bc"]
    print(f"  registry address/bc-vendor inserted     : {ab['inserted']:>4}")
    print(f"  registry address/bc-vendor noop         : {ab['noop']:>4}")
    if ab["conflicts"]:
        print(f"  registry address/bc-vendor CONFLICTS    : {len(ab['conflicts']):>4}  ← check report JSON")
    db_ = result["domain_bc"]
    print(f"  registry domain/bc-vendor  inserted     : {db_['inserted']:>4}")
    print(f"  registry domain/bc-vendor  noop         : {db_['noop']:>4}")
    if db_["conflicts"]:
        print(f"  registry domain/bc-vendor  CONFLICTS    : {len(db_['conflicts']):>4}  ← check report JSON")
    print("=" * w)
    print()


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(
        description=(
            "One-shot, idempotent seeder for comm_address_pins + "
            "ref_entity_email_domains.\n"
            "Dry-run by default — use --apply to write to the DB."
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    ap.add_argument(
        "--apply", action="store_true", default=False,
        help="Write to DB inside a transaction (default: dry-run, no writes).",
    )
    ap.add_argument(
        "--input", type=Path, default=_DEFAULT_INPUT, metavar="PATH",
        help=f"Validated CSV path (default: {_DEFAULT_INPUT}).",
    )
    args = ap.parse_args()
    dry_run    = not args.apply
    csv_path   = args.input
    mode_label = "DRY-RUN (no writes)" if dry_run else "** APPLY **"

    print(f"\nseed_comm_registry.py — {mode_label}", flush=True)
    print(f"CSV input: {csv_path}", flush=True)
    print()

    if not csv_path.exists():
        print(f"ERROR: CSV not found: {csv_path}", file=sys.stderr)
        sys.exit(1)

    # ── Connect ────────────────────────────────────────────────────────────────
    print("Connecting to maltytask MySQL …", end=" ", flush=True)
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
        read_timeout=60,
        write_timeout=60,
    )
    print("connected.", flush=True)

    try:
        # ── Load ref_suppliers ─────────────────────────────────────────────────
        print("Loading ref_suppliers …", end=" ", flush=True)
        by_id, by_norm = _load_suppliers(conn)
        print(f"done — {len(by_id):,} rows.", flush=True)

        # ── Resolve CSV ────────────────────────────────────────────────────────
        print(f"Resolving CSV ({csv_path.name}) …", end=" ", flush=True)
        resolved, flagged = _resolve_csv_rows(csv_path, by_id, by_norm)
        print(
            f"done — {len(resolved)} resolved, {len(flagged)} flagged.",
            flush=True,
        )

        # ── Build plan ─────────────────────────────────────────────────────────
        plan = _build_plan(resolved, by_id)

        # ── Write report ───────────────────────────────────────────────────────
        report_path = _write_report(
            dry_run=dry_run,
            resolved=resolved,
            flagged=flagged,
            plan=plan,
            apply_result=None,
        )

        if dry_run:
            _print_dry_run_report(resolved, flagged, plan, report_path)
            conn.close()
            return

        # ── Apply ──────────────────────────────────────────────────────────────
        print("Applying writes …", flush=True)
        apply_result = _apply(conn, plan)

        # Update report with apply result
        report_path = _write_report(
            dry_run=False,
            resolved=resolved,
            flagged=flagged,
            plan=plan,
            apply_result=apply_result,
        )

        _print_apply_summary(apply_result)
        print(f"Report written to: {report_path}", flush=True)
        conn.close()

    except Exception:
        try:
            conn.close()
        except Exception:
            pass
        raise


if __name__ == "__main__":
    main()
