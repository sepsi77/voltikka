"""Activities: one process per unit of work, and Sentry Issues for outcomes that need a person.

Rule (phase 0 decision record): activity inputs and results go to Temporal Cloud, so they hold
only references and safe facts (dates, counts, status keys). Never pass secrets, payloads or raw
command output through Temporal. Command output goes to the container log only.
"""

from __future__ import annotations

import asyncio
import json
import sys
import time
from dataclasses import dataclass, field
from typing import Any

import sentry_sdk
from temporalio import activity
from temporalio.exceptions import ApplicationError

from .settings import Settings

HEARTBEAT_SECONDS = 10


@dataclass
class CommandRequest:
    # "artisan": php artisan <args> in the Laravel directory.
    # "model": python -m <S_MODEL_MODULE> <args> (stub result when no module is configured).
    kind: str
    args: list[str]


@dataclass
class CommandResult:
    exit_code: int
    # The last stdout line when it is a JSON object, else None.
    result: dict[str, Any] | None
    duration_seconds: float


@dataclass
class IssueReport:
    workflow: str
    reason: str
    message: str
    facts: dict[str, Any] = field(default_factory=dict)
    level: str = "error"


def last_json_object(stdout: str) -> dict[str, Any] | None:
    for line in reversed(stdout.splitlines()):
        line = line.strip()
        if not line:
            continue
        try:
            value = json.loads(line)
        except ValueError:
            return None
        return value if isinstance(value, dict) else None
    return None


class Activities:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def _argv(self, request: CommandRequest) -> tuple[list[str], str | None]:
        if request.kind == "artisan":
            return [self._settings.php_binary, "artisan", *request.args], self._settings.laravel_dir
        if request.kind == "model":
            assert self._settings.s_model_module is not None
            return [sys.executable, "-m", self._settings.s_model_module, *request.args], None
        raise ApplicationError(f"Unknown command kind {request.kind}", non_retryable=True)

    @activity.defn(name="run_command")
    async def run_command(self, request: CommandRequest) -> CommandResult:
        if request.kind == "model" and self._settings.s_model_module is None:
            activity.logger.info("S model module not configured; stub result for %s", request.args[:1])
            return CommandResult(0, {"status": "stub", "command": request.args[0]}, 0.0)

        argv, cwd = self._argv(request)
        started = time.monotonic()
        process = await asyncio.create_subprocess_exec(
            *argv, cwd=cwd, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
        )
        communicate = asyncio.ensure_future(process.communicate())
        try:
            while True:
                done, _ = await asyncio.wait({communicate}, timeout=HEARTBEAT_SECONDS)
                if done:
                    break
                activity.heartbeat()
        except asyncio.CancelledError:
            # Worker shutdown or activity timeout: stop the child so a retry cannot overlap it.
            process.kill()
            await process.wait()
            raise

        stdout_bytes, stderr_bytes = communicate.result()
        stdout = stdout_bytes.decode(errors="replace")
        stderr = stderr_bytes.decode(errors="replace")
        duration = round(time.monotonic() - started, 3)
        name = " ".join(argv[1:3])
        if stdout.strip():
            activity.logger.info("%s stdout:\n%s", name, stdout[-4000:])
        if stderr.strip():
            activity.logger.warning("%s stderr:\n%s", name, stderr[-4000:])

        result = last_json_object(stdout)
        exit_code = process.returncode or 0
        if exit_code != 0:
            # Only a closed error key from the JSON line reaches Temporal, never raw output.
            error_key = (result or {}).get("error", "no_json_error")
            raise ApplicationError(
                f"{name} exited {exit_code} ({error_key})",
                type="CommandFailed",
            )
        return CommandResult(exit_code, result, duration)

    @activity.defn(name="report_issue")
    async def report_issue(self, report: IssueReport) -> None:
        activity.logger.error("%s: %s %s", report.workflow, report.reason, report.facts)
        if not self._settings.sentry_dsn:
            return
        with sentry_sdk.new_scope() as scope:
            scope.fingerprint = ["temporal-workflow", report.workflow, report.reason]
            scope.set_tag("workflow", report.workflow)
            scope.set_tag("reason", report.reason)
            scope.set_context("workflow_facts", report.facts)
            sentry_sdk.capture_message(report.message, level=report.level)
        sentry_sdk.flush(timeout=5)
