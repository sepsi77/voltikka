# Price forecasting services

Production fixed-term contract price forecasts and the public `/sahkosopimus/sahkon-hintaennuste` page.

Primary files:
- `FixedTermHedgeCostService.php` — builds FI EEX futures-implied hedge costs for 6/12/24 month delivery windows.
- `FixedTermPriceForecastService.php` — creates EWMA retail-premium / gap-closure forecast rows from daily retail statistics and hedge costs.
- `FixedTermForecastEvaluationService.php` — fills realized actuals/errors for matured stored forecasts.
- `../../Models/FixedContractPriceForecast.php` — persisted forecasts and later evaluation metrics.
- `../../Console/Commands/RunFixedContractPriceForecasts.php` — Artisan command `forecasting:run-fixed-contracts`.
- `../../Console/Commands/EvaluateFixedContractPriceForecasts.php` — Artisan command `forecasting:evaluate-fixed-contracts`.
- `../../Livewire/FixedContractPriceForecast.php` and `../../../resources/views/livewire/fixed-contract-price-forecast.blade.php` — public forecast page and provenance disclosure.
- `../../../config/price_forecasting.php` — model constants and defaults.

Model v2 conventions:
- Forecast scope is fixed-term contracts only, durations 6/12/24 months, market-level p20/median/p80 energy-price indices.
- Retail targets come from `contract_price_daily_statistics` where `metric_key = energy_price`, `consumption_kwh is null`, and segment keys are `fixed_term_6`, `fixed_term_12`, `fixed_term_24`.
- In canonical mode, the current retail row must have `pricing_basis = canonical_calculation`. There is no fallback to `observed_seller_data`; a missing canonical current row skips that forecast.
- In feature-off mode, the current row must have `pricing_basis = observed_seller_data`.
- Historical EWMA evidence uses observed seller rows strictly before the forecast date. It deduplicates each date+basis and stores basis counts and source date bounds in `source_metadata`.
- `source_metadata` records the current retail basis/date/segment/metric separately from historical evidence and futures coverage. Model v2 is the provenance boundary; v1 rows remain immutable prior records.
- Futures use FI EEX Base instruments and strictly align with `latest trade_date < forecast_date` to avoid same-day settlement leakage.
- Delivery windows start at the next full calendar month after the forecast date.
- Futures fallback order is month -> quarter -> year. Missing delivery months prevent a forecast for that row instead of silently using stale data.
- Futures settlement prices are converted from EUR/MWh to consumer c/kWh including VAT using `settlement_price / 10 * config('price_forecasting.fixed_term.vat_multiplier')`.
- The model estimates normal retail premium with EWMA, then forecasts partial 30-day gap closure: `expected_change = lambda * (hedge_cost + normal_premium - current_retail_price)`.
- Direction labels deliberately use a threshold; small moves are `slightly_rising` / `slightly_falling` and map to neutral consumer signal.
- Matured actuals remain historical `observed_seller_data`; evaluation writes their basis/date/segment/metric into the existing `source_metadata` without changing evaluation meaning.
- Public current-forecast queries require the configured current model version and the current-mode basis metadata. Canonical mode therefore hides old, missing-provenance, and observed-current rows and shows the existing unavailable state when no eligible row exists. Feature-off accepts current-model observed-basis rows.
- The public "Mediaanihinta viime kuukausina" section is the daily offered-price timeline, not persisted forecast-run history. `FixedContractPriceForecast::historySeries()` reads all non-null medians for the 6/12/24-month `energy_price` segments from `contract_price_daily_statistics` with null consumption. It keeps older `observed_seller_data` points and appends canonical daily calculations after rollout; canonical wins if both bases exist on one date. The payload keeps each point's basis so the page can explain the provenance change. Model-version and futures-coverage filters apply only to current forecasts and cannot truncate or freeze this history.

Operational commands:
```bash
php artisan forecasting:run-fixed-contracts --as-of=today --horizon=30
php artisan forecasting:run-fixed-contracts --as-of=2026-05-23 --dry-run
php artisan forecasting:evaluate-fixed-contracts --as-of=today
```

Schedule:
- `routes/console.php` runs `futures:fetch-eex` at 04:00 Europe/Helsinki so previous trading-day FI settlements are available before forecasts; the full polite-throttled EEX import can take around 90-110 minutes.
- `routes/console.php` runs `forecasting:run-fixed-contracts --require-freshness` daily at 07:30 Europe/Helsinki, after the 06:00 contract import/statistics job. The opt-in gate runs before the forecast builder. It requires same-date ready full contract/EEX checkpoints. From the checkpoint active IDs, it uses current facts and `ContractStatisticsSegmentClassifier` in the request-scoped `PricingMode` basis to select household-market `fixed_term_6`, `fixed_term_12`, and `fixed_term_24` contracts. Only that relevant set must have exactly one pointed source-observation episode and a current publication before statistics started. Unrelated Spot, Hybrid, OpenEnded, business-only, and other segments do not block this forecast. The gate still requires at least one current 6/12/24 `energy_price` statistic in that basis, current-run prior-date FI Base proof, and recent FI Base data in the database. Each duration is independent. A blocked or zero-output gated run fails and writes no forecasts. Manual runs without the flag keep their prior behavior.
- `routes/console.php` runs `forecasting:evaluate-fixed-contracts` daily at 07:45 Europe/Helsinki.

Stored forecasts are intentionally immutable-ish by default: `forecasting:run-fixed-contracts` skips an existing same date/horizon/duration/quantile/model-version row unless `--overwrite` is passed. This protects historical forecast/audit records.
