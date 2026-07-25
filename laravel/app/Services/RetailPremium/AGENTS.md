# Retail premium dataset

This directory owns the private per-contract retail premium dataset. It has no public UI.

Primary files:
- `RetailPremiumObservationService.php` builds current versioned observations.
- `RetailPremiumHistoryBackfillService.php` reconstructs historical semantic price periods from daily relational `price_components` evidence when inactive ancestors have no interpretation snapshots.
- `VintageAwareReferencePriceService.php` looks up month, quarter, year, and pure/mixed term-strip candidates at a no-same-day-leakage vintage.
- `RetailPremiumCrossCheckService.php` compares fixed-term per-lineage results with stored market-level EWMA forecast premiums.
- `../../Models/RetailPremiumObservation.php` stores one row per semantic price period and candidate wholesale reference.
- `../../Console/Commands/CollectRetailPremiumObservations.php` runs `retail-premiums:collect`.
- `../../Console/Commands/CrossCheckRetailPremiums.php` runs the read-only `retail-premiums:cross-check` diagnostic.
- `../../../database/migrations/2026_07_25_000001_create_retail_premium_observations_table.php` defines storage.
- `../../../database/migrations/2026_07_25_000002_add_vat_and_reference_evidence_to_retail_premium_observations.php` adds `fee_vat_basis`, `vat_basis_source`, and the always-stored reference-price evidence columns.

## Terms

Use **retail premium** or **spread over wholesale**. Never call this value profit or margin. The premium also pays for hedging, customer load shape, imbalance, credit risk, customer acquisition, billing, support, sourcing, and service.

## Data rules

