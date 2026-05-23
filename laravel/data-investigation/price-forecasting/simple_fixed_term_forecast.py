#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = []
# ///
"""
Local-only fixed-term electricity price forecasting exploration.

This script deliberately lives under data-investigation instead of production
Laravel code. It reads the local SQLite copy of Voltikka data, builds a joined
research dataset, and runs a very simple EWMA retail-premium / gap-closure
backtest.

No third-party Python packages are required.
"""

from __future__ import annotations

import argparse
import calendar
import csv
import math
import sqlite3
from collections import defaultdict
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from pathlib import Path
from statistics import mean
from typing import Iterable

VAT_MULTIPLIER = 1.255
SEGMENTS = {
    "fixed_term_6": 6,
    "fixed_term_12": 12,
    "fixed_term_24": 24,
}
QUANTILE_COLUMNS = {
    "p20": "p20_value",
    "median": "median_value",
    "p80": "p80_value",
}
DEFAULT_LAMBDAS = [0.1, 0.2, 0.3, 0.4, 0.5]


@dataclass(frozen=True)
class RetailPoint:
    stat_date: date
    duration_months: int
    quantile: str
    retail_price: float
    contract_count: int


@dataclass(frozen=True)
class HedgeCost:
    price_cents_per_kwh_inc_vat: float | None
    trade_date: date | None
    coverage_quality: str
    monthly_count: int
    quarter_count: int
    year_count: int
    missing_months: str


@dataclass
class ResearchRow:
    stat_date: date
    duration_months: int
    quantile: str
    contract_count: int
    retail_price: float
    hedge_cost: float | None
    retail_premium: float | None
    futures_trade_date: date | None
    coverage_quality: str
    monthly_futures_months: int
    quarter_futures_months: int
    year_futures_months: int
    missing_delivery_months: str
    hedge_change_7d: float | None
    hedge_change_30d: float | None
    spot_avg_7d: float | None
    spot_avg_30d: float | None
    spot_volatility_30d: float | None
    extreme_positive_hours_30d: int | None
    negative_price_hours_30d: int | None


def parse_date(value: str) -> date:
    return datetime.strptime(value, "%Y-%m-%d").date()


def month_add(d: date, months: int) -> date:
    year = d.year + (d.month - 1 + months) // 12
    month = (d.month - 1 + months) % 12 + 1
    day = min(d.day, calendar.monthrange(year, month)[1])
    return date(year, month, day)


def next_full_month(d: date) -> date:
    return month_add(date(d.year, d.month, 1), 1)


