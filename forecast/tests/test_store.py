import json
from datetime import date

import pandas as pd
import pytest

from voltikka_forecast.s import store as S

VERSION = {"model": "s_v1", "refit_monday": "2026-11-09", "object_key": "models/s_v1/x.pkl", "sha256": "a" * 64,
           "size_bytes": 10, "sklearn_version": "1.9.1", "code_sha256": "b" * 64, "fit_facts": {"rows": 1}}


def test_versions_are_idempotent_and_only_one_is_active(db):
    assert S.insert_version(db, {**VERSION, "version": "v1"})
    assert not S.insert_version(db, {**VERSION, "version": "v1"})
    S.insert_version(db, {**VERSION, "version": "v2"})
    assert S.active_version(db, "s_v1") is None
    S.activate(db, "s_v1", "v1")
    S.activate(db, "s_v1", "v2")
    assert S.active_version(db, "s_v1")["version"] == "v2"
    assert len(db.rows("select id from forecast_model_versions where is_active = 1")) == 1
    with pytest.raises(RuntimeError):
        S.activate(db, "s_v1", "missing")


def _vintage(db, issue):
    S.insert_version(db, {**VERSION, "version": "v1"})
    row = S.version(db, "v1")
    # D+2 of issue 2026-11-07 = 2026-11-09; its first hours in UTC.
    output = pd.DataFrame({"target_ts": [1794175200, 1794178800], "tdate": [date(2026, 11, 9)] * 2, "day": [2, 2],
                           "p10": [20.0, 21.0], "p50": [30.0, 31.0], "p90": [40.0, 41.0]})
    parts = pd.DataFrame({"view": ["afternoon"] * 2, "part": ["best_d1"] * 2, "issue": [issue] * 2,
                          "target_ts": [1794175200, 1794178800], "tdate": [date(2026, 11, 9)] * 2, "k": [1, 1],
                          "month": [11, 11], "p10": [19.0, 20.0], "p50": [29.0, 30.0], "p90": [39.0, 40.0]})
    return S.insert_vintage(db, model="s_v1", version_row=row, issue=issue, morning_cut="2026-11-07 09:30:00",
                            afternoon_cut="2026-11-07 12:30:00", started_at="2026-11-07 12:31:00", code_sha256="c" * 64,
                            inputs={"x": 1}, output=output, parts=parts)


def test_a_vintage_is_stored_once_per_issue_day(db):
    issue = date(2026, 11, 7)
    vid = _vintage(db, issue)
    assert S.vintage(db, "s_v1", issue)["id"] == vid
    assert len(db.rows("select id from forecast_vintage_values")) == 2
    with pytest.raises(Exception):
        _vintage(db, issue)
    # The failed second insert left nothing behind.
    assert len(db.rows("select id from forecast_vintage_values")) == 2
    assert len(db.rows("select id from forecast_part_predictions")) == 2


def test_score_needs_every_price_and_writes_the_miss_store(db):
    issue = date(2026, 11, 7)
    vid = _vintage(db, issue)
    # 2026-11-09 Helsinki = 2026-11-08 22:00 UTC .. 2026-11-09 22:00 UTC.
    start = 1794175200
    db.executemany("insert into spot_prices_hour (region, timestamp, price_without_tax) values ('FI', %s, %s)",
                   [(start + 3600 * i, 3.0) for i in range(23)])
    with pytest.raises(RuntimeError, match="23 of 24 FI prices"):
        S.score(db, "s_v1", date(2026, 11, 9))
    db.execute("insert into spot_prices_hour (region, timestamp, price_without_tax) values ('FI', %s, %s)",
               (start + 3600 * 23, 3.0))
    # Third party: one vintage before 14:30 Helsinki on D (c/kWh with VAT 25.5 %) and one after.
    for fetched, cents in (("2026-11-07 12:00:00", 2.51), ("2026-11-07 13:00:00", 9.99)):
        db.execute("insert into spot_price_forecast_runs (source, region, fetched_at, points) values ('nordpool_predict_fi', 'FI', %s, %s)",
                   (fetched, json.dumps([[start, cents]])))
    assert S.score(db, "s_v1", date(2026, 11, 9)) == 2
    assert S.score(db, "s_v1", date(2026, 11, 9)) == 2  # idempotent
    rows = db.rows("select * from forecast_scores where vintage_id = %s order by target_timestamp", (vid,))
    assert len(rows) == 2
    assert rows[0]["y"] == 30.0 and rows[0]["thirdparty_p50"] == pytest.approx(20.0)
    assert rows[1]["thirdparty_p50"] is None
    miss = S.misses(db, "s_v1")
    assert len(miss) == 2 and miss["y"].tolist() == [30.0, 30.0] and miss["tdate"].iloc[0] == date(2026, 11, 9)
