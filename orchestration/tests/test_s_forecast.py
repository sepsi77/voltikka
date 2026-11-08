from __future__ import annotations

import uuid
from zoneinfo import ZoneInfo
from datetime import date, datetime, time, timedelta, timezone

import pytest
from temporalio import activity
from temporalio.exceptions import ApplicationError
from temporalio.testing import WorkflowEnvironment
from temporalio.worker import Worker

from voltikka_orchestration.activities import CommandRequest, CommandResult, IssueReport
from voltikka_orchestration.s_forecast import (
    OUTAGE_FETCH,
    SDailyForecast,
    helsinki_offset,
    local_instant,
    SForecastInput,
    SRefitInput,
    SWeeklyRefit,
)

QUEUE = "test-queue"
OUTAGES = " ".join(OUTAGE_FETCH)
HELSINKI = ZoneInfo("Europe/Helsinki")


class FakeCommands:
    """Records every command; readiness answers come from the scenario."""

    def __init__(self, *, morning_ready=True, afternoon_ready_after=0, failing=()):
        self.calls: list[tuple[str, list[str], str]] = []
        self.reports: list[IssueReport] = []
        self.morning_ready = morning_ready
        self.afternoon_ready_after = afternoon_ready_after
        self.afternoon_checks = 0
        self.failing = set(failing)

    @activity.defn(name="run_command")
    async def run_command(self, request: CommandRequest) -> CommandResult:
        now = activity.info().current_attempt_scheduled_time.astimezone(HELSINKI).strftime("%H:%M")
        self.calls.append((request.kind, request.args, now))
        name = request.args[0]
        if " ".join(request.args) in self.failing or name in self.failing:
            raise ApplicationError(f"{name} failed", "test_error", non_retryable=True)
        if name == "orchestration:readiness":
            view = request.args[-1].removeprefix("--view=")
            if view == "morning":
                ready = self.morning_ready
                inputs = [{"key": "fingrid_morning", "ready": ready, "detail": "x"}]
            else:
                self.afternoon_checks += 1
                ready = self.afternoon_checks > self.afternoon_ready_after
                inputs = [{"key": "day_ahead_d1", "ready": ready, "detail": "x"}]
            return CommandResult(0, {"ready": ready, "inputs": inputs}, 0.1)
        if name == "predict":
            return CommandResult(0, {"status": "stored", "vintage_id": 7}, 0.1)
        if name == "train":
            return CommandResult(0, {"status": "trained", "candidate_version": "s-test"}, 0.1)
        return CommandResult(0, None, 0.1)

    @activity.defn(name="report_issue")
    async def report_issue(self, report: IssueReport) -> None:
        self.reports.append(report)

    def names(self) -> list[str]:
        return [" ".join(args) for _, args, _ in self.calls]


def test_helsinki_offset_matches_zoneinfo_every_hour():
    hour = datetime(2024, 1, 1, tzinfo=timezone.utc)
    while hour < datetime(2031, 1, 1, tzinfo=timezone.utc):
        assert helsinki_offset(hour) == hour.astimezone(HELSINKI).utcoffset(), hour
        hour += timedelta(hours=1)


def test_local_instant_matches_zoneinfo_for_the_workflow_times():
    day = date(2024, 1, 1)
    while day < date(2031, 1, 1):
        for at in (time(10, 55), time(11, 30), time(14, 30), time(18, 0)):
            assert local_instant(day, at) == datetime.combine(day, at, tzinfo=HELSINKI).astimezone(timezone.utc)
        day += timedelta(days=1)


@pytest.fixture(scope="session")
async def env():
    async with await WorkflowEnvironment.start_time_skipping() as environment:
        yield environment


async def run_daily(env, fake: FakeCommands, params: SForecastInput):
    async with Worker(
        env.client, task_queue=QUEUE, workflows=[SDailyForecast, SWeeklyRefit],
        activities=[fake.run_command, fake.report_issue],
        workflow_failure_exception_types=[Exception],
    ):
        return await env.client.execute_workflow(
            SDailyForecast.run, params, id=f"s-test-{uuid.uuid4()}", task_queue=QUEUE
        )


async def tomorrow(env) -> str:
    now = await env.get_current_time()
    return (now.astimezone(HELSINKI).date() + timedelta(days=1)).isoformat()


