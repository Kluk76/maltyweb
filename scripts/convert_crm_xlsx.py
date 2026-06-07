#!/usr/bin/env python3
"""
scripts/convert_crm_xlsx.py
Convert data/crm-clients-2026-06-07.xlsx (tab "Clients") to
data/crm-clients-2026-06-07.json for consumption by the PHP bootstrap.

Output JSON: { "exported_at": "<iso>", "row_count": N, "clients": [ {...}, ... ] }

Each client row:
  bc_customer_no  : str (N° column, e.g. "1001")
  name            : str (Nom column, trimmed)
  email           : str|null  (lowercased, spaces around ; stripped)
  address_line1   : str|null
  address_line2   : str|null
  postal_code     : str|null
  city            : str|null
  canton          : str|null  (2-char normalised; Comté column)
  country_code    : str|null  (2-char; Code pays/région column)

Usage:
  python3 scripts/convert_crm_xlsx.py
  python3 scripts/convert_crm_xlsx.py --input data/crm-clients-2026-06-07.xlsx \
                                       --output data/crm-clients-2026-06-07.json
"""
import argparse, json, re, sys
from datetime import datetime, timezone
from pathlib import Path

import pandas as pd

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_INPUT  = REPO_ROOT / "data" / "crm-clients-2026-06-07.xlsx"
DEFAULT_OUTPUT = REPO_ROOT / "data" / "crm-clients-2026-06-07.json"

SHEET_NAME = "Clients"

COL_NO       = "N°"
COL_NOM      = "Nom"
COL_EMAIL    = "Adresse e-mail"
COL_ADDR1    = "Adresse"
COL_ADDR2    = "Adresse (2ème ligne)"
COL_ZIP      = "Code postal"
COL_CITY     = "Ville"
COL_CANTON   = "Comté"
COL_COUNTRY  = "Code pays/région"


def clean_str(v) -> str | None:
    if v is None:
        return None
    s = str(v).strip()
    return s if s and s.lower() != "nan" else None


def clean_email(v) -> str | None:
    s = clean_str(v)
    if not s:
        return None
    # lowercase, strip spaces around semicolons
    s = s.lower()
    s = re.sub(r"\s*;\s*", ";", s)
    s = s.strip("; ")
    return s if s else None


def clean_canton(v) -> str | None:
    s = clean_str(v)
    if not s:
        return None
    # Keep only letters, uppercase, max 2 chars
    letters = re.sub(r"[^A-Za-z]", "", s)
    return letters[:2].upper() if letters else None


def clean_country(v) -> str | None:
    s = clean_str(v)
    if not s:
        return None
    letters = re.sub(r"[^A-Za-z]", "", s)
    return letters[:2].upper() if letters else None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input",  default=str(DEFAULT_INPUT))
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT))
    args = parser.parse_args()

    df = pd.read_excel(args.input, sheet_name=SHEET_NAME, dtype=str)
    print(f"Read {len(df)} rows from {args.input!r} sheet={SHEET_NAME!r}", file=sys.stderr)

    clients = []
    for _, row in df.iterrows():
        bc_no = clean_str(row.get(COL_NO))
        nom   = clean_str(row.get(COL_NOM))
        if bc_no is None or nom is None:
            continue
        clients.append({
            "bc_customer_no": bc_no,
            "name":           nom,
            "email":          clean_email(row.get(COL_EMAIL)),
            "address_line1":  clean_str(row.get(COL_ADDR1)),
            "address_line2":  clean_str(row.get(COL_ADDR2)),
            "postal_code":    clean_str(row.get(COL_ZIP)),
            "city":           clean_str(row.get(COL_CITY)),
            "canton":         clean_canton(row.get(COL_CANTON)),
            "country_code":   clean_country(row.get(COL_COUNTRY)),
        })

    out = {
        "exported_at": datetime.now(timezone.utc).isoformat(),
        "row_count":   len(clients),
        "clients":     clients,
    }
    Path(args.output).write_text(json.dumps(out, ensure_ascii=False, indent=2))
    print(f"Wrote {len(clients)} clients to {args.output!r}", file=sys.stderr)


if __name__ == "__main__":
    main()
