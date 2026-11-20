"""Own S spot price forecast workflows (pilot; tasks/temporal-orchestration/s-workflow-interface.md).

`SDailyForecast` runs once per Helsinki issue date D. The model runs only when every input of both
views is ready (user decision 2026-11-06); otherwise the issue gets no forecast and one Sentry
Issue names the missing inputs. Each input is a gate (`GATES`, tasks/s-forecast-gates/): a loop
that checks its readiness key, runs its fetch when the key is not ready, and repeats until the key
is ready or the gate's deadline passes. `SWeeklyRefit` trains and activates a new model
version; a failed refit keeps the active version.
"""

from __future__ import annotations

import asyncio
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import date, datetime, time, timedelta, timezone

from temporalio import workflow
from temporalio.common import RetryPolicy
from temporalio.exceptions import ActivityError, ApplicationError

with workflow.unsafe.imports_passed_through():
    from .activities import CommandRequest, CommandResult, IssueReport
    from .staged import PATCH as STAGED_PATCH
    from .staged import (
        failure_facts,
        failure_step,
        fetch_args,
        fetch_skipped,
        payload_expired,
        payload_reference,
        store_args,
        store_options,
    )

MORNING_OUTAGE_FETCH = time(11, 0)
MORNING_CUT = time(11, 30)
AFTERNOON_FETCH = time(14, 0)
AFTERNOON_CUT = time(14, 30)
DEADLINE = time(18, 0)  # user decision 2026-11-07
# Weekly refit (Monday): after the 10:20 fingrid:fetch, because Sunday's Fingrid hours are training
# targets (model session, 2026-11-07); activation only before 14:15, so a version never changes
# under the 14:30 predict run.
REFIT_START = time(10, 45)
REFIT_DEADLINE = time(14, 15)
POLL_INTERVAL = timedelta(minutes=5)
# ENTSO-E outages that s_v1 reads (freeze section 2): FI and SE3 A77/A80, A78 into FI. Only these, so
# a failure of a zone that the model does not use cannot stop the run.
OUTAGE_FETCH = ["entsoe:fetch", "--dataset=outages", "--zone=FI", "--zone=SE3", "--zone=SE1_FI", "--zone=SE3_FI", "--zone=EE_FI"]

# workflow.patched() ID: a run that started before the gates replays the clock-timed path.
GATES_PATCH = "s-forecast-gates"
GATE_INTERVAL = timedelta(minutes=10)
# The outage proofs have a 30-minute window, so a failed fetch repeats every 5 minutes.
OUTAGE_GATE_INTERVAL = timedelta(minutes=5)
# A gate's last readiness check starts this long before its deadline, and every check of a gate must
# end by the deadline (schedule-to-close), so no result can open a gate after its cut.
LAST_CHECK_BEFORE_DEADLINE = timedelta(minutes=2)
# A gate starts a fetch only with at least this much time left before its deadline (a fetch with
# seconds left cannot finish, and a timer can fire a moment before the deadline).
MIN_FETCH_BUDGET = timedelta(minutes=1)


@dataclass(frozen=True)
class Gate:
    """One input of the run (tasks/s-forecast-gates/spec.md, requirement 3). `key` is the readiness
    key of `view` (laravel/config/orchestration.php); a gate without a view (the outage fetches) is a
    fetch-only proof that opens on one successful fetch. `start` and `deadline` are Helsinki times
    on D. A gate without `fetch` only re-checks."""

    key: str
    view: str | None
    fetch: tuple[str, ...] | None
    start: time
    deadline: time
    interval: timedelta = GATE_INTERVAL


