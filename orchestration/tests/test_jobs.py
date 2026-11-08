from __future__ import annotations

import asyncio
import os
import sys
import uuid
from datetime import time, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

import pytest
import sentry_sdk
from temporalio import activity
from temporalio.client import Schedule, ScheduleOverlapPolicy, WorkflowFailureError
from temporalio.exceptions import ApplicationError
from temporalio.worker import UnsandboxedWorkflowRunner, Worker

from voltikka_orchestration import jobs, sentry
from voltikka_orchestration.activities import Activities, CommandRequest, CommandResult, IssueReport
from voltikka_orchestration.jobs import (
    ArtisanJob,
    ArtisanJobInput,
    ContractImport,
    EexFutures,
    MorningChain,
    MorningInput,
    Step,
)
from voltikka_orchestration.schedules import GROUPS, parse_args, schedules, select
from voltikka_orchestration.settings import Settings
from voltikka_orchestration.worker import GRACEFUL_SHUTDOWN
from voltikka_orchestration.worker import WORKFLOWS as PRODUCTION_WORKFLOWS

QUEUE = "jobs-test-queue"
HELSINKI = ZoneInfo("Europe/Helsinki")
WORKFLOWS = [ArtisanJob, MorningChain, EexFutures, ContractImport]
RETAIL = "retail-premiums:collect --scheduled"
FORECAST = "forecasting:run-fixed-contracts --scheduled"


class FakeArtisan:
    """Simulates the commands and the checkpoints that they write. Consumer behaviour per command:
    ("ready_after", n) completes the scheduled scope on tick n; ("never",) keeps waiting;
    ("fail",) stores a failed claim on the first tick; ("manual_ready",) starts as a manual-subset
    `ready` row that is not the scheduled scope; ("late",) completes only after noon. Like
    MorningConsumerExecution, a tick at or after 12:00 reserves the deadline alert of an incomplete
    consumer."""

    def __init__(self, *, failing=(), retail=("ready_after", 2), forecast=("ready_after", 1),
                 completion_ticks=2, consumer_seconds=0.0, import_state="pending_completion"):
        self.calls: list[tuple[str, str]] = []
        self.attempts: dict[str, int] = {}
        self.reports: list[IssueReport] = []
        self.failing = set(failing)
        self.checkpoints: dict[str, str | None] = {
            "eex_futures": None, "contract_import": None, "morning_retail": None, "morning_forecast": None,
        }
        self.complete = {"morning_retail": False, "morning_forecast": False}
        self.alerted = {"morning_retail": False, "morning_forecast": False}
        self.behaviour = {RETAIL: ("morning_retail", retail), FORECAST: ("morning_forecast", forecast)}
        self.ticks = {RETAIL: 0, FORECAST: 0}
        self.completion_ticks = completion_ticks
        self.import_state = import_state
        self.consumer_seconds = consumer_seconds
        self.active_consumers = 0
        self.max_active_consumers = 0
        for key, rule in self.behaviour.values():
            if rule[0] == "manual_ready":
                self.checkpoints[key] = "ready"

    @activity.defn(name="run_command")
    async def run_command(self, request: CommandRequest) -> CommandResult:
        name = " ".join(request.args)
        if request.args[0] == "orchestration:readiness":
            return CommandResult(0, {"date": "x", "checkpoints": dict(self.checkpoints),
                                     "scheduled_complete": dict(self.complete),
                                     "deadline_alerted": dict(self.alerted)}, 0.1)
        at = activity.info().current_attempt_scheduled_time.astimezone(HELSINKI).strftime("%H:%M")
        self.calls.append((name, at))
        self.attempts[name] = self.attempts.get(name, 0) + 1
        if name in self.failing:
            # Retryable by itself: only the workflow retry policy may stop the retry.
            raise ApplicationError(f"{name} exited 1 (boom)", "boom", type="CommandFailed")
        command = request.args[0]
        if command == "futures:fetch-eex":
            self.checkpoints["eex_futures"] = "ready"
        elif command == "contracts:fetch":
            self.checkpoints["contract_import"] = self.import_state
        elif command == "contracts:complete-import":
            self.completion_ticks -= 1
            if self.completion_ticks <= 0:
                self.checkpoints["contract_import"] = "ready"
        elif name in self.behaviour:
            await self._consumer(name, at)
        return CommandResult(0, None, 0.1)

    async def _consumer(self, name: str, at: str) -> None:
        key, rule = self.behaviour[name]
        self.ticks[name] += 1
        if at >= "12:00" and not self.complete[key]:
            self.alerted[key] = True
            if rule[0] == "late":  # a writer that was admitted before noon finishes late
                self.checkpoints[key], self.complete[key] = "ready", True
            return
        self.active_consumers += 1
        self.max_active_consumers = max(self.max_active_consumers, self.active_consumers)
        try:
            if self.consumer_seconds:
                await asyncio.sleep(self.consumer_seconds)
            if rule[0] == "ready_after" and self.ticks[name] >= rule[1]:
                self.checkpoints[key], self.complete[key] = "ready", True
            elif rule[0] == "manual_ready":
                self.checkpoints[key], self.complete[key] = "ready", True  # the full scheduled run
            elif rule[0] == "fail" and self.checkpoints[key] is None:
                self.checkpoints[key] = "failed"
        finally:
            self.active_consumers -= 1

    @activity.defn(name="report_issue")
    async def report_issue(self, report: IssueReport) -> None:
        self.reports.append(report)

    def names(self) -> list[str]:
        return [name for name, _ in self.calls]

    def times(self, name: str) -> list[str]:
        return [at for call, at in self.calls if call == name]


