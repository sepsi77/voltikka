# Price forecasting services

Production backend for fixed-term contract price forecasts. No public frontend is implemented yet.

Primary files:
- `FixedTermHedgeCostService.php` — builds FI EEX futures-implied hedge costs for 6/12/24 month delivery windows.
- `FixedTermPriceForecastService.php` — creates EWMA retail-premium / gap-closure forecast rows from daily retail statistics and hedge costs.
- `FixedTermForecastEvaluationService.php` — fills realized actuals/errors for matured stored forecasts.
- `../../Models/FixedContractPriceForecast.php` — persisted forecasts and later evaluation metrics.
- `../../Console/Commands/RunFixedContractPriceForecasts.php` — Artisan command `forecasting:run-fixed-contracts`.
- `../../Console/Commands/EvaluateFixedContractPriceForecasts.php` — Artisan command `forecasting:evaluate-fixed-contracts`.
- `../../../config/price_forecasting.php` — model constants and defaults.

Model v1 conventions:
- Forecast scope is fixed-term contracts only, durations 6/12/24 months, market-level p20/median/p80 energy-price indices.
- Retail targets come from `contract_price_daily_statistics` where `metric_key = energy_price`, `consumption_kwh is null`, and segment keys are `fixed_term_6`, `fixed_term_12`, `fixed_term_24`.
- Futures use FI EEX Base instruments and strictly align with `latest trade_date < forecast_date` to avoid same-day settlement leakage.
- Delivery windows start at the next full calendar month after the forecast date.
- Futures fallback order is month -> quarter -> year. Missing delivery months prevent a forecast for that row instead of silently using stale data.
- Futures settlement prices are converted from EUR/MWh to consumer c/kWh including VAT using `settlement_price / 10 * config('price_forecasting.fixed_term.vat_multiplier')`.
- The model estimates normal retail premium with EWMA, then forecasts partial 30-day gap closure: `expected_change = lambda * (hedge_cost + normal_premium - current_retail_price)`.
- Direction labels deliberately use a threshold; small moves are `slightly_rising` / `slightly_falling` and map to neutral consumer signal.

Operational commands:
```bash
php artisan forecasting:run-fixed-contracts --as-of=today --horizon=30
php artisan forecasting:run-fixed-contracts --as-of=2026-05-23 --dry-run
php artisan forecasting:evaluate-fixed-contracts --as-of=today
```

Schedule:
- `routes/console.php` runs `forecasting:run-fixed-contracts` daily at 07:30 Europe/Helsinki, after the 06:00 contract import/statistics job.
- `routes/console.php` runs `forecasting:evaluate-fixed-contracts` daily at 07:45 Europe/Helsinki.

Stored forecasts are intentionally immutable-ish by default: `forecasting:run-fixed-contracts` skips an existing same date/horizon/duration/quantile/model-version row unless `--overwrite` is passed. This protects historical forecast/audit records.
