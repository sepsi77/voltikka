"""SQLite copies of the tables the S model code reads and writes (columns as in the Laravel
migrations 2026_11_07_100001_create_forecast_model_tables, 2026_01_19_220000 spot_prices_hour and
2026_11_01_000001 spot_price_forecast_runs, hourly_forecast_runs)."""
import sqlite3

import pytest

from voltikka_forecast.s.data import Db

SCHEMA = """
create table forecast_model_versions (id integer primary key autoincrement, model text, version text unique,
  refit_monday text, object_key text, sha256 text, size_bytes integer, sklearn_version text, code_sha256 text,
  fit_facts text, is_active integer default 0, activated_at text, created_at text, updated_at text);
create table forecast_vintages (id integer primary key autoincrement, model text, model_version_id integer,
  issue_date text, morning_cut text, afternoon_cut text, started_at text, finished_at text, code_sha256 text,
  inputs text, created_at text, updated_at text, unique (model, issue_date));
create table forecast_vintage_values (id integer primary key autoincrement, vintage_id integer, target_timestamp integer,
  target_utc_datetime text, target_date text, target_day integer, p10 real, p50 real, p90 real,
  unique (vintage_id, target_timestamp));
create table forecast_part_predictions (id integer primary key autoincrement, model text, part text, view text,
  source text, vintage_id integer, issue_date text, target_timestamp integer, target_date text, k integer,
  month integer, p10 real, p50 real, p90 real, y real, scored_at text, created_at text, updated_at text,
  unique (model, part, view, issue_date, target_timestamp));
create table forecast_scores (id integer primary key autoincrement, vintage_id integer, target_timestamp integer,
  target_date text, target_day integer, y real, p10 real, p50 real, p90 real, thirdparty_p50 real,
  thirdparty_run_id integer, scored_at text, created_at text, updated_at text, unique (vintage_id, target_timestamp));
create table hourly_forecast_runs (id integer primary key autoincrement, source text, product text, point text,
  payload_hash text, issued_at text, fetched_at text, first_timestamp integer, first_utc_datetime text,
  hour_count integer, grid_latitude real, grid_longitude real, `values` text, created_at text, updated_at text);
create table spot_prices_hour (region text, timestamp integer, utc_datetime text, price_without_tax real,
  vat_rate real, primary key (region, timestamp));
create table spot_price_forecast_runs (id integer primary key autoincrement, source text, region text,
  feed_hash text, fetched_at text, point_count integer, first_utc_datetime text, last_utc_datetime text,
  points text, created_at text, updated_at text);
"""


@pytest.fixture
def db():
    conn = sqlite3.connect(":memory:")
    conn.executescript(SCHEMA)
    return Db(conn, sqlite=True)