- Store one row for each semantic contract-lineage price period, reference kind, and method version.
- Current observations use `retail-premium-v2`. Reconstructed relational history uses the separate `retail-premium-history-v2` method so it cannot merge with canonical source-snapshot rows.
- **Always filter analysis to the current method-version pair.** `method_version` is part of the unique row identity, so a method bump inserts new rows beside the old ones instead of replacing them. `retail-premium-v1` and `retail-premium-history-v1` rows stay in the table forever and keep two known defects: duplicate price periods split by a day the import missed, and no `quarter` reference for quarterly resets. Mixing versions double-counts price periods.
- Pricing/reference fields are immutable by default. The command can extend `last_observed_date` and merge carrier/component provenance monotonically for the same identity. `--overwrite` can replace analytical fields but must never shorten the stored observation range.
- `price_signature` lets source-only changes continue the same price period. A later changed price starts a new observation.
- `lineage_key` is derived from the oldest roots in the replacement DAG. `lineage_contract_id` records the active lineage tip at collection time.
- Spot observations use the disclosed canonical `spot_margin`, have `reference_kind = spot_disclosed`, and have `quality = exact`. They do not use a futures price.
- Market-reset observations use the source snapshot's first-observed date as the curve vintage and delivery-period anchor. Store every available month, quarter, and year candidate.
- Market-reset references come from `VintageAwareReferencePriceService::forResetPeriod()`. A quarterly reset price is set **before** its period starts, so at that vintage EEX still publishes the quarter contract and a plain `quarter` lookup is the primary path. Keep it that way. `quarter_month_average` is a separate additional candidate: the day-weighted average of the three month contracts of the same quarter. It is the only quarter-shaped reference left once the quarter has entered delivery, which happens for a mid-period re-anchoring vintage and for any period boundary that is not a calendar boundary. Never fold it into `quarter`; the month-versus-quarter question needs the directly observed quarter settlement to stay separate.
- Reference metadata records `delivery_start_month`, `delivery_end_month`, and `vintage_inside_delivery_period`. A row whose vintage sits inside the delivery period is flagged `vintage_inside_delivery_period`, because such a contract has partly converged to realized spot and is a weaker forward reference. The `year` candidate for a delivery month in the current calendar year is always inside delivery.
- Fixed-term observations call the existing `FixedTermHedgeCostService::calculate()` for `term_strip`. Also store pure month-only, quarter-only, and year-only strip candidates when each tenor covers the complete term. Do not apply that term reference to a post-term `continuation` phase.
- Hybrid base prices use `quality = not_comparable`, have no premium, and must stay out of aggregates.
- An unavailable prior curve creates a flagged `curve_unavailable` evidence row. A later run can add candidate-reference rows if the vintage data becomes available.
- Include the monthly fee in `retail_premium_with_fee_cents_per_kwh` at the stored `reference_consumption_kwh`. A missing fee stays null and is flagged. Do not assume that it is zero. The fee-inclusive value also needs `fee_vat_basis` to equal `vat_basis`; otherwise it stays null with `fee_vat_basis_not_comparable`.
- Keep each component's VAT basis. Do not infer VAT only from `target_group`, and do not mix included-VAT and excluded-VAT observations in aggregates.
- **`vat_basis` describes the energy component only**, because the premium is an energy-price spread. The monthly fee keeps its own `fee_vat_basis`. Do not combine the two into one basis: an energy price with a disclosed basis plus a fee with an unknown basis used to collapse into `mixed` and threw away a usable premium.
- `vat_basis_source` records how the basis was resolved: `component_explicit`, `contract_propagated`, or `unresolved`. `contract_propagated` means the component itself said `unknown` and the **same contract** discloses exactly one explicit basis on another component, so that basis fills the gap; the row is also flagged `vat_basis_propagated_within_contract`. This never reads `target_group` and never borrows from another contract, another company, or a sibling product.
- **Unresolved VAT is expected for most rows and must not be papered over.** The upstream source payload has no VAT field at all, and the interpretation system prompt does not ask about VAT, so about 70 % of canonical components carry `vat_status = unknown`. Those rows stay `unknown` with a null premium. The real fix belongs in `../ContractInterpretation/` (prompt guidance plus reinterpretation), not here.
- Always store the wholesale reference as evidence, even when the retail VAT basis is unknown: `reference_price_including_vat_cents_per_kwh`, `reference_price_excluding_vat_cents_per_kwh`, and `reference_settlement_price_eur_per_mwh`. `reference_price_cents_per_kwh` stays the VAT-matched value and is null for an unknown basis. This keeps an unknown-VAT row usable for a pass-through (`beta`) measurement from price differences, which needs only a consistent scale, while still keeping it out of VAT-mixed premium levels.
- Record zero and extreme prices and add quality flags. Do not silently remove outliers.
- Historical reconstruction uses the active lineage tip only as a semantic/VAT template. Numeric history must come from relational components. The historical row keeps interpretation columns null and records template IDs in source metadata so it does not falsely claim that an inactive carrier had an interpretation.
- Calibrate raw-to-canonical roles from the active tip's latest relational components and structured canonical evidence. Do not reconstruct description-only component values.
- Compress daily rows only when the complete normalized signature is equal. A price or discount change starts a new period. An overlapping conflicting lineage state skips that day. Never average conflicts.
- **A day without an accepted state does not by itself end a price period.** It ends one only when the lineage was genuinely absent from an import that did run, which is evidence that the product left the market; that boundary is flagged `period_follows_lineage_absence` and the absent dates go into `source_metadata.preceding_absent_observation_dates`. A day the whole import missed (no `price_components` row for any contract) or a day whose lineage rows existed but could not be read (ambiguous, incomplete, or conflicting) carries **no evidence of a price change**, so an unchanged signature continues the same period across it. Bridged days are listed in `source_metadata.bridged_observation_dates` and flagged `observation_gap_bridged_import_outage` or `observation_gap_bridged_unreadable_day`.
- Reason this rule matters, do not revert it: the import missed 2026-02-12 entirely. Under the old always-split rule that single day split roughly 100 unchanged price periods in two across the whole dataset. An unchanged price against a moved reference reads as zero pass-through, which biased `beta` down hard (0.61 against about 0.95 on the one usable series).
- Historical component VAT has no direct source field. Propagate it only through a calibrated current canonical role, flag that provenance, and keep unknown/mixed VAT premiums null.
- Structured discounts are not safely phase-aware in old relational rows. Keep their evidence, flag `discount_effect_unresolved`, and make the affected premium or fee-inclusive value null.
- Hybrid contracts are not comparable and must not enter premium aggregates.
- Store all candidate futures references for inferred observations. Do not select one global month, quarter, year, or term-strip reference.
- The two method families cannot merge into one row by design, so the point where reconstructed history stops and forward collection starts looks like a new price period at an unchanged price. `CollectRetailPremiumObservations::flagPriorHistoryContinuation()` marks that seam with `continues_prior_history_period` plus `source_metadata.continued_history_observation_key`. A pass-through analysis must drop that step; it is a method boundary, not a reset.

