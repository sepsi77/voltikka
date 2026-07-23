#!/usr/bin/env python3
"""Run reproducible OpenRouter contract-interpretation experiments.

Reads OPENROUTER_API_KEY from laravel/.env without printing it. Raw responses and
scored summaries are stored below experiments/runs/<run-name>/.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import datetime as dt
import html
import json
import math
import os
from pathlib import Path
import re
import subprocess
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[3]
EXPERIMENT_DIR = Path(__file__).resolve().parent
DEFAULT_MODELS = [
    "openai/gpt-5.6-luna",
    "qwen/qwen3.7-plus",
    "minimax/minimax-m3",
    "deepseek/deepseek-v4-pro",
]


def read_env_value(path: Path, key: str) -> str | None:
    if not path.exists():
        return None
    for raw in path.read_text().splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        name, value = line.split("=", 1)
        if name.strip() != key:
            continue
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        return value
    return None


def clean_html(value: object) -> object:
    if not isinstance(value, str):
        return value
    text = re.sub(r"<br\s*/?\s*>", " ", html.unescape(value), flags=re.IGNORECASE)
    text = re.sub(r"<[^>]+>", "", text)
    text = text.replace("\u00a0", " ").replace("\u202f", " ")
    return re.sub(r"\s+", " ", text).strip()


def contract_input(row: dict) -> dict:
    """Keep source facts, omit Voltikka's calculated annual costs/rank features."""
    keys = [
        "contract_id", "api_id", "company_name", "contract_name",
        "pricing_model", "contract_type", "fixed_time_range", "metering",
        "target_group", "spot_price_selection", "pricing_name",
        "pricing_has_discounts", "short_description", "long_description",
        "extra_information_fi", "extra_information_default",
        "time_period_definitions", "billing_frequency", "consumption_limitation",
    ]
    result = {key: row.get(key) for key in keys}
    for key in ["short_description", "long_description", "extra_information_fi", "extra_information_default"]:
        result[key] = clean_html(result.get(key))
    result["components"] = []
    component_keys = [
        "id", "price_component_type", "fuse_size", "price", "payment_unit",
        "has_discount", "discount_value", "discount_is_percentage",
        "discount_type", "discount_n_first_kwh", "discount_n_first_months",
        "discount_until_date",
    ]
    for component in row.get("components", []):
        normalized = {key: component.get(key) for key in component_keys}
        normalized["discount_n_first_kwh"] = component.get("discount_n_first_kwh", component.get("discount_discount_n_first_kwh"))
        normalized["discount_n_first_months"] = component.get("discount_n_first_months", component.get("discount_discount_n_first_months"))
        normalized["discount_until_date"] = component.get("discount_until_date", component.get("discount_discount_until_date"))
        result["components"].append(normalized)
    return result


def accepted(expected: object, actual: object) -> bool:
    if isinstance(expected, list):
        return actual in expected
    return expected == actual


def nearly_equal(a: object, b: object, tolerance: float = 0.011) -> bool:
    try:
        return math.isclose(float(a), float(b), abs_tol=tolerance)
    except (TypeError, ValueError):
        return False


def all_components(output: dict) -> list[dict]:
    components: list[dict] = []
    for phase in output.get("pricing", {}).get("phases", []):
        components.extend(phase.get("components", []))
    return components


