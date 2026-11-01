# Open-Meteo weather

Collects weather for the monthly market review (data inventory G6 and G22 in
`../../../../tasks/monthly-market-review/`): observed daily weather from the ERA5 archive, and
append-only outlook vintages from the ECMWF ensemble, EC46 and SEAS5. Nothing public reads these
rows yet.

Licence and terms: Open-Meteo data is CC BY 4.0. A published figure must credit
"Weather data by Open-Meteo.com" with a link to https://open-meteo.com/ (ERA5 is also
Copernicus/ECMWF data). The free API is for non-commercial use only. User decision 2026-11-01:
the site has no income, so the use is treated as non-commercial. Check this again before the site
gets any income (ads, affiliate links, paid features); then a paid plan is needed.

## Files

- `OpenMeteoService.php` – read-only client (no key): archive, ensemble/seasonal outlooks, and
  best-effort model-run metadata. Waits between calls; retries 429, 5xx and connection errors.
- `OpenMeteoWeatherImporter.php` – upserts observed days; stores one outlook row per distinct
  payload; checks every response unit.
- `../../Console/Commands/FetchWeather.php` – `php artisan weather:fetch`.
- `../../../config/open_meteo.php` – points, weights, variables, units, products and limits.

## Tables

- `weather_daily_observations` – one row per (point, model, date). Columns are the Open-Meteo
  daily variable names: temperature 2 m mean/min/max (°C), wind 10 m and 100 m mean/max (m/s),
  precipitation (mm), snowfall (cm) and snowfall water equivalent (mm). Upserted.
- `weather_outlook_runs` – append-only, unique (product, point, payload_hash). `members` is
  `{"variable": [[day values of the control run], [member01], ..., [member50]]}`: index 0 is the
  control run (the response key without a suffix), index n is `<variable>_memberNN`. `first_date`
  is the local day of index 0 of every series; `day_count` and `member_count` give the shape.
  `null` means no value (the last day of a run is often null). `model_run_at` is the
  initialisation time from the unofficial `static/meta.json` file, read once per product before the
  point loop, or null. The hash covers model, first date and members, so a rerun of the same
  model run adds no row.

## Rules

- Days are Europe/Helsinki local days for every point, also in Norway and Sweden (the API
  `timezone` parameter), so day and month boundaries match the review.
- Observed data uses only `models=era5`: one consistent reanalysis from 1940, so the 1991–2020
  normal and the observed month come from the same model. The default `best_match` mixes other
  models and differed by 3 °C from ERA5 in Helsinki on 2026-10-20. ERA5 is about 5–6 days late
  (2026-11-01: last day 2026-10-26), so the daily window is 21 days and days with no value are not
  stored. A review of the past month is therefore complete about the 7th day of the next month.
  ERA5T preliminary values can change; rows are upserted, so freeze review figures in the review
  snapshot instead of citing a live row.
- ERA5 has no snow depth in this API (all null), so it is not collected. Snowfall water
  equivalent is the hydro input. SEAS5 has no 100 m wind (null), so only 10 m wind is collected.
- Normals: no separate table. Backfill from 1991-01-01 and calculate the 1991–2020 normal per
  point and calendar day or month with queries. Heating degree days also come from
  `temperature_2m_mean` (for example base 17 °C, the Finnish convention).
- Units are checked against `open_meteo.units`; wind is requested in m/s because the API default
  is km/h. A wrong unit fails the point instead of storing wrong values.
- Never change the coordinates of an existing point key; add a new key. Finnish weights are
  population shares of the regions nearest to each point (see the config comment); hydro points
  have no weight.
- EC46 and SEAS5 are separate products (not the "seamless" mix), so every member is one
  continuous model trajectory and has one initialisation time.

## API budget (free tier: < 10,000 calls/day, 5,000/hour, 600/minute)

A request with more than 10 variables or more than 2 weeks of data counts as several calls
(fractional). Observed requests have exactly 10 variables. Approximate costs:

- Daily window (21 days): about 1.5 calls per point.
- Backfill 1991-01-01 to today: about 935+ calls per point (13,088 days), so about 9,400 for all
  10 points. Run it on two days, for example the 6 Finnish points one day and the 4 hydro points
  the next. One-year pieces with a 30 s pause stay under the hourly limit (~36 requests per
  point, about 18 min per point).
- Ensemble and seasonal requests count members and variables; the Open-Meteo docs only show the
  weight in their URL builder. Fetch EC46 weekly and SEAS5 monthly.

A one-request 1991–2026 archive response for one point was 883 KB and took 4.7 s (2026-11-01).
Storage: ensemble about 24 KB per point per run, EC46 about 62 KB, SEAS5 about 155 KB, so
daily ensemble + weekly EC46 + monthly SEAS5 for 10 points is about 140 MB per year.

## Commands

```bash
php artisan weather:fetch                                   # observed 21-day window + ecmwf_ens
php artisan weather:fetch --only=ecmwf_ec46                 # weekly
php artisan weather:fetch --only=ecmwf_seas5                # monthly, after the ~5th
php artisan weather:fetch --from=1991-01-01 --point=helsinki --point=oulu  # observed backfill
```

A failed point does not stop the others; the run then exits 1. Logs contain the part, point,
date range and exception class only (no URL).
