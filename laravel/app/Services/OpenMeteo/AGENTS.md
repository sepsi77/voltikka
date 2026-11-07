# Open-Meteo weather

Collects weather for the monthly market review (data inventory G6 and G22 in
`../../../../market-review/`): observed daily weather from the ERA5 archive, and
append-only outlook vintages from the ECMWF ensemble, EC46 and SEAS5. It also collects two inputs
for own spot price models (`../../../../tasks/own-forecast-model-data/`): hourly ERA5 wind at 9
neighbour wind-power points, append-only hourly solar (GHI) forecast vintages at the 7 FMI
radiation stations, the 00 UTC ECMWF run of each day at 38 points (`ecmwf_00z`, the S model
weather), and Previous Runs lead-1 temperature at 5 southern stations (`ecmwf_prev_day1`, the
S model's 7-day southern temperature). Nothing public reads these rows yet.

Licence and terms: Open-Meteo data is CC BY 4.0. A published figure must credit
"Weather data by Open-Meteo.com" with a link to https://open-meteo.com/ (ERA5 is also
Copernicus/ECMWF data). The free API is for non-commercial use only. User decision 2026-11-01:
the site has no income, so the use is treated as non-commercial. Check this again before the site
gets any income (ads, affiliate links, paid features); then a paid plan is needed. The user confirmed
this on 2026-11-02 after reading the Open-Meteo terms and the Creative Commons non-commercial
definition: non-commercial while the website is not monetised. Paid tiers (2026-11-02 price
table): "API Standard" does not include the Historical Weather (archive/ERA5), Ensemble,
Seasonal, Historical Forecast or Previous Runs APIs; the current collection needs
"API Professional" (or Enterprise). Paid tiers also give reserved servers: the free servers had
long HTTP 503 "overloaded" periods on 2026-11-02 (archive and previous-runs about 12:00 to after
15:30 UTC).

## Files

- `OpenMeteoService.php` – read-only client (no key): archive (daily and hourly), forecast
  (hourly), ensemble/seasonal outlooks, and best-effort model-run metadata. Waits between calls; retries 429, 5xx and connection errors.
- `OpenMeteoWeatherImporter.php` – upserts observed days and hours; stores one outlook row and
  one hourly forecast row per distinct payload; checks every response unit.
- `../../Console/Commands/FetchWeather.php` – `php artisan weather:fetch`.
- `../../Console/Commands/ImportResearchWeatherRuns.php` – one-time import of the S model training
  weather (`ecmwf_00z` runs, `ecmwf_prev_day1` southern temperature) from the git-ignored research
  cache through the same importer; dry run by default (user approval of the import 2026-11-06).
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

- `weather_hourly_observations` – one row per (point, model, UTC hour). `timestamp` is the UTC
  hour start in unix seconds and `utc_datetime` the same as UTC text without a cast (as in
  `spot_prices_hour`). `wind_speed_10m` and `wind_speed_100m` (m/s) are **instantaneous** values
  at that time (Open-Meteo: wind is instantaneous, radiation and precipitation are preceding-hour
  values), not hour means. Upserted.
- `hourly_forecast_runs` (shared with FMI and Fingrid, migration `2026_11_02_000001`) –
  append-only, unique (source, product, point, payload_hash). Rows here have source `open_meteo`,
  product = the config key (`solar_ghi`), point = the point key. `values` is
  `{"shortwave_radiation": [value per UTC hour from first_timestamp]}`; `payload_hash` is SHA-256
  of `json_encode([first_timestamp, values])`, so a rerun of the same run in the same UTC day adds
  no row. `issued_at` is the model run time, see Rules.

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
- Hourly parts use UTC: `timezone=GMT` and `timeformat=unixtime`. A response with a non-zero
  `utc_offset_seconds` or a time that is not a full hour fails the point. The date options of
  `observed_hourly` are UTC days (the daily part keeps Helsinki days).
- `observed_hourly` uses ERA5 (`models=era5`) for the same reason as the daily part: one
  consistent reanalysis for the whole history. ERA5 has 10 m and 100 m wind (ERA5-Land has no
  wind). Both heights are stored: the FMI forecasts of these points are 10 m wind, and the
  reference model (nordpool-predict-fi) uses 100 m history. Hours without any value (ERA5 delay)
  are not stored. The point keys in `neighbour_wind_points` are shared with the FMI forecast
  collector; never change their coordinates.
