# Decisions

- 2026-05-23: Implemented the first production backend as PHP services under `laravel/app/Services/PriceForecasting/`, keeping the frontend/UI out of scope.
- 2026-05-23: Persist forecasts in a single `fixed_contract_price_forecasts` table instead of creating separate price-index/hedge-cost tables first. The forecast row stores source hedge cost, current retail price, normal premium, fair price, direction, model version, and JSON metadata so historical forecasts remain auditable.
- 2026-05-23: `forecasting:run-fixed-contracts` skips existing same forecast date/horizon/duration/quantile/model-version rows unless `--overwrite` is supplied. This protects historical forecast records from accidental rewrites.
- 2026-05-23: Added `forecasting:evaluate-fixed-contracts` to fill realized target-date price, actual change, forecast error, absolute error, actual direction, and direction correctness once the target-date `contract_price_daily_statistics` row exists.
- 2026-05-23: Ported v1 model semantics from the local Python exploration: FI EEX Base futures, latest `trade_date < forecast_date`, month -> quarter -> year fallback, next-full-calendar-month delivery window, EUR/MWh to c/kWh including VAT, EWMA retail premium, and conservative gap closure.
- 2026-05-23: Forecast generation skips rows with missing futures delivery coverage or too few complete history observations instead of persisting low-quality/stale forecasts.
- 2026-05-23: Scheduled `forecasting:run-fixed-contracts` daily at 07:30 Europe/Helsinki, after the 06:00 contract import/statistics job. Scheduled `forecasting:evaluate-fixed-contracts` daily at 07:45 so matured forecasts are evaluated once fresh target-date statistics should exist.
