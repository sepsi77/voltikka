"""Worker entrypoint: `python -m voltikka_orchestration.worker` (Railway service voltikka-worker)."""

from __future__ import annotations

import asyncio
import logging
import signal
import sys
from datetime import timedelta

from temporalio.client import Client
from temporalio.worker import Worker

from . import sentry
from .activities import Activities
from .jobs import ArtisanJob, ContractImport, EexFutures, MorningChain
from .s_forecast import SDailyForecast, SWeeklyRefit
from .settings import Settings

# Longer than every claim-based step (interruptible=False): the morning consumers and the weekly
# social video have 60-minute timeouts (tests/test_jobs.py checks this). Railway's
# RAILWAY_DEPLOYMENT_DRAINING_SECONDS on the worker must be longer still (4000 s).
GRACEFUL_SHUTDOWN = timedelta(minutes=65)

WORKFLOWS = [SDailyForecast, SWeeklyRefit, ArtisanJob, MorningChain, EexFutures, ContractImport]


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
        # One process per command. Long activities (EEX about 1.7 h, contract import, S training)
        # hold a slot each, so the morning and 10:20-11:10 collector peaks need more than four.
        max_concurrent_activities=8,
        # Deploy drain: interruptible commands stop at once and are retried on the new worker;
        # claim-based commands (CommandRequest.interruptible=False) can finish. The Railway service
        # variable RAILWAY_DEPLOYMENT_DRAINING_SECONDS must be longer than this (4000 s).
        graceful_shutdown_timeout=GRACEFUL_SHUTDOWN,
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
    worker = build_worker(client, settings)
    # Railway sends SIGTERM to the old deployment after the new one is active. The worker stops
    # polling; interruptible commands stop at once (run_command kills the child) and fail as
    # retryable, so the new worker repeats them; claim-based commands finish within the drain.
    loop = asyncio.get_running_loop()
    loop.add_signal_handler(signal.SIGTERM, lambda: asyncio.ensure_future(worker.shutdown()))
    await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
