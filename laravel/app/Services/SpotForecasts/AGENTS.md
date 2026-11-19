# AGENTS.md

Context for spot-price forecast services.

## Purpose

This subtree handles third-party hourly Finnish spot-price forecasts for display on `/spot-price`. It is an MVP bridge, not Voltikka's own forecasting model.

## Primary files

- `NordpoolPredictFiService.php` fetches `https://raw.githubusercontent.com/vividfog/nordpool-predict-fi/main/deploy/prediction.json` as a raw response (`fetchFeed()`) and normalizes a raw body (`parseFeed(body, fetchedAt, feedUrl)`).
- `../../Models/SpotPriceForecast.php` stores imported forecast points in `spot_price_forecasts`.
- `../../Console/Commands/FetchSpotForecast.php` runs the import via `php artisan spot:fetch-forecast`. It is the reference staged import (`../ImportPayload/AGENTS.md`): the fetch step saves the one feed response in a payload file (source directory `nordpool-predict-fi`, unit `{"feed":"prediction"}`); the store step parses that file inside one transaction, and `Cache::forget('spot_price:forecast:nordpool_predict_fi:v1')` runs after the commit. `--fetch-only` and `--from-payload` work as for every staged import.
- `../../../resources/views/livewire/spot-price.blade.php` displays forecasts with attribution.

## Important semantics

- Upstream `prediction.json` is an array of `[timestamp_ms, price_cents_per_kWh_with_VAT]` points.
- Store the VAT-included source price in `spot_price_forecasts.price_with_tax`; derive VAT0 values when needed.
- Do not insert forecasts into `spot_prices_hour` or `spot_prices_quarter`. Those tables are official actual-price tables and use `(region, timestamp)` uniqueness with `insertOrIgnore()` imports.
- Forecast rows are keyed by source + region + timestamp and represent the value of the latest fetched feed for that source/timestamp (by `fetched_at`, not by store order). They are for display only.
- `fetched_at` on both tables is the payload header's fetch time, not the store time (S5). `feed_hash` is the SHA-256 of the exact body bytes as fetched.
- Not date-bound (S9): an older payload from the same day is accepted. Its vintage goes into `spot_price_forecast_runs` as always (unless that feed hash is already stored), but the display upsert is skipped when a newer feed is already stored for the same source and region: a run row or a display row with a later `fetched_at` (an unchanged feed fetched again adds no run row but refreshes the display rows' `fetched_at`). The check runs inside the store transaction. A skipped display keeps the page cache and reports `display_rows: 0` in the result counts. Reason: an older replay overwrote newer predictions while the page still showed the newer update time (review PRC-002). Tests: `FetchSpotForecastCommandTest::test_an_older_same_day_payload_archives_its_run_and_keeps_the_newer_display` and `test_a_replay_older_than_a_refetched_unchanged_feed_keeps_the_display`.
- Failures are logged once, without exception messages: `Import failed: spot:fetch-forecast` with the step, the closed error key, the exception class and, for a store `QueryException`, SQLSTATE, driver code and table.
- Each distinct feed is also kept as one append-only row in `spot_price_forecast_runs` (`../../Models/SpotPriceForecastRun.php`), written in the same transaction as the upsert. A unique (source, feed_hash) stores an unchanged feed once; `points` holds `[[unix_seconds_utc, c/kWh incl. VAT], ...]`. Reason: the monthly market review must score a forecast made before delivery against the outcome, and the upsert table loses every earlier value. Never update or delete run rows. `first/last_utc_datetime` are UTC text without a datetime cast.
- The public UI must keep forecast rows separate from official ENTSO-E/Nord Pool prices and cite `nordpool-predict-fi` by vividfog with the GitHub URL.
- Forecast rows should not drive current-price, CSV export, spot averages, contract statistics, or social media video generation unless a future product decision explicitly changes that behavior.
