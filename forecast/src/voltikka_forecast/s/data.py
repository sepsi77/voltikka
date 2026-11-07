"""Input data of s_v1 from the Voltikka database (and the archive for frozen history).

Gives the data dict of core.build_table in the shape of the research export (the same SQL as
research/s_export.py, the same column names, units and dtypes), so the model code is the one the
backtest ran. Production: MySQL through PyMySQL with the Laravel DB_* variables. Tests: SQLite.

Frozen research-only history (s-model-freeze.md gap 2) comes from an archive directory with a
SHA-256 pin per file (pins.json): weather_previous_runs.csv, weather_previous_runs_extra.csv and
border_capacity_ntc.csv (the Fingrid NTC part of border_capacity.csv, before flow-based).
"""
import hashlib
import json
import os
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd

from . import weather as W

HEL = ZoneInfo("Europe/Helsinki")
FINGRID = (74, 75, 124, 188, 191, 194, 246, 268)
SPOT_REGIONS = ("FI", "SE1", "SE3", "EE", "DE-LU")
SPOT_FROM = 1640995200  # 2022-01-01 UTC, as the research export
JAO_FROM = 1730242800  # 2024-10-29 23:00 UTC, first flow-based delivery hour (s_border_capacity.py)
BORDERS = ("SE1_FI", "SE3_FI", "FI_SE1", "FI_SE3", "EE_FI", "FI_EE")
ARCHIVE_FILES = ("weather_previous_runs.csv", "weather_previous_runs_extra.csv", "border_capacity_ntc.csv")
OUTAGE_COLUMNS = ["document_type", "zone", "document_mrid", "revision_number", "time_series_mrid", "business_type",
                  "doc_status", "is_cancelled", "document_created_at", "resource_name", "unit_name", "nominal_mw",
                  "available_periods"]
OUTAGE_SELECT = """select document_type, zone, document_mrid, revision_number, time_series_mrid,
               business_type, coalesce(doc_status, '') doc_status, is_cancelled,
               document_created_at, resource_name, coalesce(unit_name, '') unit_name,
               nominal_mw, available_periods
        from entsoe_outages where {where} order by id"""


class Db:
    """A DB-API connection with %s placeholders on MySQL and SQLite."""

    def __init__(self, conn, sqlite=False):
        self.conn, self.sqlite = conn, sqlite

    @classmethod
    def from_env(cls):
        import pymysql

        conn = pymysql.connect(host=os.environ["DB_HOST"], port=int(os.environ.get("DB_PORT", "3306")),
                               user=os.environ["DB_USERNAME"], password=os.environ["DB_PASSWORD"],
                               database=os.environ["DB_DATABASE"], charset="utf8mb4", autocommit=False)
        with conn.cursor() as cur:
            cur.execute("set time_zone = '+00:00'")
        return cls(conn)

    @classmethod
    def from_sqlite(cls, path):
        """A local SQLite database (tests and local runs)."""
        import sqlite3

        return cls(sqlite3.connect(path), sqlite=True)

    def _sql(self, sql):
        return sql.replace("%s", "?") if self.sqlite else sql

    def rows(self, sql, params=()):
        cur = self.conn.cursor()
        try:
            cur.execute(self._sql(sql), params)
            names = [d[0] for d in cur.description]
            return [dict(zip(names, r)) for r in cur.fetchall()]
        finally:
            cur.close()

    def frame(self, sql, params=(), columns=None):
        rows = self.rows(sql, params)
        return pd.DataFrame(rows, columns=columns or (list(rows[0]) if rows else None))

    def execute(self, sql, params=()):
        cur = self.conn.cursor()
        try:
            cur.execute(self._sql(sql), params)
            return cur.lastrowid
        finally:
            cur.close()

    def executemany(self, sql, seq):
        cur = self.conn.cursor()
        try:
            cur.executemany(self._sql(sql), seq)
        finally:
            cur.close()

    def commit(self):
        self.conn.commit()

    def rollback(self):
        self.conn.rollback()