async def execute(env, fake: FakeArtisan, workflow_run, params, **worker_options):
    async with Worker(
        env.client, task_queue=QUEUE, workflows=WORKFLOWS,
        activities=[fake.run_command, fake.report_issue],
        workflow_failure_exception_types=[Exception], **worker_options,
    ):
        return await env.client.execute_workflow(
            workflow_run, params, id=f"jobs-test-{uuid.uuid4()}", task_queue=QUEUE
        )


async def tomorrow(env) -> str:
    now = await env.get_current_time()
    return (now.astimezone(HELSINKI).date() + timedelta(days=1)).isoformat()


# ArtisanJob


async def test_artisan_job_runs_steps_in_order(env):
    fake = FakeArtisan()
    params = ArtisanJobInput("backup-nightly", [Step(["backup:run", "--only-db"]), Step(["backup:clean"], always=True)])

    result = await execute(env, fake, ArtisanJob.run, params)

    assert fake.names() == ["backup:run --only-db", "backup:clean"]
    assert [o.status for o in result.outcomes] == ["succeeded", "succeeded"]
    assert fake.reports == []


async def test_failed_step_is_not_retried_skips_later_steps_but_runs_always_steps(env):
    fake = FakeArtisan(failing={"backup:run --only-db"})
    params = ArtisanJobInput("backup-nightly", [
        Step(["backup:run", "--only-db"]),
        Step(["backup:verify"]),
        Step(["backup:monitor"], always=True),
    ])

    with pytest.raises(WorkflowFailureError) as failure:
        await execute(env, fake, ArtisanJob.run, params)

    assert failure.value.cause.type == "ReportedFailure"
    assert fake.attempts["backup:run --only-db"] == 1
    assert fake.names() == ["backup:run --only-db", "backup:monitor"]
    assert [(r.workflow, r.reason, r.facts["error"]) for r in fake.reports] == [("backup-nightly", "command_failed", "boom")]


async def test_a_self_reported_command_failure_gets_no_second_issue(env):
    fake = FakeArtisan(failing={"spot:fetch"})
    params = ArtisanJobInput("spot-hourly", [
        Step(["spot:fetch"], self_reports=True),
        Step(["social:publish-daily-spot"], always=True, max_attempts=1),
    ])

    with pytest.raises(WorkflowFailureError):
        await execute(env, fake, ArtisanJob.run, params)

    assert fake.names() == ["spot:fetch", "social:publish-daily-spot"]
    assert fake.reports == []


