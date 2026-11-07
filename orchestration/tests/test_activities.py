from __future__ import annotations

import sys
from pathlib import Path

import pytest
from temporalio.exceptions import ApplicationError
from temporalio.testing import ActivityEnvironment

from voltikka_orchestration.activities import Activities, CommandRequest, last_json_object
from voltikka_orchestration.settings import Settings

ARTISAN = """
import json, sys
args = sys.argv[1:]
print("progress line")
if args[0] == "fail":
    print(json.dumps({"error": "invalid_arguments", "secret": "x"}))
    sys.exit(1)
print(json.dumps({"ready": True, "args": args}))
"""


def settings(laravel_dir: Path, model: str | None = None) -> Settings:
    return Settings(
        temporal_address="x", namespace="x", api_key=None, laravel_dir=str(laravel_dir),
        php_binary=sys.executable, s_model_module=model, sentry_dsn=None, environment="test",
    )


def test_last_json_object():
    assert last_json_object('a\n{"x": 1}\n\n') == {"x": 1}
    assert last_json_object('{"x": 1}\nplain') is None
    assert last_json_object("[1]") is None
    assert last_json_object("") is None


async def test_artisan_command_returns_last_json_line(tmp_path):
    (tmp_path / "artisan").write_text(ARTISAN)
    result = await ActivityEnvironment().run(
        Activities(settings(tmp_path)).run_command, CommandRequest("artisan", ["check", "--view=morning"])
    )
    assert result.exit_code == 0
    assert result.result == {"ready": True, "args": ["check", "--view=morning"]}


async def test_failed_command_raises_with_only_the_error_key(tmp_path):
    (tmp_path / "artisan").write_text(ARTISAN)
    with pytest.raises(ApplicationError) as raised:
        await ActivityEnvironment().run(Activities(settings(tmp_path)).run_command, CommandRequest("artisan", ["fail"]))
    assert "invalid_arguments" in str(raised.value)
    assert "secret" not in str(raised.value)


async def test_model_command_is_a_stub_without_a_module(tmp_path):
    result = await ActivityEnvironment().run(
        Activities(settings(tmp_path)).run_command, CommandRequest("model", ["predict", "--issue-date=2026-11-08"])
    )
    assert result.result == {"status": "stub", "command": "predict"}


def test_entrypoints_import():
    import voltikka_orchestration.schedules  # noqa: F401
    import voltikka_orchestration.worker  # noqa: F401
