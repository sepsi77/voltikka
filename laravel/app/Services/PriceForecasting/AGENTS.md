# Price forecasting services

Fixed-term forecasts, stored evaluation, and `/sahkosopimus/sahkon-hintaennuste`.

## Files

- `FixedTermPriceForecastService.php`: expanding completed retail-change means.
- `FixedTermHedgeCostService.php`: FI EEX hedge-cost calculations for other callers and retained research. It is not a generation dependency.
- `FixedTermForecastEvaluationService.php`: exact-date, saved-basis evaluation.
- `ForecastOutlook.php`: saved-direction categories, qualified Finnish copy, complete-term summary and four direction outcomes.
- `FixedTermForecastReport.php`: bounded read-only compatible stored median summaries.
- `../../Models/FixedContractPriceForecast.php`: storage and public eligibility.
- `../../Console/Commands/{Run,Evaluate,Report}FixedContractPriceForecasts.php`: command boundaries.
- `../../Livewire/FixedContractPriceForecast.php` and its Blade template: public page.
- `../../../config/price_forecasting.php`: defaults.

## Current generation: fixed_term_historical_change_v1

This is the approved local release model, not the old unreleased v3 gap repair. Release requires separate approval. See `../../../../tasks/historical-change-forecast-release/` for specification, isolated export replay and release plan. Earlier research and gap-model replay remain historical evidence; their scores do not describe this expanding-pair variant.

For each term 6/12/24, quantile p20/median/p80 and horizon h independently:

`forecast_q(t+h) = current_q(t) + mean(R_q(s+h) - R_q(s))`

- Include ALL eligible historical start days s with exact target s+h STRICTLY BEFORE issue t. Equal weight per unique start day. Recompute when each new completed pair becomes available. No rolling window, coefficient, ridge/lambda, cross-term parameter sharing, futures feature, hedge, premium, fair-price input or fitted-parameter table.
- Default horizon is 30 days. Positive horizons only. Minimum is `max(20, configured minimum_history_observations)`; the hard floor survives an old environment value of 10. Insufficient pairs produce no forecast, not a partial estimate.
- Both current and history queries use `unit_statistics_v1`, `energy_price`, null consumption and the exact term segment. Annual method changes do not gate or bound this history.
- Current input is the exact issue date in `PricingMode`'s expected basis. Latest ID owns the date BEFORE finite-value validity. Missing, null or non-finite current input skips the row. No observed fallback in canonical mode.
- Canonical mode retains an observed prefix before that term's first canonical unit-date presence through issue, then canonical continuation only. Matching canonical presence establishes the boundary even when its latest value is invalid. Future presence cannot set the boundary. Latest ID owns each eligible date before validation. Missing/invalid canonical rows never revive observed rows after transition. Feature-off stays observed-only.
- BOTH pair endpoints must have the same basis. Missing dates, seam-crossing pairs, current-day targets and future targets are rejected. A start uses its timeline identity, not today's basis. Zero and negative finite values are accepted without clamping. Continuity between the two populations remains an assumption; classification, discounts and offer composition can differ.
- Store the raw mean in metadata; round the expected change to four decimals before direction classification. Inclusive ±0.15 defaults distinguish rising/falling; smaller nonzero changes remain slightly rising/falling. Forecast prices retain four decimals. Independent quantiles can cross; never sort, relabel or fabricate them.
- Confidence counts accepted CURRENT-BASIS PAIRS only: at least 120 gives medium, at least 365 gives high, otherwise low. Combined older-basis pairs can satisfy the minimum but cannot raise this history-coverage label. It is not accuracy or probability.
- Metadata records model, `expanding_equal_weight_completed_same_basis_pairs_v1`, pair count, unique start/issue days, start and target bounds, raw mean, basis counts, transition, current provenance, saved method and direction threshold. Compatibility history-count fields now count completed pairs, not individual levels. No unused futures metadata is added.
- New hedge_cost, retail_premium, normal_retail_premium, fair_price, gap, futures_trade_date and coverage_quality fields are NULL. Intervals remain NULL. Migration `2026_09_14_000001` makes the seven existing diagnostics nullable while retaining all old values. Its down deliberately does nothing: it cannot invent or delete history to restore NOT NULL.
- Generation rejects EVERY other model name, including v1/v2/v3, before freshness recovery can write statistics. Legacy stored `consumer_signal` keys remain for storage compatibility; public consumers use `ForecastOutlook` instead.

## Storage and evaluation