async def test_a_lost_self_reporting_attempt_is_reported_by_the_workflow(env):
    """A timeout or exhausted lost attempt never reached the command's own reporter."""

    class Lost(FakeArtisan):
        @activity.defn(name="run_command")
        async def run_command(self, request: CommandRequest) -> CommandResult:
            raise ApplicationError("heartbeat timeout", type="ActivityTimeout", non_retryable=True)

    fake = Lost()
    with pytest.raises(WorkflowFailureError):
        await execute(env, fake, ArtisanJob.run, ArtisanJobInput("eex", [Step(["futures:fetch-eex"], self_reports=True)]))

    assert [(r.reason, r.facts["command"]) for r in fake.reports] == [("command_failed", "futures:fetch-eex")]


# MorningChain


async def test_consumers_start_together_when_both_producers_have_ended(env):
    fake = FakeArtisan()

    result = await execute(env, fake, MorningChain.run, MorningInput(await tomorrow(env)))

    assert (result.eex, result.contracts) == ("succeeded", "succeeded")
    assert result.consumers == {"morning_retail": "complete", "morning_forecast": "complete"}
    assert fake.names()[0] == "futures:fetch-eex"  # starts with the workflow (Schedule 04:00)
    assert fake.times("contracts:fetch --skip-logos") == ["06:00"]
    assert fake.times("contracts:complete-import") == ["06:01", "06:02"]
    names = fake.names()
    assert names.index("forecasting:evaluate-fixed-contracts") > names.index("contracts:complete-import")
    # Event chaining: no 07:15/07:30 start, both lanes at the same instant.
    assert fake.times(RETAIL) == ["06:02", "06:07"]
    assert fake.times(FORECAST) == ["06:02"]
    assert fake.reports == []


async def test_a_slow_consumer_does_not_delay_the_other(env):
    fake = FakeArtisan(retail=("ready_after", 1), consumer_seconds=0.5)

    await execute(env, fake, MorningChain.run, MorningInput(await tomorrow(env)))

    assert fake.max_active_consumers == 2


async def test_a_manual_subset_ready_row_is_not_the_scheduled_scope(env):
    fake = FakeArtisan(retail=("manual_ready",))

    result = await execute(env, fake, MorningChain.run, MorningInput(await tomorrow(env)))

    assert fake.times(RETAIL) == ["06:02"]
    assert result.consumers["morning_retail"] == "complete"


async def test_a_failed_claim_gets_only_the_noon_tick_and_the_morning_fails_with_one_issue(env):
    fake = FakeArtisan(forecast=("fail",))

    with pytest.raises(WorkflowFailureError) as failure:
        await execute(env, fake, MorningChain.run, MorningInput(await tomorrow(env)))

    assert failure.value.cause.type == "ReportedFailure"
    forecast = fake.times(FORECAST)
    assert forecast[0] == "06:02" and forecast[1:] and set(forecast[1:]) == {"12:00"}
    assert fake.times(RETAIL) == ["06:02", "06:07"]
    assert [(r.workflow, r.reason) for r in fake.reports] == [("MorningChain", "morning_incomplete")]
    assert fake.reports[0].facts["consumers"] == {"morning_retail": "complete", "morning_forecast": "failed"}


async def test_failed_eex_leaves_consumers_waiting_until_one_noon_tick(env):
    fake = FakeArtisan(failing={"futures:fetch-eex"}, retail=("never",), forecast=("never",))

    with pytest.raises(WorkflowFailureError):
        await execute(env, fake, MorningChain.run, MorningInput(await tomorrow(env)))

    assert fake.attempts["futures:fetch-eex"] == 1
    retail = fake.times(RETAIL)
    assert retail[0] == "06:02" and retail[-1] == "12:00"
    assert all(at <= "12:00" for at in retail)
    # futures:fetch-eex reports its own failure; the workflow adds only the morning outcome.
    assert [r.reason for r in fake.reports] == ["morning_incomplete"]
    assert fake.reports[0].facts["eex"] == "failed"


