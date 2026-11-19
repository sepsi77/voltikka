"""Staged external imports (tasks/staged-api-imports/design.md section 7): one `--fetch-only`
activity saves the API responses in a payload file, then one `--from-payload` activity stores that
file. Only the reference (path and SHA-256) passes from the first activity to the second.

Pure functions only: workflows import this module.
"""

from __future__ import annotations

import re
from datetime import date
from pathlib import PurePosixPath
from typing import Any

# workflow.patched() ID. A workflow that started before staged imports replays its one-process
# command (orchestration/AGENTS.md, "Staged imports").
PATCH = "staged-api-imports"
FETCH_ONLY = "--fetch-only"
# A store is not interruptible, so it must end within the worker's deploy drain (65 minutes).
STORE_TIMEOUT_MINUTES = 60

_SHA256 = re.compile(r"[0-9a-f]{64}")


def fetch_args(args: list[str]) -> list[str]:
    return [*args, FETCH_ONLY]


def store_args(args: list[str], reference: dict[str, str]) -> list[str]:
    """The same data args as the fetch (the store compares them with the payload header)."""
    return [*args, f"--from-payload={reference['path']}", f"--payload-sha256={reference['sha256']}"]


def payload_reference(result: dict[str, Any] | None) -> dict[str, str] | None:
    """{"path", "sha256"} of a `fetched` result line, or None when the line is not a valid fetch result."""
    if not result or result.get("status") != "fetched" or not isinstance(result.get("payload"), dict):
        return None
    path, sha256 = result["payload"].get("path"), result["payload"].get("sha256")
    if not isinstance(path, str) or not path or not isinstance(sha256, str) or not _SHA256.fullmatch(sha256):
        return None
    return {"path": path, "sha256": sha256}


def fetch_skipped(result: dict[str, Any] | None) -> bool:
    """A fetch that decided not to run (`{"status": "skipped"}`, for example `gas-storage:fetch
    --if-configured` without an API key) has nothing to store."""
    return bool(result) and result.get("status") == "skipped"


def payload_date(path: str) -> date | None:
    """The Helsinki date of a payload file: `<root>/<source>/<YYYY-MM-DD>/<run-id>.ndjson.gz`."""
    parts = PurePosixPath(path).parts
    if len(parts) < 3 or not parts[-1].endswith(".ndjson.gz") or len(parts[-2]) != 10:
        return None
    try:
        return date.fromisoformat(parts[-2])
    except ValueError:
        return None
