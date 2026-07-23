#!/usr/bin/env python3
"""Evaluate one completed OpenRouter experiment run."""
from __future__ import annotations

import argparse
import calendar
import datetime as dt
import glob
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
TASK = HERE.parent


def add_year(date: dt.date) -> dt.date:
    day = min(date.day, calendar.monthrange(date.year + 1, date.month)[1])
    return date.replace(year=date.year + 1, day=day)


def within_first_12_months(boundary: dict, analysis_date: dt.date) -> bool:
    kind = boundary.get("kind")
    value = boundary.get("value")
    if kind == "after_months":
        try:
            return float(value) < 12
        except (TypeError, ValueError):
            return False
    if kind == "date" and value:
        try:
            parsed = dt.date.fromisoformat(value)
            return analysis_date <= parsed < add_year(analysis_date)
        except ValueError:
            return False
    return False


def boundaries_touch(end: dict, start: dict) -> bool:
    if end == start:
        return True
    if end.get("kind") == start.get("kind") == "date" and end.get("value") and start.get("value"):
        try:
            end_date = dt.date.fromisoformat(end["value"])
            start_date = dt.date.fromisoformat(start["value"])
            return start_date == end_date + dt.timedelta(days=1)
        except ValueError:
            return False
    return False


def focused_promotion_prediction(output: dict, analysis_date: dt.date) -> bool:
    """Derive the benchmark target deterministically from extracted facts.

    Do not trust the model's headline judgment. Require an omitted structured
    promotion transition and an introductory boundary strictly inside the first
    12 months. This intentionally excludes a normal price beginning at month 12.
    """
    consistency = output["source_consistency"]
    issues = set(consistency.get("issue_codes", []))
    if consistency.get("structured_pricing_status") not in {"incomplete", "conflicting"}:
        return False
    if not issues.intersection({"promotion_metadata_missing", "structured_matches_intro_only"}):
        return False
    if not issues.intersection({"future_price_omitted", "future_price_unknown"}):
        return False

    phases = output.get("pricing", {}).get("phases", [])
    for phase in phases:
        boundary = phase.get("ends", {})
        if phase.get("phase_kind") not in {"introductory", "current_structured"} or not within_first_12_months(boundary, analysis_date):
            continue
        following = [candidate for candidate in phases if boundaries_touch(boundary, candidate.get("starts", {}))]
        current = {component.get("component_type"): component for component in phase.get("components", []) if component.get("amount") is not None}
        for candidate in following:
            components = [component for component in candidate.get("components", []) if component.get("amount") is not None]
            # A known higher next-phase value that exists only in description is
            # the omitted transition. Values marked both/structured are already
            # represented by source component/discount metadata.
            for component in components:
                previous = current.get(component.get("component_type"))
                if previous and component.get("source_kind") == "description" and float(component["amount"]) > float(previous["amount"]):
                    return True
            # A stated reversion with no returning amount is still an unsupported
            # expiry when the text explicitly calls the current phase promotional.
            if "future_price_unknown" in issues and not components:
                return True
    return False


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("run_name")
    args = parser.parse_args()
    run_dir = HERE / "runs" / args.run_name
    metadata = json.loads((run_dir / "metadata.json").read_text())
    analysis_date = dt.date.fromisoformat(metadata["analysis_date"])
    labels = json.loads((TASK / "benchmark/top-100-labels.json").read_text())
    gold_labels = {row["contract_id"]: row["label"] == "deceptive" for row in labels["labels"]}
    benchmark_ranks = {row["contract_id"]: row["rank_at_5000_kwh"] for row in labels["labels"]}

    raw_paths = glob.glob(str(run_dir / "raw/*.json"))
    if raw_paths:
        records = [json.loads(Path(path).read_text()) for path in raw_paths]
    else:
        responses_path = run_dir / "responses.ndjson"
        records = [json.loads(line) for line in responses_path.read_text().splitlines() if line.strip()]
    successes = [record for record in records if record.get("ok")]
    models = sorted({record["model"] for record in records})
    result = {"run": args.run_name, "models": []}
    for model in models:
        rows = [record for record in successes if record["model"] == model]
        predictions = {row["contract_id"]: focused_promotion_prediction(row["output"], analysis_date) for row in rows}
        comparable = sorted(set(predictions) & set(gold_labels))
        tp = sum(predictions[key] and gold_labels[key] for key in comparable)
        fp = sum(predictions[key] and not gold_labels[key] for key in comparable)
        fn = sum(not predictions[key] and gold_labels[key] for key in comparable)
        tn = sum(not predictions[key] and not gold_labels[key] for key in comparable)
        usage_cost = sum(float(row.get("usage", {}).get("cost") or 0) for row in rows)
        result["models"].append({
            "model": model,
            "successes": len(rows),
            "failures": len([r for r in records if r["model"] == model and not r.get("ok")]),
            "reported_cost_usd": usage_cost,
            "mean_cost_per_contract_usd": usage_cost / len(rows) if rows else None,
            "projected_cost_434_contracts_usd": usage_cost / len(rows) * 434 if rows else None,
            "focused_promotion_benchmark": {
                "comparable_rows": len(comparable), "tp": tp, "fp": fp, "fn": fn, "tn": tn,
                "precision": tp / (tp + fp) if tp + fp else None,
                "recall": tp / (tp + fn) if tp + fn else None,
                "predicted_positive_ranks": sorted(benchmark_ranks[key] for key in comparable if predictions[key]),
                "predicted_positive_contract_ids": [key for key in comparable if predictions[key]],
            },
        })
    print(json.dumps(result, ensure_ascii=False, indent=2))
    (run_dir / "evaluation.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
