from __future__ import annotations

import re
import uuid
from datetime import date, datetime, time, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import pytest
from temporalio import activity
from temporalio.client import WorkflowFailureError
from temporalio.exceptions import ApplicationError
from temporalio.worker import Replayer, Worker

from voltikka_orchestration.activities import CommandRequest, CommandResult, IssueReport
from voltikka_orchestration.staged import RETENTION_DAYS, STORE_SCHEDULE_TO_CLOSE
from voltikka_orchestration.s_forecast import (
    GATES,
    LAST_CHECK_BEFORE_DEADLINE,
    OUTAGE_FETCH,
    SDailyForecast,
    helsinki_offset,
    local_instant,
    SForecastInput,
    SRefitInput,
    SWeeklyRefit,
)
from voltikka_orchestration.worker import WORKFLOWS as PRODUCTION_WORKFLOWS

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


MORNING_KEYS = ["day_ahead_d", "jao_capacity_d", "fingrid_morning", "weather_prev_day1_morning", "eex_futures"]
AFTERNOON_KEYS = ["day_ahead_d1", "jao_capacity_d1", "fingrid_afternoon", "weather_ecmwf_00z",
                  "weather_prev_day1_afternoon"]


def never(fake, now):
    return False


def from_time(at: str):
    """The input is in the database from this Helsinki time of the issue date on."""
    return lambda fake, now: now >= at


class FakeCommands:
    """Records every command. Readiness: every input of laravel/config/orchestration.php, ready unless
    `ready` holds a rule (fake, "HH:MM" Helsinki) -> bool for its key."""

    def __init__(self, *, ready=None, failing=(), fail_first=None, fail_from=None, fetch_result=None, lost_once=(),
                 errors=None):
        self.calls: list[tuple[str, list[str], str]] = []
        self.scheduled: list[datetime] = []
        self.requests: list[CommandRequest] = []
        self.schedule_to_close: list[timedelta | None] = []
        # Commands whose first attempt is lost (a retryable activity failure, not a command failure).
        self.lost_once = set(lost_once)
        self.fetch_result = fetch_result or {"status": "fetched", "step": "fetch",
                                             "payload": {"path": PAYLOAD_PATH, "sha256": SHA256, "size": 9}}
        self.reports: list[IssueReport] = []
        self.ready = ready or {}
        self.failing = set(failing)
        # Command line -> number of its first runs that fail (CommandFailed).
        self.fail_first = dict(fail_first or {})
        # Command line -> Helsinki time from which it fails.
        self.fail_from = dict(fail_from or {})
        # (command line, "HH:MM") -> closed error key of a failure (CommandFailed), or None.
        self.errors = errors or (lambda line, now: None)
        self.report_times: list[datetime] = []

    @activity.defn(name="run_command")
    async def run_command(self, request: CommandRequest) -> CommandResult:
        now = activity.info().current_attempt_scheduled_time.astimezone(HELSINKI).strftime("%H:%M")
        self.calls.append((request.kind, request.args, now))
        self.requests.append(request)
        self.schedule_to_close.append(activity.info().schedule_to_close_timeout)
        # The first attempt's schedule time: an activity's schedule-to-close counts from it.
        self.scheduled.append(activity.info().scheduled_time)
        name, line = request.args[0], " ".join(request.args)
        if key := self.errors(line, now):
            raise ApplicationError(f"{name} exited 1 ({key})", key, type="CommandFailed")
        if self.fail_first.get(line) or now >= self.fail_from.get(line, "99:99"):
            if self.fail_first.get(line):
                self.fail_first[line] -= 1
            raise ApplicationError(f"{name} exited 1 (test_error)", "test_error", type="CommandFailed")
        if line in self.failing or name in self.failing:
            # As run_command raises it: retryable by itself, so only the workflow retry policy stops a retry.
            raise ApplicationError(f"{name} exited 1 (test_error)", "test_error", type="CommandFailed")
        if line in self.lost_once:
            self.lost_once.discard(line)
            raise ApplicationError(f"{name} attempt lost", type="WorkerShutdown")
        if "--fetch-only" in request.args:
            return CommandResult(0, self.fetch_result, 0.1)
        if name == "orchestration:readiness":
            keys = MORNING_KEYS if request.args[-1] == "--view=morning" else AFTERNOON_KEYS
            inputs = [{"key": key, "ready": self.ready.get(key, lambda f, t: True)(self, now), "detail": "x"}
                      for key in keys]
            return CommandResult(0, {"ready": all(item["ready"] for item in inputs), "inputs": inputs}, 0.1)
        if name == "predict":
            return CommandResult(0, {"status": "stored", "vintage_id": 7}, 0.1)
        if name == "train":
            return CommandResult(0, {"status": "trained", "candidate_version": "s-test"}, 0.1)
        return CommandResult(0, None, 0.1)

    @activity.defn(name="report_issue")
    async def report_issue(self, report: IssueReport) -> None:
        self.reports.append(report)
        self.report_times.append(activity.info().current_attempt_scheduled_time)

    def names(self) -> list[str]:
        return [" ".join(args) for _, args, _ in self.calls]

    def times(self, line: str) -> list[str]:
        return [at for _, args, at in self.calls if " ".join(args) == line]

    def stored_ok(self, command: str) -> bool:
        """A store of `command` that did not fail (the fake records every call, also failed ones)."""
        return any(at >= "10:15" for at in self.times(store(command)))

    def stored(self, command: str, since: str) -> bool:
        return any(at >= since for at in self.times(store(command)))