def score_output(output: dict, gold: dict, source_input: dict) -> dict:
    classification = output.get("classification", {})
    consistency = output.get("source_consistency", {})
    calculation = output.get("calculation", {})
    effect = output.get("pricing", {}).get("consumption_effect", {})
    checks: list[dict] = []

    def check(name: str, passed: bool, expected: object, actual: object) -> None:
        checks.append({"name": name, "passed": bool(passed), "expected": expected, "actual": actual})

    for key in ["term_type", "primary_pricing_model", "metering", "reset_cadence", "spot_settlement_interval", "periodic_reset_cadence"]:
        if key in gold:
            check(key, accepted(gold[key], classification.get(key)), gold[key], classification.get(key))
    actual_mechanisms = set(classification.get("pricing_mechanisms", []))
    required_mechanisms = set(gold.get("required_mechanisms", []))
    check("required_mechanisms", required_mechanisms <= actual_mechanisms, sorted(required_mechanisms), sorted(actual_mechanisms))
    check("pricing_model_status", accepted(gold["pricing_model_status"], consistency.get("pricing_model_status")), gold["pricing_model_status"], consistency.get("pricing_model_status"))
    check("structured_pricing_status", accepted(gold["structured_pricing_status"], consistency.get("structured_pricing_status")), gold["structured_pricing_status"], consistency.get("structured_pricing_status"))
    check("misleading_first_12_months", accepted(gold["misleading_first_12_months"], consistency.get("misleading_first_12_months")), gold["misleading_first_12_months"], consistency.get("misleading_first_12_months"))
    actual_issues = set(consistency.get("issue_codes", []))
    required_issues = set(gold.get("required_issue_codes", []))
    check("required_issue_codes", required_issues <= actual_issues, sorted(required_issues), sorted(actual_issues))
    check("calculation_status", accepted(gold["calculation_status"], calculation.get("status")), gold["calculation_status"], calculation.get("status"))
    check("consumption_effect.present", effect.get("present") is gold["consumption_effect"], gold["consumption_effect"], effect.get("present"))

    numeric_effect_fields = {
        "consumption_expected": "expected_cents_per_kwh",
        "consumption_typical_min": "typical_min_cents_per_kwh",
        "consumption_typical_max": "typical_max_cents_per_kwh",
        "consumption_hard_min": "hard_min_cents_per_kwh",
        "consumption_hard_max": "hard_max_cents_per_kwh",
    }
    for gold_key, output_key in numeric_effect_fields.items():
        if gold_key in gold:
            check(output_key, nearly_equal(gold[gold_key], effect.get(output_key)), gold[gold_key], effect.get(output_key))
    if "uncapped" in gold:
        check("consumption_effect.uncapped", effect.get("uncapped") is gold["uncapped"], gold["uncapped"], effect.get("uncapped"))
    if "consumption_applies_to" in gold:
        check("consumption_effect.applies_to", accepted(gold["consumption_applies_to"], effect.get("applies_to")), gold["consumption_applies_to"], effect.get("applies_to"))

    components = all_components(output)
    for component_type, price in gold.get("required_prices", []):
        matches = [c for c in components if c.get("component_type") == component_type and nearly_equal(c.get("amount"), price)]
        check(f"price:{component_type}:{price}", bool(matches), [component_type, price], [[c.get("component_type"), c.get("amount")] for c in components])

    # Evidence hygiene: non-empty description quotes should occur in normalized input text.
    input_text = "\n".join(str(source_input.get(k) or "") for k in ["contract_name", "short_description", "long_description", "extra_information_fi", "extra_information_default"])
    evidence_nodes: list[dict] = []
    def walk(value: object) -> None:
        if isinstance(value, dict):
            if set(value.keys()) == {"source", "quote"}:
                evidence_nodes.append(value)
            for child in value.values():
                walk(child)
        elif isinstance(value, list):
            for child in value:
                walk(child)
    walk(output)
    description_evidence = [e for e in evidence_nodes if "description" in str(e.get("source", "")) or "information" in str(e.get("source", ""))]
    faithful = [e for e in description_evidence if str(e.get("quote", "")) and str(e["quote"]) in input_text]
    evidence_ratio = len(faithful) / len(description_evidence) if description_evidence else 1.0
    check("description_evidence_exact", evidence_ratio >= 0.95, ">=0.95", evidence_ratio)

    passed = sum(1 for item in checks if item["passed"])
    return {
        "score": passed / len(checks) if checks else 0.0,
        "passed": passed,
        "total": len(checks),
        "checks": checks,
    }


