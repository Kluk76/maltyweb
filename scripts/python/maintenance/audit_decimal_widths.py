"""
audit_decimal_widths.py — systematic DECIMAL and VARCHAR width audit for all
bd_* tables in the maltytask database.

Goal: surface columns at risk of truncation BEFORE the next operator input
      breaks the ingest cron.

DECIMAL risk heuristic:
  A DECIMAL(p,s) column whose integer part (p-s) is <= 3 can overflow on any
  measurement that exceeds 999 in its integer portion.  These are flagged.

  For each flagged column:
    - ALREADY-BURNED: max(col) = 999.999 currently stored, OR ingest_failures
      has a 1264 error for this table.
    - HIGH risk: max observed value is within 10× of the column's integer limit
      (i.e. max > limit / 10).  At 10× headroom the column is one bad batch away.
    - LOW risk: flagged for awareness but not imminently at risk.

VARCHAR risk heuristic:
  A VARCHAR(N) where N <= 32 AND the column name suggests free-text (does not
  contain any of the known-safe suffixes: _id, _code, _email, _hash, _status,
  _type, _flag, _batch, _dlc, _ref, _idx, _num) is flagged.

  - ALREADY-BURNED: ingest_failures has a 1406 error for this column.
  - HIGH risk: max observed length is >= 80% of limit.
  - LOW risk: otherwise.

Output:
  --markdown  print a Markdown report to stdout (default)
  --csv       print a CSV (table, column, type, max_val, status, recommendation)
  --json      print JSON array of findings

Usage:
    python3 scripts/python/maintenance/audit_decimal_widths.py --markdown
    python3 scripts/python/maintenance/audit_decimal_widths.py --csv
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path
from decimal import Decimal

# Allow running from repo root or from inside scripts/python/maintenance/.
_here = Path(__file__).resolve().parent
for _candidate in [_here.parent, _here.parent.parent / "scripts" / "python"]:
    if (_candidate / "lib_config.py").exists():
        sys.path.insert(0, str(_candidate))
        break

import lib_config
import lib_db

# ---------------------------------------------------------------------------
# Safe-name suffixes: column names containing any of these are NOT free-text
# and are excluded from the VARCHAR short-name heuristic.
# ---------------------------------------------------------------------------
_SAFE_SUFFIXES = (
    "_id", "_code", "_email", "_hash", "_status", "_type", "_flag",
    "_batch", "_dlc", "_ref", "_idx", "_num",
)


@dataclass
class Finding:
    table: str
    column: str
    col_type: str          # e.g. "decimal(6,3)" or "varchar(16)"
    observed_max: str      # string representation
    limit_val: float       # column's max (integer part for DECIMAL, N for VARCHAR)
    distance_to_limit: str # human-readable headroom
    status: str            # ALREADY-BURNED | HIGH | LOW
    category: str          # DECIMAL | VARCHAR
    recommendation: str


def _get_tables(conn) -> list[str]:
    with conn.cursor() as cur:
        cur.execute("SHOW TABLES LIKE 'bd_%'")
        return [list(r.values())[0] for r in cur.fetchall()]


def _get_ingest_failures(conn) -> dict[str, set[str]]:
    """
    Returns {target_table: set_of_reason_codes} collected from ingest_failures.
    Also returns the raw reason_text snippets keyed by (table, reason_code).
    """
    result: dict[str, set[str]] = {}
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT target_table, reason_code FROM ingest_failures "
                "WHERE reason_code IN ('1264', '1406') GROUP BY target_table, reason_code"
            )
            for r in cur.fetchall():
                result.setdefault(r["target_table"], set()).add(r["reason_code"])
    except Exception:
        pass  # ingest_failures may not exist on old installs
    return result


def _audit_decimal(conn, tbl: str, ingest_failures: dict[str, set[str]]) -> list[Finding]:
    findings: list[Finding] = []
    with conn.cursor() as cur:
        cur.execute(f"SHOW COLUMNS FROM `{tbl}`")
        cols = cur.fetchall()

    table_failures = ingest_failures.get(tbl, set())

    for col in cols:
        m = re.match(r"decimal\((\d+),(\d+)\)", col["Type"], re.I)
        if not m:
            continue
        p, s = int(m.group(1)), int(m.group(2))
        int_part = p - s
        if int_part > 3:
            continue  # not at risk

        limit_val = 10 ** int_part - 10 ** (-s)  # e.g. 999.999 for DECIMAL(6,3)
        fname = col["Field"]

        with conn.cursor() as cur:
            cur.execute(f"SELECT MAX(`{fname}`) AS mx FROM `{tbl}`")
            mx_row = cur.fetchone()
            mx = mx_row["mx"]

        # Check for 999.999 sentinel
        with conn.cursor() as cur:
            cur.execute(f"SELECT COUNT(*) AS cnt FROM `{tbl}` WHERE `{fname}` = 999.999")
            burned_cnt = cur.fetchone()["cnt"]

        mx_float = float(mx) if mx is not None else 0.0

        # Determine status
        if burned_cnt > 0 or "1264" in table_failures:
            status = "ALREADY-BURNED"
        elif mx_float > limit_val / 10:
            status = "HIGH"
        else:
            status = "LOW"

        headroom = limit_val - mx_float
        distance_str = f"{headroom:.3f} below limit {limit_val:.3f}" if mx is not None else "N/A"

        recommendation = (
            f"widen to DECIMAL(10,{s})" if int_part <= 3
            else "no action needed"
        )

        findings.append(Finding(
            table=tbl,
            column=fname,
            col_type=col["Type"],
            observed_max=str(mx) if mx is not None else "NULL",
            limit_val=limit_val,
            distance_to_limit=distance_str,
            status=status,
            category="DECIMAL",
            recommendation=recommendation,
        ))

    return findings


def _audit_varchar(conn, tbl: str, ingest_failures: dict[str, set[str]]) -> list[Finding]:
    findings: list[Finding] = []
    with conn.cursor() as cur:
        cur.execute(f"SHOW COLUMNS FROM `{tbl}`")
        cols = cur.fetchall()

    table_failures = ingest_failures.get(tbl, set())

    for col in cols:
        m = re.match(r"varchar\((\d+)\)", col["Type"], re.I)
        if not m:
            continue
        n = int(m.group(1))
        if n > 32:
            continue  # only short VARCHARs
        fname = col["Field"]

        # Skip known-safe column names
        if any(fname.lower().endswith(sfx) for sfx in _SAFE_SUFFIXES):
            continue

        with conn.cursor() as cur:
            cur.execute(f"SELECT MAX(LENGTH(`{fname}`)) AS mx FROM `{tbl}`")
            mx_row = cur.fetchone()
            mx_len = mx_row["mx"] or 0

        pct = mx_len / n * 100 if n > 0 else 0

        # Determine status
        if "1406" in table_failures:
            status = "ALREADY-BURNED"
        elif pct >= 80:
            status = "HIGH"
        else:
            status = "LOW"

        headroom_chars = n - mx_len
        distance_str = f"{headroom_chars} chars to limit {n}"

        recommendation = f"widen to VARCHAR({max(n * 2, 128)})" if pct >= 80 else "monitor"

        findings.append(Finding(
            table=tbl,
            column=fname,
            col_type=col["Type"],
            observed_max=str(mx_len),
            limit_val=float(n),
            distance_to_limit=distance_str,
            status=status,
            category="VARCHAR",
            recommendation=recommendation,
        ))

    return findings


def run_audit(conn) -> list[Finding]:
    tables = _get_tables(conn)
    failures = _get_ingest_failures(conn)
    all_findings: list[Finding] = []
    for tbl in sorted(tables):
        all_findings.extend(_audit_decimal(conn, tbl, failures))
        all_findings.extend(_audit_varchar(conn, tbl, failures))
    return all_findings


def _print_markdown(findings: list[Finding]) -> None:
    burned = [f for f in findings if f.status == "ALREADY-BURNED"]
    high   = [f for f in findings if f.status == "HIGH"]
    low    = [f for f in findings if f.status == "LOW"]

    def _row(f: Finding) -> str:
        return (
            f"| `{f.table}.{f.column}` | {f.col_type} | {f.observed_max} "
            f"| {f.distance_to_limit} | {f.recommendation} |"
        )

    header = (
        "| table.column | type | observed_max | distance_to_limit | recommendation |\n"
        "|---|---|---|---|---|"
    )

    print("# DECIMAL / VARCHAR Width Audit — bd_* tables\n")
    print(f"_Generated by audit_decimal_widths.py against `maltytask` DB._\n")

    print("## ALREADY-BURNED\n")
    print("> Columns with confirmed truncated rows (999.999 sentinel) or past 1264/1406 ingest failures. **Fix immediately.**\n")
    if burned:
        print(header)
        for f in burned:
            print(_row(f))
    else:
        print("_None._")

    print("\n## HIGH risk\n")
    print("> Columns whose observed max is within 10× of the integer limit. One high-ppb batch away from truncation.\n")
    if high:
        print(header)
        for f in high:
            print(_row(f))
    else:
        print("_None._")

    print("\n## LOW risk\n")
    print(f"> {len(low)} column(s) flagged for awareness (integer part ≤ 3 or short VARCHAR) but not imminently at risk.\n")
    if low:
        print(header)
        for f in low:
            print(_row(f))
    else:
        print("_None._")

    print("\n---")
    print(
        f"\n**Summary:** {len(burned)} ALREADY-BURNED, {len(high)} HIGH, {len(low)} LOW "
        f"(of {len(findings)} total flagged columns across {len({f.table for f in findings})} tables)."
    )

    if high or burned:
        print("\n## Proposed migrations (HIGH-risk + ALREADY-BURNED)\n")
        print("> These are proposals only. No migrations have been written. "
              "Approve each before implementation.\n")
        decimal_proposals = [f for f in (burned + high) if f.category == "DECIMAL"]
        varchar_proposals  = [f for f in (burned + high) if f.category == "VARCHAR"]
        if decimal_proposals:
            print("**DECIMAL widenings:**\n")
            for f in decimal_proposals:
                m = re.match(r"decimal\((\d+),(\d+)\)", f.col_type, re.I)
                s = int(m.group(2)) if m else 3
                print(f"- `{f.table}.{f.column}` `{f.col_type}` → `DECIMAL(10,{s})`")
        if varchar_proposals:
            print("\n**VARCHAR widenings:**\n")
            for f in varchar_proposals:
                m = re.match(r"varchar\((\d+)\)", f.col_type, re.I)
                n = int(m.group(1)) if m else 32
                print(f"- `{f.table}.{f.column}` `{f.col_type}` → `VARCHAR({max(n*2, 128)})`")


def _print_csv(findings: list[Finding]) -> None:
    import csv
    writer = csv.writer(sys.stdout)
    writer.writerow([
        "table", "column", "type", "category", "status",
        "observed_max", "distance_to_limit", "recommendation",
    ])
    for f in findings:
        writer.writerow([
            f.table, f.column, f.col_type, f.category, f.status,
            f.observed_max, f.distance_to_limit, f.recommendation,
        ])


def _print_json(findings: list[Finding]) -> None:
    out = [
        {
            "table": f.table,
            "column": f.column,
            "type": f.col_type,
            "category": f.category,
            "status": f.status,
            "observed_max": f.observed_max,
            "distance_to_limit": f.distance_to_limit,
            "recommendation": f.recommendation,
        }
        for f in findings
    ]
    print(json.dumps(out, indent=2))


def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Audit DECIMAL and VARCHAR column widths in bd_* tables for truncation risk. "
            "Does NOT modify the database."
        )
    )
    fmt = parser.add_mutually_exclusive_group()
    fmt.add_argument("--markdown", action="store_true", default=True,
                     help="Output Markdown report to stdout (default).")
    fmt.add_argument("--csv", action="store_true",
                     help="Output CSV to stdout.")
    fmt.add_argument("--json", action="store_true",
                     help="Output JSON array to stdout.")
    args = parser.parse_args()

    # Resolve format (argparse default=True on --markdown means we need this logic)
    if args.csv:
        fmt_mode = "csv"
    elif args.json:
        fmt_mode = "json"
    else:
        fmt_mode = "markdown"

    cfg = lib_config.load()
    conn = lib_db.connect(cfg)
    try:
        findings = run_audit(conn)
    finally:
        conn.close()

    if fmt_mode == "csv":
        _print_csv(findings)
    elif fmt_mode == "json":
        _print_json(findings)
    else:
        _print_markdown(findings)


if __name__ == "__main__":
    main()