def test_helsinki_offset_matches_zoneinfo_every_hour():
    hour = datetime(2024, 1, 1, tzinfo=timezone.utc)
    while hour < datetime(2031, 1, 1, tzinfo=timezone.utc):
        assert helsinki_offset(hour) == hour.astimezone(HELSINKI).utcoffset(), hour
        hour += timedelta(hours=1)


def test_local_instant_matches_zoneinfo_for_the_workflow_times():
    day = date(2024, 1, 1)
    while day < date(2031, 1, 1):
        for at in (time(8, 55), time(9, 0), time(10, 5), time(11, 30), time(14, 0), time(14, 30), time(18, 0)):
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


def readiness(issue: str, view: str) -> str:
    return f"orchestration:readiness s-forecast --issue-date={issue} --view={view}"


def fetches(fake: FakeCommands) -> list[str]:
    return [name for name in fake.names() if name.endswith("--fetch-only")]


def helsinki(issue: str, at: str) -> datetime:
    return datetime.combine(date.fromisoformat(issue), time.fromisoformat(at), HELSINKI).astimezone(timezone.utc)


async def test_every_gate_opens_and_the_model_runs_at_the_afternoon_cut(env):
    fake = FakeCommands()
    issue = await tomorrow(env)

    result = await run_daily(env, fake, SForecastInput(issue))

    assert result.status == "forecast_stored"
    assert result.forecast == {"status": "stored", "vintage_id": 7}
    assert fake.reports == []
    # Every input is ready at its first check, so only the two outage proofs fetch (staged: fetch,
    # then store from the payload reference only).
    assert fetches(fake) == [fetch(OUTAGES), fetch(OUTAGES)]
    assert fake.times(fetch(OUTAGES)) == fake.times(store(OUTAGES)) == ["11:00", "14:00"]
    outage = [request for request in fake.requests if request.args[0] == "entsoe:fetch"]
    assert [request.interruptible for request in outage] == [True, False, True, False]
    # One check per gate at its start (several gates share 09:00).
    assert sorted(fake.times(readiness(issue, "morning"))) == ["09:00", "09:00", "09:00", "09:30", "10:05"]
    assert sorted(fake.times(readiness(issue, "afternoon"))) == ["09:15", "12:00", "13:00", "13:30", "14:00"]
    # Every store ends within the bound that keeps it ahead of payload cleanup (spec S6).
    stores = [timeout for timeout, args in zip(fake.schedule_to_close, fake.names()) if "--from-payload=" in args]
    assert len(stores) == 2 and all(timeout <= STORE_SCHEDULE_TO_CLOSE < timedelta(days=RETENTION_DAYS)
                                    for timeout in stores)
    predict = next(c for c in fake.calls if c[1][0] == "predict")
    assert predict[0] == "model" and predict[2] == "14:30"
    assert predict[1][1:4] == [f"--issue-date={issue}", f"--morning-cut={helsinki(issue, '11:30'):%Y-%m-%dT%H:%M:%SZ}",
                               f"--afternoon-cut={helsinki(issue, '14:30'):%Y-%m-%dT%H:%M:%SZ}"]
    assert fake.names()[-1].startswith("score --target-date=")


