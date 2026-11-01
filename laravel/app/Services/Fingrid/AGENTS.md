# Fingrid open data

Collects Fingrid open-data series (https://data.fingrid.fi, CC BY 4.0) as hourly values for the
monthly market review. Nothing public reads these rows yet. Task context:
`../../../../tasks/monthly-market-review/` (data inventory G3–G5b).

## Files

- `FingridOpenDataService.php` – read-only API client: pagination (`pageSize` 20000), one request
  per `fingrid.request_delay_seconds` (default 2.5 s), retries on HTTP 429, 5xx and connection errors.
- `FingridHourlyImporter.php` – hourly mean per dataset + series key, upserted into
  `fingrid_hourly_values`.
- `../../Console/Commands/FetchFingridData.php` – `php artisan fingrid:fetch`.
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
- Datahub (358, 361, 364) arrives about 4 days late and is corrected for 11 days, so the daily run
  fetches the last 16 days for them and the last 3 days for real-time series.

## Commands

```bash
php artisan fingrid:fetch                                  # daily window (scheduled 10:20 Helsinki)
php artisan fingrid:fetch --dataset=188 --dataset=75       # only some datasets
php artisan fingrid:fetch --from=2025-10-01 --to=2026-10-31 # backfill in 7-day pieces
```

A failed dataset does not stop the others; the run then exits 1, and the scheduler failure
listener logs it. Logs contain the dataset ID and exception class only (no URL or key).
