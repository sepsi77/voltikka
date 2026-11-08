"""Scheduled data and maintenance jobs (phase 2; tasks/temporal-orchestration/phase2-plan.md).

`ArtisanJob` runs a list of `php artisan` steps for one Temporal Schedule. `MorningChain` runs the
morning chain of one Helsinki date: EEX futures and the contract import at the same time, then the
two bounded consumers until their checkpoints are terminal.

Retry rule: a command that exits non-zero (`CommandFailed`) is never run again automatically, as
with the Laravel scheduler. Only a lost attempt (worker restart, heartbeat timeout) is retried.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, time, timedelta

from temporalio import workflow
from temporalio.common import RetryPolicy
from temporalio.exceptions import ActivityError, ApplicationError

with workflow.unsafe.imports_passed_through():
    from .activities import CommandRequest, CommandResult, IssueReport
    from .s_forecast import error_key, local_date, local_instant, report, sleep_until

# Error type of a workflow that ends failed after it sent its own Issue; the Sentry interceptor
# does not report it a second time.
REPORTED_FAILURE = "ReportedFailure"

HEARTBEAT_TIMEOUT = timedelta(minutes=2)
READINESS_OPTIONS = dict(
    start_to_close_timeout=timedelta(minutes=2),
    retry_policy=RetryPolicy(maximum_attempts=3, initial_interval=timedelta(seconds=20)),
)

CONTRACT_IMPORT_START = time(6, 0)
CONSUMER_DEADLINE = time(12, 0)  # MorningConsumerExecution deadline (first tick at or after noon alerts)
CONSUMER_POLL = timedelta(minutes=5)
COMPLETION_POLL = timedelta(minutes=1)
# ContractImportCompletion bounds its own claims to 60 checks / two hours.
COMPLETION_LIMIT = timedelta(hours=2, minutes=10)
TERMINAL = {"ready", "failed"}
CONSUMERS = {
    "morning_retail": ["retail-premiums:collect", "--scheduled"],
    "morning_forecast": ["forecasting:run-fixed-contracts", "--scheduled"],
}


@dataclass
class Step:
    args: list[str]
    timeout_minutes: int = 30
    # Run even when an earlier step failed (for example backup:monitor after backup:run).
    always: bool = False
    # The command sends its own Sentry Issue for a failure (spot:fetch, futures:fetch-eex,
    # contracts:fetch). Other commands relied on the scheduler failure listener, which does not run
    # here, so the workflow reports them.
    self_reports: bool = False
    # Extra attempts after a lost attempt. A failed command is never retried.
    max_attempts: int = 3


@dataclass
class ArtisanJobInput:
    name: str
    steps: list[Step]


@dataclass
class StepOutcome:
    command: str
    status: str  # succeeded | failed | skipped
    error: str | None = None
    result: dict | None = None


@dataclass
class ArtisanJobResult:
    name: str
    outcomes: list[StepOutcome] = field(default_factory=list)


def step_options(step: Step) -> dict:
    return dict(
        start_to_close_timeout=timedelta(minutes=step.timeout_minutes),
        heartbeat_timeout=HEARTBEAT_TIMEOUT,
        retry_policy=RetryPolicy(
            maximum_attempts=step.max_attempts,
            initial_interval=timedelta(seconds=30),
            non_retryable_error_types=["CommandFailed"],
        ),
    )


async def artisan(step: Step) -> CommandResult:
    return await workflow.execute_activity(
        "run_command", CommandRequest("artisan", step.args), result_type=CommandResult, **step_options(step)
    )


async def run_steps(name: str, steps: list[Step]) -> list[StepOutcome]:
    """Run the steps in order. A failure skips later steps except `always` steps, and sends one
    Issue unless the command reports itself."""
    outcomes: list[StepOutcome] = []
    failed = False
    for step in steps:
        command = step.args[0]
        if failed and not step.always:
            outcomes.append(StepOutcome(command, "skipped"))
            continue
        try:
            result = await artisan(step)
            outcomes.append(StepOutcome(command, "succeeded", result=result.result))
        except ActivityError as error:
            failed = True
            key = error_key(error)
            outcomes.append(StepOutcome(command, "failed", key))
            if not step.self_reports:
                await report(IssueReport(
                    name, "command_failed", f"{name}: {' '.join(step.args)} failed ({key})",
                    {"command": command, "error": key},
                ))
    return outcomes


@workflow.defn(name="ArtisanJob")
class ArtisanJob:
    @workflow.run
    async def run(self, params: ArtisanJobInput) -> ArtisanJobResult:
        outcomes = await run_steps(params.name, params.steps)
        failed = [outcome.command for outcome in outcomes if outcome.status == "failed"]
        if failed:
            # Failed in the Temporal UI; the Issue was sent per step (or by the command itself).
            raise ApplicationError(f"{params.name}: failed {', '.join(failed)}", failed, type=REPORTED_FAILURE)
        return ArtisanJobResult(params.name, outcomes)


@dataclass
class MorningInput:
    # YYYY-MM-DD (Helsinki); None = today (Schedule starts at 04:00).
    date: str | None = None


@dataclass
class MorningResult:
    date: str
    eex: str
    contracts: str
    checkpoints: dict = field(default_factory=dict)


EEX_STEP = Step(["futures:fetch-eex"], timeout_minutes=6 * 60, self_reports=True)
CONTRACTS_STEP = Step(["contracts:fetch", "--skip-logos"], timeout_minutes=90, self_reports=True)
COMPLETION_STEP = Step(["contracts:complete-import"], timeout_minutes=35)
EVALUATION_STEP = Step(["forecasting:evaluate-fixed-contracts"], timeout_minutes=30)


async def morning_checkpoints(day: str) -> dict:
    result = await workflow.execute_activity(
        "run_command",
        CommandRequest("artisan", ["orchestration:readiness", "morning", f"--date={day}"]),
        result_type=CommandResult,
        **READINESS_OPTIONS,
    )
    if result.result is None or "checkpoints" not in result.result:
        raise ApplicationError("orchestration:readiness returned no JSON result", non_retryable=True)
    return result.result["checkpoints"]


@workflow.defn(name="EexFutures")
class EexFutures:
    """`futures:fetch-eex` as one heartbeating activity (about 1.7 h). A worker deploy repeats the
    run; the command writes a new `running` checkpoint with its own owner UUID."""

    @workflow.run
    async def run(self, params: MorningInput) -> str:
        outcomes = await run_steps("EexFutures", [EEX_STEP])
        return outcomes[0].status


@workflow.defn(name="ContractImport")
class ContractImport:
    """06:00 full import, then deferred completion while the checkpoint is `pending_completion`,
    then the forecast evaluation (it needs the day's statistics, not today's forecast)."""

    @workflow.run
    async def run(self, params: MorningInput) -> str:
        day = date.fromisoformat(params.date) if params.date else local_date(workflow.now())
        await sleep_until(local_instant(day, CONTRACT_IMPORT_START))
        status = (await run_steps("ContractImport", [CONTRACTS_STEP]))[0].status

        if status == "succeeded":
            limit = workflow.now() + COMPLETION_LIMIT
            while (await morning_checkpoints(day.isoformat())).get("contract_import") == "pending_completion":
                if workflow.now() >= limit:
                    status = "pending_completion"
                    await report(IssueReport(
                        "ContractImport", "completion_not_finished",
                        f"Contract import {day}: still pending_completion after {COMPLETION_LIMIT}",
                        {"date": day.isoformat()},
                    ))
                    break
                await workflow.sleep(COMPLETION_POLL)
                if (await run_steps("ContractImport", [COMPLETION_STEP]))[0].status == "failed":
                    break

        await run_steps("ContractImport", [EVALUATION_STEP])
        return status


@workflow.defn(name="MorningChain")
class MorningChain:
    """One Helsinki date. Event chaining: the consumers start when both producers have ended, not
    at fixed times. The consumer commands keep their freshness gate, claims and deadline alert;
    the workflow ticks them every five minutes until both checkpoints are terminal, and once at or
    after 12:00 so that an unfinished consumer sends its deadline alert."""

    def __init__(self) -> None:
        self._stage = "producers"
        self._checkpoints: dict = {}

    @workflow.query
    def stage(self) -> dict:
        return {"stage": self._stage, "checkpoints": self._checkpoints}

    @workflow.run
    async def run(self, params: MorningInput) -> MorningResult:
        day = date.fromisoformat(params.date) if params.date else local_date(workflow.now())
        iso = day.isoformat()
        child = MorningInput(iso)
        eex = await workflow.start_child_workflow(EexFutures.run, child, id=f"eex-futures-{iso}")
        contracts = await workflow.start_child_workflow(ContractImport.run, child, id=f"contract-import-{iso}")
        eex_status = await self._outcome(eex)
        contracts_status = await self._outcome(contracts)

        self._stage = "consumers"
        deadline = local_instant(day, CONSUMER_DEADLINE)
        while True:
            self._checkpoints = await morning_checkpoints(iso)
            open_consumers = [key for key in CONSUMERS if self._checkpoints.get(key) not in TERMINAL]
            if not open_consumers:
                break
            last_tick = workflow.now() >= deadline
            for key in open_consumers:
                await run_steps("MorningChain", [Step(CONSUMERS[key], timeout_minutes=120, max_attempts=1)])
            if last_tick:
                self._checkpoints = await morning_checkpoints(iso)
                break
            await workflow.sleep(min(CONSUMER_POLL, max(deadline - workflow.now(), timedelta(seconds=1))))

        self._stage = "done"
        return MorningResult(iso, eex_status, contracts_status, self._checkpoints)

    @staticmethod
    async def _outcome(handle) -> str:
        try:
            return await handle
        except Exception:  # noqa: BLE001 - a failed child (already reported) must not stop the consumers
            return "workflow_failed"