async def test_a_gate_opens_after_a_late_input(env):
    issue = await tomorrow(env)
    # The 12:00 and 12:10 checks miss it; the third check finds it.
    fake = FakeCommands(ready={"jao_capacity_d1": from_time("12:20")})

    result = await run_daily(env, fake, SForecastInput(issue))

    assert result.status == "forecast_stored" and fake.reports == []
    assert fake.times(fetch("jao:fetch")) == fake.times(store("jao:fetch")) == ["12:00", "12:10"]
    assert {"12:00", "12:10", "12:20"} <= set(fake.times(readiness(issue, "afternoon")))


async def test_a_failed_fetch_is_repeated_and_a_later_success_opens_the_gate_without_an_issue(env):
    fake = FakeCommands(ready={"fingrid_morning": lambda f, now: f.stored("fingrid:fetch", "00:00")},
                        fail_first={fetch("fingrid:fetch"): 1})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "forecast_stored"
    assert fake.reports == []
    assert fake.times(fetch("fingrid:fetch")) == ["10:05", "10:15"]
    assert fake.times(store("fingrid:fetch")) == ["10:15"]


async def test_late_fingrid_capacity_rows_open_the_afternoon_gate(env):
    """2026-11-17: fingrid:fetch succeeded at 14:00, but datasets 267/268 published the 13:00 hour
    later (here at 14:05), so the old single 14:00 fetch never stored it. The gate fetches again."""
    fake = FakeCommands(ready={"fingrid_afternoon": lambda f, now: f.stored("fingrid:fetch", "14:05")})
    issue = await tomorrow(env)
    async with Worker(env.client, task_queue=QUEUE, workflows=[SDailyForecast],
                      activities=[fake.run_command, fake.report_issue], workflow_failure_exception_types=[Exception]):
        handle = await env.client.start_workflow(SDailyForecast.run, SForecastInput(issue),
                                                 id=f"s-test-{uuid.uuid4()}", task_queue=QUEUE)
        result = await handle.result()

    assert result.status == "forecast_stored" and fake.reports == []
    assert fake.times(fetch("fingrid:fetch")) == ["14:00", "14:10"]
    assert next(c for c in fake.calls if c[1][0] == "predict")[2] == "14:30"
    # The gate path replays deterministically on the production worker's workflows.
    await Replayer(workflows=PRODUCTION_WORKFLOWS).replay_workflow(await handle.fetch_history())


async def test_a_missed_afternoon_deadline_names_the_gate_and_its_last_failure(env):
    fake = FakeCommands(ready={"fingrid_afternoon": never}, fail_from={fetch("fingrid:fetch"): "13:00"})
    issue = await tomorrow(env)

    result = await run_daily(env, fake, SForecastInput(issue))

    assert result.status == "no_forecast" and result.missing == ["fingrid_afternoon"]
    # Every 10 minutes from 14:00; the last check starts 2 minutes before the 18:00 deadline.
    times = fake.times(fetch("fingrid:fetch"))
    assert times[0] == "14:00" and times[-1] == "17:50" and len(times) == 24
    assert max(fake.times(readiness(issue, "afternoon"))) == "17:58"
    assert [(r.reason, r.facts) for r in fake.reports] == [("deadline_missed", {
        "issue_date": issue, "missing": ["fingrid_afternoon"],
        "failures": {"fingrid_afternoon": {"command": "fingrid:fetch", "error": "test_error", "step": "fetch"}}})]
    assert not any(args[0] == "predict" for _, args, _ in fake.calls)


