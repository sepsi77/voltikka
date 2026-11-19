"""Scheduled data and maintenance jobs (phase 2; tasks/temporal-orchestration/phase2-plan.md).

`ArtisanJob` runs a list of `php artisan` steps for one Temporal Schedule. `MorningChain` runs the
morning chain of one Helsinki date: EEX futures and the contract import at the same time, then the
two bounded consumers in independent lanes until each has completed its scheduled scope, with a
noon deadline check that never waits for a producer or for the other consumer. A staged step (an
external API import) runs as two activities, fetch then store (`staged.py`); `ImportReplay` runs
only the store of a saved payload, by hand, after a fix.

Retry rule: a command that exits non-zero (`CommandFailed`) is never run again automatically, as
with the Laravel scheduler. Only a lost attempt (worker shutdown, heartbeat timeout) is retried.
"""

from __future__ import annotations

import asyncio
from dataclasses import dataclass, field, replace
from datetime import date, datetime, time, timedelta

from temporalio import workflow
from temporalio.common import RetryPolicy
from temporalio.exceptions import ActivityError, ApplicationError

with workflow.unsafe.imports_passed_through():
    from .activities import CommandRequest, CommandResult, IssueReport
    from .s_forecast import error_key, local_date, local_instant, report, sleep_until
    from .staged import PATCH as STAGED_PATCH
    from .staged import (
        STORE_TIMEOUT_MINUTES,
        failure_facts,
        fetch_args,
        fetch_skipped,
        payload_date,
        payload_expired,
        payload_reference,
        store_args,
        store_options,
    )

# Error type of a workflow that ends failed after its Issue was sent (by the workflow or by the
# command itself); the Sentry interceptor does not report it a second time.
REPORTED_FAILURE = "ReportedFailure"

HEARTBEAT_TIMEOUT = timedelta(minutes=2)
# The one exception to "a failed command is never retried": `orchestration:readiness` only reads
# checkpoints, so a failed read repeats for up to 30 minutes (orchestration/AGENTS.md). A short
# database break must not end a morning; a longer one fails the run with one Issue.
READINESS_OPTIONS = dict(
    start_to_close_timeout=timedelta(minutes=2),
    schedule_to_close_timeout=timedelta(minutes=30),
    retry_policy=RetryPolicy(maximum_attempts=0, initial_interval=timedelta(seconds=20),
                             maximum_interval=timedelta(minutes=2)),
)

MORNING_START = time(4, 0)  # the Schedule start; a run for a later date waits until then
CONTRACT_IMPORT_START = time(6, 0)
CONSUMER_DEADLINE = time(12, 0)  # MorningConsumerExecution deadline (first tick at or after noon alerts)
CONSUMER_POLL = timedelta(minutes=5)
COMPLETION_POLL = timedelta(minutes=1)
# ContractImportCompletion bounds its own claims to 60 checks / two hours.
COMPLETION_LIMIT = timedelta(hours=2, minutes=10)
# A claim-based step must end within the worker's graceful drain (worker.GRACEFUL_SHUTDOWN), or a
# deploy still kills it at the end of the drain and its claim stays locked.
CONSUMER_TIMEOUT_MINUTES = 60
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
    # The command sends its own Sentry Issue when it exits non-zero (spot:fetch, futures:fetch-eex,
    # contracts:fetch, contracts:complete-import). Other commands relied on the scheduler failure
    # listener, which does not run here, so the workflow reports them. A lost or timed-out attempt
    # never reached the command's reporter, so the workflow reports it for every step.
    self_reports: bool = False
    # Total attempts, including the first; only a lost attempt is repeated. A failed command is never retried.
    max_attempts: int = 3
    # False for commands with a durable claim or lease: they finish during the deploy drain instead
    # of being killed (see CommandRequest.interruptible).
    interruptible: bool = True
    # An external API import (tasks/staged-api-imports/): `--fetch-only` with this step's
    # `interruptible`, then `--from-payload` with the same args, never interruptible and at most
    # STORE_TIMEOUT_MINUTES, ending within STORE_SCHEDULE_TO_CLOSE of being scheduled. A failed fetch
    # skips the store; a failed store never starts a new fetch.
    staged: bool = False


@dataclass
class ArtisanJobInput:
    name: str
    steps: list[Step]


