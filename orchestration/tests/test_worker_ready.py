"""The worker's Docker health check: the ready file exists only after connect() succeeded."""

from __future__ import annotations

import asyncio

import pytest

from voltikka_orchestration import worker


class _Settings:
    sentry_dsn = None
    environment = "test"
    namespace = "ns"
    task_queue = "q"


@pytest.fixture
def ready_file(tmp_path, monkeypatch):
    path = tmp_path / "voltikka-worker-ready"
    monkeypatch.setattr(worker, "READY_FILE", path)
    monkeypatch.setattr(worker.Settings, "from_env", staticmethod(lambda: _Settings()))
    monkeypatch.setattr(worker.sentry, "init", lambda *args: None)
    return path


def test_a_failed_connect_leaves_no_ready_file(ready_file, monkeypatch):
    ready_file.write_text("connected\n")  # left by an earlier run of the same container

    async def fail(settings):
        raise RuntimeError("cannot reach Temporal")

    monkeypatch.setattr(worker, "connect", fail)
    with pytest.raises(RuntimeError):
        asyncio.run(worker.main())
    assert not ready_file.exists()


def test_the_ready_file_appears_after_connect_and_before_the_worker_runs(ready_file, monkeypatch):
    seen = {}

    async def ok(settings):
        seen["at_connect"] = ready_file.exists()
        return object()

    class _Worker:
        async def run(self):
            seen["at_run"] = ready_file.exists()

        async def shutdown(self):
            pass

    monkeypatch.setattr(worker, "connect", ok)
    monkeypatch.setattr(worker, "build_worker", lambda client, settings: _Worker())
    asyncio.run(worker.main())
    assert seen == {"at_connect": False, "at_run": True}
