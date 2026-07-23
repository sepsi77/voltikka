# Manual benchmark: 100 currently cheapest contracts

## Selection

`top-100-input.json` was generated from Railway production MySQL read-only on snapshot date 2026-07-23.

The population is the 100 active household-eligible contracts with non-null `contract_price_snapshots.annual_cost_5000_kwh`, ordered by that current Voltikka annual estimate ascending. This intentionally samples where misleading introductory prices can cause the most ranking harm; it is not a representative sample of all active contracts.

Files:

- `top-100-input.json` — immutable review input extracted read-only from production
- `top-100-labels.json` — complete manual labels and audit fields
- `results.md` — counts, positive findings, preliminary detector metrics, and limitations

## Manual label definition

Each contract receives a target label:

- `deceptive`: the persisted description announces that the current structured price/component is valid only for a stated period, but corresponding promotion or future-phase data is absent from the structured components, causing Voltikka's next-12-month calculation to extend a temporary price beyond its validity.
- `not_deceptive`: no such persisted target signal exists. A missing description is simply negative/no-signal for this detector; it must not itself trigger review or warning.

A low price by itself is not enough for `deceptive`. Quarterly/monthly reset cadence, seasonal tariffs, fixed-term continuation, and signup deadlines are not deceptive by themselves. A legitimate recurring product can still separately contain an omitted introductory promotion.

Other calculation defects are recorded in `issue_types` but are negative for this focused promotion benchmark. Examples include `pricing_model_mismatch` and `unsupported_consumption_effect`.
## Required output per contract

- rank and contract ID
- `label`: `deceptive` or `not_deceptive`
- confidence: `high`, `medium`, or `low`
- schedule kinds, when applicable
- concise reason codes
- explanation
- exact description evidence where available
- structured evidence identifying relevant components/discount metadata
- separate non-target `issue_types`, when found
- whether the row is eligible for binary benchmark scoring

## Important limitations

- Labels use persisted database text and structured fields, not provider checkout pages.
- Existing descriptions are not generally refreshed for existing contract IDs and may be stale.
- Fifty-six active contracts in the broader production population have no usable persisted description; this is not a signal for the focused detector.
- The current snapshot calculation itself is the behavior under investigation.
- The benchmark measures the top-100 cheapest slice; later evaluation should add a stratified random sample and all deterministic/anomaly candidates.