@dataclass
class StepOutcome:
    command: str
    status: str  # succeeded | failed | skipped
    error: str | None = None
    # The last JSON line of the step's command. Staged step: the store's line; a failed store keeps
    # the payload reference ({"payload": {"path", "sha256"}}) for an ImportReplay.
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


async def artisan(step: Step, day: str | None = None, store: bool = False) -> CommandResult:
    options = step_options(step)
    return await workflow.execute_activity(
        "run_command",
        CommandRequest("artisan", step.args, step.interruptible, day),
        result_type=CommandResult,
        **(store_options(options) if store else options),
    )


def reported_by_command(step: Step, error: ActivityError) -> bool:
    """Only a non-zero exit of a self-reporting command has already sent its Issue."""
    cause = error.cause
    return step.self_reports and isinstance(cause, ApplicationError) and cause.type == "CommandFailed"


def store_step(step: Step, reference: dict[str, str]) -> Step:
    """The store activity of a staged step: same data args, not interruptible (it finishes within
    the deploy drain), same attempts and self-reporting."""
    return replace(step, args=store_args(step.args, reference), interruptible=False, staged=False,
                   timeout_minutes=min(step.timeout_minutes, STORE_TIMEOUT_MINUTES))


async def run_steps(name: str, steps: list[Step], day: str | None = None) -> list[StepOutcome]:
    """Run the steps in order. A failure skips later steps except `always` steps, and sends one
    Issue unless the command has already reported it. With `day`, a step that would start after that
    Helsinki date has passed fails (`DatePassed`) before PHP starts."""
    outcomes: list[StepOutcome] = []
    failed = False
    for step in steps:
        if failed and not step.always:
            outcomes.append(StepOutcome(step.args[0], "skipped"))
            continue
        outcome = await run_step(name, step, day)
        failed = failed or outcome.status == "failed"
        outcomes.append(outcome)
    return outcomes


async def run_step(name: str, step: Step, day: str | None, reference: dict[str, str] | None = None) -> StepOutcome:
    """One step. With `reference` (ImportReplay), only the store of that saved payload runs."""
    command = step.args[0]
    # patched(): a workflow that started before staged imports keeps its one-process command.
    staged = reference is not None or (step.staged and workflow.patched(STAGED_PATCH))
    try:
        if staged and reference is None:
            fetched = (await artisan(replace(step, args=fetch_args(step.args), staged=False), day)).result
            if fetch_skipped(fetched):
                return StepOutcome(command, "succeeded", result=fetched)
            reference = payload_reference(fetched)
            if reference is None:
                # The command printed no payload reference, so it reported nothing either.
                return await failed_step(name, step, "fetch_result_invalid", "fetch returned no payload reference",
                                         failure_facts("fetch", None))
        if reference is not None:
            if payload_expired(reference["path"], local_date(workflow.now())):
                # Cleanup could delete the file during the store (spec S6), so the store never starts.
                return await failed_step(name, step, "payload_expired", "payload is past retention",
                                         failure_facts("store", reference), reference)
            result = await artisan(store_step(step, reference), day, store=True)
        else:
            result = await artisan(step, day)
        return StepOutcome(command, "succeeded", result=result.result)
    except ActivityError as error:
        key = error_key(error)
        facts = failure_facts("store" if reference else "fetch", reference) if staged else {}
        if reported_by_command(step, error):
            return StepOutcome(command, "failed", key, result={"payload": reference} if reference else None)
        return await failed_step(name, step, key, f"failed ({key})", facts, reference)


async def failed_step(name: str, step: Step, key: str, message: str, facts: dict,
                      reference: dict[str, str] | None = None) -> StepOutcome:
    """The one workflow Issue of a failed step; a failed store keeps its payload reference."""
    await report(IssueReport(name, "command_failed", f"{name}: {' '.join(step.args)} {message}",
                             {"command": step.args[0], "error": key} | facts))
    return StepOutcome(step.args[0], "failed", key, result={"payload": reference} if reference else None)


def reported_failure(message: str, details: list) -> ApplicationError:
    return ApplicationError(message, details, type=REPORTED_FAILURE)


@workflow.defn(name="ArtisanJob")
class ArtisanJob:
    @workflow.run
    async def run(self, params: ArtisanJobInput) -> ArtisanJobResult:
        outcomes = await run_steps(params.name, params.steps)
        failed = [outcome.command for outcome in outcomes if outcome.status == "failed"]
        if failed:
            # Failed in the Temporal UI; the Issue was sent per step (or by the command itself).
            raise reported_failure(f"{params.name}: failed {', '.join(failed)}", failed)
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
    consumers: dict = field(default_factory=dict)