Generation skips existing date/horizon/term/quantile/model rows by default. `--overwrite` replaces only unevaluated rows. Completed rows remain intact even with overwrite. Date identity uses `whereDate`, so SQLite's midnight date casting does not create duplicate rerun inserts. Other model rows are never rewritten.

Evaluation reads exact target-date unit evidence under each forecast's SAVED current basis and method, not today's feature flag. Latest-ID ownership precedes finite validity; zero and negative finite actuals are valid. Saved direction threshold governs actual classification. Known v1 may default missing basis to observed and missing threshold to 0.15. Known v1/v2 may default absent method to unit_statistics_v1. V2 still requires basis and threshold. Other models require all three fields explicitly. Invalid present values, unknown bases, unsupported methods and non-finite/negative thresholds skip as unsupported provenance. Unknown saved directions also skip.

Round actual-minus-current to four decimals before the inclusive threshold check. New evaluations add same_basis_v1, actual provenance, unchanged-price absolute-error baseline, saved forecast/actual categories, and correct/wrong_way/missed_move/false_move. Completed evaluations are never relabelled. ID-ordered chunks visit out-of-date-order rows without skips. Dry run computes identical results without saves.

The report reads stored completed medians only in bounded 100-row issue-date/ID pages. Model/horizon default to current config; optional model/date-window selectors retain separate compatibility groups. Groups include model, horizon, term, saved basis, unit/evaluation methods and threshold. Actual metadata must match exact target date, segment, metric and positive sample count. Missing legacy actual provenance and incompatible methods have named skips. Valid n, unique issue days, four outcomes and correct rate accompany the unchanged-direction baseline. Empty denominators have no percentage. No statistics refetch, historical rewrite, report write, new schedule, MAE claim or model-superiority claim is part of that command.

## Public presentation

Current page, listing teasers and article accept only the configured model and expected current-basis provenance. Missing/old rows show unavailable; a model switch does not invent an initial forecast. Caches already include model and horizon, so this version change invalidates prepared forecast payloads.

Public qualified outlooks use saved direction through `ForecastOutlook`, never legacy timing advice. Slight moves mean approximately unchanged; unknown means unavailable. All three terms must be complete and agree before an overall direction; partial coverage is incomplete and conflicting categories are mixed. Show saved dates/horizon and `Suuntaa antava arvio, ei varma hintakehitys.` History-count confidence is `Ennusteen tietopohja`, not measured accuracy. p20/p80 are offer-price quantiles, not uncertainty bounds.

The page omits obsolete financial diagnostics and futures facts, including JSON-LD. It explains equal weights, completed exact-horizon history, separate term/quantile fits, overlapping periods and turning-point lag. Crossing quantiles remain unchanged and have an explicit warning that they are not an ordered distribution. The article's existing ordered-distribution guard remains; median teasers can remain valid independently. Preferred Sources and layout stay in place.

The public median history is independent of forecast generation eligibility. It reads complete offered-price unit history, retaining observed evidence followed by canonical daily calculations and basis metadata. Model versions must not truncate it.

## Operations and release

Commands:
```bash
php artisan forecasting:run-fixed-contracts --as-of=YYYY-MM-DD --horizon=30 --require-freshness --dry-run
php artisan forecasting:evaluate-fixed-contracts --as-of=YYYY-MM-DD --dry-run
php artisan forecasting:report-fixed-contracts
```

Do not run these against production without the required inspection/operation approval. Dry generation shows pair count/minimum, mean, both endpoint bounds and current-basis pair count. Non-dry generation can write statistics during freshness recovery.

Schedules stay 07:30 generation with --require-freshness and 07:45 evaluation, Europe/Helsinki. Forecast freshness requires the full current contract checkpoint, relevant 6/12/24 household contract observation/publication evidence, current expected-basis unit statistics and publication-before-statistics order. It does NOT query EEX checkpoint/presence/age. Retail-premium freshness retains all EEX checks. If publication order alone fails, only non-dry generation can overwrite current-date statistics from all active contracts and rerun the complete gate. Other failures stay closed. No new deployment-time generation or schedule is added.

Manager's read-only preflight found no model/minimum/threshold/horizon environment pins. Defaults should activate through one approved Git deployment, including automatic nullable migration. Recheck if delayed; any variable mutation needs explicit approval. The model switch hides old public forecasts until new rows exist. Separately approve an exact-context first generation, including its possible statistics recovery. No release, production mutation, commit or push was executed in this task.
