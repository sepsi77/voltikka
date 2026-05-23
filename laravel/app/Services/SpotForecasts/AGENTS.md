# AGENTS.md

Context for spot-price forecast services.

## Purpose

This subtree handles third-party hourly Finnish spot-price forecasts for display on `/spot-price`. It is an MVP bridge, not Voltikka's own forecasting model.

## Primary files

- `NordpoolPredictFiService.php` fetches and normalizes `https://raw.githubusercontent.com/vividfog/nordpool-predict-fi/main/deploy/prediction.json`.
- `../../Models/SpotPriceForecast.php` stores imported forecast points in `spot_price_forecasts`.
- `../../Console/Commands/FetchSpotForecast.php` runs the import via `php artisan spot:fetch-forecast`.
- `../../../resources/views/livewire/spot-price.blade.php` displays forecasts with attribution.

## Important semantics

- Upstream `prediction.json` is an array of `[timestamp_ms, price_cents_per_kWh_with_VAT]` points.
- Store the VAT-included source price in `spot_price_forecasts.price_with_tax`; derive VAT0 values when needed.
- Do not insert forecasts into `spot_prices_hour` or `spot_prices_quarter`. Those tables are official actual-price tables and use `(region, timestamp)` uniqueness with `insertOrIgnore()` imports.
- Forecast rows are keyed by source + region + timestamp and represent the latest imported value for that source/timestamp. This MVP does not preserve forecast-run snapshots.
- The public UI must keep forecast rows separate from official ENTSO-E/Nord Pool prices and cite `nordpool-predict-fi` by vividfog with the GitHub URL.
- Forecast rows should not drive current-price, CSV export, spot averages, contract statistics, or social media video generation unless a future product decision explicitly changes that behavior.