async def test_an_input_that_never_arrives_misses_the_deadline_without_failure_facts(env):
    fake = FakeCommands(ready={"day_ahead_d1": never})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.missing == ["day_ahead_d1"]
    assert [(r.reason, set(r.facts)) for r in fake.reports] == [("deadline_missed", {"issue_date", "missing"})]
    times = fake.times(fetch("spot:fetch-neighbours"))
    assert times[0] == "13:00" and times[-1] == "17:50" and len(times) == 30


async def run_daily_handle(env, fake: FakeCommands, params: SForecastInput):
    async with Worker(env.client, task_queue=QUEUE, workflows=[SDailyForecast],
                      activities=[fake.run_command, fake.report_issue], workflow_failure_exception_types=[Exception]):
        handle = await env.client.start_workflow(SDailyForecast.run, params, id=f"s-test-{uuid.uuid4()}",
                                                 task_queue=QUEUE)
        return await handle.result(), handle


def near(instant: datetime, expected: datetime) -> bool:
    """Within the test server's timer jitter (a timer can fire a millisecond early)."""
    return expected - timedelta(seconds=1) <= instant <= expected + timedelta(seconds=5)


async def test_a_missed_morning_gate_ends_the_run_at_the_cut_with_one_issue(env):
    issue = await tomorrow(env)
    fake = FakeCommands(ready={"fingrid_morning": never})

    result, handle = await run_daily_handle(env, fake, SForecastInput(issue))

    assert result.status == "no_forecast"
    assert result.missing == ["fingrid_morning"]
    assert [r.reason for r in fake.reports] == ["inputs_missing"]
    assert fake.times(fetch("fingrid:fetch")) == ["10:05", "10:15", "10:25", "10:35", "10:45", "10:55", "11:05",
                                                   "11:15", "11:25"]
    # The day cannot get a forecast, so the run does not wait for the afternoon gates: its Issue and
    # its close are at the 11:30 cut.
    assert max(at for _, _, at in fake.calls) == "11:28"
    assert near(fake.report_times[0], helsinki(issue, "11:30"))
    assert near((await handle.describe()).close_time, helsinki(issue, "11:30"))
    assert not any(args[0] in ("predict", "jao:fetch") for _, args, _ in fake.calls)


@pytest.mark.parametrize("view, command_cut, starts", [
    ("morning", "10:00", ["09:30", "09:40", "09:50"]),
    ("afternoon", "14:00", ["13:30", "13:40", "13:50"]),
])
async def test_the_vintage_gates_never_fetch_after_their_cut(env, view, command_cut, starts):
    key = f"weather_prev_day1_{view}"
    issue = await tomorrow(env)
    fake = FakeCommands(ready={key: never})

    result = await run_daily(env, fake, SForecastInput(issue))

    command = "weather:fetch --only=ecmwf_prev_day1"
    assert result.missing == [key]
    assert [r.reason for r in fake.reports] == ["inputs_missing"]
    assert fake.times(fetch(command)) == starts
    # Neither the fetch nor its store can run past the cut: schedule-to-close is the time left at the
    # workflow task, a few milliseconds before the server schedules the activity.
    cut = helsinki(issue, command_cut)
    budgets = [(at, timeout) for at, timeout, name in zip(fake.scheduled, fake.schedule_to_close, fake.names())
               if name.startswith(command)]
    assert len(budgets) == 2 * len(starts) and all(at + timeout <= cut + timedelta(seconds=1) for at, timeout in budgets)
    # A morning gate is collected at the 11:30 cut; the afternoon one ends the run at its cut.
    assert max(at for _, _, at in fake.calls) <= ("11:30" if view == "morning" else command_cut)
    assert not any(args[0] == "predict" for _, args, _ in fake.calls)


