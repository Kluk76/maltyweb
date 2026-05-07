"""
lib_config — load database + Google service-account paths.

Sources, in order of precedence:
  1. environment variables (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD,
     GOOGLE_APPLICATION_CREDENTIALS, BSF_SPREADSHEET_ID)
  2. /var/www/maltytask/config/db.env (the deployed config file)
  3. Hard defaults below.
"""
from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


CONFIG_DIR = Path("/var/www/maltytask/config")
DB_ENV_PATH = CONFIG_DIR / "db.env"
SERVICE_ACCOUNT_PATH = CONFIG_DIR / "service-account.json"

# BSF spreadsheet ID — from the maltytask repo's lib/config.js.
DEFAULT_BSF_SPREADSHEET_ID = "1zTgfTJrLd_kQfwQxfS9SjQ5MLkUYK-CyXX13TKRMJiE"


@dataclass(frozen=True)
class Config:
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    service_account_path: Path
    bsf_spreadsheet_id: str


def _parse_env_file(path: Path) -> dict[str, str]:
    """Minimal KEY=VALUE parser; ignores blanks and # comments."""
    if not path.exists():
        return {}
    out: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip()
    return out


def load() -> Config:
    file_vals = _parse_env_file(DB_ENV_PATH)

    def get(key: str, default: str | None = None) -> str:
        v = os.environ.get(key) or file_vals.get(key) or default
        if v is None:
            raise RuntimeError(f"missing config key: {key}")
        return v

    sa_path = Path(os.environ.get("GOOGLE_APPLICATION_CREDENTIALS")
                   or file_vals.get("GOOGLE_APPLICATION_CREDENTIALS")
                   or str(SERVICE_ACCOUNT_PATH))

    return Config(
        db_host=get("DB_HOST", "127.0.0.1"),
        db_port=int(get("DB_PORT", "3306")),
        db_name=get("DB_NAME"),
        db_user=get("DB_USER"),
        db_password=get("DB_PASSWORD"),
        service_account_path=sa_path,
        bsf_spreadsheet_id=os.environ.get("BSF_SPREADSHEET_ID")
                           or file_vals.get("BSF_SPREADSHEET_ID")
                           or DEFAULT_BSF_SPREADSHEET_ID,
    )
