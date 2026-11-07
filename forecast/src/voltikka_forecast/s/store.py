"""The own tables of the S model code (created by Laravel migrations 2026_11_07_1000xx).

The model code writes only these tables (agreement with the Temporal session, 2026-11-07):
  forecast_model_versions   one row per fitted version; the file is in the archive bucket
  forecast_vintages         one append-only vintage per (model, issue day)
  forecast_vintage_values   p10/p50/p90 per target hour of a vintage
  forecast_part_predictions raw part predictions; with y they are the miss store of *_scal
  forecast_scores           per vintage and target hour: the price, the own forecast and the
                            third-party value of its latest vintage before D 14:30
All times are UTC text; target_date is the Helsinki day.
"""
import json
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

import numpy as np
import pandas as pd

HEL = ZoneInfo("Europe/Helsinki")


class PricesIncomplete(RuntimeError):
    """The target day does not have every FI price yet."""


def utc_text(ts):
    return datetime.fromtimestamp(int(ts), timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def now_text():
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def _f(v):
    return None if v is None or (isinstance(v, float) and np.isnan(v)) else float(v)


# --- model versions -------------------------------------------------------------------------------

def insert_version(db, row):
    """A new inactive version (idempotent on version)."""
    if db.rows("select id from forecast_model_versions where version = %s", (row["version"],)):
        return False
    stamp = now_text()
    db.execute("""insert into forecast_model_versions (model, version, refit_monday, object_key, sha256, size_bytes,
        sklearn_version, code_sha256, fit_facts, is_active, created_at, updated_at)
        values (%s, %s, %s, %s, %s, %s, %s, %s, %s, 0, %s, %s)""",
               (row["model"], row["version"], row["refit_monday"], row["object_key"], row["sha256"], row["size_bytes"],
                row["sklearn_version"], row["code_sha256"], json.dumps(row["fit_facts"], default=str), stamp, stamp))
    db.commit()
    return True


def version(db, version_key):
    rows = db.rows("select * from forecast_model_versions where version = %s", (version_key,))
    return rows[0] if rows else None


def active_version(db, model):
    rows = db.rows("select * from forecast_model_versions where model = %s and is_active = 1", (model,))
    if len(rows) > 1:
        raise RuntimeError(f"{model}: {len(rows)} active versions")
    return rows[0] if rows else None


def activate(db, model, version_key):
    """One transaction: the given version becomes the only active version of the model."""
    row = version(db, version_key)
    if row is None or row["model"] != model:
        raise RuntimeError(f"no {model} version {version_key}")
    stamp = now_text()
    try:
        db.execute("update forecast_model_versions set is_active = 0, updated_at = %s where model = %s and is_active = 1",
                   (stamp, model))
        db.execute("update forecast_model_versions set is_active = 1, activated_at = %s, updated_at = %s where id = %s",
                   (stamp, stamp, row["id"]))
        db.commit()
    except Exception:
        db.rollback()
        raise


# --- vintages ---------------------------------------------------------------------------------------

def vintage(db, model, issue):
    rows = db.rows("select * from forecast_vintages where model = %s and issue_date = %s", (model, issue.isoformat()))
    return rows[0] if rows else None


def insert_vintage(db, *, model, version_row, issue, morning_cut, afternoon_cut, started_at, code_sha256, inputs,
                   output, parts):
    """The vintage, its values and its part predictions in one transaction."""
    stamp = now_text()
    try:
        vid = db.execute("""insert into forecast_vintages (model, model_version_id, issue_date, morning_cut,
            afternoon_cut, started_at, finished_at, code_sha256, inputs, created_at, updated_at)
            values (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                         (model, version_row["id"], issue.isoformat(), morning_cut, afternoon_cut, started_at, stamp,
                          code_sha256, json.dumps(inputs, default=str), stamp, stamp))
        db.executemany("""insert into forecast_vintage_values (vintage_id, target_timestamp, target_utc_datetime,
            target_date, target_day, p10, p50, p90) values (%s, %s, %s, %s, %s, %s, %s, %s)""",
                       [(vid, int(r.target_ts), utc_text(r.target_ts), str(r.tdate), int(r.day), _f(r.p10), _f(r.p50),
                         _f(r.p90)) for r in output.itertuples(index=False)])
        insert_parts(db, model, parts, source="live", vintage_id=vid, commit=False)
        db.commit()
        return vid
    except Exception:
        db.rollback()
        raise


def insert_parts(db, model, parts, source, vintage_id=None, commit=True):
    """Part predictions (columns view, part, issue, target_ts, tdate, k, month, p10, p50, p90 and,
    for seeded backtest rows, y)."""
    stamp = now_text()
    has_y = "y" in parts
    db.executemany("""insert into forecast_part_predictions (model, part, view, source, vintage_id, issue_date,
        target_timestamp, target_date, k, month, p10, p50, p90, y, scored_at, created_at, updated_at)
        values (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                   [(model, r.part, r.view, source, vintage_id, str(r.issue), int(r.target_ts), str(r.tdate), int(r.k),
                     int(r.month), _f(r.p10), _f(r.p50), _f(r.p90), _f(r.y) if has_y else None,
                     stamp if has_y else None, stamp, stamp) for r in parts.itertuples(index=False)])
    if commit:
        db.commit()


def misses(db, model):
    """The miss store: part predictions with a price (input of v1.seasonal_shifts)."""
    frame = db.frame("""select part, target_date, k, month, y, p10, p90 from forecast_part_predictions
        where model = %s and y is not null and p10 is not null and p90 is not null""", (model,),
                     ["part", "target_date", "k", "month", "y", "p10", "p90"])
    frame["tdate"] = frame.pop("target_date").map(lambda v: date.fromisoformat(str(v)[:10]))
    return frame.astype({"k": "int64", "month": "int64", "y": "float64", "p10": "float64", "p90": "float64"})


# --- scoring ----------------------------------------------------------------------------------------

def thirdparty_vat(d):
    """VAT of the third-party feed (research/s_thirdparty_history.py)."""
    if date(2022, 12, 1) <= d <= date(2023, 4, 30):
        return 0.10
    return 0.24 if d < date(2024, 9, 1) else 0.255


def score(db, model, target):
    """Scores of every vintage of the model with values on the Helsinki day target, and the
    prices of the part predictions of that day. Idempotent (delete and insert per vintage)."""
    start = int(datetime(target.year, target.month, target.day, tzinfo=HEL).timestamp())
    nxt = target + timedelta(days=1)
    end = int(datetime(nxt.year, nxt.month, nxt.day, tzinfo=HEL).timestamp())
    prices = {int(r["timestamp"]): float(r["price_without_tax"]) * 10.0 for r in db.rows(
        "select timestamp, price_without_tax from spot_prices_hour where region = 'FI' and timestamp >= %s and timestamp < %s",
        (start, end))}
    if len(prices) != round((end - start) / 3600):
        raise PricesIncomplete(f"{target}: {len(prices)} of {round((end - start) / 3600)} FI prices")
    values = db.rows("""select v.vintage_id, v.target_timestamp, v.target_day, v.p10, v.p50, v.p90, t.issue_date
        from forecast_vintage_values v join forecast_vintages t on t.id = v.vintage_id
        where t.model = %s and v.target_timestamp >= %s and v.target_timestamp < %s""", (model, start, end))
    stamp = now_text()
    by_vintage = {}
    for r in values:
        by_vintage.setdefault((r["vintage_id"], str(r["issue_date"])[:10]), []).append(r)
    try:
        for (vid, issue_text), rows in by_vintage.items():
            issue = date.fromisoformat(issue_text)
            run_id, tp = thirdparty_before(db, issue)
            db.execute("delete from forecast_scores where vintage_id = %s and target_date = %s", (vid, target.isoformat()))
            db.executemany("""insert into forecast_scores (vintage_id, target_timestamp, target_date, target_day, y, p10,
                p50, p90, thirdparty_p50, thirdparty_run_id, scored_at, created_at, updated_at)
                values (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                           [(vid, int(r["target_timestamp"]), target.isoformat(), int(r["target_day"]),
                             prices[int(r["target_timestamp"])], r["p10"], r["p50"], r["p90"],
                             tp.get(int(r["target_timestamp"])), run_id if tp else None, stamp, stamp, stamp) for r in rows])
        for ts, y in prices.items():
            db.execute("""update forecast_part_predictions set y = %s, scored_at = %s, updated_at = %s
                where model = %s and target_timestamp = %s and source = 'live'""", (y, stamp, stamp, model, ts))
        db.commit()
    except Exception:
        db.rollback()
        raise
    return sum(len(r) for r in by_vintage.values())


def thirdparty_before(db, issue):
    """The latest nordpool-predict-fi vintage stored before D 14:30 Helsinki (the decision rule of
    s-model-freeze.md section 5), as EUR/MWh VAT 0 per UTC hour."""
    cut = datetime(issue.year, issue.month, issue.day, 14, 30, tzinfo=HEL).astimezone(timezone.utc)
    rows = db.rows("""select id, points from spot_price_forecast_runs where source = 'nordpool_predict_fi'
        and region = 'FI' and fetched_at < %s order by fetched_at desc, id desc limit 1""",
                   (cut.strftime("%Y-%m-%d %H:%M:%S"),))
    if not rows:
        return None, {}
    points = rows[0]["points"]
    points = json.loads(points) if isinstance(points, str) else points
    out = {}
    for ts, cents in points:
        d = datetime.fromtimestamp(int(ts), timezone.utc).astimezone(HEL).date()
        out[int(ts)] = float(cents) / (1 + thirdparty_vat(d)) * 10.0
    return rows[0]["id"], out
