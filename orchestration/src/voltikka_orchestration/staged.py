"""Staged external imports (tasks/staged-api-imports/design.md section 7): one `--fetch-only`
activity saves the API responses in a payload file, then one `--from-payload` activity stores that
file. Only the reference (path and SHA-256) passes from the first activity to the second.

Pure functions only: workflows import this module.
"""

from __future__ import annotations

import re
from datetime import date, timedelta
from pathlib import PurePosixPath
from typing import Any

# workflow.patched() ID. A workflow that started before staged imports replays its one-process
# command (orchestration/AGENTS.md, "Staged imports").
PATCH = "staged-api-imports"
FETCH_ONLY = "--fetch-only"
# A store is not interruptible, so it must end within the worker's deploy drain (65 minutes).
STORE_TIMEOUT_MINUTES = 60
# Payload retention: `ImportPayloadCleanup::RETENTION_DAYS` (laravel/app/Services/ImportPayload/)
# deletes a payload when its Helsinki date directory is more than this many days old. A test keeps
# the two values equal.
RETENTION_DAYS = 14
# Spec S6: cleanup never deletes a file that an open run still uses. There is no reference registry.
# Instead every store activity must end within this bound after it is scheduled (a store that cannot
# start in time fails as a timed-out store with one Issue), and no store is scheduled on a payload
# whose date directory could expire within it (`payload_expired()`). Whole days, well below retention.
STORE_SCHEDULE_TO_CLOSE = timedelta(days=1)

_SHA256 = re.compile(r"[0-9a-f]{64}")


def fetch_args(args: list[str]) -> list[str]:
    return [*args, FETCH_ONLY]


def store_args(args: list[str], reference: dict[str, str]) -> list[str]:
    """The same data args as the fetch (the store compares them with the payload header)."""
    return [*args, f"--from-payload={reference['path']}", f"--payload-sha256={reference['sha256']}"]


def store_options(options: dict) -> dict:
    """Activity options of a store: `options` with a schedule-to-close of at most STORE_SCHEDULE_TO_CLOSE."""
    bound = min(options.get("schedule_to_close_timeout") or STORE_SCHEDULE_TO_CLOSE, STORE_SCHEDULE_TO_CLOSE)
    return dict(options, schedule_to_close_timeout=bound)


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


def payload_expired(path: str, today: date) -> bool:
    """True when cleanup could delete the payload before a store scheduled today ends: its date
    directory is more than RETENTION_DAYS old by the end of STORE_SCHEDULE_TO_CLOSE. A path without a
    date directory is outside cleanup."""
    day = payload_date(path)
    return day is not None and day < today - timedelta(days=RETENTION_DAYS) + STORE_SCHEDULE_TO_CLOSE


# The closed store error of a partial acquisition (StagedImport, `ImportStoreResult.errorStep='fetch'`):
# the fetch exited 0 with failed units, the store kept the rows of the good units and exited 1 with
# step `fetch`. It is an API failure, not a store bug, so a repeat (fetch, then store) is safe.
FETCH_INCOMPLETE = "fetch_incomplete"


def failure_step(step: str, error: str) -> str:
    """The step that a failed staged activity names: a store failing `fetch_incomplete` reports `fetch`."""
    return "fetch" if step == "store" and error == FETCH_INCOMPLETE else step


def failure_facts(step: str, reference: dict[str, str] | None) -> dict[str, str]:
    """The closed facts that name a failed staged step in its one Issue: `step` (`fetch` or `store`)
    and, once the fetch has given a reference, `payload_path`/`payload_sha256` for an ImportReplay."""
    return {"step": step} | {f"payload_{key}": value for key, value in (reference or {}).items()}