@pytest.mark.parametrize("failing", [fetch(OUTAGES), store(OUTAGES)])
async def test_a_failed_morning_outage_fetch_repeats_and_a_failed_store_stops_the_fetches(env, failing):
    fake = FakeCommands(failing={failing})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "no_forecast"
    assert result.missing == ["entsoe_outages_morning"]
    outages = [name for name in fake.names() if name.startswith("entsoe:fetch")]
    failed_store = failing == store(OUTAGES)
    if failed_store:
        # A failed store never starts a new fetch.
        assert outages == [fetch(OUTAGES), store(OUTAGES)]
    else:
        assert fake.times(fetch(OUTAGES)) == ["11:00", "11:05", "11:10", "11:15", "11:20", "11:25"]
        assert store(OUTAGES) not in outages
    # The one Issue names the step and, for a store, the payload to replay.
    payload = {"payload_path": PAYLOAD_PATH, "payload_sha256": SHA256} if failed_store else {}
    assert [(r.reason, r.facts) for r in fake.reports] == [("inputs_missing", {
        "issue_date": result.issue_date, "missing": ["entsoe_outages_morning"], "failures": {"entsoe_outages_morning": {
            "command": "entsoe:fetch", "error": "test_error", "step": "store" if failed_store else "fetch", **payload}}})]


async def test_a_failed_outage_fetch_is_repeated_until_one_succeeds(env):
    fake = FakeCommands(fail_first={fetch(OUTAGES): 2})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "forecast_stored" and fake.reports == []
    assert fake.times(fetch(OUTAGES)) == ["11:00", "11:05", "11:10", "14:00"]
    assert fake.times(store(OUTAGES)) == ["11:10", "14:00"]


async def test_a_failed_afternoon_outage_window_ends_the_run_at_its_cut(env):
    fake = FakeCommands(fail_from={fetch(OUTAGES): "13:00"})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.missing == ["entsoe_outages_afternoon"]
    assert [r.reason for r in fake.reports] == ["inputs_missing"]
    assert fake.times(fetch(OUTAGES)) == ["11:00", "14:00", "14:05", "14:10", "14:15", "14:20", "14:25"]
    # The outage proof has no readiness check: the last failed pass is at 14:25, and the run ends at 14:30.
    assert max(at for _, _, at in fake.calls) == "14:25"
    assert not any(args[0] == "predict" for _, args, _ in fake.calls)


async def test_a_lost_input_store_repeats_the_store_not_the_fetch(env):
    fake = FakeCommands(lost_once={store(OUTAGES)})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "forecast_stored"
    assert [name for name in fake.names() if name.startswith("entsoe")][:3] == [fetch(OUTAGES), store(OUTAGES),
                                                                                store(OUTAGES)]


async def test_an_input_store_never_starts_on_an_expired_payload(env):
    expired = "/app/storage/app/import-payloads/entsoe/2000-01-01/run-1.ndjson.gz"
    fake = FakeCommands(fetch_result={"status": "fetched", "payload": {"path": expired, "sha256": SHA256}})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.missing == ["entsoe_outages_morning"]
    assert [name for name in fake.names() if name.startswith("entsoe")] == [fetch(OUTAGES)]
    failure = fake.reports[0].facts["failures"]["entsoe_outages_morning"]
    assert len(fake.reports) == 1 and (failure["error"], failure["step"], failure["payload_path"]) == (
        "payload_expired", "store", expired)


async def test_a_fetch_without_a_payload_reference_is_a_missing_input(env):
    fake = FakeCommands(fetch_result={"status": "fetched"})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.missing == ["entsoe_outages_morning"]
    assert fake.times(fetch(OUTAGES)) == ["11:00", "11:05", "11:10", "11:15", "11:20", "11:25"]
    assert [{key: r.facts["failures"]["entsoe_outages_morning"][key] for key in ("error", "step")}
            for r in fake.reports] == [{"error": "fetch_result_invalid", "step": "fetch"}]