# Morning-view deadlines are the 11:30 cut and the Previous Runs gates end at their fetch cuts
# (10:00 and 14:00), because the freeze defines those inputs as of those times. FI day-ahead prices
# come from the hourly `spot-hourly` job; the day-ahead gates fetch only the neighbour zones. EEX
# futures belong to the MorningChain, so their gate only re-checks.
GATES: list[Gate] = [
    Gate("fingrid_morning", "morning", ("fingrid:fetch",), time(10, 5), MORNING_CUT),
    Gate("fingrid_afternoon", "afternoon", ("fingrid:fetch",), AFTERNOON_FETCH, DEADLINE),
    Gate("day_ahead_d", "morning", ("spot:fetch-neighbours",), time(9, 0), MORNING_CUT),
    Gate("day_ahead_d1", "afternoon", ("spot:fetch-neighbours",), time(13, 0), DEADLINE),
    Gate("jao_capacity_d", "morning", ("jao:fetch",), time(9, 0), MORNING_CUT),
    Gate("jao_capacity_d1", "afternoon", ("jao:fetch",), time(12, 0), DEADLINE),
    Gate("weather_ecmwf_00z", "afternoon", ("weather:fetch", "--only=ecmwf_00z"), time(9, 15), DEADLINE),
    Gate("weather_prev_day1_morning", "morning", ("weather:fetch", "--only=ecmwf_prev_day1"), time(9, 30), time(10, 0)),
    Gate("weather_prev_day1_afternoon", "afternoon", ("weather:fetch", "--only=ecmwf_prev_day1"), time(13, 30),
         AFTERNOON_FETCH),
    Gate("eex_futures", "morning", None, time(9, 0), MORNING_CUT),
    Gate("entsoe_outages_morning", None, tuple(OUTAGE_FETCH), MORNING_OUTAGE_FETCH, MORNING_CUT, OUTAGE_GATE_INTERVAL),
    Gate("entsoe_outages_afternoon", None, tuple(OUTAGE_FETCH), AFTERNOON_FETCH, AFTERNOON_CUT, OUTAGE_GATE_INTERVAL),
]

# Read-only readiness checks retry for up to 30 minutes, as in jobs.py: the gates check many times a
# day, and a short database break must not end the run.
READINESS_OPTIONS = dict(
    start_to_close_timeout=timedelta(minutes=2),
    schedule_to_close_timeout=timedelta(minutes=30),
    retry_policy=RetryPolicy(maximum_attempts=0, initial_interval=timedelta(seconds=20),
                             maximum_interval=timedelta(minutes=2)),
)
# Input fetches and stores. Temporal never retries a failed command (spec S13; jobs.py retry rule), only
# a lost attempt; a gate runs a failed fetch again in its next pass.
FETCH_OPTIONS = dict(
    start_to_close_timeout=timedelta(minutes=15),
    heartbeat_timeout=timedelta(minutes=1),
    retry_policy=RetryPolicy(maximum_attempts=3, initial_interval=timedelta(minutes=1),
                             non_retryable_error_types=["CommandFailed"]),
)
MODEL_OPTIONS = dict(
    start_to_close_timeout=timedelta(minutes=30),
    heartbeat_timeout=timedelta(minutes=1),
    retry_policy=RetryPolicy(maximum_attempts=3, initial_interval=timedelta(minutes=1)),
)
TRAIN_OPTIONS = dict(
    start_to_close_timeout=timedelta(hours=3),
    heartbeat_timeout=timedelta(minutes=1),
    retry_policy=RetryPolicy(maximum_attempts=2, initial_interval=timedelta(minutes=5)),
)
REPORT_OPTIONS = dict(
    start_to_close_timeout=timedelta(seconds=30),
    retry_policy=RetryPolicy(maximum_attempts=5),
)


@dataclass
class SForecastInput:
    # YYYY-MM-DD; None = today in Helsinki (Schedule starts).
    issue_date: str | None = None
    # The outage fetches before each cut are the proof that the outage data of each view is
    # current. A manual start after the cuts cannot make them; it may skip both proofs
    # explicitly (manual checks only, never the Schedule).
    require_cut_fetches: bool = True


