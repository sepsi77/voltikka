"""Every Temporal job has the same group in laravel/routes/console.php, and the reverse.

console.php skips a group when TEMPORAL_SCHEDULE_GROUPS lists it. If a job had another group
there, applying its Temporal group would leave it running in both schedulers (or in neither).
Commands are compared as full strings, so a lost variant (for example one `weather:fetch --only=`
job) fails the test.
"""
import re
from pathlib import Path

from voltikka_orchestration import jobs
from voltikka_orchestration.schedules import JOBS

CONSOLE = Path(__file__).resolve().parents[2] / "laravel" / "routes" / "console.php"
# Flags that only the Temporal step adds. --if-configured replaces the console `when(...)` check of
# gas-storage:fetch; --skip-logos moves logos:download to the web scheduler (morning group).
TEMPORAL_ONLY_FLAGS = {"--if-configured", "--skip-logos"}
# Console entries with no Temporal job: they leave the web scheduler with their group.
# cache:reclaim-expired-files reclaims the file cache only; with the shared Redis store it has no
# work (phase2-plan.md R3).
NO_TEMPORAL_JOB = {("maintenance", "cache:reclaim-expired-files --apply --scheduled")}


def console_entries() -> set[tuple[str, str]]:
    """(group, command) of every entry inside an `if ($inWebScheduler('<group>'))` block."""
    found = re.findall(r"if \(\$inWebScheduler\('([a-z-]+)'\)\) \{\s*Schedule::command\('([^']+)'\)", CONSOLE.read_text())
    return set(found)


def temporal_entries() -> set[tuple[str, str]]:
    """(group, command) of every Temporal step, without the Temporal-only flags."""
    steps = [(group, step.args) for group, _, job_steps in JOBS.values() for step in job_steps]
    morning = [jobs.EEX_STEP, jobs.CONTRACTS_STEP, jobs.COMPLETION_STEP, jobs.EVALUATION_STEP]
    steps += [("morning", step.args) for step in morning]
    steps += [("morning", args) for args in jobs.CONSUMERS.values()]
    return {(group, " ".join(arg for arg in args if arg not in TEMPORAL_ONLY_FLAGS)) for group, args in steps}


def test_the_console_pattern_matches_its_group_blocks():
    assert len(console_entries()) > 30


def test_every_temporal_step_has_its_group_in_console_php():
    assert temporal_entries() - console_entries() == set()


def test_every_console_group_entry_is_a_temporal_step():
    assert console_entries() - temporal_entries() == NO_TEMPORAL_JOB