def maturity_for_month(delivery_month: date, maturity_type: str) -> str:
    if maturity_type == "month":
        return f"{delivery_month.year:04d}{delivery_month.month:02d}"
    if maturity_type == "quarter":
        quarter_start = ((delivery_month.month - 1) // 3) * 3 + 1
        return f"{delivery_month.year:04d}{quarter_start:02d}"
    if maturity_type == "year":
        return f"{delivery_month.year:04d}01"
    raise ValueError(f"Unsupported maturity type: {maturity_type}")


def month_days(delivery_month: date) -> int:
    return calendar.monthrange(delivery_month.year, delivery_month.month)[1]


def query_one(conn: sqlite3.Connection, sql: str, params: tuple = ()):
    row = conn.execute(sql, params).fetchone()
    return row[0] if row else None


def load_retail_points(conn: sqlite3.Connection, from_date: date, to_date: date) -> list[RetailPoint]:
    rows: list[RetailPoint] = []
    sql = """
        select stat_date, segment_key, contract_count,
               p20_value, median_value, p80_value
        from contract_price_daily_statistics
        where metric_key = 'energy_price'
          and segment_key in ('fixed_term_6', 'fixed_term_12', 'fixed_term_24')
          and stat_date between ? and ?
        order by stat_date, segment_key
    """
    for row in conn.execute(sql, (from_date.isoformat(), to_date.isoformat())):
        stat_date = parse_date(row["stat_date"])
        duration = SEGMENTS[row["segment_key"]]
        contract_count = int(row["contract_count"])
        for quantile, column in QUANTILE_COLUMNS.items():
            value = row[column]
            if value is None:
                continue
            rows.append(RetailPoint(stat_date, duration, quantile, float(value), contract_count))
    return rows


def latest_futures_trade_date(conn: sqlite3.Connection, stat_date: date) -> date | None:
    value = query_one(
        conn,
        """
        select max(trade_date)
        from electricity_futures_eod_prices
        where area = 'FI' and trade_date < ?
        """,
        (stat_date.isoformat(),),
    )
    return parse_date(value) if value else None


def load_futures_curve(conn: sqlite3.Connection, trade_date: date) -> dict[tuple[str, str], float]:
    curve: dict[tuple[str, str], float] = {}
    sql = """
        select maturity_type, maturity, settlement_price
        from electricity_futures_eod_prices
        where area = 'FI' and product = 'Base' and trade_date = ?
          and maturity_type in ('month', 'quarter', 'year')
    """
    for row in conn.execute(sql, (trade_date.isoformat(),)):
        curve[(row["maturity_type"], row["maturity"])] = float(row["settlement_price"])
    return curve


def hedge_cost_for(conn: sqlite3.Connection, stat_date: date, duration_months: int) -> HedgeCost:
    trade_date = latest_futures_trade_date(conn, stat_date)
    if trade_date is None:
        return HedgeCost(None, None, "missing_no_prior_trade_date", 0, 0, 0, "")

    curve = load_futures_curve(conn, trade_date)
    weighted_sum = 0.0
    total_weight = 0
    coverage_counts = defaultdict(int)
    missing: list[str] = []

    start = next_full_month(stat_date)
    for offset in range(duration_months):
        delivery_month = month_add(start, offset)
        weight = month_days(delivery_month)
        total_weight += weight
        selected_type = None
        selected_price = None
        for maturity_type in ("month", "quarter", "year"):
            maturity = maturity_for_month(delivery_month, maturity_type)
            if (maturity_type, maturity) in curve:
                selected_type = maturity_type
                selected_price = curve[(maturity_type, maturity)]
                break
        if selected_price is None:
            missing.append(delivery_month.strftime("%Y-%m"))
            continue
        coverage_counts[selected_type] += 1
        weighted_sum += selected_price * weight

    if missing:
        quality = "partial_missing"
    elif coverage_counts["year"]:
        quality = "mixed_with_year_fallback"
    elif coverage_counts["quarter"]:
        quality = "mixed_with_quarter_fallback"
    else:
        quality = "all_monthly"

    if total_weight == 0 or missing:
        price = None
    else:
        eur_per_mwh = weighted_sum / total_weight
        price = eur_per_mwh / 10.0 * VAT_MULTIPLIER

    return HedgeCost(
        price,
        trade_date,
        quality,
        coverage_counts["month"],
        coverage_counts["quarter"],
        coverage_counts["year"],
        ";".join(missing),
    )


def spot_features(conn: sqlite3.Connection, stat_date: date) -> dict[str, float | int | None]:
    # Use the previous complete 30 UTC days before the retail observation date.
    end = datetime.combine(stat_date, datetime.min.time())
    start_30 = end - timedelta(days=30)
    start_7 = end - timedelta(days=7)
    sql = """
        select utc_datetime, price_without_tax, vat_rate
        from spot_prices_hour
        where region = 'FI'
          and utc_datetime >= ?
          and utc_datetime < ?
        order by utc_datetime
    """
    prices_30: list[tuple[datetime, float]] = []
    for row in conn.execute(sql, (start_30.strftime("%Y-%m-%d %H:%M:%S"), end.strftime("%Y-%m-%d %H:%M:%S"))):
        ts = datetime.strptime(row["utc_datetime"], "%Y-%m-%d %H:%M:%S")
        inc_vat = float(row["price_without_tax"]) * (1.0 + float(row["vat_rate"]))
        prices_30.append((ts, inc_vat))

    if not prices_30:
        return {
            "spot_avg_7d": None,
            "spot_avg_30d": None,
            "spot_volatility_30d": None,
            "extreme_positive_hours_30d": None,
            "negative_price_hours_30d": None,
        }

    values_30 = [price for _, price in prices_30]
    values_7 = [price for ts, price in prices_30 if ts >= start_7]
    avg_30 = mean(values_30)
    variance_30 = mean([(price - avg_30) ** 2 for price in values_30])
    return {
        "spot_avg_7d": mean(values_7) if values_7 else None,
        "spot_avg_30d": avg_30,
        "spot_volatility_30d": math.sqrt(variance_30),
        "extreme_positive_hours_30d": sum(1 for price in values_30 if price > 20.0),
        "negative_price_hours_30d": sum(1 for price in values_30 if price < 0.0),
    }


def build_dataset(conn: sqlite3.Connection, from_date: date, to_date: date) -> list[ResearchRow]:
    retail_points = load_retail_points(conn, from_date, to_date)
    hedge_cache: dict[tuple[date, int], HedgeCost] = {}
    spot_cache: dict[date, dict[str, float | int | None]] = {}
    rows: list[ResearchRow] = []

    for point in retail_points:
        hedge = hedge_cache.get((point.stat_date, point.duration_months))
        if hedge is None:
            hedge = hedge_cost_for(conn, point.stat_date, point.duration_months)
            hedge_cache[(point.stat_date, point.duration_months)] = hedge
        features = spot_cache.get(point.stat_date)
        if features is None:
            features = spot_features(conn, point.stat_date)
            spot_cache[point.stat_date] = features
        retail_premium = point.retail_price - hedge.price_cents_per_kwh_inc_vat if hedge.price_cents_per_kwh_inc_vat is not None else None
        rows.append(
            ResearchRow(
                stat_date=point.stat_date,
                duration_months=point.duration_months,
                quantile=point.quantile,
                contract_count=point.contract_count,
                retail_price=point.retail_price,
                hedge_cost=hedge.price_cents_per_kwh_inc_vat,
                retail_premium=retail_premium,
                futures_trade_date=hedge.trade_date,
                coverage_quality=hedge.coverage_quality,
                monthly_futures_months=hedge.monthly_count,
                quarter_futures_months=hedge.quarter_count,
                year_futures_months=hedge.year_count,
                missing_delivery_months=hedge.missing_months,
                hedge_change_7d=None,
                hedge_change_30d=None,
                spot_avg_7d=features["spot_avg_7d"],
                spot_avg_30d=features["spot_avg_30d"],
                spot_volatility_30d=features["spot_volatility_30d"],
                extreme_positive_hours_30d=features["extreme_positive_hours_30d"],
                negative_price_hours_30d=features["negative_price_hours_30d"],
            )
        )

    add_hedge_changes(rows, 7)
    add_hedge_changes(rows, 30)
    return rows


def add_hedge_changes(rows: list[ResearchRow], days: int) -> None:
    by_key: dict[tuple[int, str], list[ResearchRow]] = defaultdict(list)
    for row in rows:
        by_key[(row.duration_months, row.quantile)].append(row)
    for group in by_key.values():
        group.sort(key=lambda r: r.stat_date)
        for row in group:
            target_date = row.stat_date - timedelta(days=days)
            previous = None
            for candidate in reversed(group):
                if candidate.stat_date <= target_date and candidate.hedge_cost is not None:
                    previous = candidate
                    break
            change = None
            if previous is not None and row.hedge_cost is not None:
                change = row.hedge_cost - previous.hedge_cost
            if days == 7:
                row.hedge_change_7d = change
            elif days == 30:
                row.hedge_change_30d = change


def ewma(values: Iterable[float], alpha: float) -> float | None:
    current = None
    for value in values:
        current = value if current is None else alpha * value + (1.0 - alpha) * current
    return current


def find_future_row(group: list[ResearchRow], start_index: int, horizon_days: int) -> ResearchRow | None:
    target = group[start_index].stat_date + timedelta(days=horizon_days)
    for candidate in group[start_index + 1 :]:
        if candidate.stat_date >= target:
            return candidate
    return None


def classify_change(change: float, flat_threshold: float) -> str:
    if change > flat_threshold:
        return "up"
    if change < -flat_threshold:
        return "down"
    return "flat"


def backtest(
    rows: list[ResearchRow],
    horizons: list[int],
    ewma_alpha: float,
    lambdas: list[float],
    min_history: int,
    flat_threshold: float,
) -> list[dict[str, object]]:
    results: list[dict[str, object]] = []
    by_key: dict[tuple[int, str], list[ResearchRow]] = defaultdict(list)
    for row in rows:
        if row.hedge_cost is not None and row.retail_premium is not None:
            by_key[(row.duration_months, row.quantile)].append(row)

    for (duration, quantile), group in sorted(by_key.items()):
        group.sort(key=lambda r: r.stat_date)
        for horizon in horizons:
            predictions_by_lambda: dict[float, list[tuple[float, float, float]]] = defaultdict(list)
            no_change_errors: list[float] = []
            no_change_direction_hits = 0
            actual_direction_count = 0

            for index, row in enumerate(group):
                history = [r.retail_premium for r in group[: index + 1] if r.retail_premium is not None]
                if len(history) < min_history:
                    continue
                future = find_future_row(group, index, horizon)
                if future is None:
                    continue
                normal_premium = ewma(history, ewma_alpha)
                if normal_premium is None or row.hedge_cost is None:
                    continue
                fair_price = row.hedge_cost + normal_premium
                gap = fair_price - row.retail_price
                actual_change = future.retail_price - row.retail_price
                no_change_errors.append(abs(actual_change))
                actual_direction = classify_change(actual_change, flat_threshold)
                if actual_direction == "flat":
                    no_change_direction_hits += 1
                actual_direction_count += 1
                for lambda_value in lambdas:
                    predicted_change = lambda_value * gap
                    predicted_price = row.retail_price + predicted_change
                    error = predicted_price - future.retail_price
                    direction_hit = classify_change(predicted_change, flat_threshold) == actual_direction
                    predictions_by_lambda[lambda_value].append((abs(error), error, 1.0 if direction_hit else 0.0))

            if not no_change_errors:
                continue
            no_change_mae = mean(no_change_errors)
            no_change_dir = no_change_direction_hits / actual_direction_count if actual_direction_count else None
            best_lambda = None
            best_mae = None
            best_bias = None
            best_dir = None
            for lambda_value, observations in predictions_by_lambda.items():
                mae = mean(item[0] for item in observations)
                if best_mae is None or mae < best_mae:
                    best_lambda = lambda_value
                    best_mae = mae
                    best_bias = mean(item[1] for item in observations)
                    best_dir = mean(item[2] for item in observations)
            results.append(
                {
                    "duration_months": duration,
                    "quantile": quantile,
                    "horizon_days": horizon,
                    "observations": len(no_change_errors),
                    "best_lambda": best_lambda,
                    "model_mae": best_mae,
                    "model_bias": best_bias,
                    "model_directional_accuracy": best_dir,
                    "no_change_mae": no_change_mae,
                    "no_change_directional_accuracy": no_change_dir,
                    "mae_delta_vs_no_change": (best_mae - no_change_mae) if best_mae is not None else None,
                }
            )
    return results


def fmt(value: object, digits: int = 3) -> str:
    if value is None:
        return ""
    if isinstance(value, float):
        return f"{value:.{digits}f}"
    if isinstance(value, date):
        return value.isoformat()
    return str(value)


def write_dataset(path: Path, rows: list[ResearchRow]) -> None:
    fields = [
        "stat_date",
        "duration_months",
        "quantile",
        "contract_count",
        "retail_price_cents_per_kwh",
        "hedge_cost_cents_per_kwh_inc_vat",
        "retail_premium_cents_per_kwh",
        "futures_trade_date",
        "coverage_quality",
        "monthly_futures_months",
        "quarter_futures_months",
        "year_futures_months",
        "missing_delivery_months",
        "hedge_change_7d",
        "hedge_change_30d",
        "spot_avg_7d",
        "spot_avg_30d",
        "spot_volatility_30d",
        "extreme_positive_hours_30d",
        "negative_price_hours_30d",
    ]
    with path.open("w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for row in rows:
            writer.writerow(
                {
                    "stat_date": row.stat_date.isoformat(),
                    "duration_months": row.duration_months,
                    "quantile": row.quantile,
                    "contract_count": row.contract_count,
                    "retail_price_cents_per_kwh": fmt(row.retail_price),
                    "hedge_cost_cents_per_kwh_inc_vat": fmt(row.hedge_cost),
                    "retail_premium_cents_per_kwh": fmt(row.retail_premium),
                    "futures_trade_date": row.futures_trade_date.isoformat() if row.futures_trade_date else "",
                    "coverage_quality": row.coverage_quality,
                    "monthly_futures_months": row.monthly_futures_months,
                    "quarter_futures_months": row.quarter_futures_months,
                    "year_futures_months": row.year_futures_months,
                    "missing_delivery_months": row.missing_delivery_months,
                    "hedge_change_7d": fmt(row.hedge_change_7d),
                    "hedge_change_30d": fmt(row.hedge_change_30d),
                    "spot_avg_7d": fmt(row.spot_avg_7d),
                    "spot_avg_30d": fmt(row.spot_avg_30d),
                    "spot_volatility_30d": fmt(row.spot_volatility_30d),
                    "extreme_positive_hours_30d": row.extreme_positive_hours_30d if row.extreme_positive_hours_30d is not None else "",
                    "negative_price_hours_30d": row.negative_price_hours_30d if row.negative_price_hours_30d is not None else "",
                }
            )


def write_backtest(path: Path, results: list[dict[str, object]]) -> None:
    fields = [
        "duration_months",
        "quantile",
        "horizon_days",
        "observations",
        "best_lambda",
        "model_mae",
        "model_bias",
        "model_directional_accuracy",
        "no_change_mae",
        "no_change_directional_accuracy",
        "mae_delta_vs_no_change",
    ]
    with path.open("w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for result in results:
            writer.writerow({field: fmt(result.get(field)) for field in fields})


def direction_label(expected_change: float, threshold: float) -> str:
    if expected_change >= threshold:
        return "rising"
    if expected_change <= -threshold:
        return "falling"
    if expected_change > 0:
        return "slightly_rising"
    if expected_change < 0:
        return "slightly_falling"
    return "flat"


def consumer_signal(direction: str) -> str:
    if direction == "rising":
        return "lock_sooner"
    if direction == "falling":
        return "wait_if_flexible"
    return "neutral"


def confidence_label(complete_rows: int, horizon_observations: int) -> str:
    if complete_rows >= 365 and horizon_observations >= 180:
        return "high"
    if complete_rows >= 120 and horizon_observations >= 60:
        return "medium"
    return "low"


def latest_outlook(
    rows: list[ResearchRow],
    backtest_results: list[dict[str, object]],
    ewma_alpha: float,
    outlook_lambda: float,
    outlook_horizon: int,
    direction_threshold: float,
) -> list[dict[str, object]]:
    by_key: dict[tuple[int, str], list[ResearchRow]] = defaultdict(list)
    for row in rows:
        if row.hedge_cost is not None and row.retail_premium is not None:
            by_key[(row.duration_months, row.quantile)].append(row)

    horizon_observations: dict[tuple[int, str], int] = {}
    for result in backtest_results:
        if result["horizon_days"] == outlook_horizon:
            horizon_observations[(int(result["duration_months"]), str(result["quantile"]))] = int(result["observations"])

    outlook_rows: list[dict[str, object]] = []
    for duration in sorted({key[0] for key in by_key.keys()}):
        quantile_groups = {quantile: sorted(by_key[(duration, quantile)], key=lambda r: r.stat_date) for quantile in ("p20", "median", "p80") if (duration, quantile) in by_key}
        if "median" not in quantile_groups:
            continue
        median_group = quantile_groups["median"]
        last = median_group[-1]
        history = [row.retail_premium for row in median_group if row.retail_premium is not None]
        normal_premium = ewma(history, ewma_alpha)
        if normal_premium is None or last.hedge_cost is None:
            continue
        fair_price = last.hedge_cost + normal_premium
        gap = fair_price - last.retail_price
        expected_change = outlook_lambda * gap
        forecast_price = last.retail_price + expected_change
        direction = direction_label(expected_change, direction_threshold)
        p20_last = quantile_groups.get("p20", [None])[-1]
        p80_last = quantile_groups.get("p80", [None])[-1]
        bt_obs = horizon_observations.get((duration, "median"), 0)
        outlook_rows.append(
            {
                "as_of_date": last.stat_date,
                "duration_months": duration,
                "horizon_days": outlook_horizon,
                "current_median_price": last.retail_price,
                "current_p20_price": p20_last.retail_price if p20_last else None,
                "current_p80_price": p80_last.retail_price if p80_last else None,
                "hedge_cost": last.hedge_cost,
                "current_retail_premium": last.retail_premium,
                "ewma_retail_premium": normal_premium,
                "fair_price": fair_price,
                "gap": gap,
                "expected_change": expected_change,
                "forecast_price": forecast_price,
                "direction": direction,
                "consumer_signal": consumer_signal(direction),
                "confidence": confidence_label(len(median_group), bt_obs),
                "complete_rows": len(median_group),
                "backtest_observations_for_horizon": bt_obs,
                "coverage_quality": last.coverage_quality,
                "hedge_change_7d": last.hedge_change_7d,
                "hedge_change_30d": last.hedge_change_30d,
            }
        )
    return outlook_rows


def write_outlook(path: Path, outlook_rows: list[dict[str, object]]) -> None:
    fields = [
        "as_of_date",
        "duration_months",
        "horizon_days",
        "current_median_price",
        "current_p20_price",
        "current_p80_price",
        "hedge_cost",
        "current_retail_premium",
        "ewma_retail_premium",
        "fair_price",
        "gap",
        "expected_change",
        "forecast_price",
        "direction",
        "consumer_signal",
        "confidence",
        "complete_rows",
        "backtest_observations_for_horizon",
        "coverage_quality",
        "hedge_change_7d",
        "hedge_change_30d",
    ]
    with path.open("w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for row in outlook_rows:
            writer.writerow({field: fmt(row.get(field)) for field in fields})


def summarize_dataset(rows: list[ResearchRow]) -> list[str]:
    lines: list[str] = []
    lines.append("## Dataset summary")
    lines.append("")
    if not rows:
        lines.append("No rows built.")
        return lines
    dates = sorted({row.stat_date for row in rows})
    complete = [row for row in rows if row.hedge_cost is not None]
    lines.append(f"- Retail date range: {dates[0]} to {dates[-1]} ({len(dates)} dates).")
    lines.append(f"- Research rows: {len(rows)}; rows with complete FI hedge cost: {len(complete)}.")
    coverage = defaultdict(int)
    for row in rows:
        coverage[row.coverage_quality] += 1
    lines.append("- Hedge coverage quality counts: " + ", ".join(f"{k}={v}" for k, v in sorted(coverage.items())))
    lines.append("")
    lines.append("| Duration | Quantile | Complete rows | Last retail | Last hedge | Last premium | Contract count | Coverage |")
    lines.append("| ---: | --- | ---: | ---: | ---: | ---: | ---: | --- |")
    by_key: dict[tuple[int, str], list[ResearchRow]] = defaultdict(list)
    for row in complete:
        by_key[(row.duration_months, row.quantile)].append(row)
    for (duration, quantile), group in sorted(by_key.items()):
        group.sort(key=lambda r: r.stat_date)
        last = group[-1]
        lines.append(
            f"| {duration} | {quantile} | {len(group)} | {last.retail_price:.2f} | {last.hedge_cost:.2f} | "
            f"{last.retail_premium:.2f} | {last.contract_count} | {last.coverage_quality} |"
        )
    lines.append("")
    return lines


def summarize_outlook(outlook_rows: list[dict[str, object]]) -> list[str]:
    lines = ["## Latest consumer direction outlook", ""]
    if not outlook_rows:
        lines.append("No current outlook rows could be built.")
        lines.append("")
        return lines
    lines.append("This is the latest median-price signal from the simple EWMA premium/gap model. Consumer wording should stay cautious while confidence is low.")
    lines.append("")
    lines.append("| Duration | Current median | Expected move | Forecast | Direction | Consumer signal | Confidence | Notes |")
    lines.append("| ---: | ---: | ---: | ---: | --- | --- | --- | --- |")
    for row in outlook_rows:
        notes = f"gap {fmt(row['gap'])} c/kWh; hedge 7d {fmt(row['hedge_change_7d'])}; coverage {row['coverage_quality']}"
        lines.append(
            f"| {row['duration_months']}m | {fmt(row['current_median_price'])} | {fmt(row['expected_change'])} c/kWh | "
            f"{fmt(row['forecast_price'])} | {row['direction']} | {row['consumer_signal']} | {row['confidence']} | {notes} |"
        )
    lines.append("")
    return lines


def summarize_backtest(results: list[dict[str, object]]) -> list[str]:
    lines = ["## EWMA gap-closure backtest", ""]
    if not results:
        lines.append("No backtest rows. This usually means there is not enough complete futures history for the chosen horizons/min-history settings.")
        lines.append("")
        return lines
    lines.append("The reported model picks the best fixed lambda from the supplied lambda grid for each segment/quantile/horizon. This is diagnostic and in-sample, not production validation.")
    lines.append("")
    lines.append("| Duration | Quantile | Horizon | Obs. | Lambda | Model MAE | No-change MAE | Δ MAE | Model bias | Dir. acc. |")
    lines.append("| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |")
    for result in results:
        lines.append(
            "| {duration_months} | {quantile} | {horizon_days} | {observations} | {best_lambda} | {model_mae} | {no_change_mae} | {delta} | {bias} | {diracc} |".format(
                duration_months=result["duration_months"],
                quantile=result["quantile"],
                horizon_days=result["horizon_days"],
                observations=result["observations"],
                best_lambda=fmt(result["best_lambda"], 2),
                model_mae=fmt(result["model_mae"], 3),
                no_change_mae=fmt(result["no_change_mae"], 3),
                delta=fmt(result["mae_delta_vs_no_change"], 3),
                bias=fmt(result["model_bias"], 3),
                diracc=fmt(result["model_directional_accuracy"], 3),
            )
        )
    lines.append("")
    return lines


def write_report(path: Path, args: argparse.Namespace, rows: list[ResearchRow], backtest_results: list[dict[str, object]], outlook_rows: list[dict[str, object]]) -> None:
    lines: list[str] = [
        "# Simple fixed-term price forecast exploration",
        "",
        "Local-only research output. Do not treat this as a production model or public forecast.",
        "",
        "## Run settings",
        "",
        f"- Database: `{args.database}`",
        f"- Date range: {args.from_date} to {args.to_date}",
        f"- Horizons: {', '.join(str(h) for h in args.horizons)} days",
        f"- EWMA alpha: {args.ewma_alpha}",
        f"- Minimum history before prediction: {args.min_history} complete observations",
        f"- Lambda grid: {', '.join(str(v) for v in args.lambdas)}",
        f"- Latest outlook horizon/lambda/threshold: {args.outlook_horizon} days / {args.outlook_lambda} / {args.direction_threshold} c/kWh",
        f"- Futures alignment: latest FI EEX `trade_date < stat_date`",
        f"- Futures conversion: EUR/MWh / 10 × {VAT_MULTIPLIER} = c/kWh incl. VAT",
        "",
    ]
    lines.extend(summarize_dataset(rows))
    lines.extend(summarize_outlook(outlook_rows))
    lines.extend(summarize_backtest(backtest_results))
    lines.extend(
        [
            "## First interpretation",
            "",
            "- Current local history is still very short for model validation because FI futures start on 2026-04-08.",
            "- 30-day and 60-day horizons therefore have few or no independent observations; use 7/14-day rows mainly to smoke-test the pipeline.",
            "- The next useful step is to keep collecting futures/retail data and rerun this script before creating production tables or UI.",
            "",
        ]
    )
    path.write_text("\n".join(lines))


def default_database_path() -> Path:
    return Path(__file__).resolve().parents[2] / "database" / "database.sqlite"


def main() -> int:
    parser = argparse.ArgumentParser(description="Run local fixed-term forecasting exploration.")
    parser.add_argument("--database", default=str(default_database_path()), help="Path to Laravel SQLite database.")
    parser.add_argument("--from-date", default="2026-01-01", help="Retail/statistics start date YYYY-MM-DD.")
    parser.add_argument("--to-date", default=date.today().isoformat(), help="Retail/statistics end date YYYY-MM-DD.")
    parser.add_argument("--output-dir", default=str(Path(__file__).resolve().parent / "outputs"), help="Directory for CSV/Markdown outputs.")
    parser.add_argument("--horizons", nargs="+", type=int, default=[7, 14, 30, 60], help="Backtest horizons in days.")
    parser.add_argument("--ewma-alpha", type=float, default=0.25, help="EWMA alpha for normal retail premium.")
    parser.add_argument("--lambdas", nargs="+", type=float, default=DEFAULT_LAMBDAS, help="Gap-closure lambdas to evaluate.")
    parser.add_argument("--min-history", type=int, default=10, help="Minimum complete observations before a prediction is scored.")
    parser.add_argument("--flat-threshold", type=float, default=0.10, help="Absolute c/kWh change considered flat for directional scoring.")
    parser.add_argument("--outlook-horizon", type=int, default=30, help="Consumer direction outlook horizon in days.")
    parser.add_argument("--outlook-lambda", type=float, default=0.30, help="Conservative gap-closure lambda for the latest outlook.")
    parser.add_argument("--direction-threshold", type=float, default=0.15, help="Expected c/kWh move needed before calling the outlook clearly rising/falling.")
    args = parser.parse_args()

    from_date = parse_date(args.from_date)
    to_date = parse_date(args.to_date)
    output_dir = Path(args.output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    conn = sqlite3.connect(args.database)
    conn.row_factory = sqlite3.Row
    try:
        rows = build_dataset(conn, from_date, to_date)
        backtest_results = backtest(rows, args.horizons, args.ewma_alpha, args.lambdas, args.min_history, args.flat_threshold)
        outlook_rows = latest_outlook(rows, backtest_results, args.ewma_alpha, args.outlook_lambda, args.outlook_horizon, args.direction_threshold)
    finally:
        conn.close()

    dataset_path = output_dir / "simple_fixed_term_dataset.csv"
    backtest_path = output_dir / "simple_fixed_term_backtest.csv"
    outlook_path = output_dir / "simple_fixed_term_outlook.csv"
    report_path = output_dir / "simple_fixed_term_report.md"
    write_dataset(dataset_path, rows)
    write_backtest(backtest_path, backtest_results)
    write_outlook(outlook_path, outlook_rows)
    write_report(report_path, args, rows, backtest_results, outlook_rows)

    complete_count = sum(1 for row in rows if row.hedge_cost is not None)
    print(f"Built {len(rows)} research rows ({complete_count} with complete hedge cost).")
    print(f"Wrote {dataset_path}")
    print(f"Wrote {backtest_path}")
    print(f"Wrote {outlook_path}")
    print(f"Wrote {report_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