# Staged: the interruptible fetch (about 1.7 h) repeats on a worker deploy; its store finishes in the drain.
EEX_STEP = Step(["futures:fetch-eex"], timeout_minutes=6 * 60, self_reports=True, staged=True)
# Its first post-import work holds the 30-minute import execution lock (ContractImportCompletion):
# a killed run would leave it locked, and a retry would fail on it. So it finishes in the drain.
CONTRACTS_STEP = Step(["contracts:fetch", "--skip-logos"], timeout_minutes=60, self_reports=True, interruptible=False,
                      staged=True)
# Its claim cannot be resumed after a kill (an expired claim becomes interrupted_execution).
COMPLETION_STEP = Step(["contracts:complete-import"], timeout_minutes=35, self_reports=True, interruptible=False)
EVALUATION_STEP = Step(["forecasting:evaluate-fixed-contracts"], timeout_minutes=30)


async def morning_facts(day: str) -> dict:
    result = await workflow.execute_activity(
        "run_command",
        CommandRequest("artisan", ["orchestration:readiness", "morning", f"--date={day}"]),
        result_type=CommandResult,
        **READINESS_OPTIONS,
    )
    facts = result.result or {}
    if not {"checkpoints", "scheduled_complete", "deadline_alerted"} <= facts.keys():
        raise ApplicationError("orchestration:readiness returned no JSON result", non_retryable=True)
    return facts


@workflow.defn(name="EexFutures")
class EexFutures:
    """`futures:fetch-eex` as one heartbeating activity (about 1.7 h). A worker deploy repeats the
    run; the command writes a new `running` checkpoint with its own owner UUID."""

    @workflow.run
    async def run(self, params: MorningInput) -> str:
        outcome = (await run_steps("EexFutures", [EEX_STEP], params.date))[0]
        if outcome.status == "failed":
            raise reported_failure(f"futures:fetch-eex failed ({outcome.error})", [outcome.error])
        return outcome.status


async def complete_contract_import(name: str, iso: str) -> str:
    """After a `contracts:fetch` store that exited 0: `contracts:complete-import` every minute while
    the checkpoint is `pending_completion` (at most COMPLETION_LIMIT). Returns `succeeded` or the
    closed outcome (`pending_completion`, `completion_failed`, `import_<checkpoint>`)."""
    status = "succeeded"
    limit = workflow.now() + COMPLETION_LIMIT
    while (state := (await morning_facts(iso))["checkpoints"].get("contract_import")) == "pending_completion":
        if workflow.now() >= limit:
            status = "pending_completion"
            await report(IssueReport(
                name, "completion_not_finished",
                f"Contract import {iso}: still pending_completion after {COMPLETION_LIMIT}",
                {"date": iso},
            ))
            break
        await workflow.sleep(COMPLETION_POLL)
        if (await run_steps(name, [COMPLETION_STEP], iso))[0].status == "failed":
            status = "completion_failed"
            break
    else:
        # Exit 0 is not a full import: a partial acquisition stores `incomplete` (the command
        # already sent its Issue for the failed postcodes); any other state is unexpected.
        if state != "ready":
            status = f"import_{state or 'missing'}"
            if state != "incomplete":
                await report(IssueReport(
                    name, "import_not_ready",
                    f"Contract import {iso}: contracts:fetch exited 0 with checkpoint {state or 'missing'}",
                    {"date": iso, "checkpoint": state},
                ))
    return status


@workflow.defn(name="ContractImport")
class ContractImport:
    """06:00 full import, then deferred completion while the checkpoint is `pending_completion`,
    then the forecast evaluation (it needs the day's statistics, not today's forecast)."""

    @workflow.run
    async def run(self, params: MorningInput) -> str:
        day = date.fromisoformat(params.date) if params.date else local_date(workflow.info().workflow_start_time)
        await sleep_until(local_instant(day, CONTRACT_IMPORT_START))
        iso = day.isoformat()
        status = (await run_steps("ContractImport", [CONTRACTS_STEP], iso))[0].status
        if status == "succeeded":
            status = await complete_contract_import("ContractImport", iso)

        evaluation = (await run_steps("ContractImport", [EVALUATION_STEP], iso))[0].status
        if status != "succeeded" or evaluation == "failed":
            raise reported_failure(f"Contract import {day}: {status}, evaluation {evaluation}", [status, evaluation])
        return status


