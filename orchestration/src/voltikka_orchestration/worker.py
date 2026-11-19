"""Worker entrypoint: `python -m voltikka_orchestration.worker` (Kamal role `worker`)."""

from __future__ import annotations

import asyncio
import logging
import signal
import sys
from datetime import timedelta
from pathlib import Path

from temporalio.client import Client
from temporalio.worker import Worker

from . import sentry
from .activities import Activities
from .jobs import ArtisanJob, ContractImport, EexFutures, ImportReplay, MorningChain
from .s_forecast import SDailyForecast, SWeeklyRefit
from .settings import Settings

# Longer than every claim-based step (interruptible=False): the morning consumers and the weekly
# social video have 60-minute timeouts (tests/test_jobs.py checks this). The Kamal stop_timeout of
# the worker role (config/deploy.yml, 3960 s) must be longer still.
GRACEFUL_SHUTDOWN = timedelta(minutes=65)

# Docker health check of the worker role (config/deploy.yml `health-cmd`): Kamal stops the old
# worker only after this container is healthy, that is, after connect() succeeded. It is in the
# container's own /tmp, never on the shared storage volume, where the old container's file would
# make a new worker look healthy.
READY_FILE = Path("/tmp/voltikka-worker-ready")

WORKFLOWS = [SDailyForecast, SWeeklyRefit, ArtisanJob, MorningChain, EexFutures, ContractImport, ImportReplay]


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
        # claim-based commands (CommandRequest.interruptible=False) can finish. The Kamal
        # stop_timeout of the worker role must be longer than this (3960 s).
        graceful_shutdown_timeout=GRACEFUL_SHUTDOWN,
    )


async def main() -> None:
    # stdout, not stderr (Railway showed every stderr line with level "error").
    logging.basicConfig(
        level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s", stream=sys.stdout
    )
    # A restart of the same container keeps /tmp: the file must mean "this process connected".
    READY_FILE.unlink(missing_ok=True)
    settings = Settings.from_env()
    sentry.init(settings.sentry_dsn, settings.environment)
    client = await connect(settings)
    READY_FILE.write_text("connected\n")
    logging.getLogger(__name__).info(
        "Worker started: namespace %s, task queue %s", settings.namespace, settings.task_queue
    )
    worker = build_worker(client, settings)
    # Kamal sends SIGTERM to the old worker after the new one is healthy. The worker stops
    # polling; interruptible commands stop at once (run_command kills the child) and fail as
    # retryable, so the new worker repeats them; claim-based commands finish within the drain.
    loop = asyncio.get_running_loop()
    loop.add_signal_handler(signal.SIGTERM, lambda: asyncio.ensure_future(worker.shutdown()))
    await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
