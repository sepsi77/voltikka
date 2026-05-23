# Decisions

- Start with the public `https://raw.githubusercontent.com/vividfog/nordpool-predict-fi/main/deploy/prediction.json` feed instead of building a native model.
- Treat this as a third-party forecast source. Voltikka must display attribution and must not blend predictions into official spot-price actuals without labelling.
- The upstream `prediction.json` format is an array of `[timestamp_ms, price_cents_per_kWh_with_VAT]` points, hourly UTC timestamps. Store the VAT-included source value and derive VAT0 values for internal comparisons when needed.
- Store forecasts in a dedicated table, not `spot_prices_hour`, because actual spot rows use `(region, timestamp)` uniqueness and `insertOrIgnore()`; forecast rows must never block later official ENTSO-E imports.
- MVP stores only the latest forecast value per source/region/timestamp via an upsert. It does not preserve historical forecast-run snapshots yet.
- `spot:fetch-forecast` is scheduled every six hours and can also be run manually. `php artisan schedule:list` reports it as `0 */6 * * *`.
- `/spot-price` displays imported forecasts only after the latest future official actual price and keeps them in a separate section with source attribution.
- Verification run: `cd laravel && php artisan test --filter=FetchSpotForecastCommandTest` passed.
- Broader `php artisan test --filter=SpotPriceComponentTest` still has existing stale-copy/data-shape failures unrelated to this change (`Seuraa sähkön pörssihinnan kehitystä`, `Edullisimmat tunnit`, `vs kallein aika`, weekly chart dataset count). The explorer had already flagged stale assertions in this file.
