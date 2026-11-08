"""Create or update the Temporal Schedules (idempotent).

`python -m voltikka_orchestration.schedules [--apply]`: without --apply, print the plan only.
Creating or changing a production schedule is a production change and needs user approval.
"""

from __future__ import annotations

import argparse
import asyncio
from datetime import timedelta

from temporalio.client import (
    Client,
    Schedule,
    ScheduleActionStartWorkflow,
    ScheduleAlreadyRunningError,
    ScheduleCalendarSpec,
    ScheduleOverlapPolicy,
    SchedulePolicy,
    ScheduleRange,
    ScheduleSpec,
    ScheduleUpdate,
    ScheduleUpdateInput,
)

from .s_forecast import SDailyForecast, SForecastInput, SRefitInput, SWeeklyRefit
from .settings import Settings
from .worker import connect

TIMEZONE = "Europe/Helsinki"


def schedules(settings: Settings) -> dict[str, Schedule]:
    policy = SchedulePolicy(overlap=ScheduleOverlapPolicy.SKIP, catchup_window=timedelta(minutes=30))
    return {
        "s-forecast-daily": Schedule(
            action=ScheduleActionStartWorkflow(
                SDailyForecast.run, SForecastInput(), id="s-forecast", task_queue=settings.task_queue
            ),
            spec=ScheduleSpec(
                calendars=[ScheduleCalendarSpec(hour=[ScheduleRange(10)], minute=[ScheduleRange(55)])],
                time_zone_name=TIMEZONE,
            ),
            policy=policy,
        ),
        "s-refit-weekly": Schedule(
            action=ScheduleActionStartWorkflow(
                SWeeklyRefit.run, SRefitInput(), id="s-refit", task_queue=settings.task_queue
            ),
            spec=ScheduleSpec(
                calendars=[
                    ScheduleCalendarSpec(
                        day_of_week=[ScheduleRange(1)], hour=[ScheduleRange(10)], minute=[ScheduleRange(45)]
                    )
                ],
                time_zone_name=TIMEZONE,
            ),
            policy=policy,
        ),
    }


async def apply(client: Client, wanted: dict[str, Schedule]) -> None:
    for schedule_id, schedule in wanted.items():
        try:
            await client.create_schedule(schedule_id, schedule)
            print(f"created {schedule_id}")
        except ScheduleAlreadyRunningError:
            async def replace(_: ScheduleUpdateInput, new: Schedule = schedule) -> ScheduleUpdate:
                return ScheduleUpdate(schedule=new)

            await client.get_schedule_handle(schedule_id).update(replace)
            print(f"updated {schedule_id}")


async def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="create or update the schedules")
    parser.add_argument("--only", action="append", help="schedule ID(s) to apply (default: all)")
    args = parser.parse_args()

    settings = Settings.from_env()
    wanted = schedules(settings)
    if args.only:
        wanted = {key: value for key, value in wanted.items() if key in args.only}
    for schedule_id in wanted:
        print(f"{schedule_id} -> namespace {settings.namespace}, task queue {settings.task_queue}")
    if args.apply:
        await apply(await connect(settings), wanted)


if __name__ == "__main__":
    asyncio.run(main())
