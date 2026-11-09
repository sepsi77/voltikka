"""Every Temporal job has the same group in laravel/routes/console.php.

console.php skips a group when TEMPORAL_SCHEDULE_GROUPS lists it. If a job had another group
there, applying its Temporal group would leave it running in both schedulers (or in neither).
"""
import re
from pathlib import Path

from voltikka_orchestration import jobs
from voltikka_orchestration.schedules import JOBS

CONSOLE = Path(__file__).resolve().parents[2] / "laravel" / "routes" / "console.php"
# Console entries with no Temporal job: they leave the web scheduler with their group.
# cache:reclaim-expired-files reclaims the file cache only; with the shared Redis store it has no
# work (phase2-plan.md R3).
NO_TEMPORAL_JOB = {("maintenance", "cache:reclaim-expired-files")}


def console_groups() -> dict[str, str]:
    """Command string -> group of every entry inside an `if ($inWebScheduler('<group>'))` block."""
    found = re.findall(r"if \(\$inWebScheduler\('([a-z-]+)'\)\) \{\s*Schedule::command\('([^']+)'\)", CONSOLE.read_text())
    return {command: group for group, command in found}


def console_group(groups: dict[str, str], args: list[str]) -> str | None:
    """The console group of a Temporal step: the exact command first, then the command name, as
    long as only one console entry has that name (Temporal adds flags such as --if-configured)."""
    full = " ".join(args)
    if full in groups:
        return groups[full]
    same_name = [group for command, group in groups.items() if command.split()[0] == args[0]]
    return same_name[0] if len(same_name) == 1 else None


def temporal_steps() -> list[tuple[str, list[str]]]:
    steps = [(group, step.args) for group, _, job_steps in JOBS.values() for step in job_steps]
    morning = [jobs.EEX_STEP, jobs.CONTRACTS_STEP, jobs.COMPLETION_STEP, jobs.EVALUATION_STEP]
    steps += [("morning", step.args) for step in morning]
    steps += [("morning", args) for args in jobs.CONSUMERS.values()]
    return steps


def test_every_temporal_step_has_its_group_in_console_php():
    groups = console_groups()
    assert len(groups) > 30, "the console.php pattern no longer matches its group blocks"
    wrong = [
        (" ".join(args), group, console_group(groups, args))
        for group, args in temporal_steps()
        if console_group(groups, args) != group
    ]
    assert wrong == []


def test_every_console_group_entry_is_a_temporal_step():
    temporal = {(group, args[0]) for group, args in temporal_steps()}
    extra = [(command, group) for command, group in console_groups().items() if (group, command.split()[0]) not in temporal | NO_TEMPORAL_JOB]
    assert extra == []
