# LLM extraction experiment results

## OpenRouter verification

`OPENROUTER_API_KEY` from `laravel/.env` was verified without printing the value.

- `GET /api/v1/models`: HTTP 200
- Available requested models:
  - `openai/gpt-5.6-luna`
  - `qwen/qwen3.7-plus`
  - `minimax/minimax-m3`
  - `deepseek/deepseek-v4-pro`
- Structured chat-completion calls succeeded. Qwen rejected JSON Schema `uniqueItems`, so the portable schema avoids that keyword.
- Approximate reported spend across all iterative smoke, model, prompt, reasoning, top-100, and 434-contract runs was **$11.73**. This includes discarded intermediate experiments; retained final-run costs are listed below.

## Model comparison

All four requested models were tested on the same 22 manually specified key-field cases using prompt/schema v2 and low reasoning. Scores are weighted deterministic checks, not an LLM judge.

| Model | Successful | Failed | Key-field score | Reported cost | Cost/contract | Mean latency |
|---|---:|---:|---:|---:|---:|---:|
| `openai/gpt-5.6-luna` | 22 | 0 | **95.9%** | $0.2817 | $0.0128 | 12.6 s |
| `minimax/minimax-m3` | 22 | 0 | 76.0% | $0.0776 | $0.0035 | 19.5 s |
| `qwen/qwen3.7-plus` | 22 | 0 | 72.2% | $0.2176 | $0.0099 | 131.8 s |
| `deepseek/deepseek-v4-pro` | 19 | 3 | 70.0% | $0.2730 | $0.0144 | 174.1 s |

`gpt-5.6-luna` was substantially more correct, faster, and more reliable. Qwen was only slightly cheaper in this experiment because it used many reasoning tokens. DeepSeek was both less reliable and more expensive than GPT for these structured outputs. MiniMax was economical but not accurate enough for trusted extraction.

## Prompt optimization

Prompt iterations added explicit rules for:

1. separating the structured-only ledger from description facts
2. treating monthly/quarterly resets as recurring products rather than promotions
3. retaining broad `FixedPrice` compatibility while extracting richer periodic-reset semantics
4. distinguishing Spot settlement interval from price-reset cadence
5. treating hourly Spot measurement as `General` retail metering, not `Time`
6. mapping General c/kWh to `spot_margin` after a contract is identified as Spot
7. keeping consumption-effect cadence separate from base-price reset cadence
8. treating an after-month-12 continuation as outside the first-12-month horizon
9. scoping Finnish duration phrases to the component they grammatically modify
10. recognizing that an omitted free month overstates rather than understates cost
11. preventing optional-fixing consumption effects from contaminating the base Spot estimate
12. requiring exact evidence and conservative unknowns

The final prompt is `system-prompt-v5.md`.

## Reasoning-effort comparison

On 22 cases with GPT:

| Effort | Key-field score | Reported cost | Mean latency |
|---|---:|---:|---:|
| none | 96.5% | $0.2496 | 7.9 s |
| minimal | 97.2% | $0.2854 | 10.0 s |
| low | **98.1%** | **$0.1829** | 9.9 s |
| medium, final prompt | 98.1% | $0.3641 | 16.2 s |

OpenRouter routing/costs varied between runs, but low effort gave the best observed correctness/economy balance. Medium effort did not materially improve the score. Recommended production policy: low by default; medium only for validation failures or high-impact adjudication.

## Final top-100 run

Configuration: GPT, prompt v5, schema v2, low reasoning.

- 100/100 valid structured responses
- 0 API/JSON failures
- 97.8% weighted key-field score on the 22 detailed gold cases in that run
- $1.1521 reported total cost
- $0.01152 per contract
- 11.4 s mean latency with ten concurrent requests
- 97.2% of substantive description evidence quotes were exact substrings; non-exact quotes must be rejected or repaired deterministically

### Focused promotion mismatch

The final decision is derived from validated extracted facts, not the model headline:

- structured pricing is incomplete/conflicting
- promotion-specific and omitted/unknown-future issue evidence exists
- the cheap phase ends strictly inside 12 months
- the touching next phase contains a higher description-only component, or explicitly reverts to an unknown previous tariff

On the 100 manually labeled contracts:

| TP | FP | FN | TN | Precision | Recall |
|---:|---:|---:|---:|---:|---:|
| 4 | 0 | 0 | 96 | 100% | 100% |

This is preliminary because there are only four positives and the benchmark informed prompt development.

## Full active-contract shadow run

The final configuration was also run over the normalized read-only export of all 434 active contracts.

- 434/434 valid structured responses
- 0 failures
- $5.2239 reported cost total
- $0.01204 per contract
- 10.7 s mean latency

Output distribution:

| Dimension | Counts |
|---|---|
| Structured pricing | 137 complete, 125 incomplete, 7 conflicting, 1 uncertain, 164 not assessable |
| Calculation | 207 exact, 140 estimate required, 68 unsupported, 19 incomplete |
| Headline first-12-month judgment | 14 detected, 107 uncertain, 146 not detected, 167 not assessable |

The deterministic focused promotion rule selected seven active contracts:

