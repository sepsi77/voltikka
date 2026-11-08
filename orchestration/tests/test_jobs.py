from __future__ import annotations

import uuid
from datetime import timedelta
from zoneinfo import ZoneInfo

import pytest
from temporalio import activity
from temporalio.client import WorkflowFailureError
from temporalio.exceptions import ApplicationError
from temporalio.worker import Worker

from voltikka_orchestration.activities import CommandRequest, CommandResult, IssueReport
from voltikka_orchestration.jobs import (
    ArtisanJob,
    ArtisanJobInput,
    ContractImport,
    EexFutures,
    MorningChain,
    MorningInput,
    Step,
)
from voltikka_orchestration.schedules import GROUPS, JOBS, schedules
from voltikka_orchestration.settings import Settings

QUEUE = "jobs-test-queue"
HELSINKI = ZoneInfo("Europe/Helsinki")
WORKFLOWS = [ArtisanJob, MorningChain, EexFutures, ContractImport]


class FakeArtisan:
    """Simulates commands and the checkpoints that they write."""

    def __init__(self, *, failing=(), retail_ready_after=2, consumers_never_ready=False, completion_ticks=2):
        self.calls: list[tuple[str, str]] = []
        self.attempts: dict[str, int] = {}
        self.reports: list[IssueReport] = []
        self.failing = set(failing)
        self.checkpoints: dict[str, str | None] = {
            "eex_futures": None, "contract_import": None, "morning_retail": None, "morning_forecast": None,
        }
        self.retail_ready_after = retail_ready_after
        self.consumers_never_ready = consumers_never_ready
        self.completion_ticks = completion_ticks
        self.retail_ticks = 0

    @activity.defn(name="run_command")
    async def run_command(self, request: CommandRequest) -> CommandResult:
        name = " ".join(request.args)
        if request.args[0] != "orchestration:readiness":
            at = activity.info().current_attempt_scheduled_time.astimezone(HELSINKI).strftime("%H:%M")
            self.calls.append((name, at))
            self.attempts[name] = self.attempts.get(name, 0) + 1
        if name in self.failing:
            # Retryable by itself: only the workflow retry policy may stop the retry.
            raise ApplicationError(f"{name} exited 1 (boom)", "boom", type="CommandFailed")
        command = request.args[0]
        if command == "orchestration:readiness":
            return CommandResult(0, {"date": "x", "checkpoints": dict(self.checkpoints)}, 0.1)
        if command == "futures:fetch-eex":
            self.checkpoints["eex_futures"] = "ready"
        elif command == "contracts:fetch":
            self.checkpoints["contract_import"] = "pending_completion"
        elif command == "contracts:complete-import":
            self.completion_ticks -= 1
            if self.completion_ticks <= 0:
                self.checkpoints["contract_import"] = "ready"
        elif command == "retail-premiums:collect" and not self.consumers_never_ready:
            self.retail_ticks += 1
            if self.retail_ticks >= self.retail_ready_after:
                self.checkpoints["morning_retail"] = "ready"
        elif command == "forecasting:run-fixed-contracts" and not self.consumers_never_ready:
            self.checkpoints["morning_forecast"] = "ready"
        return CommandResult(0, None, 0.1)

    @activity.defn(name="report_issue")
    async def report_issue(self, report: IssueReport) -> None:
        self.reports.append(report)

    def names(self) -> list[str]:
        return [name for name, _ in self.calls]


async def execute(env, fake: FakeArtisan, workflow_run, params):
    async with Worker(
        env.client, task_queue=QUEUE, workflows=WORKFLOWS,
        activities=[fake.run_command, fake.report_issue],
        workflow_failure_exception_types=[Exception],
    ):
        return await env.client.execute_workflow(
            workflow_run, params, id=f"jobs-test-{uuid.uuid4()}", task_queue=QUEUE
        )


async def tomorrow(env) -> str:
    now = await env.get_current_time()
    return (now.astimezone(HELSINKI).date() + timedelta(days=1)).isoformat()


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


async def test_a_self_reporting_command_gets_no_second_issue(env):
    fake = FakeArtisan(failing={"spot:fetch"})
    params = ArtisanJobInput("spot-hourly", [
        Step(["spot:fetch"], self_reports=True),
        Step(["social:publish-daily-spot"], always=True, max_attempts=1),
    ])

    with pytest.raises(WorkflowFailureError):
        await execute(env, fake, ArtisanJob.run, params)

    assert fake.names() == ["spot:fetch", "social:publish-daily-spot"]
    assert fake.reports == []


async def test_morning_chain_starts_consumers_when_both_producers_have_ended(env):
    fake = FakeArtisan()
    day = await tomorrow(env)

    result = await execute(env, fake, MorningChain.run, MorningInput(day))

    assert result.eex == "succeeded" and result.contracts == "succeeded"
    assert result.checkpoints["morning_retail"] == "ready"
    assert result.checkpoints["morning_forecast"] == "ready"
    calls = dict((name, at) for name, at in reversed(fake.calls))  # first time of each command
    assert fake.names()[0] == "futures:fetch-eex"  # starts with the workflow (Schedule 04:00)
    assert calls["contracts:fetch --skip-logos"] == "06:00"
    completions = [at for name, at in fake.calls if name == "contracts:complete-import"]
    assert completions == ["06:01", "06:02"]
    names = fake.names()
    assert names.index("forecasting:evaluate-fixed-contracts") > names.index("contracts:complete-import")
    retail = [at for name, at in fake.calls if name == "retail-premiums:collect --scheduled"]
    forecast = [at for name, at in fake.calls if name == "forecasting:run-fixed-contracts --scheduled"]
    assert retail == ["06:02", "06:07"]
    assert forecast == ["06:02"]
    assert fake.reports == []


async def test_morning_chain_ticks_once_at_noon_for_the_deadline_alert(env):
    fake = FakeArtisan(failing={"futures:fetch-eex"}, consumers_never_ready=True)
    day = await tomorrow(env)

    result = await execute(env, fake, MorningChain.run, MorningInput(day))

    assert result.eex == "failed"
    assert fake.attempts["futures:fetch-eex"] == 1
    retail = [at for name, at in fake.calls if name == "retail-premiums:collect --scheduled"]
    assert retail[0] == "06:02"
    assert retail[-1] == "12:00"
    assert all(at <= "12:00" for at in retail)
    # futures:fetch-eex reports its own failure.
    assert fake.reports == []


def test_every_schedule_starts_a_registered_workflow_and_groups_cover_all():
    wanted = schedules(Settings.from_env())
    assert set().union(*GROUPS.values()) == set(wanted)
    assert len(wanted) == len(JOBS) + 3
    for name, (_, _, steps) in JOBS.items():
        assert steps, name
        assert all(step.args and ":" in step.args[0] for step in steps), name
