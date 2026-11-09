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

from .jobs import ArtisanJob, ArtisanJobInput, MorningChain, MorningInput, Step
from .s_forecast import SDailyForecast, SForecastInput, SRefitInput, SWeeklyRefit
from .settings import Settings
from .worker import connect

TIMEZONE = "Europe/Helsinki"


def at(hours: list[int] | None = None, minute: int = 0, *, weekday: int | None = None,
       day: int | None = None) -> ScheduleCalendarSpec:
    """Helsinki wall-clock times. hours=None = every hour; weekday 0 = Sunday (cron)."""
    return ScheduleCalendarSpec(
        hour=[ScheduleRange(h) for h in hours] if hours is not None else [ScheduleRange(0, 23)],
        minute=[ScheduleRange(minute)],
        day_of_week=[ScheduleRange(weekday)] if weekday is not None else [ScheduleRange(0, 6)],
        day_of_month=[ScheduleRange(day)] if day is not None else [ScheduleRange(1, 31)],
    )


def every_minutes(step: int) -> ScheduleCalendarSpec:
    return ScheduleCalendarSpec(hour=[ScheduleRange(0, 23)], minute=[ScheduleRange(0, 59, step)])


def job(*steps: Step) -> list[Step]:
    return list(steps)


# Phase 2 jobs (phase2-plan.md), moved from laravel/routes/console.php with the same Helsinki times
# unless a chain replaced the time. Groups are cut over one at a time (`--group`).
JOBS: dict[str, tuple[str, list[ScheduleCalendarSpec], list[Step]]] = {
    # A. Market-review and own-model collectors (no cache writes).
    "fingrid-fetch": ("collectors", [at([10], 20)], job(Step(["fingrid:fetch"], 60))),
    "gas-storage-fetch": ("collectors", [at([10], 35)], job(Step(["gas-storage:fetch", "--if-configured"]))),
    "weather-fetch": ("collectors", [at([10], 40)], job(Step(["weather:fetch"], 60))),
    "weather-fetch-ec46": ("collectors", [at([10], 50, weekday=1)], job(Step(["weather:fetch", "--only=ecmwf_ec46"], 60))),
    "weather-fetch-seas5": ("collectors", [at([11], 0, day=8)], job(Step(["weather:fetch", "--only=ecmwf_seas5"], 60))),
    "entsoe-fetch": ("collectors", [at([10], 50)], job(Step(["entsoe:fetch"], 60))),
    "fmi-fetch": ("collectors", [at([11], 5)], job(Step(["fmi:fetch"]))),
    "fmi-fetch-forecast": ("collectors", [at([23], 5)], job(Step(["fmi:fetch", "--only=forecast"]))),
    "weather-fetch-solar-ghi": ("collectors", [at([11, 23], 5)], job(Step(["weather:fetch", "--only=solar_ghi"]))),
    # Every hour 09:15-14:15: each tick stores only the points still missing.
    "weather-fetch-ecmwf-00z": ("collectors", [at(list(range(9, 15)), 15)], job(Step(["weather:fetch", "--only=ecmwf_00z"]))),
    # Before both S cuts (Fingrid 10:00 and 14:00).
    "weather-fetch-ecmwf-prev-day1": ("collectors", [at([9, 13], 40)], job(Step(["weather:fetch", "--only=ecmwf_prev_day1"]))),
    "fingrid-forecast-vintages": ("collectors", [at(None, 45)], job(Step(["fingrid:fetch-forecast-vintages"], 10))),
    "syke-fetch": ("collectors", [at([11], 10)], job(Step(["syke:fetch"]))),
    "eex-europe-fetch": ("collectors", [at([12], 30)], job(Step(["futures:fetch-eex-europe"]))),
    "jao-fetch": ("collectors", [at([12], 40)], job(Step(["jao:fetch"]))),
    "nve-fetch-reservoirs": ("collectors", [at([15], 30, weekday=3)], job(Step(["nve:fetch-reservoirs"]))),
    # B. Maintenance and other scheduled work.
    "feedback-purge-text": ("maintenance", [at([2], 15)], job(Step(["feedback:purge-text"]))),
    # Chain: clean and monitor after the backup, also when it failed (monitor reports a missing backup).
    "backup-nightly": ("maintenance", [at([3], 0)], job(
        Step(["backup:run", "--only-db"], 60),
        Step(["backup:clean"], always=True),
        Step(["backup:monitor"], always=True),
    )),
    # The shared Redis cache: one producer for all instances (was per instance every minute). The
    # warm holds the 30-minute producer lease, so a deploy lets it finish instead of killing it.
    "price-cache-maintenance": ("maintenance", [every_minutes(5)], job(
        Step(["contracts:warm-cache", "--pending"], 35, interruptible=False),
        Step(["contracts:cleanup-price-cache"], 10, always=True),
    )),
    "retail-premiums-calibrate": ("maintenance", [at([8], 0, day=2)], job(Step(["retail-premiums:calibrate"]))),
    # Social posting: never repeated automatically.
    "social-weekly-offers-video": ("maintenance", [at([13], 0, weekday=0)], job(
        Step(["social:weekly-offers-video"], 60, max_attempts=1, interruptible=False),
    )),
    # C. Spot. Publication follows the hourly import (it checks complete data itself).
    "spot-hourly": ("spot", [at(None, 0)], job(
        Step(["spot:fetch"], 55, self_reports=True),
        Step(["social:publish-daily-spot"], 15, always=True, max_attempts=1, interruptible=False),
    )),
    # Independent watchdog: it must run even when spot:fetch hangs.
    "spot-check-freshness": ("spot", [at(None, 10)], job(Step(["spot:check-freshness"], 5))),
    "spot-fetch-neighbours": ("spot", [at(None, 25)], job(Step(["spot:fetch-neighbours"]))),
    "spot-fetch-forecast": ("spot", [at([0, 6, 12, 18], 0)], job(Step(["spot:fetch-forecast"]))),
}


