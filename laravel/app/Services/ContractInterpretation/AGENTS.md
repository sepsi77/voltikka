# Contract interpretation services

This directory implements the raw source and automatic contract interpretation pipeline.

## Source snapshots

- `ContractSourceCanonicalizer` creates a stable SHA-256 fingerprint from one complete upstream payload.
- It normalizes object-key order and string whitespace, preserves list order, and excludes shared `Details.SpotFutures` market data.
- `contract_source_snapshots.source_payload` keeps the original complete payload.
- An unchanged import updates only `last_observed_at`.
- Snapshots are written inside the `contracts:fetch` transaction.

## Automatic interpretation

Primary services:

- `ContractInterpretationInputBuilder` maps a source snapshot to the compact prompt input used in experiments and normalizes HTML descriptions without changing case or punctuation.
- `ContractAnalysisFingerprint` combines source, schema, prompt, deterministic validator, provider, and model versions.
- `OpenRouterContractInterpretationClient` requests strict JSON Schema output.
- `ContractInterpretationValidator` validates schema shape, identity, dates/ranges, exact description evidence, and classification consistency.
- `ContractInterpretationDispatcher` creates one interpretation per analysis fingerprint and dispatches the job after commit.
- `ContractInterpretationPublisher` automatically publishes valid compatible classifications and current canonical pricing JSON.
- `CanonicalPriceComponentWriter` writes relational source components only after the relevant source version is safe to expose.

Configuration and assets:

- `config/contract_interpretation.php`
- `resources/contract-interpretation/schema-v3.json`
- `resources/contract-interpretation/system-prompt-v15.md`

Important semantics:

