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
- `resources/contract-interpretation/system-prompt-v8.md`

Important semantics:

- Enable import-time queueing with `CONTRACT_INTERPRETATION_ENABLED=true`.
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
- `validator_version` is stored on each interpretation and participates in the analysis fingerprint. Change it whenever deterministic publication semantics change enough to require reanalysis.
- Phase-aware calculations do not yet read `canonical_pricing`; that is a separate pending task.
- Source snapshots remain immutable evidence.

Manual queue command:

```bash
php artisan contracts:interpret
php artisan contracts:interpret --contract=LOCAL_CONTRACT_ID
php artisan contracts:interpret --include-inactive
php artisan contracts:interpret --retry-failed
```
