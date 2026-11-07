"""Own S spot price forecast workflows (pilot; tasks/temporal-orchestration/s-workflow-interface.md).

`SDailyForecast` runs once per Helsinki issue date D. The model runs only when every input of both
views is ready (user decision 2026-11-06); otherwise the issue gets no forecast and one Sentry
Issue names the missing inputs. `SWeeklyRefit` trains and activates a new model
version; a failed refit keeps the active version.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, datetime, time, timedelta, timezone

from temporalio import workflow
from temporalio.common import RetryPolicy
from temporalio.exceptions import ActivityError, ApplicationError

with workflow.unsafe.imports_passed_through():
    from .activities import CommandRequest, CommandResult, IssueReport

MORNING_OUTAGE_FETCH = time(11, 0)
MORNING_CUT = time(11, 30)
AFTERNOON_FETCH = time(14, 0)
AFTERNOON_CUT = time(14, 30)
DEADLINE = time(18, 0)  # user decision 2026-11-07
POLL_INTERVAL = timedelta(minutes=5)
# ENTSO-E outages that s_v1 reads (freeze section 2): FI and SE3 A77/A80, A78 into FI. Only these, so
# a failure of a zone that the model does not use cannot stop the run.
OUTAGE_FETCH = ["entsoe:fetch", "--dataset=outages", "--zone=FI", "--zone=SE3", "--zone=SE1_FI", "--zone=SE3_FI", "--zone=EE_FI"]

READINESS_OPTIONS = dict(
    start_to_close_timeout=timedelta(minutes=2),
    retry_policy=RetryPolicy(maximum_attempts=3, initial_interval=timedelta(seconds=20)),
)
FETCH_OPTIONS = dict(
    start_to_close_timeout=timedelta(minutes=15),
    heartbeat_timeout=timedelta(minutes=1),
    retry_policy=RetryPolicy(maximum_attempts=3, initial_interval=timedelta(minutes=1)),
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


async def run(kind: str, args: list[str], options: dict) -> CommandResult:
    return await workflow.execute_activity(
        "run_command", CommandRequest(kind, args), result_type=CommandResult, **options
    )


@workflow.defn(name="SDailyForecast")
class SDailyForecast:
    def __init__(self) -> None:
        self._stage = "starting"
        self._last_readiness: dict | None = None

    @workflow.query
    def stage(self) -> dict:
        return {"stage": self._stage, "last_readiness": self._last_readiness}

    @workflow.run
    async def run(self, params: SForecastInput) -> SForecastResult:
        today = local_date(workflow.now())
        day = date.fromisoformat(params.issue_date) if params.issue_date else today
        issue = day.isoformat()
        morning_cut = local_instant(day, MORNING_CUT)
        afternoon_cut = local_instant(day, AFTERNOON_CUT)
        deadline = local_instant(day, DEADLINE)

        # 1. Morning outage fetch, finished before the morning cut (the proof for the morning view).
        if params.require_cut_fetches:
            self._stage = "morning_outage_fetch"
            if workflow.now() >= morning_cut:
                return await self._no_forecast(issue, ["entsoe_outages_morning"], "started_after_morning_cut")
            await sleep_until(local_instant(day, MORNING_OUTAGE_FETCH))
            if not await self._fetch(OUTAGE_FETCH, until=morning_cut):
                return await self._no_forecast(issue, ["entsoe_outages_morning"], "inputs_missing")

        # 2. Morning view at its cut. Its inputs are append-only vintages: missing now = missing forever.
        self._stage = "morning_readiness"
        await sleep_until(morning_cut)
        morning = await self._readiness(issue, "morning")
        if not morning["ready"]:
            return await self._no_forecast(issue, self._missing(morning), "inputs_missing")

        # 3. Afternoon fetches after 14:00 (Fingrid hourly data and outage versions before the 14:30 cut).
        self._stage = "afternoon_fetch"
        await sleep_until(local_instant(day, AFTERNOON_FETCH))
        fingrid_ok = await self._fetch(["fingrid:fetch"], until=deadline)
        if params.require_cut_fetches:
            if not await self._fetch(OUTAGE_FETCH, until=afternoon_cut):
                return await self._no_forecast(issue, ["entsoe_outages_afternoon"], "inputs_missing")

        # 4. Afternoon view: poll from the cut until every input is ready or the deadline passes.
        self._stage = "afternoon_readiness"
        await sleep_until(afternoon_cut)
        while True:
            afternoon = await self._readiness(issue, "afternoon")
            if afternoon["ready"]:
                break
            if workflow.now() + POLL_INTERVAL > deadline:
                missing = self._missing(afternoon) + ([] if fingrid_ok else ["fingrid_fetch_failed"])
                return await self._no_forecast(issue, missing, "deadline_missed")
            await workflow.sleep(POLL_INTERVAL)

        # 5. Model run with explicit cuts, then scoring of D+1 (its prices are now complete).
        self._stage = "predict"
        forecast = await run(
            "model",
            ["predict", f"--issue-date={issue}", f"--morning-cut={iso_utc(morning_cut)}",
             f"--afternoon-cut={iso_utc(afternoon_cut)}"],
            MODEL_OPTIONS,
        )
        self._stage = "score"
        score = await run("model", ["score", f"--target-date={(day + timedelta(days=1)).isoformat()}"], MODEL_OPTIONS)

        self._stage = "done"
        return SForecastResult(issue, "forecast_stored", forecast=forecast.result, score=score.result)

    async def _fetch(self, args: list[str], until: datetime) -> bool:
        """Run one fetch command; it must succeed before `until`. A failure is a missing input, not a workflow error."""
        budget = until - workflow.now()
        if budget <= timedelta(0):
            return False
        options = dict(FETCH_OPTIONS, schedule_to_close_timeout=budget)
        try:
            await run("artisan", args, options)
        except ActivityError:
            return False
        return True

    async def _readiness(self, issue: str, view: str) -> dict:
        result = await run(
            "artisan", ["orchestration:readiness", "s-forecast", f"--issue-date={issue}", f"--view={view}"],
            READINESS_OPTIONS,
        )
        if result.result is None or "ready" not in result.result:
            raise ApplicationError("orchestration:readiness returned no JSON result", non_retryable=True)
        self._last_readiness = result.result
        return result.result

    @staticmethod
    def _missing(readiness: dict) -> list[str]:
        return [item["key"] for item in readiness.get("inputs", []) if not item.get("ready")]

    async def _no_forecast(self, issue: str, missing: list[str], reason: str) -> SForecastResult:
        self._stage = "no_forecast"
        await workflow.execute_activity(
            "report_issue",
            IssueReport(
                workflow="SDailyForecast",
                reason=reason,
                message=f"S forecast {issue}: no forecast ({reason}): " + ", ".join(missing),
                facts={"issue_date": issue, "missing": missing},
            ),
            **REPORT_OPTIONS,
        )
        return SForecastResult(issue, "no_forecast", missing=missing)


@dataclass
class SRefitResult:
    status: str  # activated | failed
    candidate: str | None = None


@workflow.defn(name="SWeeklyRefit")
class SWeeklyRefit:
    """Monday refit (s-model-freeze.md section 1). The frozen method has no validation gate: `train`
    fails unless the refit is technically valid (every part fitted, finite p50 for the current
    issue), and a successful candidate is activated. A failure keeps the active version."""

    @workflow.run
    async def run(self) -> SRefitResult:
        as_of = iso_utc(workflow.now())
        try:
            trained = await run("model", ["train", f"--as-of={as_of}"], TRAIN_OPTIONS)
            candidate = (trained.result or {}).get("candidate_version")
            if not candidate:
                raise ApplicationError("train returned no candidate_version")
            await run("model", ["activate", f"--candidate={candidate}"], MODEL_OPTIONS)
        except (ActivityError, ApplicationError) as error:
            await workflow.execute_activity(
                "report_issue",
                IssueReport(
                    "SWeeklyRefit", "refit_failed",
                    f"S weekly refit failed; active model kept ({type(error).__name__})", {"as_of": as_of},
                ),
                **REPORT_OPTIONS,
            )
            return SRefitResult("failed")
        return SRefitResult("activated", candidate)