- Import-time queueing uses `CONTRACT_INTERPRETATION_ENABLED=true`; production enabled it after the successful 425-contract backfill on 2026-07-24.
- There is no human review, approval, or override workflow.
- Validation failure can cause at most two model correction calls. Each call receives the exact validator errors and the previous complete output. All attempts are stored in `llm_attempts`.
- Invalid final output is stored as failed and does not publish.
- A stale job becomes `superseded` if a newer source snapshot exists.
- High-confidence, internally consistent mismatch corrections can publish automatically.
- `electricity_contracts.published_interpretation_id` identifies the published version; `published_fields` records the exact canonical fields it owns.
- `canonical_pricing`, `canonical_source_consistency`, and `canonical_calculation` hold the current validated rich JSON on the contract row. Versioned interpretation rows provide its history.
- Later source imports preserve only fields in `published_fields` until a newer interpretation publishes.
- New contracts remain inactive until their first valid interpretation publishes safe pricing.
- For an interpreted contract, a changed raw source price does not replace relational prices before the new interpretation validates it.
- `relational_pricing_published` records whether source components passed the safe-publication gate.
- Unsafe incomplete/conflicting structured prices remain in canonical interpretation JSON but do not activate a new contract or replace relational `price_components`, including on later imports.
- Evidence paths are relative to the flat prompt input. They must identify one scalar leaf; description quotes must match normalized prompt text exactly.
- Structured discounted amounts can pass without a literal output number only when the validator independently recomputes the amount from separately cited structured discount operands and matching phase limits.
- Validator v2 rejects fixed-fee taxonomy drift: `fixed` on Spot needs an actual fixed energy component, `flat_fee_or_package` must match a `flat_fee` component, and seasonal/time-of-use/consumption-effect mechanisms must match extracted pricing facts.
- Validator v3 keeps `periodic_market_reset`, both cadence fields, recurring-schedule presence, and calculation status consistent. It also recognizes strong source facts such as `Kvartaalisähkö`, four price changes per year, or explicit three-month price periods and requires a quarterly reset instead of trusting an internally consistent omission.
- Prompt v7 adds an explicit invariant matrix and compact examples for Spot, fixed, Hybrid, recurring reset, time-of-use, seasonal, flat-fee package, optional fixing, promotions, and common negative cases. Prompt v8 clarifies that unknown future recurring market prices are expected and do not make otherwise complete current structured prices incomplete.
- Validator v4 rejects `incomplete`, a non-uncertain warning state, or a non-estimate calculation when `recurring_reset_requires_estimate` is the only pricing limitation. This permits validated current relational components to publish while canonical calculation remains estimate-required.
- Prompt v9 and validator v5 treat `analysis_date` as the current-offer cutoff: absolute-date phases ending earlier are historical and cannot create current phases, missing facts, or issue codes. They also reject `uncertain` when pricing is complete/exact and no directional omission or conflict exists.
- Prompt v10 and validator v6 detect a flat consumption package from package wording + positive monthly charge + zero unit price + positive consumption limit. They require `flat_fee_or_package`/`flat_fee`, suppress zero included energy as an ordinary unit-price component, and disallow `fixed` without a positive energy rate. They also retain source Hybrid when sparse text provides no explicit contrary evidence.
- Validator v7 recognizes explicit symmetric numeric evidence such as `+/-1,5`, `±1.5`, and `+−1,5` as evidence for both positive and negative bounds. A normal one-direction value remains evidence for that direction only.
- Prompt v11 and validator v8 prohibit unknown package-fee placeholders derived from zero included energy. They also require `not_detected` for complete structured pricing without a directional issue, including ordinary Spot estimates and optional fixing excluded from the base price.
- Prompt v12 and validator v9 treat recognized non-discounted structured-only FixedPrice/Spot data as assessable when descriptions are empty. They require source-model match, complete pricing, `not_detected`, no description-only insufficient-evidence issue, and exact/estimate-required calculation by model. `SeasonalWinterDay` maps to canonical winter energy.
- Prompt v13 and validator v10 prohibit pricing phases derived from stale discount timing fields when the source component has `has_discount=false`. Such duplicate components remain current/conflicting facts instead of an invented promotion schedule.
- Prompt v14 requires an exact opaque copy of `contract_id`, including ASCII slugs that differ from Finnish display names. Medium reasoning is the production default after a low-reasoning model repeatedly changed `kesakampanja` to `kesäkampanja`. Reasoning effort is part of the analysis fingerprint so effort changes preserve prior attempts and create a new interpretation version.
- Prompt v15 and validator v11 recognize explicit excess-use packages: a monthly fee includes a package amount and excess consumption has a separate positive unit price. The Monthly component maps to `flat_fee`, the positive General component stays `energy_general`, and missing package limits remain incomplete with a non-recurring issue code. Validator v11 also treats `after_months=0` as equivalent to `contract_start` when it verifies deterministic first-N-month discount arithmetic.
- Prompt v17 and validator v13 lead with a "trust the structured data" mission: the structured API fields are the baseline and the LLM's active judgment is scoped to three jobs — (1) integrity/deception detection, (2) type recovery the source enum cannot express (kvartaali/monthly market reset, spot-plus-margin), and (3) correcting a structured field only on explicit contrary text. Rules baked in:
  - **Spot ⇒ margin**: on a Spot contract every supplier c/kWh energy adder is `spot_margin`, whichever tariff slot the source used (General/DayTime/NightTime/Seasonal); a small day/night/seasonal value is the margin, not a fixed energy price, so it does not add `time_of_use`/`seasonal` mechanisms. A larger standalone value (>~2 c/kWh) is a genuine all-in market/intro price and stays `energy_general`. Enforced deterministically: `interpretedComponentType` maps every Spot energy source type to `spot_margin`, and a check rejects any `energy_*` component at or below `SPOT_MARGIN_CEILING_CENTS` (2.0, mirroring `CanonicalContractPriceCalculator`) on a Spot contract.
  - **Computational deceptive gate**: `misleading_first_12_months=detected` requires that the structured data — pricing fields AND discount/promotion fields, treating the structured `price` as the original/normal amount — is insufficient to compute the true first-12-month cost. A promotion fully encoded in structured discount fields is not deceptive (evaluated per component); promo wording in a description alone never warrants `detected`. The classic `detected` case is a promo price in the structured pricing fields with the increase disclosed only in prose (Tyyni Vakiohinta).
  - **Market-linked continuations are not deceptive**: when the ongoing price is market-linked — pure Spot (Nord Pool + margin) or a periodic market reset (Kvartaalisähkö/monthly `markkinahintasähkö`) — a cheaper fixed first-period intro is a normal promo, `uncertain` + `estimate_required`, never `detected`. Whether the next period's price is disclosed is not a signal in either direction. `detected` is reserved for a hidden increase of a *persistent* priced component: a fixed energy jump (Tyyni 5.49→13.65) or a spot-margin adder hike (Cheap kampanja 0.39→0.78). Validator v13 enforces the reset case: a product with `recurring_schedule.present=true` and `detected` whose issue codes are only reset/intro/future codes is rejected (must be `uncertain`); a genuine non-reset conflict code still permits `detected`.
  - Also: a Spot delivery-fee/`toimitusmaksu` margin is not `incomplete`; a duplicate monthly base fee resolves to the higher value while staying complete.

  This is the deterministic-rule half of the eventual "extract with the LLM, derive with code" (option B) direction. NOTE: the analysis fingerprint keys on the version *strings*, so iterating on prompt content or validator code requires bumping `prompt_version`/`validator_version` to force re-interpretation of already-processed contracts. See `tasks/contract-description-pricing-phases/decisions.md`.
- `validator_version` is stored on each interpretation and participates in the analysis fingerprint. Change it whenever deterministic publication semantics change enough to require reanalysis.
- Phase-aware calculations that read `canonical_pricing` live in `../CanonicalPricing/` (gated behind `CANONICAL_PRICING_ENABLED`). This directory only produces and publishes the canonical JSON; it does not calculate prices from it.
- Source snapshots remain immutable evidence.

Manual queue command:

```bash
php artisan contracts:interpret
php artisan contracts:interpret --contract=LOCAL_CONTRACT_ID
php artisan contracts:interpret --include-inactive
php artisan contracts:interpret --retry-failed
```
