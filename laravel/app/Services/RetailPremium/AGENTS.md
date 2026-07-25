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

## Terms

Use **retail premium** or **spread over wholesale**. Never call this value profit or margin. The premium also pays for hedging, customer load shape, imbalance, credit risk, customer acquisition, billing, support, sourcing, and service.

## Data rules

- Store one row for each semantic contract-lineage price period, reference kind, and method version.
- Current observations use `retail-premium-v1`. Reconstructed relational history uses the separate `retail-premium-history-v1` method so it cannot merge with canonical source-snapshot rows.
- Pricing/reference fields are immutable by default. The command can extend `last_observed_date` and merge carrier/component provenance monotonically for the same identity. `--overwrite` can replace analytical fields but must never shorten the stored observation range.
- `price_signature` lets source-only changes continue the same price period. A later changed price starts a new observation.
- `lineage_key` is derived from the oldest roots in the replacement DAG. `lineage_contract_id` records the active lineage tip at collection time.
- Spot observations use the disclosed canonical `spot_margin`, have `reference_kind = spot_disclosed`, and have `quality = exact`. They do not use a futures price.
- Market-reset observations use the source snapshot's first-observed date as the curve vintage and delivery-period anchor. Store every available month, quarter, and year candidate.
- Fixed-term observations call the existing `FixedTermHedgeCostService::calculate()` for `term_strip`. Also store pure month-only, quarter-only, and year-only strip candidates when each tenor covers the complete term. Do not apply that term reference to a post-term `continuation` phase.
- Hybrid base prices use `quality = not_comparable`, have no premium, and must stay out of aggregates.
- An unavailable prior curve creates a flagged `curve_unavailable` evidence row. A later run can add candidate-reference rows if the vintage data becomes available.
- Include the monthly fee in `retail_premium_with_fee_cents_per_kwh` at the stored `reference_consumption_kwh`. A missing fee stays null and is flagged. Do not assume that it is zero.
- Keep each component's VAT basis. Do not infer VAT only from `target_group`, and do not mix included-VAT and excluded-VAT observations in aggregates.
- Record zero and extreme prices and add quality flags. Do not silently remove outliers.
- Historical reconstruction uses the active lineage tip only as a semantic/VAT template. Numeric history must come from relational components. The historical row keeps interpretation columns null and records template IDs in source metadata so it does not falsely claim that an inactive carrier had an interpretation.
- Calibrate raw-to-canonical roles from the active tip's latest relational components and structured canonical evidence. Do not reconstruct description-only component values.
- Compress consecutive daily rows only when the complete normalized signature is equal. A missing day, price/discount change, or overlapping conflicting lineage state starts a boundary or skips the conflicting day. Never average conflicts.
- Historical component VAT has no direct source field. Propagate it only through a calibrated current canonical role, flag that provenance, and keep unknown/mixed VAT premiums null.
- Structured discounts are not safely phase-aware in old relational rows. Keep their evidence, flag `discount_effect_unresolved`, and make the affected premium or fee-inclusive value null.
- Hybrid contracts are not comparable and must not enter premium aggregates.
- Store all candidate futures references for inferred observations. Do not select one global month, quarter, year, or term-strip reference.

## Shared services and boundaries

- `ElectricityContract::getReplacementLineageIds()` and `getLineagePriceComponents()` collect the replacement ancestor set and then order raw prices by `price_date`.
- Do not refactor `PriceForecasting/FixedTermHedgeCostService`. Fixed-term observations must call its existing vintage-aware `calculate()` method.
- New single-period futures lookup code belongs beside this service, not inside `FixedTermHedgeCostService`.
- Treat `contract_price_daily_statistics` as read-only.
- Do not change `app/Services/CanonicalPricing/` from this feature.

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

`retail-premiums:cross-check` is read-only. It compares included-VAT `energy_general` term-strip observations with the stored median fixed-term forecast and shows per-company medians. `--as-of` sets the collection date only. It does not rewind current contract JSON. `routes/console.php` runs the collector daily at 07:15 Europe/Helsinki, after the morning contract import.
