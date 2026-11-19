from __future__ import annotations

import uuid
from zoneinfo import ZoneInfo
from datetime import date, datetime, time, timedelta, timezone

import pytest
from temporalio import activity
from temporalio.exceptions import ApplicationError
from temporalio.worker import Worker

from voltikka_orchestration.activities import CommandRequest, CommandResult, IssueReport
from voltikka_orchestration.staged import RETENTION_DAYS, STORE_SCHEDULE_TO_CLOSE
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
# No date directory, so never expired: the session's time-skipping clock jumps by decades, and a store
# never starts on a payload that cleanup could delete during it (staged.payload_expired; own tests).
PAYLOAD_PATH = "/app/storage/app/import-payloads/entsoe/run-1.ndjson.gz"
SHA256 = "cd" * 32


def fetch(command: str) -> str:
    return f"{command} --fetch-only"


def store(command: str) -> str:
    return f"{command} --from-payload={PAYLOAD_PATH} --payload-sha256={SHA256}"


class FakeCommands:
    """Records every command; readiness answers come from the scenario."""

    def __init__(self, *, morning_ready=True, afternoon_ready_after=0, failing=(), fetch_result=None, lost_once=()):
        self.calls: list[tuple[str, list[str], str]] = []
        self.requests: list[CommandRequest] = []
        self.schedule_to_close: list[timedelta | None] = []
        # Commands whose first attempt is lost (a retryable activity failure, not a command failure).
        self.lost_once = set(lost_once)
        self.fetch_result = fetch_result or {"status": "fetched", "step": "fetch",
                                             "payload": {"path": PAYLOAD_PATH, "sha256": SHA256, "size": 9}}
        self.reports: list[IssueReport] = []
        self.morning_ready = morning_ready
        self.afternoon_ready_after = afternoon_ready_after
        self.afternoon_checks = 0
        self.failing = set(failing)

    @activity.defn(name="run_command")
    async def run_command(self, request: CommandRequest) -> CommandResult:
        now = activity.info().current_attempt_scheduled_time.astimezone(HELSINKI).strftime("%H:%M")
        self.calls.append((request.kind, request.args, now))
        self.requests.append(request)
        self.schedule_to_close.append(activity.info().schedule_to_close_timeout)
        name = request.args[0]
        if " ".join(request.args) in self.failing or name in self.failing:
            # As run_command raises it: retryable by itself, so only the workflow retry policy stops a retry.
            raise ApplicationError(f"{name} exited 1 (test_error)", "test_error", type="CommandFailed")
        if " ".join(request.args) in self.lost_once:
            self.lost_once.discard(" ".join(request.args))
            raise ApplicationError(f"{name} attempt lost", type="WorkerShutdown")
        if "--fetch-only" in request.args:
            return CommandResult(0, self.fetch_result, 0.1)
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
    # Every input fetch is staged: fetch, then store from the payload reference only.
    assert names[:2] == [fetch(OUTAGES), store(OUTAGES)]
    assert fake.calls[0][2] == "11:00"
    assert [request.interruptible for request in fake.requests[:2]] == [True, False]
    assert names[2] == f"orchestration:readiness s-forecast --issue-date={issue} --view=morning"
    assert fake.calls[2][2] == "11:30"
    assert names[3:7] == [fetch("fingrid:fetch"), store("fingrid:fetch"), fetch(OUTAGES), store(OUTAGES)]
    # Every store ends within the bound that keeps it ahead of payload cleanup (spec S6).
    stores = [timeout for timeout, args in zip(fake.schedule_to_close, names) if "--from-payload=" in args]
    assert len(stores) == 3 and all(timeout <= STORE_SCHEDULE_TO_CLOSE < timedelta(days=RETENTION_DAYS)
                                    for timeout in stores)
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
    assert fake.names()[:2] == [fetch(OUTAGES), store(OUTAGES)]


