"""The live input check of v1.predict near a clock change (found by the production replay of 2026-10-21)."""
from datetime import date

import numpy as np
import pandas as pd
import pytest

from voltikka_forecast.s import v1


def table(issue, empty_hour):
    cols = {c: [1.0] * 4 for c in (*v1.LIVE_REQUIRED, "om_fi_temp_south", "imp_cap", "se3_nuc_unavail")}
    cols.update({f"om_ws100_fmi_{i}": [1.0] * 4 for i in range(29)})
    frame = pd.DataFrame({"issue": [issue] * 4, "hour": [2, 3, 3, 4], **cols})
    frame.loc[frame["hour"] == empty_hour, "p_same_hour_d"] = np.nan
    return frame


def test_hour_3_may_be_empty_near_a_clock_change():
    v1._check_table(table(date(2026, 10, 21), 3), "morning", live=True)


def test_hour_3_must_be_present_on_a_normal_week():
    with pytest.raises(v1.InputMissing, match="p_same_hour_d"):
        v1._check_table(table(date(2026, 7, 1), 3), "morning", live=True)


def test_other_hours_must_be_present_near_a_clock_change():
    with pytest.raises(v1.InputMissing, match="p_same_hour_d"):
        v1._check_table(table(date(2026, 10, 21), 4), "morning", live=True)


def test_other_inputs_must_be_present_near_a_clock_change():
    frame = table(date(2026, 10, 21), 3)
    frame.loc[frame["hour"] == 3, "fut_month"] = np.nan
    with pytest.raises(v1.InputMissing, match="fut_month"):
        v1._check_table(frame, "morning", live=True)