@dataclass
class SForecastResult:
    issue_date: str
    status: str  # forecast_stored | no_forecast
    missing: list[str] = field(default_factory=list)
    forecast: dict | None = None
    score: dict | None = None


def _last_sunday_0100_utc(year: int, month: int) -> datetime:
    last_day = date(year, month + 1, 1) - timedelta(days=1)
    sunday = last_day - timedelta(days=(last_day.weekday() - 6) % 7)
    return datetime(sunday.year, sunday.month, sunday.day, 1, tzinfo=timezone.utc)


def helsinki_offset(instant: datetime) -> timedelta:
    """UTC offset of Europe/Helsinki by the EU summer-time rule (01:00 UTC on the last Sunday of
    March and October). The workflow sandbox proxies zoneinfo objects, so the rule is pure
    arithmetic; tests compare it with zoneinfo for every hour of several years."""
    year = instant.astimezone(timezone.utc).year
    summer = _last_sunday_0100_utc(year, 3) <= instant < _last_sunday_0100_utc(year, 10)
    return timedelta(hours=3 if summer else 2)


def local_instant(day: date, at: time) -> datetime:
    """UTC instant of a Helsinki wall-clock time (no times in the 03:00-04:00 change hours are used)."""
    naive = datetime.combine(day, at, tzinfo=timezone.utc)
    for hours in (3, 2):
        candidate = naive - timedelta(hours=hours)
        if helsinki_offset(candidate) == timedelta(hours=hours):
            return candidate
    return naive - timedelta(hours=2)


def local_date(instant: datetime) -> date:
    return (instant + helsinki_offset(instant)).date()


def iso_utc(instant: datetime) -> str:
    return instant.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


async def sleep_until(instant: datetime) -> None:
    delay = instant - workflow.now()
    if delay > timedelta(0):
        await workflow.sleep(delay)


async def run(kind: str, args: list[str], options: dict, interruptible: bool = True) -> CommandResult:
    return await workflow.execute_activity(
        "run_command", CommandRequest(kind, args, interruptible), result_type=CommandResult, **options
    )


async def staged_fetch(args: list[str], options: Callable[[], dict]) -> dict | None:
    """An input fetch as two activities: `--fetch-only`, then `--from-payload` (not interruptible)
    with the same args. `options()` gives each activity the budget left at its start; it raises
    ApplicationError (closed key in its details) when no time is left. Returns None on success, else
    the closed facts for the caller's one Issue, in the shape of jobs.run_step: `command`, `error`,
    `step` and, once the fetch has given a reference, `payload_path`/`payload_sha256`. A failed store
    never starts a new fetch."""
    step, reference = "fetch", None
    try:
        if not workflow.patched(STAGED_PATCH):
            # Started before staged imports: replay the one-process command (no step).
            step = None
            await run("artisan", args, options())
            return None
        fetched = (await run("artisan", fetch_args(args), options())).result
        if fetch_skipped(fetched):
            return None
        reference = payload_reference(fetched)
        if reference is None:
            return {"command": args[0], "error": "fetch_result_invalid"} | failure_facts(step, None)
        step = "store"
        if payload_expired(reference["path"], local_date(workflow.now())):
            # Cleanup could delete the file during the store (spec S6), so the store never starts.
            return {"command": args[0], "error": "payload_expired"} | failure_facts(step, reference)
        await run("artisan", store_args(args, reference), store_options(options()), interruptible=False)
    except ActivityError as error:
        key = error_key(error)
    except ApplicationError as error:
        key = str(error.details[0]) if error.details else error.type or "ApplicationError"
    else:
        return None
    # A partial acquisition fails at the store with step `fetch`: the caller may fetch again.
    return {"command": args[0], "error": key} | (failure_facts(failure_step(step, key), reference) if step else {})


async def report(issue: IssueReport) -> None:
    await workflow.execute_activity("report_issue", issue, **REPORT_OPTIONS)