- `solar_ghi` requests one model, ECMWF IFS HRES 9 km (`models=ecmwf_ifs`; 15 days, runs every
  6 hours, hourly to +90 h, 3-hourly to +144 h, 6-hourly later, interpolated to 1 h by
  Open-Meteo; https://open-meteo.com/en/docs/ecmwf-api). The default `best_match` mixes models,
  so a vintage would have no single run time. HRES is preferred to IFS 0.25° (3-hourly) and to
  MET Nordic / ICON (2.5–7.5 days, shorter than the 10-day need).
- `shortwave_radiation` is global **horizontal** irradiance (GHI, W/m²), the same quantity as the
  FMI `GLOB_1MIN` history (decisions.md 2026-11-01), not the reference model's
  `global_tilted_irradiance`. Open-Meteo gives it as the **mean of the preceding hour**
  (https://open-meteo.com/en/docs: "Solar radiation is averaged over the past hour"). The importer
  therefore stores the API value of time T at the hour that starts at T − 1 h
  (`time_shift_seconds = -3600`): index 0 of a run fetched with first API time 00:00Z is the hour
  23:00–00:00Z. Readers that join FMI radiation must use the same hour-start convention.
- `issued_at` of `solar_ghi` is `last_run_initialisation_time` from
  `https://api.open-meteo.com/data/ecmwf_ifs/static/meta.json` (the same unofficial pattern as the
  ensemble products; not yet checked live). It is read before and after the point loop; when the
  two differ or the file cannot be read, `issued_at` is null and `fetched_at` is the vintage time.
  Not verified: whether Open-Meteo fills the tail of the shorter 06/18 UTC HRES runs from the
  previous run. Check it live before a model relies on `issued_at` for 06/18 UTC runs.
- EC46 and SEAS5 are separate products (not the "seamless" mix), so every member is one
  continuous model trajectory and has one initialisation time.
- `ecmwf_00z` (2026-11-06) is the issue-day weather of the own S model `s_v1`
  (`../../../../tasks/own-forecast-model-data/s-model-freeze.md`). It asks the **Single Runs API**
  (`https://single-runs-api.open-meteo.com/v1/forecast`, `models=ecmwf_ifs`,
  `run=<Helsinki day>T00:00`) for one exact run: ECMWF IFS HRES 00 UTC of the day, 9 days,
  `temperature_2m`, `wind_speed_10m`, `wind_speed_100m`, `shortwave_radiation`, at the 38 training
  points (the 29 `fmi.weather_stations`, keys = FMISID strings, and the 9 `neighbour_wind_points`;
  `points` is a list of full config keys). It is the source the model was trained on
  (`research/s_weather_single_runs.py`), so do not change the model, variables, days, points or
  time convention without a new model version. Rules:
  - `issued_at` is the requested run time, not the metadata file, so it is exact.
  - **No time shift**, also for radiation (stored at the API time, unlike `solar_ghi`), because the
    training data has none.
  - A point already stored for the run is not requested again, so the hourly ticks (09:15–14:15
    Helsinki) fill only what is missing and then cost nothing.
  - HTTP 400 "The requested model run is not available" (a run not ready yet) is a quiet wait:
    exit 0, nothing stored, the other points are not requested. Other errors fail the point.
    The S workflow's readiness check owns the deadline. On 2026-11-06 the 00 UTC run was available
    at 19:10 Helsinki; the 12 UTC run was not yet at 19:15. The morning availability time is not
    measured yet.
  - User decision 2026-11-06 (option A): all four variables come from here, an exception to the
    rule "Open-Meteo only where FMI has no data"; FMI's ECMWF copy stays collected for the planned
    comparison. Production had no 100 m wind and no radiation at these points before.
- `ecmwf_prev_day1` (2026-11-07) is the second weather input of `s_v1`: `temp_south_7`, the
  population-weighted temperature of the five `SOUTH_WEIGHTS` stations (101004, 101118, 101065,
  101191, 101237; `point_keys` filters the FMI station set) over the 7 days before the Fingrid
  cut, which enters the stage-1 net load. Training read it from the **Previous Runs API**
  (`https://previous-runs-api.open-meteo.com/v1/forecast`, `models=ecmwf_ifs025`,
  `temperature_2m_previous_day1`; `research/s_weather_previous_runs.py`), so production does
  too. Checked 2026-11-07: the live answer equals the research cache (216 of 216 hours at
  101004). The request has `past_days=8` and `forecast_days=1`; there is no metadata file, so
  `issued_at` is null (one series mixes many runs). Values of past hours do not change, so a
  reader selects by valid hour; the readiness check needs a row fetched before the cut. Twice a
  day (09:40 and 13:40 Helsinki), before both Fingrid cuts. Do not change the model, variable,
  points or weights without a new model version.

## API budget (free tier: < 10,000 calls/day, 5,000/hour, 600/minute)

A request with more than 10 variables or more than 2 weeks of data counts as several calls
(fractional). Observed requests have exactly 10 variables. Approximate costs:

- Daily window (21 days): about 1.5 calls per point.
- Backfill 1991-01-01 to today: about 935+ calls per point (13,088 days), so about 9,400 for all
  10 points. Run it on two days, for example the 6 Finnish points one day and the 4 hydro points
  the next. One-year pieces with a 30 s pause stay under the hourly limit (~36 requests per
  point, about 18 min per point).
- Hourly window (`observed_hourly`, 2 variables, 21 days): about 1.5 calls per point, 13.5 for
  the 9 points. It is part of the default run (local change 2026-11-01, not deployed), so the daily
  run at 10:40 costs about 13.5 calls more.
- Hourly backfill from 2023-01-01: about 1,400 days / 14 = 100 calls per point, about 900 for the
  9 points. Calendar-year pieces cost at most 26 calls each (36 requests, about 18 min with the
  30 s pause), so it fits one day and one hour. Do not run it in the same hour as another
  backfill that uses more than about 4,000 calls.
- `solar_ghi`: 1 variable, 10 days = 1 call per point, 7 per fetch. Metadata calls are not
  counted (https://open-meteo.com/en/docs/model-updates).
- `ecmwf_00z`: 4 variables, 9 days = 1 call per point, 38 per stored run (once a day; ticks
  after the run is stored send nothing).
- `ecmwf_prev_day1`: 1 variable, 9 days = 1 call per point, 5 per fetch, 10 per day.
- Call weight (https://open-meteo.com/en/pricing): more than 10 variables or more than 2 weeks
  per location counts fractionally, e.g. 2 weeks with 15 variables = 1.5 calls.
- Ensemble and seasonal requests count members and variables; the Open-Meteo docs only show the
  weight in their URL builder. Fetch EC46 weekly and SEAS5 monthly.

A one-request 1991–2026 archive response for one point was 883 KB and took 4.7 s (2026-11-01).
Storage: ensemble about 24 KB per point per run, EC46 about 62 KB, SEAS5 about 155 KB, so
daily ensemble + weekly EC46 + monthly SEAS5 for 10 points is about 140 MB per year.
`weather_hourly_observations`: 9 × 8,760 = about 79k rows (about 12 MB) per year; the backfill
from 2023 is about 300k rows (about 45 MB). `solar_ghi` twice a day: about 5,100 rows (240 hourly
values, about 9 MB) per year.

## Commands

```bash
php artisan weather:fetch                                   # observed 21-day windows (daily + hourly) + ecmwf_ens
php artisan weather:fetch --only=ecmwf_ec46                 # weekly
php artisan weather:fetch --only=ecmwf_seas5                # monthly, after the ~5th
php artisan weather:fetch --from=1991-01-01 --point=helsinki --point=oulu  # observed backfill
php artisan weather:fetch --only=solar_ghi                  # solar GHI forecast vintage (own schedule)
php artisan weather:fetch --only=ecmwf_00z                  # S model weather: 00 UTC run of today (hourly 09:15-14:15)
php artisan weather:fetch --only=ecmwf_prev_day1            # S model 7-day southern temperature (09:40, 13:40)
php artisan weather:import-research-runs ecmwf_00z <dir> [--apply]  # one-time: S training runs from the research cache (dry run first)
php artisan weather:fetch --from=2023-01-01 --only=observed_hourly  # hourly wind backfill (UTC days)
```

`--point` limits every selected part to those keys; a part without any of them is skipped, and a
point that no selected part uses is an error. A failed point does not stop the others; the run
then exits 1. Logs contain the part, point,
date range and exception class only (no URL).