def helsinki_ts(d, hour=0):
    return int(datetime(d.year, d.month, d.day, hour, tzinfo=HEL).timestamp())


def _in(values):
    return ", ".join("%s" for _ in values)


def read_archive(path):
    """The pinned archive files (raises when a file is missing or its SHA-256 differs)."""
    path = Path(path)
    pins = json.loads((path / "pins.json").read_text())
    out = {}
    for name in ARCHIVE_FILES:
        digest = hashlib.sha256((path / name).read_bytes()).hexdigest()
        if digest != pins[name]["sha256"]:
            raise RuntimeError(f"archive {name}: SHA-256 differs from pins.json")
        out[name] = pd.read_csv(path / name)
    return out


def _spot(db, since, until_ts):
    spot = db.frame("select region, timestamp, price_without_tax from spot_prices_hour "
                    f"where region in ({_in(SPOT_REGIONS)}) and timestamp >= %s order by region, timestamp",
                    (*SPOT_REGIONS, since), ["region", "timestamp", "price_without_tax"])
    spot = spot.astype({"timestamp": "int64", "price_without_tax": "float64"})
    # Future target hours: FI rows without a price, so build_table has their hour keys
    # (targets_known_before keeps them with y = NaN).
    fi = spot.loc[spot["region"] == "FI", "timestamp"]
    start = int(fi.max()) + 3600 if len(fi) else since
    future = pd.DataFrame({"region": "FI", "timestamp": np.arange(start, until_ts, 3600, dtype="int64"),
                           "price_without_tax": np.nan})
    return pd.concat([spot, future], ignore_index=True)


def _fingrid(db, datasets, since):
    frame = db.frame("select dataset_id, timestamp, value from fingrid_hourly_values "
                     f"where series_key = '' and dataset_id in ({_in(datasets)}) and timestamp >= %s "
                     "order by dataset_id, timestamp", (*datasets, since), ["dataset_id", "timestamp", "value"])
    return frame.astype({"dataset_id": "int64", "timestamp": "int64", "value": "float64"})


def _outages(db, where, params):
    frame = db.frame(OUTAGE_SELECT.format(where=where), params, OUTAGE_COLUMNS)
    frame["document_created_at"] = frame["document_created_at"].map(lambda v: None if v is None else str(v)[:19])
    frame["is_cancelled"] = frame["is_cancelled"].astype("int64")
    frame["nominal_mw"] = pd.to_numeric(frame["nominal_mw"], errors="coerce")
    frame["available_periods"] = frame["available_periods"].map(lambda v: v if isinstance(v, str) else json.dumps(v))
    return frame


def _futures(db):
    frame = db.frame("""select trade_date, maturity_type, maturity, settlement_price
        from electricity_futures_eod_prices
        where area = 'FI' and product = 'Base' and maturity_type in ('month', 'quarter', 'year')
        order by trade_date, maturity_type, maturity""", (), ["trade_date", "maturity_type", "maturity", "settlement_price"])
    frame["trade_date"] = frame["trade_date"].map(lambda v: str(v)[:10])
    frame["maturity"] = frame["maturity"].astype("int64")
    frame["settlement_price"] = frame["settlement_price"].astype("float64")
    return frame


def _border_capacity(db, since, archive):
    jao = db.frame(f"select border, timestamp, capacity_mw from jao_max_border_flows where border in ({_in(BORDERS)}) "
                   "and timestamp >= %s order by border, timestamp", (*BORDERS, max(since, JAO_FROM)),
                   ["border", "timestamp", "capacity_mw"])
    jao = jao.astype({"timestamp": "int64"})
    # The research file kept one decimal (f"{mw:.1f}").
    jao["capacity_mw"] = jao["capacity_mw"].map(lambda v: float(f"{float(v):.1f}"))
    jao["source"] = "jao"
    parts = [jao]
    if archive is not None:
        ntc = archive["border_capacity_ntc.csv"]
        parts.insert(0, ntc[ntc["timestamp"] >= since])
    return pd.concat(parts, ignore_index=True)[["border", "timestamp", "capacity_mw", "source"]]


