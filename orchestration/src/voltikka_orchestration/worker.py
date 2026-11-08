"""Worker entrypoint: `python -m voltikka_orchestration.worker` (Railway service voltikka-worker)."""

from __future__ import annotations

import asyncio
import logging
import sys

from temporalio.client import Client
from temporalio.worker import Worker

from . import sentry
from .activities import Activities
from .s_forecast import SDailyForecast, SWeeklyRefit
from .settings import Settings

WORKFLOWS = [SDailyForecast, SWeeklyRefit]


async def connect(settings: Settings) -> Client:
    if settings.api_key:
        return await Client.connect(
            settings.temporal_address, namespace=settings.namespace, api_key=settings.api_key, tls=True
        )
    return await Client.connect(settings.temporal_address, namespace=settings.namespace)


def build_worker(client: Client, settings: Settings) -> Worker:
    activities = Activities(settings)
    return Worker(
        client,
        task_queue=settings.task_queue,
        workflows=WORKFLOWS,
        activities=[activities.run_command, activities.report_issue],
        # Any exception from workflow code fails the workflow (one Sentry Issue from the
        # interceptor) instead of retrying its workflow task forever.
        workflow_failure_exception_types=[Exception],
        interceptors=[sentry.SentryInterceptor()],
        # One process per command: keep concurrency small (shared 32 vCPU host, MySQL, API limits).
        max_concurrent_activities=4,
    )


async def main() -> None:
    # stdout: Railway shows every stderr line with level "error".
    logging.basicConfig(
        level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s", stream=sys.stdout
    )
    settings = Settings.from_env()
    sentry.init(settings.sentry_dsn, settings.environment)
    client = await connect(settings)
    logging.getLogger(__name__).info(
        "Worker started: namespace %s, task queue %s", settings.namespace, settings.task_queue
    )
    await build_worker(client, settings).run()


if __name__ == "__main__":
    asyncio.run(main())
