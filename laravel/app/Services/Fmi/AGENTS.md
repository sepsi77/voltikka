# FMI open data

Collects Finnish Meteorological Institute open data (https://opendata.fmi.fi/wfs, no key,
CC BY 4.0, credit "Ilmatieteen laitos" / "Finnish Meteorological Institute") as inputs for own
spot price models. Nothing public reads these rows yet. Task context:
`../../../../tasks/own-forecast-model-data/` (spec.md, decisions.md).

## Files

- `FmiOpenDataService.php` – read-only WFS client. Builds the query by hand, because `fmisid`
  and `latlon` repeat. One request per `fmi.request_delay_seconds` (default 1 s); retries
  HTTP 429, 5xx and connection errors. An FMI exception report (HTTP 400 with `ExceptionText`)
  becomes `FmiRequestException` with the first ExceptionText only: FMI repeats the full request
  URI in a second ExceptionText ("URI: ..."), which is never used.
- `FmiMultiPointCoverageParser.php` – parses `multipointcoverage` XML with DOM: no DTD load, no
  entity substitution, `LIBXML_NONET`, and any DOCTYPE is rejected. Output is columnar per
  position (timestamps + one list per field; `NaN` → null), because one PHP array per row used
  77 MB for one radiation week (7 stations × 10,080 minutes); columnar uses about 41 MB peak.
- `FmiObservationImporter.php` – upserts `fmi_hourly_observations`.
- `FmiForecastImporter.php` – append-only `hourly_forecast_runs` rows (source `fmi`).
- `../../Console/Commands/FetchFmiData.php` – `php artisan fmi:fetch`.
- `../../../config/fmi.php` – stored queries, stations, points, limits.

## Time-stamp convention (verified 2026-11-01)

`timestamp` / `utc_datetime` in `fmi_hourly_observations` is the **start** of the UTC hour that
the value describes, like `spot_prices_hour`.