def call_openrouter(api_key: str, model: str, prompt: str, schema: dict, source_input: dict, analysis_date: str, reasoning_effort: str, retries: int = 3) -> dict:
    user_content = json.dumps({"analysis_date": analysis_date, **source_input}, ensure_ascii=False, separators=(",", ":"))
    body = {
        "model": model,
        "max_tokens": 6000,
        "reasoning": {"effort": reasoning_effort, "exclude": True},
        "messages": [
            {"role": "system", "content": prompt},
            {"role": "user", "content": user_content},
        ],
        "response_format": {
            "type": "json_schema",
            "json_schema": {"name": "voltikka_contract_interpretation", "strict": True, "schema": schema},
        },
    }
    encoded = json.dumps(body, ensure_ascii=False).encode()
    last_error = None
    for attempt in range(1, retries + 1):
        request = urllib.request.Request(
            "https://openrouter.ai/api/v1/chat/completions",
            data=encoded,
            method="POST",
            headers={
                "Authorization": f"Bearer {api_key}",
                "Content-Type": "application/json",
                "HTTP-Referer": "https://voltikka.fi",
                "X-Title": "Voltikka contract interpretation experiment",
            },
        )
        started = time.monotonic()
        try:
            with urllib.request.urlopen(request, timeout=240) as response:
                payload = json.load(response)
            latency = time.monotonic() - started
            content = payload.get("choices", [{}])[0].get("message", {}).get("content")
            if isinstance(content, list):
                content = "".join(part.get("text", "") if isinstance(part, dict) else str(part) for part in content)
            parsed = json.loads(content)
            return {
                "ok": True,
                "output": parsed,
                "usage": payload.get("usage", {}),
                "provider": payload.get("provider"),
                "latency_seconds": latency,
                "response_id": payload.get("id"),
            }
        except urllib.error.HTTPError as error:
            body_text = error.read().decode(errors="replace")[:2000]
            last_error = f"HTTP {error.code}: {body_text}"
            if error.code not in (408, 409, 429, 500, 502, 503, 504):
                break
        except Exception as error:  # noqa: BLE001 - experiment must retain failure details
            last_error = f"{type(error).__name__}: {error}"
        if attempt < retries:
            time.sleep(2 ** (attempt - 1))
    return {"ok": False, "error": last_error}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-name", default=dt.datetime.now().strftime("%Y%m%d-%H%M%S"))
    parser.add_argument("--prompt", default="system-prompt-v1.md")
    parser.add_argument("--schema", default="schema-v1.json")
    parser.add_argument("--gold", default="gold-v1.json")
    parser.add_argument("--input-file", help="JSON input path; defaults to benchmark/top-100-input.json")
    parser.add_argument("--models", nargs="*", default=DEFAULT_MODELS)
    parser.add_argument("--ranks", nargs="*", type=int)
    parser.add_argument("--all-benchmark", action="store_true", help="Analyze all 100 benchmark rows; score rows present in the gold file")
    parser.add_argument("--concurrency", type=int, default=4)
    parser.add_argument("--reasoning-effort", choices=["none", "minimal", "low", "medium", "high", "xhigh"], default="low")
    args = parser.parse_args()

    api_key = os.environ.get("OPENROUTER_API_KEY") or read_env_value(ROOT / "laravel/.env", "OPENROUTER_API_KEY")
    if not api_key:
        raise SystemExit("OPENROUTER_API_KEY is not configured")

    schema = json.loads((EXPERIMENT_DIR / args.schema).read_text())
    prompt = (EXPERIMENT_DIR / args.prompt).read_text()
    gold_payload = json.loads((EXPERIMENT_DIR / args.gold).read_text())
    gold_cases = gold_payload["cases"]
    gold_by_rank = {case["rank"]: case for case in gold_cases}

    benchmark_path = Path(args.input_file).resolve() if args.input_file else EXPERIMENT_DIR.parent / "benchmark/top-100-input.json"
    benchmark = json.loads(benchmark_path.read_text())
    rows = {row["rank_at_5000_kwh"]: row for row in benchmark["contracts"]}
    analysis_date = benchmark["snapshot_date"]
    if args.all_benchmark:
        selected_ranks = sorted(rows)
    elif args.ranks:
        selected_ranks = [rank for rank in args.ranks if rank in rows]
    else:
        selected_ranks = [case["rank"] for case in gold_cases]

    run_dir = EXPERIMENT_DIR / "runs" / args.run_name
    raw_dir = run_dir / "raw"
    raw_dir.mkdir(parents=True, exist_ok=True)
    metadata = {
        "run_name": args.run_name,
        "started_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "models": args.models,
        "ranks": selected_ranks,
        "prompt": args.prompt,
        "schema": args.schema,
        "gold": args.gold,
        "analysis_date": analysis_date,
        "input_file": str(benchmark_path),
        "reasoning_effort": args.reasoning_effort,
    }
    (run_dir / "metadata.json").write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + "\n")

    jobs = []
    for model in args.models:
        for rank in selected_ranks:
            jobs.append((model, rank, gold_by_rank.get(rank), contract_input(rows[rank])))

    def run_one(job: tuple[str, int, dict | None, dict]) -> dict:
        model, rank, gold, source = job
        print(f"START {model} rank={rank}", flush=True)
        result = call_openrouter(api_key, model, prompt, schema, source, analysis_date, args.reasoning_effort)
        record = {"model": model, "rank": rank, "contract_id": source["contract_id"], "input": {"analysis_date": analysis_date, **source}, **result}
        if result.get("ok"):
            if gold is not None:
                record["evaluation"] = score_output(result["output"], gold, source)
                score_suffix = f" score={record['evaluation']['score']:.3f}"
            else:
                score_suffix = ""
            print(f"DONE  {model} rank={rank}{score_suffix}", flush=True)
        else:
            print(f"FAIL  {model} rank={rank} {result.get('error')}", flush=True)
        safe_model = model.replace("/", "__")
        (raw_dir / f"{safe_model}__rank-{rank:03d}.json").write_text(json.dumps(record, ensure_ascii=False, indent=2) + "\n")
        return record

    records = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=max(1, args.concurrency)) as executor:
        futures = [executor.submit(run_one, job) for job in jobs]
        for future in concurrent.futures.as_completed(futures):
            records.append(future.result())

    validation_process = subprocess.run(
        ["php", str(EXPERIMENT_DIR / "validate_run.php"), str(run_dir)],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
    )
    validation_summary = json.loads(validation_process.stdout)
    validation_by_case = {
        (item["model"], item["rank"]): item
        for item in validation_summary["results"]
    }
    for record in records:
        validation = validation_by_case.get((record["model"], record["rank"]))
        if validation is not None:
            record["production_validation"] = validation

    summaries = []
    for model in args.models:
        model_records = [record for record in records if record["model"] == model]
        successes = [record for record in model_records if record.get("ok")]
        total_cost = sum(float(record.get("usage", {}).get("cost") or 0) for record in successes)
        prompt_tokens = sum(int(record.get("usage", {}).get("prompt_tokens") or 0) for record in successes)
        completion_tokens = sum(int(record.get("usage", {}).get("completion_tokens") or 0) for record in successes)
        evaluated = [record for record in successes if "evaluation" in record]
        score = sum(record["evaluation"]["passed"] for record in evaluated) / sum(record["evaluation"]["total"] for record in evaluated) if evaluated else None
        production_valid = [record for record in successes if record.get("production_validation", {}).get("valid")]
        summaries.append({
            "model": model,
            "successes": len(successes),
            "failures": len(model_records) - len(successes),
            "production_validator_passes": len(production_valid),
            "production_validator_failures": len(successes) - len(production_valid),
            "evaluated_cases": len(evaluated),
            "weighted_score": score,
            "mean_case_score": sum(record["evaluation"]["score"] for record in evaluated) / len(evaluated) if evaluated else None,
            "prompt_tokens": prompt_tokens,
            "completion_tokens": completion_tokens,
            "reported_cost_usd": total_cost,
            "mean_latency_seconds": sum(record["latency_seconds"] for record in successes) / len(successes) if successes else None,
        })
    summaries.sort(key=lambda item: (-(item["weighted_score"] or 0), item["reported_cost_usd"]))
    result_payload = {"metadata": metadata, "models": summaries}
    (run_dir / "summary.json").write_text(json.dumps(result_payload, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps(result_payload, ensure_ascii=False, indent=2))
    return 0 if all(item["failures"] == 0 for item in summaries) else 1


if __name__ == "__main__":
    raise SystemExit(main())