def _single_runs(db, first_day, last_day):
    rows = db.rows("select point, issued_at, fetched_at, first_timestamp, `values` from hourly_forecast_runs "
                   "where source = 'open_meteo' and product = %s and issued_at >= %s and issued_at <= %s "
                   "order by fetched_at, id",
                   (W.SINGLE_PRODUCT, f"{first_day} 00:00:00", f"{last_day} 00:00:00"))
    return W.single_runs_frame(rows)


def _previous_south(db, since, until_ts):
    # Every stored row whose hours overlap the window: the imported history rows each hold one
    # calendar year from 1 January, the live rows 8 past days (an e2e test of 2026-10-21 found
    # that a filter on the first hour alone missed the history rows).
    rows = db.rows("select point, fetched_at, first_timestamp, `values` from hourly_forecast_runs "
                   f"where source = 'open_meteo' and product = %s and point in ({_in(W.SOUTH_WEIGHTS)}) "
                   "and first_timestamp < %s and first_timestamp + 3600 * hour_count > %s order by fetched_at, id",
                   (W.PREV_PRODUCT, *W.SOUTH_WEIGHTS, until_ts, since - 9 * 86400))
    return W.previous_south_frame(rows)


def load(db, *, first_issue, last_issue, history_from=None, archive=None):
    """The data dict for issues first_issue..last_issue. history_from (a date) limits the long
    series; None = everything the research export had (training). archive: read_archive() result,
    needed by training (issues before 2024-03-14 and NTC capacity); the live run does not need it."""
    since = SPOT_FROM if history_from is None else helsinki_ts(history_from)
    until = helsinki_ts(last_issue + timedelta(days=10))  # D+8 of the afternoon view, with margin
    data = {
        "spot": _spot(db, since, until),
        "fingrid": _fingrid(db, FINGRID, since),
        # Capacities: the last value before each cut; read all of them.
        "fingrid_extra": _fingrid(db, (267,), 0),
        "outages": _outages(db, "(zone = 'FI' and psr_type = 'B14' and document_type in ('A77', 'A80')) "
                                "or (document_type = 'A78' and zone in ('SE1_FI', 'SE3_FI', 'EE_FI'))", ()),
        "outages_se3": _outages(db, "zone = 'SE3' and psr_type = 'B14' and document_type in ('A77', 'A80')", ()),
        "futures": _futures(db),
        # Only the p_standin stand-in temp_day reads it; s_v1 does not.
        "weather_daily": pd.DataFrame({"point": pd.Series(dtype=str), "date": pd.Series(dtype=str),
                                       "temperature_2m_mean": pd.Series(dtype="float64"),
                                       "wind_speed_100m_mean": pd.Series(dtype="float64")}),
        "border_capacity": _border_capacity(db, since, archive),
    }
    # The 268 capacity is read in full (last value before the cut).
    cap = _fingrid(db, (268,), 0)
    data["fingrid"] = pd.concat([data["fingrid"][data["fingrid"]["dataset_id"] != 268], cap], ignore_index=True)
    south = _previous_south(db, since, until)
    # The live run needs only the run of its issue day; training needs every stored run.
    single_from = max(first_issue, date(2024, 3, 14)) if history_from is not None else date(2024, 3, 14)
    data["weather_single_runs"] = _single_runs(db, single_from, last_issue)
    if archive is not None:
        prev = archive["weather_previous_runs.csv"]
        merged = pd.concat([prev, south], ignore_index=True).drop_duplicates(["target_ts", "lead_day"], keep="first")
        data["weather_previous_runs"] = merged.sort_values(["lead_day", "target_ts"], kind="stable").reset_index(drop=True)
        data["weather_previous_runs_extra"] = archive["weather_previous_runs_extra.csv"]
    else:
        data["weather_previous_runs"] = south
        data["weather_previous_runs_extra"] = W.empty_extra()
    return data
