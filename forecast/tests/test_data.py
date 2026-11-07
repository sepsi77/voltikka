"""The stored-row readers of data.py."""
import json

from voltikka_forecast.s import data as D
from voltikka_forecast.s import weather as W

JAN_1 = 1767225600  # 2026-01-01 00:00 UTC


def _store(db, first, hours, fetched_at, value):
    for point in W.SOUTH_WEIGHTS:
        db.execute("""insert into hourly_forecast_runs (source, product, point, fetched_at, first_timestamp, hour_count,
            `values`) values ('open_meteo', %s, %s, %s, %s, %s, %s)""",
                   (W.PREV_PRODUCT, point, fetched_at, first, hours, json.dumps({W.PREV_VAR: [value] * hours})))


def test_a_year_long_history_row_serves_a_window_after_its_first_hour(db):
    # The imported history holds one row per point and year from 1 January; a live forecast in
    # October reads only a few weeks, which start long after that first hour.
    _store(db, JAN_1, 8760, "2026-11-07 06:22:17", 5.0)
    since = JAN_1 + 290 * 86400
    frame = D._previous_south(db, since, since + 20 * 86400)
    assert frame["target_ts"].min() <= since - 8 * 86400
    assert (frame["fi_temp_south"] == 5.0).all()


def test_rows_outside_the_window_are_not_read(db):
    _store(db, JAN_1, 24, "2026-11-07 06:22:17", 5.0)
    since = JAN_1 + 290 * 86400
    assert D._previous_south(db, since, since + 20 * 86400).empty