async def test_runs_model_only_after_every_input_is_ready(env):
    fake = FakeCommands(afternoon_ready_after=2)
    issue = await tomorrow(env)

    result = await run_daily(env, fake, SForecastInput(issue))

    assert result.status == "forecast_stored"
    assert result.forecast == {"status": "stored", "vintage_id": 7}
    assert fake.reports == []
    names = fake.names()
    assert names[0] == OUTAGES
    assert fake.calls[0][2] == "11:00"
    assert names[1] == f"orchestration:readiness s-forecast --issue-date={issue} --view=morning"
    assert fake.calls[1][2] == "11:30"
    assert names[2:4] == ["fingrid:fetch", OUTAGES]
    afternoon = [c for c in fake.calls if c[1][-1] == "--view=afternoon"]
    assert [c[2] for c in afternoon] == ["14:30", "14:35", "14:40"]
    predict = next(c for c in fake.calls if c[1][0] == "predict")
    assert predict[0] == "model" and predict[2] == "14:40"
    morning_cut = datetime.combine(date.fromisoformat(issue), time(11, 30), HELSINKI).astimezone(timezone.utc)
    assert predict[1][1:3] == [f"--issue-date={issue}", f"--morning-cut={morning_cut:%Y-%m-%dT%H:%M:%SZ}"]
    assert fake.names()[-1].startswith("score --target-date=")


async def test_missing_morning_input_ends_at_the_cut_with_one_issue(env):
    fake = FakeCommands(morning_ready=False)

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "no_forecast"
    assert result.missing == ["fingrid_morning"]
    assert [r.reason for r in fake.reports] == ["inputs_missing"]
    assert not any(args[0] in ("predict", "fingrid:fetch") for _, args, _ in fake.calls)


async def test_deadline_without_ready_inputs_gives_no_forecast(env):
    fake = FakeCommands(afternoon_ready_after=10_000)

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "no_forecast"
    assert result.missing == ["day_ahead_d1"]
    assert [r.reason for r in fake.reports] == ["deadline_missed"]
    times = [c[2] for c in fake.calls if c[1][-1] == "--view=afternoon"]
    assert times[0] == "14:30" and times[-1] == "17:55" and len(times) == 42
    assert not any(args[0] == "predict" for _, args, _ in fake.calls)


async def test_failed_morning_outage_fetch_is_a_missing_input(env):
    fake = FakeCommands(failing={OUTAGES})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "no_forecast"
    assert result.missing == ["entsoe_outages_morning"]


async def test_manual_start_after_the_morning_cut_needs_explicit_skip(env):
    now = await env.get_current_time()
    yesterday = (now.astimezone(HELSINKI).date() - timedelta(days=1)).isoformat()

    late = await run_daily(env, FakeCommands(), SForecastInput(yesterday))
    assert late.missing == ["entsoe_outages_morning"]

    skipped = await run_daily(env, FakeCommands(), SForecastInput(yesterday, require_cut_fetches=False))
    # Every cut of yesterday is past: the run checks each view at once and runs the model.
    assert skipped.status == "forecast_stored"


async def test_model_failure_is_one_issue_with_its_error_key(env):
    failed = FakeCommands(failing={"predict"})
    result = await run_daily(env, failed, SForecastInput(await tomorrow(env)))
    assert result.status == "no_forecast"
    assert result.missing == ["predict:test_error"]
    assert [r.reason for r in failed.reports] == ["predict_failed"]
    assert not any(args[0] == "score" for _, args, _ in failed.calls)

    unscored = FakeCommands(failing={"score"})
    result = await run_daily(env, unscored, SForecastInput(await tomorrow(env)))
    assert result.status == "forecast_stored" and result.score is None
    assert [r.reason for r in unscored.reports] == ["score_failed"]
    assert unscored.reports[0].facts["error"] == "test_error"


async def run_refit(env, fake: FakeCommands, run_date: str):
    async with Worker(
        env.client, task_queue=QUEUE, workflows=[SWeeklyRefit],
        activities=[fake.run_command, fake.report_issue],
        workflow_failure_exception_types=[Exception],
    ):
        return await env.client.execute_workflow(
            SWeeklyRefit.run, SRefitInput(run_date), id=f"r-{uuid.uuid4()}", task_queue=QUEUE
        )


async def test_refit_runs_after_the_fingrid_fetch_and_keeps_the_old_version_on_failure(env):
    day = await tomorrow(env)
    fake = FakeCommands()
    result = await run_refit(env, fake, day)
    assert result.status == "activated"
    assert [args[0] for _, args, _ in fake.calls] == ["fingrid:fetch", "train", "activate"]
    assert fake.calls[0][2] == "10:45"
    assert fake.names()[-1] == "activate --candidate=s-test"

    failed = FakeCommands(failing={"train"})
    result = await run_refit(env, failed, await tomorrow(env))
    assert result.status == "failed" and result.error == "test_error"
    assert not any(args[0] == "activate" for _, args, _ in failed.calls)
    assert [r.reason for r in failed.reports] == ["refit_failed"]


async def test_refit_never_activates_after_its_deadline(env):
    now = await env.get_current_time()
    yesterday = (now.astimezone(HELSINKI).date() - timedelta(days=1)).isoformat()
    fake = FakeCommands()
    result = await run_refit(env, fake, yesterday)
    assert result.status == "failed" and result.error == "refit_deadline_passed"
    assert fake.calls == []
