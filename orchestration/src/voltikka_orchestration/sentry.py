"""Sentry for the worker: init, and one Issue for an unexpected workflow failure.

Expected outcomes that need a person (no forecast, missed deadline, failed refit) are sent by the
`report_issue` activity. This interceptor covers the rest: an exception that leaves workflow code
(a bug, a sandbox restriction, an activity error that the workflow does not handle). The worker
sets `workflow_failure_exception_types=[Exception]`, so such an exception fails the workflow once
instead of retrying its workflow task forever, and this reports it once. An `ArtisanJob` that ends
with a failed step raises `ReportedFailure` after its own Issue; that error is not reported again.
"""

from __future__ import annotations

from typing import Any

from temporalio import workflow
from temporalio.exceptions import ApplicationError
from temporalio.worker import ExecuteWorkflowInput, Interceptor, WorkflowInboundInterceptor, WorkflowInterceptorClassInput

with workflow.unsafe.imports_passed_through():
    import sentry_sdk
    from sentry_sdk.integrations.logging import LoggingIntegration


def init(dsn: str | None, environment: str, transport: object | None = None) -> None:
    """`transport` replaces only the network transport (tests)."""
    if not dsn:
        return
    # event_level=None: a log line is never an Issue; only the explicit captures are (report_issue
    # logs its outcome at ERROR and captures it once).
    sentry_sdk.init(
        dsn=dsn,
        environment=environment,
        traces_sample_rate=0.0,
        integrations=[LoggingIntegration(event_level=None)],
        transport=transport,
    )


def report_workflow_failure(info: workflow.Info, error: BaseException) -> None:
    with sentry_sdk.new_scope() as scope:
        scope.fingerprint = ["temporal-workflow", info.workflow_type, "unexpected_failure", type(error).__name__]
        scope.set_tag("workflow", info.workflow_type)
        scope.set_tag("reason", "unexpected_failure")
        scope.set_context(
            "temporal",
            {"workflow_id": info.workflow_id, "run_id": info.run_id, "attempt": info.attempt},
        )
        sentry_sdk.capture_exception(error)


class SentryInterceptor(Interceptor):
    def workflow_interceptor_class(self, input: WorkflowInterceptorClassInput) -> type[WorkflowInboundInterceptor]:
        return _WorkflowInbound


class _WorkflowInbound(WorkflowInboundInterceptor):
    async def execute_workflow(self, input: ExecuteWorkflowInput) -> Any:
        try:
            return await super().execute_workflow(input)
        except Exception as error:
            # A replay re-raises the same error: report only the first time it happens. A
            # "ReportedFailure" (jobs.py) already sent its own Issue.
            already_reported = isinstance(error, ApplicationError) and error.type == "ReportedFailure"
            if not already_reported and not workflow.unsafe.is_replaying():
                with workflow.unsafe.sandbox_unrestricted():
                    report_workflow_failure(workflow.info(), error)
            raise