def error_key(error: BaseException) -> str:
    """The closed error key of a failed command (run_command puts it in the failure details)."""
    cause = getattr(error, "cause", None)
    if isinstance(cause, ApplicationError) and cause.details:
        return str(cause.details[0])
    return type(cause or error).__name__


@workflow.defn(name="SDailyForecast")
class SDailyForecast:
    def __init__(self) -> None:
        self._stage = "starting"
        self._last_readiness: dict | None = None
        # Gate key -> waiting | checking | open | missed | stopped.
        self._gates: dict[str, str] = {}
        # Gate key -> facts of its last failed fetch (staged_fetch).
        self._failures: dict[str, dict] = {}
        self._stopped = False
        # Gates inside an activity (readiness check or fetch); a stopped run waits for them.
        self._busy = 0

    @workflow.query
    def stage(self) -> dict:
        return {"stage": self._stage, "gates": dict(self._gates), "last_readiness": self._last_readiness}

    @workflow.run
    async def run(self, params: SForecastInput) -> SForecastResult:
        today = local_date(workflow.now())
        day = date.fromisoformat(params.issue_date) if params.issue_date else today
        issue = day.isoformat()
        morning_cut = local_instant(day, MORNING_CUT)
        afternoon_cut = local_instant(day, AFTERNOON_CUT)
        if not workflow.patched(GATES_PATCH):
            return await self._run_before_gates(params, day, issue, morning_cut, afternoon_cut)
        if params.require_cut_fetches and workflow.now() >= morning_cut:
            return await self._no_forecast(issue, ["entsoe_outages_morning"], "started_after_morning_cut")

        # 1. Every gate runs as its own loop. The run collects them by deadline (the morning ones at
        # the 11:30 cut) and ends at the first deadline that a gate missed: the day can no longer get a
        # forecast. The other gates start no new activity; the run waits for the ones that run (a store
        # is never cut off) and leaves the sleeping ones behind (no timer is cancelled).
        self._stage = "gates"
        gates = [gate for gate in GATES if gate.view or params.require_cut_fetches]
        tasks = {gate.key: asyncio.ensure_future(self._gate(gate, day, issue)) for gate in gates}

        def collected_at(gate: Gate) -> datetime:
            return max(local_instant(day, gate.deadline), morning_cut)

        def failed() -> BaseException | None:
            # A gate that raised (a readiness check that failed after its retries) fails the run at once.
            return next((task.exception() for task in tasks.values() if task.done() and task.exception()), None)

        for end in sorted({collected_at(gate) for gate in gates}):
            group = [tasks[gate.key] for gate in gates if collected_at(gate) == end]
            await workflow.wait_condition(lambda group=group: failed() is not None or all(task.done() for task in group))
            if error := failed():
                raise error
            missed = [gate for gate in gates if self._gates[gate.key] == "missed"]
            if missed:
                # The run ends at the collection time (a 10:00 miss is reported with the 11:30 cut).
                self._stopped = True
                await sleep_until(end)
                await workflow.wait_condition(lambda: self._busy == 0)
                # Only afternoon gates end at 18:00; a morning, vintage or outage gate is an input missing for good.
                reason = "deadline_missed" if all(gate.deadline == DEADLINE for gate in missed) else "inputs_missing"
                failures = {gate.key: self._failures[gate.key] for gate in missed if gate.key in self._failures}
                return await self._no_forecast(issue, [gate.key for gate in missed], reason,
                                               {"failures": failures} if failures else None)

        # 2. Every gate is open; the afternoon view is as of its cut.
        await sleep_until(afternoon_cut)
        return await self._predict(day, issue, morning_cut, afternoon_cut)

    async def _gate(self, gate: Gate, day: date, issue: str) -> None:
        """One gate loop: from `start`, every `interval` (measured from the pass start): check the key;
        when it is not ready, run the fetch (fetch, then store). A failed fetch is not final: the next
        pass fetches again, also after a store that failed `fetch_incomplete` (a partial acquisition).
        Any other failed store never starts a new fetch (S13), so after one the gate only re-checks (a
        collector's store can still make the key ready). A fetch never starts at or after the deadline
        and never runs past it (`_fetch` budget). The last check starts LAST_CHECK_BEFORE_DEADLINE
        before the deadline and, like every check, must end by it (`_key_ready`); a gate still closed then
        is missed at the deadline. A manual start after the deadline checks once and never fetches.
        Ends with the gate's state in `_gates`."""
        deadline = local_instant(day, gate.deadline)
        last_check = deadline - LAST_CHECK_BEFORE_DEADLINE
        fetch = list(gate.fetch) if gate.fetch else None
        self._gates[gate.key] = "waiting"
        await sleep_until(local_instant(day, gate.start))
        while not self._stopped:
            self._gates[gate.key] = "checking"
            started = workflow.now()
            if gate.view:
                ready = await self._key_ready(issue, gate, deadline)
                if ready:
                    self._gates[gate.key] = "open"
                    return
                if ready is None or workflow.now() >= last_check:
                    # A gate misses at its deadline, so the Issue and an early end fall on the cut.
                    await sleep_until(deadline)
                    self._gates[gate.key] = "missed"
                    return
            elif workflow.now() >= deadline - MIN_FETCH_BUDGET:
                await sleep_until(deadline)
                self._gates[gate.key] = "missed"
                return
            if fetch and not self._stopped:
                self._busy += 1
                try:
                    failure = await self._fetch(fetch, until=deadline)
                finally:
                    self._busy -= 1
                if failure:
                    self._failures[gate.key] = failure
                    if failure.get("step") == "store":
                        fetch = None
                elif gate.view is None:
                    self._gates[gate.key] = "open"
                    return
            await sleep_until(min(started + gate.interval, last_check if gate.view else deadline))
        self._gates[gate.key] = "stopped"

    async def _key_ready(self, issue: str, gate: Gate, deadline: datetime) -> bool | None:
        """The gate's key in one readiness check of its view. Before the deadline the check (with its
        retries) must end by the deadline: a check whose budget the deadline cut and that still fails
        (Temporal stops retrying once the next attempt could not start in time), or whose result comes
        after the deadline, is None (the gate missed; its failure facts are recorded). A check that
        failed for its full 30 minutes well before the deadline fails the run, as before. A manual start
        after the deadline gets the normal 30-minute check. A result without JSON fails the run."""
        started = workflow.now()
        budget = READINESS_OPTIONS["schedule_to_close_timeout"]
        options = READINESS_OPTIONS
        cut_by_deadline = started < deadline and deadline - started < budget
        if cut_by_deadline:
            options = dict(READINESS_OPTIONS, schedule_to_close_timeout=deadline - started)
        self._busy += 1
        try:
            readiness = await self._readiness(issue, gate.view, options)
        except ActivityError as error:
            if not cut_by_deadline:
                raise
            self._failures[gate.key] = {"command": "orchestration:readiness", "error": error_key(error)}
            return None
        finally:
            self._busy -= 1
        if started < deadline < workflow.now():
            return None  # never open a gate on a result from after its cut
        for item in readiness.get("inputs", []):
            if item.get("key") == gate.key:
                return bool(item.get("ready"))
        raise ApplicationError(f"orchestration:readiness has no input {gate.key}", non_retryable=True)

    async def _run_before_gates(self, params: SForecastInput, day: date, issue: str, morning_cut: datetime,
                                afternoon_cut: datetime) -> SForecastResult:
        """The clock-timed path of a run that started before the gates (replay only)."""
        deadline = local_instant(day, DEADLINE)

        # 1. Morning outage fetch, finished before the morning cut (the proof for the morning view).
        if params.require_cut_fetches:
            self._stage = "morning_outage_fetch"
            if workflow.now() >= morning_cut:
                return await self._no_forecast(issue, ["entsoe_outages_morning"], "started_after_morning_cut")
            await sleep_until(local_instant(day, MORNING_OUTAGE_FETCH))
            if failure := await self._fetch(OUTAGE_FETCH, until=morning_cut):
                return await self._no_forecast(issue, ["entsoe_outages_morning"], "inputs_missing", failure)

        # 2. Morning view at its cut. Its inputs are append-only vintages: missing now = missing forever.
        self._stage = "morning_readiness"
        await sleep_until(morning_cut)
        morning = await self._readiness(issue, "morning")
        if not morning["ready"]:
            return await self._no_forecast(issue, self._missing(morning), "inputs_missing")

        # 3. Afternoon fetches after 14:00 (Fingrid hourly data and outage versions before the 14:30 cut).
        self._stage = "afternoon_fetch"
        await sleep_until(local_instant(day, AFTERNOON_FETCH))
        fingrid_failure = await self._fetch(["fingrid:fetch"], until=deadline)
        if params.require_cut_fetches:
            if failure := await self._fetch(OUTAGE_FETCH, until=afternoon_cut):
                return await self._no_forecast(issue, ["entsoe_outages_afternoon"], "inputs_missing", failure)

        # 4. Afternoon view: poll from the cut until every input is ready or the deadline passes.
        self._stage = "afternoon_readiness"
        await sleep_until(afternoon_cut)
        while True:
            afternoon = await self._readiness(issue, "afternoon")
            if afternoon["ready"]:
                break
            if workflow.now() + POLL_INTERVAL > deadline:
                missing = self._missing(afternoon) + (["fingrid_fetch_failed"] if fingrid_failure else [])
                return await self._no_forecast(issue, missing, "deadline_missed", fingrid_failure)
            await workflow.sleep(POLL_INTERVAL)

        return await self._predict(day, issue, morning_cut, afternoon_cut)

    async def _predict(self, day: date, issue: str, morning_cut: datetime, afternoon_cut: datetime) -> SForecastResult:
        """Model run with explicit cuts, then scoring of D+1 (its prices are now complete). A model
        failure (for example artifact_mismatch after a deploy) is one Issue with its closed key."""
        self._stage = "predict"
        try:
            forecast = await run(
                "model",
                ["predict", f"--issue-date={issue}", f"--morning-cut={iso_utc(morning_cut)}",
                 f"--afternoon-cut={iso_utc(afternoon_cut)}"],
                MODEL_OPTIONS,
            )
        except ActivityError as error:
            return await self._no_forecast(issue, [f"predict:{error_key(error)}"], "predict_failed")

        self._stage = "score"
        target = (day + timedelta(days=1)).isoformat()
        try:
            score = (await run("model", ["score", f"--target-date={target}"], MODEL_OPTIONS)).result
        except ActivityError as error:
            score = None
            await report(IssueReport(
                "SDailyForecast", "score_failed",
                f"S forecast {issue}: scoring of {target} failed ({error_key(error)})",
                {"issue_date": issue, "target_date": target, "error": error_key(error)},
            ))

        self._stage = "done"
        return SForecastResult(issue, "forecast_stored", forecast=forecast.result, score=score)

    async def _fetch(self, args: list[str], until: datetime) -> dict | None:
        """Run one input fetch (fetch, then store); both must succeed before `until`. A failure is a missing
        input, not a workflow error: it returns the failure facts (staged_fetch) for the one Issue."""
        if until <= workflow.now():
            return {"command": args[0], "error": "fetch_deadline_passed", "step": "fetch"}

        def options() -> dict:
            budget = until - workflow.now()
            if budget <= timedelta(0):
                raise ApplicationError(f"{args[0]}: no time left before {until}", "fetch_deadline_passed")
            return dict(FETCH_OPTIONS, schedule_to_close_timeout=budget)

        return await staged_fetch(args, options)

    async def _readiness(self, issue: str, view: str, options: dict = READINESS_OPTIONS) -> dict:
        result = await run(
            "artisan", ["orchestration:readiness", "s-forecast", f"--issue-date={issue}", f"--view={view}"],
            options,
        )
        if result.result is None or "ready" not in result.result:
            raise ApplicationError("orchestration:readiness returned no JSON result", non_retryable=True)
        self._last_readiness = result.result
        return result.result

    @staticmethod
    def _missing(readiness: dict) -> list[str]:
        return [item["key"] for item in readiness.get("inputs", []) if not item.get("ready")]

    async def _no_forecast(self, issue: str, missing: list[str], reason: str,
                           failure: dict | None = None) -> SForecastResult:
        """The one Issue of a run without a forecast; `failure` adds the failed input fetch's facts."""
        self._stage = "no_forecast"
        await report(IssueReport(
            workflow="SDailyForecast",
            reason=reason,
            message=f"S forecast {issue}: no forecast ({reason}): " + ", ".join(missing),
            facts={"issue_date": issue, "missing": missing} | (failure or {}),
        ))
        return SForecastResult(issue, "no_forecast", missing=missing)