async def test_deadline_without_ready_inputs_gives_no_forecast(env):
    fake = FakeCommands(afternoon_ready_after=10_000)

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "no_forecast"
    assert result.missing == ["day_ahead_d1"]
    assert [r.reason for r in fake.reports] == ["deadline_missed"]
    times = [c[2] for c in fake.calls if c[1][-1] == "--view=afternoon"]
    assert times[0] == "14:30" and times[-1] == "17:55" and len(times) == 42
    assert not any(args[0] == "predict" for _, args, _ in fake.calls)


@pytest.mark.parametrize("failing", [fetch(OUTAGES), store(OUTAGES)])
async def test_failed_morning_outage_fetch_or_store_is_a_missing_input(env, failing):
    fake = FakeCommands(failing={failing})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "no_forecast"
    assert result.missing == ["entsoe_outages_morning"]
    # A failed command runs once: a failed fetch skips the store; a failed store never starts a new fetch.
    failed_store = failing == store(OUTAGES)
    assert fake.names() == ([fetch(OUTAGES), store(OUTAGES)] if failed_store else [fetch(OUTAGES)])
    # The one Issue names the step and, for a store, the payload to replay.
    payload = {"payload_path": PAYLOAD_PATH, "payload_sha256": SHA256} if failed_store else {}
    assert [(r.reason, r.facts) for r in fake.reports] == [("inputs_missing", {
        "issue_date": result.issue_date, "missing": ["entsoe_outages_morning"], "command": "entsoe:fetch",
        "error": "test_error", "step": "store" if failed_store else "fetch", **payload})]


async def test_a_lost_input_store_repeats_the_store_not_the_fetch(env):
    fake = FakeCommands(lost_once={store(OUTAGES)})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "forecast_stored"
    assert fake.names()[:3] == [fetch(OUTAGES), store(OUTAGES), store(OUTAGES)]


async def test_an_input_store_never_starts_on_an_expired_payload(env):
    expired = "/app/storage/app/import-payloads/entsoe/2000-01-01/run-1.ndjson.gz"
    fake = FakeCommands(fetch_result={"status": "fetched", "payload": {"path": expired, "sha256": SHA256}})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.missing == ["entsoe_outages_morning"]
    assert fake.names() == [fetch(OUTAGES)]
    assert [(r.facts["error"], r.facts["step"], r.facts["payload_path"]) for r in fake.reports] == [
        ("payload_expired", "store", expired)]


async def test_a_fetch_without_a_payload_reference_is_a_missing_input(env):
    fake = FakeCommands(fetch_result={"status": "fetched"})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.missing == ["entsoe_outages_morning"]
    assert fake.names() == [fetch(OUTAGES)]
    assert [(r.facts["error"], r.facts["step"]) for r in fake.reports] == [("fetch_result_invalid", "fetch")]


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
    assert fake.names()[:2] == [fetch("fingrid:fetch"), store("fingrid:fetch")]
    assert [args[0] for _, args, _ in fake.calls[2:]] == ["train", "activate"]
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


async def test_a_refit_whose_fingrid_store_fails_keeps_the_old_version(env):
    for failing, key in ((store("fingrid:fetch"), "test_error"), (None, "fetch_result_invalid")):
        fake = FakeCommands(failing={failing} if failing else (), fetch_result=None if failing else {"status": "x"})
        result = await run_refit(env, fake, await tomorrow(env))
        assert result.status == "failed" and result.error == key
        assert not any(args[0] in ("train", "activate") for _, args, _ in fake.calls)
        assert fake.names().count(fetch("fingrid:fetch")) == 1
        # A failed store runs once, and the one Issue names the step and the payload.
        assert fake.names().count(store("fingrid:fetch")) == (1 if failing else 0)
        payload = {"payload_path": PAYLOAD_PATH, "payload_sha256": SHA256} if failing else {}
        assert [r.facts for r in fake.reports] == [{"as_of": None, "error": key, "command": "fingrid:fetch",
                                                    "step": "store" if failing else "fetch", **payload}]