@workflow.defn(name="MorningChain")
class MorningChain:
    """One Helsinki date. Event chaining: each consumer lane starts when both producers have ended,
    not at a fixed time. The consumer commands keep their freshness gate, claims and deadline alert;
    a lane ticks its command every five minutes until the scheduled scope is complete
    (`scheduled_complete`, not only `ready`: a manual subset run is not the daily scope). At 12:00
    every incomplete lane runs one tick, also when its producers still run or its claim failed, so
    `MorningConsumerExecution` reserves the deadline alert. The two lanes never wait for each other.
    An incomplete morning ends with one Issue and a failed run."""

    def __init__(self) -> None:
        self._stage = "producers"
        self._producers_done = False
        self._lanes: dict[str, str] = {}

    @workflow.query
    def stage(self) -> dict:
        return {"stage": self._stage, "producers_done": self._producers_done, "lanes": self._lanes}

    @workflow.run
    async def run(self, params: MorningInput) -> MorningResult:
        # The Schedule's date is the date the run started, not the time of its first workflow task:
        # after a worker outage across midnight, the run must fail visibly, not do the next morning.
        day = date.fromisoformat(params.date) if params.date else local_date(workflow.info().workflow_start_time)
        if day < local_date(workflow.now()):
            # The commands write the current Helsinki date, so a run for a past date would read old
            # checkpoints while it writes today's facts. A later activity that would start after the
            # date has passed (a worker outage across midnight) is refused by run_command (`day`).
            raise ApplicationError(f"Morning chain {day}: the date has passed", non_retryable=True)
        await sleep_until(local_instant(day, MORNING_START))
        iso = day.isoformat()
        noon = local_instant(day, CONSUMER_DEADLINE)
        child = MorningInput(iso)
        eex = await workflow.start_child_workflow(EexFutures.run, child, id=f"eex-futures-{iso}")
        contracts = await workflow.start_child_workflow(ContractImport.run, child, id=f"contract-import-{iso}")

        async def producers() -> tuple[str, str]:
            outcome = (await self._outcome(eex), await self._outcome(contracts))
            self._producers_done = True
            return outcome

        producer_task = asyncio.ensure_future(producers())
        noon_task = asyncio.ensure_future(self._noon_check(iso, noon))
        self._stage = "consumers"
        lanes = await asyncio.gather(*(self._lane(key, iso, noon) for key in CONSUMERS))
        consumers = dict(zip(CONSUMERS, lanes))
        # Children close with the parent, so wait for a producer that still runs after noon.
        eex_status, contracts_status = await producer_task
        await noon_task
        self._stage = "done"

        # A scheduled run that finished after noon is complete but late: PHP reserved its deadline alert.
        alerted = (await morning_facts(iso))["deadline_alerted"]
        consumers = {key: ("complete_after_deadline" if state == "complete" and alerted.get(key) else state)
                     for key, state in consumers.items()}
        incomplete = {key: state for key, state in consumers.items() if state != "complete"}
        if incomplete:
            await report(IssueReport(
                "MorningChain", "morning_incomplete",
                f"Morning chain {iso}: incomplete consumers " + ", ".join(f"{k}={v}" for k, v in incomplete.items()),
                {"date": iso, "eex": eex_status, "contracts": contracts_status, "consumers": consumers},
            ))
            raise reported_failure(f"Morning chain {iso}: incomplete {sorted(incomplete)}", sorted(incomplete))
        return MorningResult(iso, eex_status, contracts_status, consumers)

    async def _noon_check(self, iso: str, noon: datetime) -> None:
        """One tick at 12:00 for every consumer that is not complete, independent of the lanes: a lane
        can still wait for a producer or for its own running command. A noon tick on a running or
        failed claim only reserves the deadline alert (MorningConsumerExecution), so a second tick
        from the lane is harmless."""
        await sleep_until(noon)
        facts = await morning_facts(iso)
        open_keys = [key for key in CONSUMERS if not facts["scheduled_complete"].get(key)]
        await asyncio.gather(*(run_steps("MorningChain", [Step(CONSUMERS[key], timeout_minutes=5, max_attempts=1,
                                                                 interruptible=False)], iso) for key in open_keys))

    async def _lane(self, key: str, iso: str, noon: datetime) -> str:
        self._lanes[key] = "waiting_for_producers"
        remaining = noon - workflow.now()
        if remaining > timedelta(0):
            try:
                await workflow.wait_condition(lambda: self._producers_done, timeout=remaining)
            except asyncio.TimeoutError:
                pass  # noon: tick anyway, so the deadline alert is not lost behind a slow producer
        step = Step(CONSUMERS[key], timeout_minutes=CONSUMER_TIMEOUT_MINUTES, max_attempts=1, interruptible=False)
        while True:
            facts = await morning_facts(iso)
            if facts["scheduled_complete"].get(key):
                self._lanes[key] = "complete"
                return "complete"
            at_deadline = workflow.now() >= noon
            if facts["checkpoints"].get(key) == "failed" and not at_deadline:
                # A failed claim admits no new writes; only its noon tick still has work to do.
                self._lanes[key] = "failed_until_deadline"
                await sleep_until(noon)
                continue
            self._lanes[key] = "ticking"
            await run_steps("MorningChain", [step], iso)
            if at_deadline:
                facts = await morning_facts(iso)
                state = "complete" if facts["scheduled_complete"].get(key) else (facts["checkpoints"].get(key) or "missing")
                self._lanes[key] = state
                return state
            await workflow.sleep(min(CONSUMER_POLL, max(noon - workflow.now(), timedelta(seconds=1))))

    @staticmethod
    async def _outcome(handle) -> str:
        try:
            return await handle
        except Exception:  # noqa: BLE001 - a failed child (already reported) must not stop the consumers
            return "failed"


