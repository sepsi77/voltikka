import json
import sqlite3

from voltikka_forecast.s import __main__ as cli

from conftest import SCHEMA


def _db(tmp_path, monkeypatch):
    path = tmp_path / "t.sqlite"
    conn = sqlite3.connect(path)
    conn.executescript(SCHEMA)
    conn.execute("""insert into forecast_model_versions (model, version, refit_monday, object_key, sha256, size_bytes,
        sklearn_version, code_sha256, fit_facts, is_active) values ('s_v1', 's_v1-x', '2026-11-02', 'k', 's', 1, '1.9.1', 'c', '{}', 1)""")
    conn.execute("""insert into forecast_vintages (model, model_version_id, issue_date, morning_cut, afternoon_cut,
        started_at, finished_at, code_sha256, inputs) values ('s_v1', 1, '2026-11-07', 'a', 'b', 'c', 'd', 'e', '{}')""")
    conn.commit()
    monkeypatch.setenv("FORECAST_SQLITE", str(path))


def _last_json(capsys):
    return json.loads(capsys.readouterr().out.strip().splitlines()[-1])


def test_predict_rejects_other_cuts_than_the_frozen_ones(tmp_path, monkeypatch, capsys):
    _db(tmp_path, monkeypatch)
    code = cli.main(["predict", "--issue-date=2026-11-07", "--morning-cut=2026-11-07T09:00:00Z",
                     "--afternoon-cut=2026-11-07T12:30:00Z"])
    out = _last_json(capsys)
    assert code == 1 and out["status"] == "failed" and out["error"] == "cuts_differ"


def test_predict_is_idempotent_per_issue_day(tmp_path, monkeypatch, capsys):
    _db(tmp_path, monkeypatch)
    # 11:30 and 14:30 Helsinki (EET, UTC+2) on 2026-11-07.
    code = cli.main(["predict", "--issue-date=2026-11-07", "--morning-cut=2026-11-07T09:30:00Z",
                     "--afternoon-cut=2026-11-07T12:30:00+00:00"])
    out = _last_json(capsys)
    assert code == 0 and out == {"status": "exists", "vintage_id": 1, "model_version": "s_v1-x"}


def test_missing_database_variable_gives_the_json_line(monkeypatch, capsys):
    monkeypatch.delenv("FORECAST_SQLITE", raising=False)
    monkeypatch.delenv("DB_HOST", raising=False)
    code = cli.main(["score", "--target-date=2026-11-07"])
    lines = capsys.readouterr().out.strip().splitlines()
    assert code == 1 and json.loads(lines[-1]) == {"status": "failed", "command": "score", "error": "internal_error"}
    assert lines[-2] == "score failed: KeyError 'DB_HOST'"