@dataclass
class SRefitInput:
    # YYYY-MM-DD (Helsinki); None = today (Schedule starts). The run waits until 10:45 of that day.
    run_date: str | None = None


@dataclass
class SRefitResult:
    status: str  # activated | failed
    candidate: str | None = None
    error: str | None = None


@workflow.defn(name="SWeeklyRefit")
class SWeeklyRefit:
    """Monday refit (s-model-freeze.md section 1). The frozen method has no validation gate: `train`
    fails unless the refit is technically valid (every part fitted, finite p50 for the current
    issue), and a successful candidate is activated. A failure keeps the active version.

    It starts at 10:45, after the 10:20 Fingrid fetch, and runs `fingrid:fetch` once more itself:
    Sunday's Fingrid hours are training targets. Train and activate must finish before 14:15, so the
    active version never changes under the 14:30 predict run."""

    @workflow.run
    async def run(self, params: SRefitInput) -> SRefitResult:
        day = date.fromisoformat(params.run_date) if params.run_date else local_date(workflow.now())
        await sleep_until(local_instant(day, REFIT_START))
        deadline = local_instant(day, REFIT_DEADLINE)

        def budget(options: dict) -> dict:
            remaining = deadline - workflow.now()
            if remaining <= timedelta(0):
                raise ApplicationError("refit_deadline_passed", "refit_deadline_passed")
            return dict(options, schedule_to_close_timeout=remaining)

        if failure := await staged_fetch(["fingrid:fetch"], lambda: budget(FETCH_OPTIONS)):
            return await self._failed(failure["error"], None, failure)
        as_of = None
        try:
            as_of = iso_utc(workflow.now())
            trained = await run("model", ["train", f"--as-of={as_of}"], budget(TRAIN_OPTIONS))
            candidate = (trained.result or {}).get("candidate_version")
            if not candidate:
                raise ApplicationError("train returned no candidate_version", "no_candidate")
            await run("model", ["activate", f"--candidate={candidate}"], budget(MODEL_OPTIONS))
        except ActivityError as error:
            return await self._failed(error_key(error), as_of)
        except ApplicationError as error:
            return await self._failed(str(error.details[0]) if error.details else "refit_error", as_of)
        return SRefitResult("activated", candidate)

    async def _failed(self, key: str, as_of: str | None, failure: dict | None = None) -> SRefitResult:
        await report(IssueReport(
            "SWeeklyRefit", "refit_failed",
            f"S weekly refit failed ({key}); active model kept", {"as_of": as_of, "error": key} | (failure or {}),
        ))
        return SRefitResult("failed", error=key)