# Commands whose rows or checkpoints carry the import date (spec S9): a replay must be for today.
DATE_BOUND_COMMANDS = frozenset({"contracts:fetch", "futures:fetch-eex", "futures:fetch-eex-europe"})
# Commands that send their own Sentry Issue for a non-zero exit (the self_reports steps).
SELF_REPORTING_COMMANDS = frozenset({"contracts:fetch", "futures:fetch-eex", "spot:fetch"})


@dataclass
class ImportReplayInput:
    command: str  # for example "contracts:fetch"
    args: list[str]  # the data args of the original fetch, without the command (["--skip-logos"])
    payload_path: str
    sha256: str
    # YYYY-MM-DD (Helsinki) for the date-bound commands; None = the date in the payload path.
    date: str | None = None


@workflow.defn(name="ImportReplay")
class ImportReplay:
    """Store a saved payload again after a fix (spec S16): the store activity only, never a fetch.
    Started by hand with the user's approval; no Schedule. A date-bound command is refused before PHP
    starts unless the payload is today's (the PHP command checks too). A contract replay then runs
    the ContractImport completion loop."""

    @workflow.run
    async def run(self, params: ImportReplayInput) -> StepOutcome:
        day = None
        if params.command in DATE_BOUND_COMMANDS:
            today = local_date(workflow.now())
            in_path = payload_date(params.payload_path)
            try:
                wanted = date.fromisoformat(params.date) if params.date else in_path
            except ValueError:
                wanted = None
            if wanted != today or (in_path is not None and in_path != wanted):
                facts = {"command": params.command, "date": params.date, "payload_date": in_path and in_path.isoformat(),
                         "today": today.isoformat()}
                await report(IssueReport(
                    "ImportReplay", "wrong_date",
                    f"ImportReplay {params.command}: the payload is not from today ({today})", facts,
                ))
                raise reported_failure(f"ImportReplay {params.command}: wrong_date", ["wrong_date"])
            day = today.isoformat()

        step = Step([params.command, *params.args], timeout_minutes=STORE_TIMEOUT_MINUTES,
                    self_reports=params.command in SELF_REPORTING_COMMANDS)
        # run_step names the store and its payload in a failure Issue and refuses an expired payload.
        outcome = await run_step("ImportReplay", step, day, {"path": params.payload_path, "sha256": params.sha256})
        if outcome.status == "succeeded" and params.command == "contracts:fetch":
            outcome.status = await complete_contract_import("ImportReplay", day)
        if outcome.status != "succeeded":
            raise reported_failure(f"ImportReplay {params.command}: {outcome.status} ({outcome.error})",
                                   [outcome.status, outcome.error])
        return outcome
