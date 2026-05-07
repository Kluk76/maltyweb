"""
lib_sheets — minimal Google Sheets read client.

Service-account auth, read-only scope. One method: read_range(spreadsheet_id, range_a1).
"""
from __future__ import annotations

from pathlib import Path

from google.oauth2.service_account import Credentials
from googleapiclient.discovery import build


SCOPES = ["https://www.googleapis.com/auth/spreadsheets.readonly"]


class SheetsClient:
    def __init__(self, service_account_path: Path):
        creds = Credentials.from_service_account_file(
            str(service_account_path), scopes=SCOPES
        )
        self._service = build("sheets", "v4", credentials=creds, cache_discovery=False)

    def read_range(self, spreadsheet_id: str, range_a1: str) -> list[list[str]]:
        """Returns the raw 2D array of strings. Empty trailing cells are NOT padded."""
        res = (
            self._service.spreadsheets()
            .values()
            .get(spreadsheetId=spreadsheet_id, range=range_a1, valueRenderOption="UNFORMATTED_VALUE", dateTimeRenderOption="FORMATTED_STRING")
            .execute()
        )
        # values can be missing entirely if the range is empty
        return res.get("values", [])

    def read_range_serial(self, spreadsheet_id: str, range_a1: str) -> list[list]:
        """
        Like read_range() but uses SERIAL_NUMBER for dateTimeRenderOption so that
        date/datetime cells come back as numeric serials (float) instead of
        formatted strings.

        Use this for a targeted side-channel fetch of timestamp columns only —
        the main read_range() call must remain FORMATTED_STRING so that row_hash
        values are stable (idempotency guarantee).
        """
        res = (
            self._service.spreadsheets()
            .values()
            .get(
                spreadsheetId=spreadsheet_id,
                range=range_a1,
                valueRenderOption="UNFORMATTED_VALUE",
                dateTimeRenderOption="SERIAL_NUMBER",
            )
            .execute()
        )
        return res.get("values", [])