async def test_the_noon_tick_does_not_wait_for_a_producer_that_still_runs(env, monkeypatch):
    # Deadline before the 06:00 import start: the contract child is still waiting at the deadline.
    monkeypatch.setattr(jobs, "CONSUMER_DEADLINE", time(5, 0))
    fake = FakeArtisan(retail=("never",), forecast=("never",))

    with pytest.raises(WorkflowFailureError):
        await execute(env, fake, MorningChain.run, MorningInput(await tomorrow(env)),
                      workflow_runner=UnsandboxedWorkflowRunner())

    assert set(fake.times(RETAIL)) == {"05:00"}
    assert set(fake.times(FORECAST)) == {"05:00"}
    assert fake.names().index(RETAIL) < fake.names().index("contracts:fetch --skip-logos")


async def test_a_scheduled_run_that_finishes_after_noon_is_a_late_morning(env):
    fake = FakeArtisan(forecast=("late",), retail=("ready_after", 1))

    with pytest.raises(WorkflowFailureError):
        await execute(env, fake, MorningChain.run, MorningInput(await tomorrow(env)))

    assert fake.complete["morning_forecast"] and fake.alerted["morning_forecast"]
    assert [r.reason for r in fake.reports] == ["morning_incomplete"]
    assert fake.reports[0].facts["consumers"] == {"morning_retail": "complete",
                                                  "morning_forecast": "complete_after_deadline"}


async def test_a_morning_for_a_past_date_is_refused_before_any_command(env):
    now = await env.get_current_time()
    yesterday = (now.astimezone(HELSINKI).date() - timedelta(days=1)).isoformat()
    fake = FakeArtisan()

    with pytest.raises(WorkflowFailureError) as failure:
        await execute(env, fake, MorningChain.run, MorningInput(yesterday))

    assert "the date has passed" in str(failure.value.cause)
    assert fake.calls == [] and fake.reports == []


@pytest.mark.parametrize("state, reports", [("incomplete", []), (None, ["import_not_ready"])])
async def test_an_import_that_exits_0_without_a_ready_checkpoint_fails(env, state, reports):
    """A partial acquisition exits 0 with `incomplete` (contracts:fetch sent its own Issue)."""
    fake = FakeArtisan(import_state=state)

    with pytest.raises(WorkflowFailureError) as failure:
        await execute(env, fake, ContractImport.run, MorningInput(await tomorrow(env)))

    assert failure.value.cause.type == "ReportedFailure"
    assert f"import_{state or 'missing'}" in str(failure.value.cause)
    assert "contracts:complete-import" not in fake.names()
    assert fake.names()[-1] == "forecasting:evaluate-fixed-contracts"
    assert [r.reason for r in fake.reports] == reports


# Schedules (independent of the JOBS table: the approved plan and the old Helsinki times)