## Shared services and boundaries

- `ElectricityContract::getReplacementLineageIds()` and `getLineagePriceComponents()` collect the replacement ancestor set and then order raw prices by `price_date`.
- Do not refactor `PriceForecasting/FixedTermHedgeCostService`. Fixed-term observations must call its existing vintage-aware `calculate()` method.
- New single-period futures lookup code belongs beside this service, not inside `FixedTermHedgeCostService`.
- Treat `contract_price_daily_statistics` as read-only.
- Do not change `app/Services/CanonicalPricing/` from this feature.

## TO BE IMPLEMENTED IN THE FUTURE: per-company calibration

The dataset's first purpose is to calibrate the market-reset annualised price estimate in
`../CanonicalPricing/` — the reference period each company prices from, and the pass-through
coefficient `beta`. **That calibration is deferred and the estimator ships with one global `beta`
instead.** Read `../CanonicalPricing/AGENTS.md` for the estimator side.

State as of 2026-07-25:

- Measured `beta` on a month reference: Pohjois-Karjalan Sähkö **0.90** (R² 0.99), Kokkolan Energia
  **1.01** (R² 0.66). Both consistent with full pass-through. Robust to the VAT scale ambiguity.
- Only **3 multi-period reset series** exist, so nothing can be concluded per company yet, and every
  quarterly cadence is uncalibrated — quarterly products are about two thirds of the reset population.

Why it cannot be finished now, and when it can:

- Calibration needs the curve **at the vintage the price was set**. FI curve history starts
  **2026-04-08** and EEX enforces an approximately 45-day rolling window server-side, so earlier
  vintages are permanently unrecoverable. Verified by request: an expired quarter maturity returns zero
  rows even with the local cap lifted. The Azure `Details.SpotFutures` field is a single scalar, not a
  term structure, so it cannot substitute.
- **1 October 2026** gives each quarterly lineage a second period, so roughly 24 lineages contribute a
  pass-through step at once. January 2027 doubles that.
- Buying historical FI Base month/quarter settlements for January–April 2026 would unlock it about two
  months earlier; see `tasks/retail-premium-dataset/decisions.md` for verified vendor terms.

Two open questions for that future work, both recorded with evidence in the task decisions file:

1. Whether quarterly sellers price off the quarter contract or off something else. Untestable today.
2. Why the pooled `beta` (0.53) sits far below both per-company fits — a third series with poor
   pass-through dominates the `dF^2` weighting.

## Command

```bash
php artisan retail-premiums:collect
php artisan retail-premiums:collect --contract=contract-id --dry-run
php artisan retail-premiums:collect --overwrite
php artisan retail-premiums:collect --include-inactive --dry-run
php artisan retail-premiums:collect --include-inactive --only=spot
php artisan retail-premiums:collect --include-inactive --from=2026-04-08 --to=2026-07-22
php artisan retail-premiums:cross-check --as-of=2026-07-25
```

`--include-inactive` keeps `--contract` scoped to active lineage-tip IDs and reconstructs compatible inactive ancestors from `price_components`. By default it ends yesterday and also excludes the terminal open period owned by the canonical forward collector. Use `--include-open` only for a deliberate overlap. The backfill never calls current-row `reuseOpenPricePeriodIdentity()`.

`retail-premiums:cross-check` is read-only. It compares included-VAT `energy_general` term-strip observations of the **current method-version pair only** with the stored median fixed-term forecast and shows per-company medians. `--as-of` sets the collection date only. It does not rewind current contract JSON. `routes/console.php` runs the collector daily at 07:15 Europe/Helsinki, after the morning contract import.
