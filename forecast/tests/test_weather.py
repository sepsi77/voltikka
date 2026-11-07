import json

import pytest

from voltikka_forecast.s import weather as W


def run_rows(day, value, integer_like=False):
    rows = []
    for p in (*W.FMI_STATIONS, *W.NEIGHBOURS):
        series = {v: [value, value + 0.61, None] for v in W.SINGLE_VARS}
        if integer_like:
            series = {v: [int(value) if float(value).is_integer() else value, value + 0.61, None] for v in W.SINGLE_VARS}
        rows.append({"point": p, "issued_at": f"{day} 00:00:00", "fetched_at": "x", "first_timestamp": 1793750400,
                     "values": json.dumps(series)})
    return rows


def test_integer_json_values_give_the_same_frame_as_floats():
    # PHP stores 6.0 as 6; the reducer must not change a mean because of it.
    a = W.single_runs_frame(run_rows("2026-11-01", 6.0))
    b = W.single_runs_frame(run_rows("2026-11-01", 6.0, integer_like=True))
    assert a.equals(b)
    assert list(a.columns) == W.SINGLE_COLUMNS
    assert a["issue_date"].unique().tolist() == ["2026-11-01"]
    assert a["target_ts"].tolist() == [1793750400, 1793754000, 1793757600]


def test_a_run_without_all_38_points_fails():
    rows = run_rows("2026-11-01", 3.0)[:-1]
    with pytest.raises(ValueError, match="1 of 38 points missing"):
        W.single_runs_frame(rows)


def test_the_first_stored_answer_of_a_point_wins():
    rows = run_rows("2026-11-01", 3.0) + run_rows("2026-11-01", 9.0)
    frame = W.single_runs_frame(rows)
    assert frame["fi_temp"].iloc[0] == 3.0


def test_southern_temperature_is_weighted_and_needs_80_percent_of_the_weight():
    rows = [{"point": p, "fetched_at": "2026-11-07 07:40:00", "first_timestamp": 1793750400,
             "values": {W.PREV_VAR: [10.0 if p == "101004" else 0.0, None if p == "101004" else 1.0]}}
            for p in W.SOUTH_WEIGHTS]
    frame = W.previous_south_frame(rows)
    total = sum(W.SOUTH_WEIGHTS.values())
    assert frame["fi_temp_south"].iloc[0] == pytest.approx(round(10.0 * 1.75 / total, 3))
    # Without Helsinki (1.75 of 3.05) the second hour has too little weight: no row.
    assert frame["target_ts"].tolist() == [1793750400]
    assert frame["lead_day"].tolist() == [1]