EXPECTED_SCHEDULES = {
    # name: (group, [step argv], [(hours, minute, weekday, day)])
    "fingrid-fetch": ("collectors", [["fingrid:fetch"]], [([10], 20, None, None)]),
    "gas-storage-fetch": ("collectors", [["gas-storage:fetch", "--if-configured"]], [([10], 35, None, None)]),
    "weather-fetch": ("collectors", [["weather:fetch"]], [([10], 40, None, None)]),
    "weather-fetch-ec46": ("collectors", [["weather:fetch", "--only=ecmwf_ec46"]], [([10], 50, 1, None)]),
    "weather-fetch-seas5": ("collectors", [["weather:fetch", "--only=ecmwf_seas5"]], [([11], 0, None, 8)]),
    "entsoe-fetch": ("collectors", [["entsoe:fetch"]], [([10], 50, None, None)]),
    "fmi-fetch": ("collectors", [["fmi:fetch"]], [([11], 5, None, None)]),
    "fmi-fetch-forecast": ("collectors", [["fmi:fetch", "--only=forecast"]], [([23], 5, None, None)]),
    "weather-fetch-solar-ghi": ("collectors", [["weather:fetch", "--only=solar_ghi"]], [([11, 23], 5, None, None)]),
    "weather-fetch-ecmwf-00z": ("collectors", [["weather:fetch", "--only=ecmwf_00z"]], [([9, 10, 11, 12, 13, 14], 15, None, None)]),
    "weather-fetch-ecmwf-prev-day1": ("collectors", [["weather:fetch", "--only=ecmwf_prev_day1"]], [([9, 13], 40, None, None)]),
    "fingrid-forecast-vintages": ("collectors", [["fingrid:fetch-forecast-vintages"]], [(list(range(24)), 45, None, None)]),
    "syke-fetch": ("collectors", [["syke:fetch"]], [([11], 10, None, None)]),
    "eex-europe-fetch": ("collectors", [["futures:fetch-eex-europe"]], [([12], 30, None, None)]),
    "jao-fetch": ("collectors", [["jao:fetch"]], [([12], 40, None, None)]),
    "nve-fetch-reservoirs": ("collectors", [["nve:fetch-reservoirs"]], [([15], 30, 3, None)]),
    "feedback-purge-text": ("maintenance", [["feedback:purge-text"]], [([2], 15, None, None)]),
    "backup-nightly": ("maintenance", [["backup:run", "--only-db"], ["backup:clean"], ["backup:monitor"]], [([3], 0, None, None)]),
    "price-cache-maintenance": ("maintenance", [["contracts:warm-cache", "--pending"], ["contracts:cleanup-price-cache"]], "every-5-minutes"),
    "retail-premiums-calibrate": ("maintenance", [["retail-premiums:calibrate"]], [([8], 0, None, 2)]),
    "social-weekly-offers-video": ("maintenance", [["social:weekly-offers-video"]], [([13], 0, 0, None)]),
    "spot-hourly": ("spot", [["spot:fetch"], ["social:publish-daily-spot"]], [(list(range(24)), 0, None, None)]),
    "spot-check-freshness": ("spot", [["spot:check-freshness"]], [(list(range(24)), 10, None, None)]),
    "spot-fetch-neighbours": ("spot", [["spot:fetch-neighbours"]], [(list(range(24)), 25, None, None)]),
    "spot-fetch-forecast": ("spot", [["spot:fetch-forecast"]], [([0, 6, 12, 18], 0, None, None)]),
}


def expand(ranges) -> list[int]:
    return [value for r in ranges for value in range(r.start, max(r.end, r.start) + 1, r.step or 1)]


def test_schedules_match_the_approved_plan_and_old_helsinki_times():
    wanted = schedules(Settings.from_env())
    assert set(wanted) == set(EXPECTED_SCHEDULES) | {"morning-chain", "s-forecast-daily", "s-refit-weekly"}
    registered = {workflow.__temporal_workflow_definition.name for workflow in PRODUCTION_WORKFLOWS}
    for name, schedule in wanted.items():
        assert isinstance(schedule, Schedule)
        assert schedule.policy.overlap == ScheduleOverlapPolicy.SKIP, name
        assert schedule.spec.time_zone_name == "Europe/Helsinki", name
        assert schedule.action.workflow in registered, name
    for name, (group, argv, calendars) in EXPECTED_SCHEDULES.items():
        schedule = wanted[name]
        assert name in GROUPS[group], name
        assert schedule.action.workflow == "ArtisanJob"
        assert [step.args for step in schedule.action.args[0].steps] == argv, name
        if calendars == "every-5-minutes":
            spec = schedule.spec.calendars[0]
            assert expand(spec.hour) == list(range(24)) and expand(spec.minute) == list(range(0, 60, 5))
            continue
        actual = [(expand(c.hour), expand(c.minute), expand(c.day_of_week), expand(c.day_of_month)) for c in schedule.spec.calendars]
        expected = [(hours, [minute], [weekday] if weekday is not None else list(range(7)),
                     [day] if day is not None else list(range(1, 32))) for hours, minute, weekday, day in calendars]
        assert actual == expected, name
    morning = wanted["morning-chain"]
    assert morning.action.workflow == "MorningChain" and "morning-chain" in GROUPS["morning"]
    assert (expand(morning.spec.calendars[0].hour), expand(morning.spec.calendars[0].minute)) == ([4], [0])


