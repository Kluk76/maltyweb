#!/usr/bin/env python3
"""
send_email_comm.py — Send a single outbound reply via Gmail (DWD service account).

Reads a JSON payload from stdin or --payload <file>, sends via the Gmail API
impersonating sender_email, and writes a JSON result to stdout.

Input JSON shape:
  {
    "sender_email": "kouros@lanebuleuse.ch",
    "to": "recipient@supplier.com",
    "subject": "Re: Your enquiry",
    "body": "Text of the reply",
    "body_format": "text",
    "in_reply_to": "<msgid@lanebuleuse.ch>",   // nullable / omit
    "references":  "<msgid@lanebuleuse.ch>",   // nullable / omit
    "attachments": [                            // optional, default []
      {"local_path": "/var/www/…/doc.pdf", "filename": "doc.pdf", "mime_type": "application/pdf"}
    ]
  }

Output JSON (stdout):
  {"ok": true,  "message_id": "<uuid@lanebuleuse.ch>", "gmail_message_id": "...", "thread_id_gmail": "..."}
  {"ok": false, "error": "description"}

Flags:
  --payload <file>  Read JSON payload from file instead of stdin
  --dry-run         Build MIME + generate Message-ID but do NOT call Gmail send
"""

from __future__ import annotations

import argparse
import base64
import json
import os
import sys
import uuid
from datetime import datetime, timezone
from email import encoders
from email.mime.base import MIMEBase
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.utils import formatdate
from pathlib import Path
from typing import Any


_COMM_ENV_PATH = Path("/var/www/maltytask/config/gmail-comm.env")


def _load_comm_env(path: Path) -> dict[str, str]:
    """Load KEY=VALUE env file; raise RuntimeError on missing GMAIL_SA_KEYFILE."""
    if not path.exists():
        raise RuntimeError(f"Gmail comm env not found at {path}")
    cfg: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()
    if "GMAIL_SA_KEYFILE" not in cfg:
        raise RuntimeError("Missing key GMAIL_SA_KEYFILE in gmail-comm.env")
    return cfg


def _build_message(payload: dict[str, Any]) -> tuple[str, str]:
    """
    Build a raw MIME message from payload dict.
    Returns (raw_base64url, message_id).
    """
    sender_email = payload["sender_email"]
    to = payload["to"]
    subject = payload["subject"]
    body = payload["body"]
    in_reply_to: str | None = payload.get("in_reply_to") or None
    references: str | None = payload.get("references") or None
    attachments: list[dict[str, Any]] = payload.get("attachments") or []

    message_id = f"<{uuid.uuid4()}@lanebuleuse.ch>"

    if attachments:
        msg: MIMEBase = MIMEMultipart("mixed")
        msg.attach(MIMEText(body, "plain", "utf-8"))
        for att in attachments:
            local_path = att["local_path"]
            filename = att["filename"]
            mime_type = att.get("mime_type", "application/octet-stream")
            main_type, sub_type = mime_type.split("/", 1) if "/" in mime_type else ("application", "octet-stream")
            with open(local_path, "rb") as f:
                part = MIMEBase(main_type, sub_type)
                part.set_payload(f.read())
            encoders.encode_base64(part)
            part.add_header("Content-Disposition", "attachment", filename=filename)
            msg.attach(part)
    else:
        msg = MIMEText(body, "plain", "utf-8")

    msg["From"] = sender_email
    msg["To"] = to
    msg["Subject"] = subject
    msg["Message-ID"] = message_id
    msg["Date"] = formatdate(usegmt=True)

    if in_reply_to:
        msg["In-Reply-To"] = in_reply_to
    if references:
        msg["References"] = references

    raw_bytes = msg.as_bytes()
    raw_b64 = base64.urlsafe_b64encode(raw_bytes).decode("ascii")

    return raw_b64, message_id


def _send(payload: dict[str, Any], dry_run: bool) -> dict[str, Any]:
    """Build and (optionally) send the message. Returns result dict."""
    required = ("sender_email", "to", "subject", "body")
    for field in required:
        if not payload.get(field):
            return {"ok": False, "error": f"Missing required field: {field}"}

    try:
        comm_cfg = _load_comm_env(_COMM_ENV_PATH)
    except RuntimeError as e:
        return {"ok": False, "error": str(e)}

    try:
        raw_b64, message_id = _build_message(payload)
    except Exception as e:
        return {"ok": False, "error": f"MIME build error: {e}"}

    if dry_run:
        return {
            "ok": True,
            "message_id": message_id,
            "gmail_message_id": None,
            "thread_id_gmail": None,
            "dry_run": True,
        }

    sender_email = payload["sender_email"]
    sa_keyfile = comm_cfg["GMAIL_SA_KEYFILE"]

    try:
        from google.oauth2 import service_account  # type: ignore[import]
        from googleapiclient.discovery import build  # type: ignore[import]
    except ImportError:
        return {"ok": False, "error": "google-api-python-client not installed"}

    try:
        scopes = ["https://www.googleapis.com/auth/gmail.send"]
        creds = service_account.Credentials.from_service_account_file(
            sa_keyfile, scopes=scopes, subject=sender_email
        )
        service = build("gmail", "v1", credentials=creds, cache_discovery=False)
        result = service.users().messages().send(
            userId="me",
            body={"raw": raw_b64},
        ).execute()
    except Exception as e:
        return {"ok": False, "error": str(e)}

    return {
        "ok": True,
        "message_id": message_id,
        "gmail_message_id": result.get("id"),
        "thread_id_gmail": result.get("threadId"),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Send outbound comm email via Gmail DWD")
    parser.add_argument("--payload", metavar="FILE", help="Path to JSON payload file (default: read stdin)")
    parser.add_argument("--dry-run", action="store_true", help="Build MIME but do not send")
    args = parser.parse_args()

    try:
        if args.payload:
            raw = Path(args.payload).read_text(encoding="utf-8")
        else:
            raw = sys.stdin.read()
        payload = json.loads(raw)
    except Exception as e:
        print(json.dumps({"ok": False, "error": f"Payload parse error: {e}"}))
        sys.exit(1)

    result = _send(payload, dry_run=args.dry_run)
    print(json.dumps(result))
    sys.exit(0 if result.get("ok") else 1)


if __name__ == "__main__":
    main()