- `brxibd-aalto-energia-oyj-tyyni-vakiohinta`
- `kz9acg-cheap-energy-finland-oy-cheap-porssisahko-kampanja-perusmaksualennus-6-kk-490-kk-ja-marginaalialennus-6kk-039-sntkwh`
- `1ucmby-cheap-energy-finland-oy-cheap-kvartaalisahko`
- `iq7fja-vimpelin-voima-oy-vuodenaikatariffi`
- `gto5hg-vimpelin-voima-oy-paiva-ja-yotariffi`
- `rfarmu-vimpelin-voima-oy-vuodenaikatariffi`
- `xqf8be-vimpelin-voima-oy-sulaketariffi`

The 100 benchmark contracts embedded in this wider run still produced 4 TP, 0 FP, 0 FN, and 96 TN after deterministic phase comparison.

The model also surfaced 70 contracts mentioning some form of consumption effect and 51 periodic-reset classifications. These counts are candidate interpretations, not validated production facts.

## Category correction findings

The full active run proposed nine broad pricing-model corrections. Only the known Hehku Spot mismatch is already confirmed. Other suggestions include package products, fixed-to-Spot continuations, and ambiguous Hybrid products where the legacy enum has no clean answer.

Therefore a single LLM response must not directly rewrite `pricing_model`. Activation should require:

- deterministic taxonomy checks
- consistency across duplicate/product variants
- a second medium-effort adjudication for high-impact category changes
- review when no current broad enum accurately represents the richer mechanism

The model initially confused hourly Spot measurement with `Time` metering. Prompt v4/v5 corrected this, and the final full run produced no metering corrections. Production validation should still derive metering from component types rather than trusting the model.

## Required deterministic validation

Before any output affects calculation or frontend behavior:

1. Validate JSON Schema and contract identity.
2. Require exact evidence quotes or deterministically locate/normalize them.
3. Verify every extracted number/date/unit from source evidence.
4. Recompute structured discounts independently.
5. Validate touching, non-overlapping phase boundaries.
6. Compare corresponding component transitions and their direction.
7. Treat month 12 as outside the first-12-month continuation horizon.
8. Derive metering from component types; hourly measurement is not a time tariff.
9. Derive warning/integrity states from validated facts rather than trusting the model headline.
10. Block or review category changes, unsupported mechanisms, contradictory duplicates, and unknown future phases.

## Production-validator smoke run after rollout

A five-case local smoke run on 2026-07-23 used the exact production `system-prompt-v5.md`, `schema-v2.json`, GPT-5.6 Luna, and low reasoning.

- OpenRouter returned 5/5 schema-valid responses.
- The existing experiment key-field scorer reported a 96.15% weighted score.
- The production `ContractInterpretationValidator` accepted 0/5 responses.
- Reported OpenRouter cost was $0.0487.

The failures showed a gap in the earlier experiment:

- evidence sources sometimes named an object, a combined expression, or the visible `contract.*` envelope instead of one scalar input value
- quotes were not always exact substrings of the specifically cited field
- deterministically derived discounted amounts did not always occur literally in evidence text
- production and experiment input normalization and field names had drifted

The retained run is `runs/local-production-v5-smoke-20260723/`. Its `production-validation-summary.json` records the production-validator result. Do not resume the full production backfill until the experiment runner uses the production input builder and validator and a new local smoke run passes.

## Prompt v6 and production-validator smoke run

Prompt v6 and schema v3 define one flat model input, require one scalar source path per evidence item, and constrain evidence path syntax. The experiment runner now sends the production field names and runs the exact Laravel production validator.

A five-case local run used GPT-5.6 Luna with low reasoning:

- 5/5 OpenRouter calls succeeded
- 5/5 initial outputs passed the production validator
- weighted key-field score was 97.44%
- reported cost was $0.0580
- mean latency was 9.86 seconds

A separate correction smoke test gave one old failed output and its 13 validator errors to the production `repair()` call. The corrected complete output passed with zero errors on the first correction call. That call cost $0.0099 and took 14.8 seconds.

The complete 22-case gold set then tested the bounded correction policy:

- 22/22 initial OpenRouter calls succeeded
- 17/22 initial outputs passed the exact production validator
- all five failed outputs passed after one correction call
- 22/22 final outputs passed; no case needed a second correction call
- weighted key-field score before correction was 98.11%
- initial calls cost $0.2164; five correction calls cost $0.0446
- mean initial latency was 8.81 seconds

Retained evidence:

- `runs/local-production-v6-smoke-20260723/summary.json`
- `runs/local-production-v6-smoke-20260723/production-validation-summary.json`
- `runs/local-production-v6-smoke-20260723/repair-smoke-rank-002.json`
- `runs/local-production-v6-gold22-20260723/summary.json`
- `runs/local-production-v6-gold22-20260723/production-validation-summary.json`
- `runs/local-production-v6-gold22-20260723/repair-summary.json`

## Recommendation

Use `openai/gpt-5.6-luna`, prompt v6, schema v3, low reasoning, exact production validation, and at most two automatic correction calls. Run asynchronously and idempotently by source/prompt/model fingerprint. Persist every model attempt, evidence, usage, and validation result. Do not publish any output that still fails deterministic validation.
