# Fingrid open data

Collects Fingrid open-data series (https://data.fingrid.fi, CC BY 4.0) as hourly values for the
monthly market review, and vintages of rolling Fingrid forecasts for own spot price models.
Nothing public reads these rows yet. Task context: `../../../../market-review/`
(data inventory G3–G5b) and `../../../../tasks/own-forecast-model-data/`.

## Files

- `FingridOpenDataService.php` – read-only API client: `recordPages()` records every page of one
  range on the import payload, following `nextPage` (`pageSize` 20000); `page()` reads a recorded
  body. One request per `fingrid.request_delay_seconds` (default 2.5 s), retries on HTTP 429, 5xx
  and connection errors.
- `FingridHourlyImporter.php` – hourly mean per dataset + series key, upserted into
  `fingrid_hourly_values`.
- `FingridForecastVintageImporter.php` – `series()` builds the hourly series; `store()` inserts one
  append-only `hourly_forecast_runs` row per distinct forecast vintage (source `fingrid`, product =
  dataset ID, point '').
- `../../Console/Commands/FetchFingridData.php` – `php artisan fingrid:fetch`.
- `../../Console/Commands/FetchFingridForecastVintages.php` –
  `php artisan fingrid:fetch-forecast-vintages`.
- `../../../config/fingrid.php` – the dataset list and limits. The key is `FINGRID_API_KEY`.

## Rules

- Fingrid allows one request every 2 seconds and 10,000 requests per 24 h per key. Keep the delay.
- The importer stores the **mean** of the samples in each UTC hour. This is correct only for power
  series (MW, MWh/h) and for series that are already hourly (Datahub kWh). Do not add a
  15-minute energy dataset (MWh per 15 min, e.g. 403/404/406): its hour needs a sum.
- `sample_count` is the number of samples in the hour (3-min data: 20, 15-min: 4, 1-min: 60).
  Use it to find incomplete hours; the importer does not fill gaps.
- Rows are upserted on (dataset_id, series_key, timestamp), because Fingrid corrects data.
  A frozen monthly figure must therefore be copied to the review snapshot when it is published;
  do not cite a live row as a frozen value.
- `utc_datetime` is UTC text without a cast (the app timezone can be Europe/Helsinki), the same
  rule as `spot_prices_hour`. `timestamp` is the UTC hour start in unix seconds.
- `series_key` is '' for a plain series. Datahub rows use their breakdown attributes from
  `additionalJson`, sorted, e.g. `UserGroup=BE02` or `CustomerType=AB02`. `point_count` keeps the
  number of accounting points (`Count`).
- Sign rules: flows 55 (EE), 60 (SE1), 61 (SE3) are positive for transmission **from** Finland
  (export). Net import 194 is also negative for import: on 2026-10-29..11-01 consumption (124) −
  production (74) was +1,320 MW and 194 was −1,323 MW.
- The official day-ahead capacity datasets (24–27, 112, 115) hold only zeros or nothing since the
  flow-based market coupling (checked 2026-11-01), so they are not collected. The real day-ahead
  limit is JAO `maxBorderFlow` (not collected yet).
- Rolling forecasts (config `forecast_vintages`, now only 245) never go to
  `fingrid_hourly_values`: Fingrid overwrites them every 15 minutes and an upsert would keep only
  the latest revision, so a model could not be trained on the forecast that existed at decision
  time. Dataset 246 (daily wind forecast) stays in `fingrid_hourly_values`.
- Dataset 245 (https://data.fingrid.fi/en/datasets/245): wind power generation forecast for the
  next 72 hours, updated every 15 minutes, 15-minute values in MWh/h (hourly before
  2023-05-31). The page names no issue-time attribute, so `issued_at` is null and `fetched_at` is
  the vintage time. The rows carry only `startTime`, `endTime` and `value` as far as known; check
  `additionalJson` live once.
- A vintage starts at the **next full UTC hour** after the fetch (`first_timestamp`), because
  some quarters of the current hour are already past and their values are no longer forecasts.
  One request covers 72 hours from there. The value of an hour (`wind_power_mw`) is the mean of
  its four quarters (:00, :15, :30, :45). An hour with fewer than four numeric quarters is `null`,
  so a partial hour is never mixed with a full one; trailing null hours are trimmed. A response
  without any complete hour fails the dataset (for example a change to hourly resolution).
- `payload_hash` is SHA-256 of `json_encode([first_timestamp, values])`: a rerun in the same hour
  with the same values adds no row; a revised forecast adds one.
- Datahub (358, 361, 364) arrives about 4 days late and is corrected for 11 days, so the daily run
  fetches the last 16 days for them and the last 3 days for real-time series.

## Staged import (fetch, then store)

Both commands follow `../ImportPayload/AGENTS.md` (source directory `fingrid`). The key travels in
the `x-api-key` header, which a payload never records (tests prove the key is not in the file).

- `fingrid:fetch`: unit `{dataset, window, page}`. Header fact `current_hour` (the window end).
  The store aggregates and upserts each dataset window with the header `fetched_at`: one
  transaction for the daily run, one per 7-day window for a backfill (`--from`). A window with a
  failed page is left out; failed datasets make the store exit 1 with `fetch_incomplete` after
  the commit (S10).
- `fingrid:fetch-forecast-vintages`: unit `{dataset, page}`. Header facts `first_hour` and
  `hours` (horizon per dataset). The store inserts each vintage with the header `fetched_at` as
  its vintage time (`issued_at` stays null), so a replay never moves a vintage past the S model's
  `fetched_at <= cut` filter. A response without a complete hour exits 1 with `no_complete_hour`
  (step store).
- Not date-bound (S9), but a `--from-payload` store of an older payload is refused
  (`payload_invalid`, `superseded`, no write) while a newer usable payload of the same options
  exists (shared rule, `../ImportPayload/AGENTS.md`). The newest payload of a failed store still
  replays. Without a newer file, `fingrid:fetch` upserts the payload's values and fetch time; the
  vintage command inserts its vintage only when that payload hash is not stored yet.
- `--dataset` IDs are normalized (`intval`) and deduplicated before planning in both commands
  (review PR #31): a repeated ID (`--dataset=188 --dataset=0188`) shared one unit key, so its
  pages merged into one window (double samples) and a failed copy hid the answered one. Tests
  `test_a_repeated_dataset_option_fetches_the_dataset_once` in both command tests.

## Commands

```bash
php artisan fingrid:fetch                                  # daily window (scheduled 10:20 Helsinki)
php artisan fingrid:fetch --dataset=188 --dataset=75       # only some datasets
php artisan fingrid:fetch --from=2025-10-01 --to=2026-10-31 # backfill in 7-day pieces
php artisan fingrid:fetch-forecast-vintages                # one 245 vintage (1 request)
php artisan fingrid:fetch --fetch-only                     # payload only, prints its reference
php artisan fingrid:fetch --from-payload=<path>            # store a saved payload, no API call
```

The vintage command has no backfill: past vintages cannot be fetched again. Cost: one request
(288 rows, one page) per run. Hourly runs are 24 requests per day of the 10,000 per key and add
about 8,760 rows (about 6 MB) per year.

A failed dataset does not stop the others; the run then exits 1, and the last stdout line is the
closed JSON result (`fetch_incomplete`). Logs contain the dataset ID and exception class only (no
URL or key).