- **FMI labels an aggregate with the END of its period.** Evidence: Helsinki Kumpula 101004 on
  2026-10-31, `TA_PT1H_AVG`/`MAX`/`MIN` compared with 10-minute `t2m`. At label 08:00 FMI gave
  7.2/7.3/7.1 °C; the 10-minute values in (07:00, 08:00] give mean 7.20, max 7.3, min 7.1, but
  [08:00, 09:00) gives mean 7.47 and min 7.3 (higher than FMI's minimum, so impossible). All 11
  hours 04:00–14:00 matched the ending window. So an hourly value at label T is stored at T − 1 h.
  Thus the decisions.md note "Kumpula 4.2 °C at 2023-01-01 00Z" is the hour 2022-12-31 23:00–24:00;
  the stored row 2023-01-01 00:00 is 3.9 °C (label 01:00).
- **`GLOB_1MIN` at T is taken as the minute ending at T** (same "avg PT1M" metadata form as
  "avg PT1H"; FMI documentation does not state it). The hour [H, H+1) is the mean of labels
  H+1 min … H+60 min. Data could not prove the minute direction: a clear-day noon-symmetry fit
  (Jokioinen/Utö/Kumpula/Vantaa/Sotkamo, June 2026 and July 2025) scattered by about ±150 s, so it
  cannot separate a ±30 s label offset. If the convention were start-of-minute, one minute of 60
  would belong to the next hour: at most about 1/60 of the hour-to-hour change.
- Request windows follow this: hourly `starttime = S + 1 h`, `endtime = E` (FMI includes both
  ends) gives hours [S, E); radiation `starttime = S + 1 min`, `endtime = E`.
- **Forecast values are instants at the valid time** (`temperature`, `windspeedms`,
  `totalcloudcover`, ECMWF `Temperature`/`WindUMS`/`WindVMS`). They are not shifted: index i of a
  `hourly_forecast_runs` array is the value at `first_timestamp + i h`. A model that pairs them
  with observations must know that an observation row at H is the mean of [H, H+1).

## Observations (`fmi_hourly_observations`, upsert)

- `TA_PT1H_AVG` (°C) and `WS_PT1H_AVG` (m/s, 10 m) at the 29 stations of the reference model,
  stored query `fmi::observations::weather::hourly::multipointcoverage`, all stations in one
  request, at most 744 hours per request (FMI rejects more). One 744 h request for 29 stations:
  1.6 MB, about 15 s. `sample_count` = 1 (one FMI hourly value).
- `GLOB_PT1H_AVG` (W/m², global horizontal) = hourly mean of `GLOB_1MIN` at the 7 FMI radiation
  stations, `fmi::observations::radiation::multipointcoverage`, at most 168 hours per request.
  One 168 h request for 7 stations: 4.8 MB, about 42 s. `sample_count` = minutes with a value;
  gaps are not filled, so use `sample_count` to find incomplete hours. Night values are slightly
  negative (pyranometer offset, down to about −7 W/m²); they are stored as measured.
- Rows map to stations by the position of `gml:Point gml:id="point-<fmisid>"`.
- 101268 Kristiinankaupunki majakka has hourly data in 2023–mid-2025, none on 2026-03-01 and later
  (checked 2026-11-01). It stays in the list for history and still has an edited forecast.
- FMI corrects observations, so the daily run re-reads the last 72 hours.

## Forecast vintages (`hourly_forecast_runs`, source `fmi`, append-only)

| Product | Stored query | Points | FMI parameter → value key |
|---|---|---|---|
| `edited` | `fmi::forecast::edited::weather::scandinavia::point::multipointcoverage` | 29 stations (`fmisid`, point key = FMISID string) + 8 neighbour points (`latlon`, point key e.g. `ee01_hiiumaa`) | `temperature` → `temperature_2m` (°C), `windspeedms` → `wind_speed_10m` (m/s), `totalcloudcover` → `total_cloud_cover` (%) |
| `ecmwf` | `ecmwf::forecast::surface::point::multipointcoverage` | `de02_niedersachsen` (latlon) | `Temperature` → `temperature_2m`, `WindUMS` → `wind_u_10m`, `WindVMS` → `wind_v_10m`; computed `wind_speed_10m` = √(u² + v²), 2 decimals |

- The value keys are the Open-Meteo names for the same quantity, so a reader can mix sources.
- One request per product, `timestep=60`, from the current UTC hour to +240 h. The edited
  forecast reaches about D+9.5 (229 hours on 2026-11-01 12 UTC); ECMWF's last 12 hours were NaN.
  Hours where every variable is null are trimmed at both ends.
- `issued_at` = `om:resultTime` (edited: updated about every hour at :45 UTC; ECMWF: the
  production time of the model run, e.g. 08:02 UTC for the 00 UTC run).
- Response points are matched to configured points by coordinates (tolerance 0.01°); the response
  order is not the request order, and forecast points carry geoids, not FMISIDs. Station
  coordinates in `config/fmi.php` are FMI's own (2026-11-01). If FMI moves a station by more than
  0.01°, its forecast point goes missing and the run fails until the config is updated.
- `grid_latitude/longitude` hold the position FMI returns: the requested point (FMI interpolates),
  not a model grid cell.
- `payload_hash` = SHA-256 of `json_encode([first_timestamp, values])`. A same-hour rerun of an
  unchanged forecast inserts nothing; a fetch in a later hour always gives a new row, because the
  first hour moves.
- A configured point without values fails the product (exit 1); the other points are stored.
- The edited forecast has no data at DE02 ("No data available"), so DE02 uses FMI's ECMWF copy;
  its `WindSpeedMS`, cloud and radiation parameters are NaN.

## Rules

- Limits: 20,000 download requests per day, 600 requests per 5 minutes. Keep the delay.
- Logs contain the part, the window, the exception class and, for `FmiRequestException` only, its
  URL-free message. Never log other exception messages: Laravel HTTP exceptions contain the URL.
- A failed piece (one request) does not stop the other pieces or parts; the run then exits 1.
- Forecasts have no backfill: FMI serves only the current forecast.

## Commands

```bash
php artisan fmi:fetch                                   # observed + radiation (last 72 h) + one forecast vintage
php artisan fmi:fetch --only=forecast                   # forecast vintage only
php artisan fmi:fetch --only=observed --only=radiation  # observation parts only
php artisan fmi:fetch --from=2023-01-01 --to=2023-01-31 # backfill: 744 h / 168 h pieces
```

Backfill 2023-01-01 → 2026-11-01 (33,600 hours): about 46 hourly requests (about 12 min) and
200 radiation requests (about 2.5 h, about 950 MB downloaded).