def schedules(settings: Settings) -> dict[str, Schedule]:
    policy = SchedulePolicy(overlap=ScheduleOverlapPolicy.SKIP, catchup_window=timedelta(minutes=30))
    wanted = {
        name: Schedule(
            action=ScheduleActionStartWorkflow(
                ArtisanJob.run, ArtisanJobInput(name, steps), id=name, task_queue=settings.task_queue
            ),
            spec=ScheduleSpec(calendars=calendars, time_zone_name=TIMEZONE),
            policy=policy,
        )
        for name, (_, calendars, steps) in JOBS.items()
    }
    # D. Morning chain: EEX and the contract import, then the consumers (jobs.MorningChain).
    wanted["morning-chain"] = Schedule(
        action=ScheduleActionStartWorkflow(
            MorningChain.run, MorningInput(), id="morning-chain", task_queue=settings.task_queue
        ),
        spec=ScheduleSpec(calendars=[at([4], 0)], time_zone_name=TIMEZONE),
        policy=policy,
    )
    return wanted | {
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


GROUPS: dict[str, set[str]] = {
    "s-forecast": {"s-forecast-daily", "s-refit-weekly"},
    "morning": {"morning-chain"},
}
for _name, (_group, _, _) in JOBS.items():
    GROUPS.setdefault(_group, set()).add(_name)


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


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="create or update the selected schedules")
    parser.add_argument("--only", action="append", help="schedule ID(s) to select (without a selector: plan of all)")
    parser.add_argument("--group", action="append", choices=sorted(GROUPS), help="schedule group(s) to select")
    args = parser.parse_args(argv)
    if args.apply and not args.group:
        # A bare apply would start groups that still run in laravel/routes/console.php.
        parser.error("--apply needs --group")
    if args.apply and args.only:
        # console.php skips whole groups (TEMPORAL_SCHEDULE_GROUPS): one applied schedule of a group
        # would run in both schedulers, and a listed group's other jobs would run nowhere.
        parser.error("--apply takes whole groups only (--group), not --only")
    return args


def select(wanted: dict[str, Schedule], only: list[str] | None, groups: list[str] | None) -> dict[str, Schedule]:
    if not (only or groups):
        return wanted
    selected = set(only or [])
    for group in groups or []:
        selected |= GROUPS[group]
    return {key: value for key, value in wanted.items() if key in selected}


async def main() -> None:
    args = parse_args()
    settings = Settings.from_env()
    wanted = select(schedules(settings), args.only, args.group)
    for schedule_id in wanted:
        print(f"{schedule_id} -> namespace {settings.namespace}, task queue {settings.task_queue}")
    if args.apply:
        await apply(await connect(settings), wanted)


if __name__ == "__main__":
    asyncio.run(main())