async def test_manual_start_after_the_morning_cut_needs_explicit_skip(env):
    now = await env.get_current_time()
    yesterday = (now.astimezone(HELSINKI).date() - timedelta(days=1)).isoformat()

    late_fake = FakeCommands()
    late = await run_daily(env, late_fake, SForecastInput(yesterday))
    assert late.missing == ["entsoe_outages_morning"]
    assert [r.reason for r in late_fake.reports] == ["started_after_morning_cut"] and late_fake.calls == []

    skipped_fake = FakeCommands()
    skipped = await run_daily(env, skipped_fake, SForecastInput(yesterday, require_cut_fetches=False))
    # Every deadline of yesterday is past: each gate checks once, nothing fetches, the model runs.
    assert skipped.status == "forecast_stored"
    assert fetches(skipped_fake) == []
    assert len([name for name in skipped_fake.names() if name.startswith("orchestration:readiness")]) == 10

    # A gate past its deadline whose input is not ready is missed at once, without a fetch.
    missed_fake = FakeCommands(ready={"fingrid_morning": never})
    missed = await run_daily(env, missed_fake, SForecastInput(yesterday, require_cut_fetches=False))
    assert missed.missing == ["fingrid_morning"] and fetches(missed_fake) == []
    assert [r.reason for r in missed_fake.reports] == ["inputs_missing"]


async def test_the_stage_query_reports_the_gates(env):
    fake = FakeCommands()
    async with Worker(env.client, task_queue=QUEUE, workflows=[SDailyForecast],
                      activities=[fake.run_command, fake.report_issue], workflow_failure_exception_types=[Exception]):
        handle = await env.client.start_workflow(SDailyForecast.run, SForecastInput(await tomorrow(env)),
                                                 id=f"s-test-{uuid.uuid4()}", task_queue=QUEUE)
        stage = await handle.query(SDailyForecast.stage)
        assert stage["stage"] == "gates"
        assert set(stage["gates"]) == {gate.key for gate in GATES}
        await handle.result()
        assert set((await handle.query(SDailyForecast.stage))["gates"].values()) == {"open"}


def test_one_gate_per_readiness_input_and_the_two_outage_proofs():
    """requirement 1: the gates follow laravel/config/orchestration.php, key and view."""
    config = (Path(__file__).resolve().parents[2] / "laravel" / "config" / "orchestration.php").read_text()
    inputs = set(re.findall(r"\['key' => '([a-z0-9_]+)', 'view' => '(morning|afternoon)'", config))
    assert len(inputs) == 10
    assert {(gate.key, gate.view) for gate in GATES if gate.view} == inputs
    assert [gate.key for gate in GATES if gate.view is None] == ["entsoe_outages_morning", "entsoe_outages_afternoon"]
    assert all(gate.start < gate.deadline for gate in GATES)


async def test_a_failed_readiness_check_fails_the_run_at_once(env):
    """A readiness check that fails after its retries is a workflow failure (one Sentry Issue through
    the interceptor), also in an afternoon gate long before its 18:00 collection."""

    class BrokenAfternoon(FakeCommands):
        @activity.defn(name="run_command")
        async def run_command(self, request: CommandRequest) -> CommandResult:
            if request.args[-1] == "--view=afternoon":
                raise ApplicationError("database", "database", type="CommandFailed", non_retryable=True)
            return await super().run_command(request)

    fake = BrokenAfternoon()
    with pytest.raises(WorkflowFailureError):
        await run_daily(env, fake, SForecastInput(await tomorrow(env)))
    # The failed 09:15 check (not recorded) ends the run: nothing runs after the 09:00 checks.
    assert max(at for _, _, at in fake.calls) == "09:00"
    assert fake.reports == []


