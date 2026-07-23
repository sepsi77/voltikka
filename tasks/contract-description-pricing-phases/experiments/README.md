# Contract interpretation LLM experiments

This directory contains the reproducible prompt/model evaluation requested before implementing the Laravel interpretation pipeline.

## Recommended configuration

- Model: `openai/gpt-5.6-luna`
- Reasoning effort: `low`
- Prompt: `system-prompt-v6.md`
- JSON Schema: `schema-v3.json`
- Deterministic post-processing example: `evaluate_run.py`

The prompt must not be used without deterministic evidence, phase, component, and category validation. See `results.md`.

## Files

- `schema-v3.json` — recommended strict structured-output schema with evidence-path constraints
- `system-prompt-v6.md` — recommended prompt with flat-input and scalar-evidence rules
- `gold-v3.json` — 22 manually specified key-field cases covering Spot, fixed, recurring reset, promotions, missing descriptions, seasonal tariffs, optional fixing, and consumption effect
- `active-434-input.json` — normalized read-only production export used for the full shadow run
- `run_experiment.py` — OpenRouter runner, cost/latency recorder, key-field scorer, and production-validator launcher
- `validate_run.php` — runs the exact Laravel production validator against each successful experiment output
- `repair_run.php` — gives failed outputs and exact validation errors to the production correction client, with a hard maximum of two correction calls
- `evaluate_run.py` — focused deterministic promotion-mismatch derivation and benchmark evaluator
- `results.md` — findings and recommendation
- `runs/*/summary.json` — aggregate run metrics
- `runs/*/responses.ndjson` — retained auditable responses for the principal comparisons/final runs

Earlier prompt/schema versions are retained to show the optimization path.

## API-key handling

The runner reads `OPENROUTER_API_KEY` from the process environment or `laravel/.env`. It never prints or stores the key.

## Reproduce the final top-100 run

```bash
cd /Users/seppo/code/voltikka
python3 tasks/contract-description-pricing-phases/experiments/run_experiment.py \
  --run-name top100-gpt-v6-low-rerun \
  --prompt system-prompt-v6.md \
  --schema schema-v3.json \
  --gold gold-v3.json \
  --models openai/gpt-5.6-luna \
  --all-benchmark \
  --concurrency 10 \
  --reasoning-effort low
```

Retry production-validator failures from a retained run, with at most two correction calls:

```bash
php tasks/contract-description-pricing-phases/experiments/repair_run.php \
  tasks/contract-description-pricing-phases/experiments/runs/top100-gpt-v6-low-rerun \
  2
```

Evaluate the focused promotion benchmark:

```bash
python3 tasks/contract-description-pricing-phases/experiments/evaluate_run.py \
  top100-gpt-v6-low-rerun
```

## Important evaluation limitations

- Only 22 contracts have detailed field-level gold expectations.
- The focused top-100 benchmark has only four positives and was used during prompt iteration, so its perfect final score is preliminary rather than an unbiased production estimate.
- The production descriptions are persisted database values and may be stale.
- OpenRouter provider routing, model implementations, latency, and pricing can change.
- LLM headline judgments are less stable than extracted facts. Production code should deterministically derive effective pricing and warning states from validated phases/components.
