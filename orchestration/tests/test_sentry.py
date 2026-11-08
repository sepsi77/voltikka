from __future__ import annotations

import uuid

from temporalio import workflow

with workflow.unsafe.imports_passed_through():
    import pytest
    import sentry_sdk
    from sentry_sdk.transport import Transport
    from temporalio.client import WorkflowFailureError
    from temporalio.worker import Worker

    from voltikka_orchestration import sentry

QUEUE = "sentry-test"


@workflow.defn
class Buggy:
    @workflow.run
    async def run(self) -> str:
        return {}["missing"]  # a KeyError from workflow code


@workflow.defn
class Fine:
    @workflow.run
    async def run(self) -> str:
        return "ok"


class Collect(Transport):
    def __init__(self, options=None):
        super().__init__(options)
        self.events: list[dict] = []

    def capture_envelope(self, envelope):
        self.events += [item.payload.json for item in envelope.items if item.type == "event"]


@pytest.fixture
def transport():
    collect = Collect()
    sentry_sdk.init(dsn="https://key@sentry.invalid/1", transport=collect,
                    integrations=[], default_integrations=False)
    yield collect
    sentry_sdk.init(dsn=None)


async def execute(env, workflow_run):
    async with Worker(
        env.client, task_queue=QUEUE, workflows=[Buggy, Fine],
        workflow_failure_exception_types=[Exception], interceptors=[sentry.SentryInterceptor()],
    ):
        return await env.client.execute_workflow(workflow_run, id=f"sentry-{uuid.uuid4()}", task_queue=QUEUE)


async def test_a_bug_in_workflow_code_fails_the_run_with_one_issue(env, transport):
    with pytest.raises(WorkflowFailureError):
        await execute(env, Buggy.run)
    sentry_sdk.flush()
    assert len(transport.events) == 1
    event = transport.events[0]
    assert event["fingerprint"] == ["temporal-workflow", "Buggy", "unexpected_failure", "KeyError"]
    assert event["tags"]["reason"] == "unexpected_failure"


async def test_a_normal_result_sends_nothing(env, transport):
    assert await execute(env, Fine.run) == "ok"
    sentry_sdk.flush()
    assert transport.events == []