# TST-001: each gate kept closed in turn, against this table (independent of s_forecast.GATES): its
# fetch command line (None: re-check only), its fetch times, its last check, the reason and when the
# run ends (the collection time of its deadline).
def every_10(first: str, last: str) -> list[str]:
    start, end = datetime.strptime(first, "%H:%M"), datetime.strptime(last, "%H:%M")
    return [(start + timedelta(minutes=10 * n)).strftime("%H:%M") for n in range((end - start).seconds // 600 + 1)]


CLOSED_GATES = {
    "fingrid_morning": ("fingrid:fetch", every_10("10:05", "11:25"), "11:28", "11:30", "inputs_missing"),
    "fingrid_afternoon": ("fingrid:fetch", every_10("14:00", "17:50"), "17:58", "18:00", "deadline_missed"),
    "day_ahead_d": ("spot:fetch-neighbours", every_10("09:00", "11:20"), "11:28", "11:30", "inputs_missing"),
    "day_ahead_d1": ("spot:fetch-neighbours", every_10("13:00", "17:50"), "17:58", "18:00", "deadline_missed"),
    "jao_capacity_d": ("jao:fetch", every_10("09:00", "11:20"), "11:28", "11:30", "inputs_missing"),
    "jao_capacity_d1": ("jao:fetch", every_10("12:00", "17:50"), "17:58", "18:00", "deadline_missed"),
    "weather_ecmwf_00z": ("weather:fetch --only=ecmwf_00z", every_10("09:15", "17:55"), "17:58", "18:00",
                          "deadline_missed"),
    "weather_prev_day1_morning": ("weather:fetch --only=ecmwf_prev_day1", ["09:30", "09:40", "09:50"], "09:58",
                                  "11:30", "inputs_missing"),
    "weather_prev_day1_afternoon": ("weather:fetch --only=ecmwf_prev_day1", ["13:30", "13:40", "13:50"], "13:58",
                                    "14:00", "inputs_missing"),
    "eex_futures": (None, [], "11:28", "11:30", "inputs_missing"),
}


@pytest.mark.parametrize("key", sorted(CLOSED_GATES))
async def test_each_gate_obeys_its_fetch_command_and_deadline(env, key):
    line, fetch_times, last_check, ends, reason = CLOSED_GATES[key]
    issue = await tomorrow(env)
    fake = FakeCommands(ready={key: never})

    result, handle = await run_daily_handle(env, fake, SForecastInput(issue))

    assert result.status == "no_forecast" and result.missing == [key]
    assert [r.reason for r in fake.reports] == [reason]
    # Only this gate fetches (besides the outage proofs), with its exact command and options.
    assert sorted(set(fetches(fake)) - {fetch(OUTAGES)}) == ([fetch(line)] if line else [])
    # The last check starts 2 minutes before the gate deadline; no fetch or store runs past it.
    deadline = helsinki(issue, last_check) + LAST_CHECK_BEFORE_DEADLINE
    if line:
        assert fake.times(fetch(line)) == fake.times(store(line)) == fetch_times
        budgets = [at + timeout for at, timeout, name in zip(fake.scheduled, fake.schedule_to_close, fake.names())
                   if name.startswith(line)]
        assert all(end <= deadline + timedelta(seconds=1) for end in budgets)
    view = "morning" if key in MORNING_KEYS else "afternoon"
    assert last_check in fake.times(readiness(issue, view))
    assert max(at for _, _, at in fake.calls) <= ends
    assert near(fake.report_times[0], helsinki(issue, ends))
    assert near((await handle.describe()).close_time, helsinki(issue, ends))
    assert not any(args[0] == "predict" for _, args, _ in fake.calls)


@pytest.mark.parametrize("key, rule, fetch_times, ends", [
    ("entsoe_outages_morning", {}, ["11:00", "11:05", "11:10", "11:15", "11:20", "11:25"], "11:30"),
    ("entsoe_outages_afternoon", {fetch(OUTAGES): "13:00"}, ["14:00", "14:05", "14:10", "14:15", "14:20", "14:25"],
     "14:30"),
])
async def test_each_outage_gate_obeys_its_window(env, key, rule, fetch_times, ends):
    issue = await tomorrow(env)
    fake = FakeCommands(fail_from=rule) if rule else FakeCommands(failing={fetch(OUTAGES)})

    result, handle = await run_daily_handle(env, fake, SForecastInput(issue))

    assert result.missing == [key] and [r.reason for r in fake.reports] == ["inputs_missing"]
    assert fake.times(fetch(OUTAGES))[-len(fetch_times):] == fetch_times
    assert near((await handle.describe()).close_time, helsinki(issue, ends))
    assert not any(args[0] == "predict" for _, args, _ in fake.calls)


async def test_all_missed_gates_report_their_latest_failures_once(env):
    """TST-002: two afternoon gates closed, errors that change on every attempt."""

    def errors(line: str, now: str) -> str | None:
        if line == fetch("fingrid:fetch"):
            return f"fingrid_down_{now.replace(':', '')}"
        if line == fetch("jao:fetch") and now < "15:00":
            return f"jao_down_{now.replace(':', '')}"
        if line == store("jao:fetch") and now >= "15:00":
            return "store_failed"  # a store bug: the gate stops fetching
        return None

    issue = await tomorrow(env)
    fake = FakeCommands(ready={"fingrid_afternoon": never, "jao_capacity_d1": never}, errors=errors)

    result = await run_daily(env, fake, SForecastInput(issue))

    assert result.missing == ["fingrid_afternoon", "jao_capacity_d1"]
    assert fake.times(fetch("jao:fetch"))[-1] == "15:00"
    assert [(r.reason, r.facts) for r in fake.reports] == [("deadline_missed", {
        "issue_date": issue, "missing": ["fingrid_afternoon", "jao_capacity_d1"], "failures": {
            "fingrid_afternoon": {"command": "fingrid:fetch", "error": "fingrid_down_1750", "step": "fetch"},
            "jao_capacity_d1": {"command": "jao:fetch", "error": "store_failed", "step": "store",
                                "payload_path": PAYLOAD_PATH, "payload_sha256": SHA256}}})]


async def test_a_partial_acquisition_is_fetched_again(env):
    """PLT-001: a fetch with failed units exits 0; its store keeps the good rows and fails
    `fetch_incomplete` (step `fetch`). The gate fetches again; a later complete fetch opens it."""
    fake = FakeCommands(ready={"fingrid_morning": lambda f, now: f.stored_ok("fingrid:fetch")},
                        errors=lambda line, now: "fetch_incomplete" if line == store("fingrid:fetch") and now < "10:15"
                        else None)

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.status == "forecast_stored" and fake.reports == []
    assert fake.times(fetch("fingrid:fetch")) == ["10:05", "10:15"]


async def test_a_partial_acquisition_that_never_completes_names_the_fetch_step(env):
    fake = FakeCommands(ready={"fingrid_afternoon": never},
                        errors=lambda line, now: "fetch_incomplete" if line == store("fingrid:fetch") else None)
    issue = await tomorrow(env)

    result = await run_daily(env, fake, SForecastInput(issue))

    assert result.missing == ["fingrid_afternoon"]
    assert len(fake.times(fetch("fingrid:fetch"))) == 24  # every pass fetches again
    assert [r.facts["failures"] for r in fake.reports] == [{"fingrid_afternoon": {
        "command": "fingrid:fetch", "error": "fetch_incomplete", "step": "fetch",
        "payload_path": PAYLOAD_PATH, "payload_sha256": SHA256}}]


async def test_a_readiness_check_failing_across_the_cut_misses_the_gate(env):
    """PLT-002: every check of a gate ends by its deadline. A check that still fails at the cut
    misses the gate (no run error); no result can open it after the cut."""
    issue = await tomorrow(env)
    fake = FakeCommands(ready={"fingrid_morning": never}, fail_from={readiness(issue, "morning"): "11:20"})

    result, handle = await run_daily_handle(env, fake, SForecastInput(issue))

    assert result.missing == ["fingrid_morning"]
    assert [(r.reason, r.facts["failures"]) for r in fake.reports] == [("inputs_missing", {
        "fingrid_morning": {"command": "orchestration:readiness", "error": "test_error"}})]
    # The retries of the 11:25 check stop at the cut (schedule-to-close = the time left).
    budgets = [at + timeout for at, timeout, name in zip(fake.scheduled, fake.schedule_to_close, fake.names())
               if name == readiness(issue, "morning")]
    assert all(end <= helsinki(issue, "11:30") + timedelta(seconds=1) for end in budgets)
    assert near((await handle.describe()).close_time, helsinki(issue, "11:30"))
    assert not any(args[0] == "predict" for _, args, _ in fake.calls)


async def test_a_row_that_appears_after_the_cut_never_opens_the_gate(env):
    fake = FakeCommands(ready={"fingrid_morning": from_time("11:29")})

    result = await run_daily(env, fake, SForecastInput(await tomorrow(env)))

    assert result.missing == ["fingrid_morning"]
    assert [r.reason for r in fake.reports] == ["inputs_missing"]


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