def test_apply_needs_an_explicit_group_and_selects_only_that_group():
    with pytest.raises(SystemExit):
        parse_args(["--apply"])
    assert parse_args([]).apply is False  # the read-only plan of every schedule stays available
    args = parse_args(["--apply", "--group", "collectors"])
    wanted = schedules(Settings.from_env())
    assert set(select(wanted, args.only, args.group)) == GROUPS["collectors"]
    assert set(select(wanted, None, None)) == set(wanted)


def test_claim_based_steps_finish_during_a_deploy_drain():
    wanted = schedules(Settings.from_env())
    steps = {tuple(step.args): step for schedule in wanted.values() if schedule.action.workflow == "ArtisanJob"
             for step in schedule.action.args[0].steps}
    for argv in (("contracts:warm-cache", "--pending"), ("social:publish-daily-spot",), ("social:weekly-offers-video",)):
        assert steps[argv].interruptible is False, argv
    assert jobs.COMPLETION_STEP.interruptible is False and jobs.COMPLETION_STEP.self_reports is True
    assert steps[("spot:fetch",)].interruptible is True
    # Every claim-based step ends within the drain, or the end of the drain still kills it.
    protected = [step for step in steps.values() if not step.interruptible] + [jobs.COMPLETION_STEP]
    limits = [timedelta(minutes=step.timeout_minutes) for step in protected] + [timedelta(minutes=jobs.CONSUMER_TIMEOUT_MINUTES)]
    assert max(limits) < GRACEFUL_SHUTDOWN


# Real activity boundary (a local fake `artisan` script; no Laravel, no network)

ARTISAN = """
import json, os, sys, time
args = sys.argv[1:]
with open("calls.log", "a") as log:
    log.write(" ".join(args) + "\\n")
if args[0] == "fail":
    sys.exit(1)
if args[0] == "sleep-once" and not os.path.exists("slept"):
    open("slept", "w").close()
    with open("pid", "w") as f:
        f.write(str(os.getpid()))
    time.sleep(30)
if args[0] == "short":
    with open("pid", "w") as f:
        f.write(str(os.getpid()))
    time.sleep(1.5)
print(json.dumps({"ok": True}))
"""


def real_settings(laravel_dir: Path, dsn: str | None = None) -> Settings:
    return Settings(temporal_address="x", namespace="x", api_key=None, laravel_dir=str(laravel_dir),
                    php_binary=sys.executable, s_model_module=None, sentry_dsn=dsn, environment="test")


def real_worker(env, activities: Activities, **options) -> Worker:
    # No workflow cache: no sticky queue, so the workflow task after a worker shutdown reaches the
    # replacement worker (the test server, unlike Temporal Cloud, does not time out a sticky queue).
    return Worker(env.client, task_queue=QUEUE, workflows=WORKFLOWS,
                  activities=[activities.run_command, activities.report_issue],
                  workflow_failure_exception_types=[Exception], max_cached_workflows=0, **options)


def calls(path: Path) -> list[str]:
    return (path / "calls.log").read_text().splitlines()


async def wait_for(path: Path) -> int:
    for _ in range(100):
        if path.exists() and path.read_text():
            return int(path.read_text())
        await asyncio.sleep(0.05)
    raise AssertionError(f"{path} not written")


def alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
    except OSError:
        return False
    return True


async def test_a_real_nonzero_exit_is_command_failed_and_runs_once(env, tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    (tmp_path / "artisan").write_text(ARTISAN)
    activities = Activities(real_settings(tmp_path))
    async with real_worker(env, activities):
        with pytest.raises(WorkflowFailureError):
            await env.client.execute_workflow(ArtisanJob.run, ArtisanJobInput("t", [Step(["fail"])]),
                                              id=f"real-{uuid.uuid4()}", task_queue=QUEUE)
    assert calls(tmp_path) == ["fail"]


async def test_a_deploy_kills_an_interruptible_command_and_the_new_worker_repeats_it(env, tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    (tmp_path / "artisan").write_text(ARTISAN)
    activities = Activities(real_settings(tmp_path))
    worker = real_worker(env, activities, graceful_shutdown_timeout=timedelta(seconds=20))
    run = asyncio.ensure_future(worker.run())
    handle = await env.client.start_workflow(ArtisanJob.run, ArtisanJobInput("t", [Step(["sleep-once"])]),
                                             id=f"real-{uuid.uuid4()}", task_queue=QUEUE)
    pid = await wait_for(tmp_path / "pid")
    await worker.shutdown()
    await run
    assert not alive(pid)

    async with real_worker(env, activities):
        result = await handle.result()
    assert result.outcomes[0].status == "succeeded"
    assert calls(tmp_path) == ["sleep-once", "sleep-once"]


async def test_a_deploy_lets_a_claim_based_command_finish(env, tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    (tmp_path / "artisan").write_text(ARTISAN)
    activities = Activities(real_settings(tmp_path))
    worker = real_worker(env, activities, graceful_shutdown_timeout=timedelta(seconds=20))
    run = asyncio.ensure_future(worker.run())
    handle = await env.client.start_workflow(ArtisanJob.run, ArtisanJobInput("t", [Step(["short"], interruptible=False)]),
                                             id=f"real-{uuid.uuid4()}", task_queue=QUEUE)
    await wait_for(tmp_path / "pid")
    await worker.shutdown()
    await run

    async with real_worker(env, activities):
        result = await handle.result()
    assert result.outcomes[0].status == "succeeded"
    assert calls(tmp_path) == ["short"]


# Sentry: exactly one event per failure through the production reporting path


class Collect(sentry_sdk.transport.Transport):
    def __init__(self, options=None):
        super().__init__(options)
        self.events: list[dict] = []

    def capture_envelope(self, envelope):
        self.events += [item.payload.json for item in envelope.items if item.type == "event"]


@pytest.fixture
def transport():
    # Production initialisation; only the network transport is replaced.
    collect = Collect()
    sentry.init("https://key@sentry.invalid/1", "test", transport=collect)
    yield collect
    sentry_sdk.init(dsn=None)


@pytest.mark.parametrize(("step", "events"), [
    (Step(["fail"]), [["temporal-workflow", "backup-nightly", "command_failed"]]),
    (Step(["fail"], self_reports=True), []),
    (Step(["short"]), []),
])
async def test_one_sentry_event_per_failed_job(env, tmp_path, monkeypatch, transport, step, events):
    monkeypatch.chdir(tmp_path)
    (tmp_path / "artisan").write_text(ARTISAN)
    activities = Activities(real_settings(tmp_path, dsn="https://key@sentry.invalid/1"))
    async with real_worker(env, activities, interceptors=[sentry.SentryInterceptor()]):
        try:
            await env.client.execute_workflow(ArtisanJob.run, ArtisanJobInput("backup-nightly", [step]),
                                              id=f"real-{uuid.uuid4()}", task_queue=QUEUE)
        except WorkflowFailureError as failure:
            assert failure.cause.type == "ReportedFailure"
    sentry_sdk.flush()
    assert [event["fingerprint"] for event in transport.events] == events
