# Decisions

## 2026-11-01 – Task created

- User goal: own short- and medium-term spot price forecasts in the end, instead of
  third-party forecasts that are not unique to the site. Not urgent. This task covers only the
  missing short-term input data; the model is a later task.
- Origin: comparison of the nordpool-predict-fi inputs with the market-review collectors built on
  2026-11-01 (see `tasks/monthly-market-review/decisions.md`). That comparison found the gaps
  listed in spec.md: hourly weather history and 8-day forecasts, neighbour wind speed points,
  solar irradiance, Fingrid 245, Syke hydrology and holidays.
- Already collected and usable as model inputs (production since 2026-11-01, deploy-000013):
  FI spot prices (`spot_prices_hour/quarter`, history repaired 2022-01..2026-11), Fingrid system
  data (`fingrid_hourly_values`, 23 datasets incl. 188 nuclear, 75 wind, 268 wind capacity,
  246 daily wind forecast, 124 consumption), ENTSO-E outage versions (`entsoe_outages`),
  JAO import capacity (`jao_max_border_flows`), neighbour-zone prices (SE1, SE3, EE, DE-LU in the
  spot tables), daily ERA5 weather and ECMWF outlook vintages (`weather_daily_observations`,
  `weather_outlook_runs`), third-party forecast vintages (`spot_price_forecast_runs`).
- Known history limits: JAO data starts 2024-10-29; FI futures from 2026-04-08; most Fingrid
  series have long history but are not yet backfilled in production; the outage daily window
  reads only documents updated in the last 3 days, so an outage backfill is needed.
- Earlier decision kept: Syke was removed from the market-review plan because Open-Meteo
  snow/precipitation covered the review. For a model it is a gap again, because the reference
  model uses Syke catchment data.
- The free Open-Meteo API is used under the user's non-commercial decision (site has no income).

## 2026-11-01 – Source checks (read-only), FMI preferred

- User rule (2026-11-01): prefer non-Open-Meteo sources and APIs where they are available.
  Open-Meteo is the fallback only.
- User rule (2026-11-01): the production backfills (Fingrid, ENTSO-E chain incl. JAO/NVE/GIE,
  Open-Meteo weather) run until about 16:30 Helsinki. Do not call those APIs from here until they
  have finished (Fingrid and ENTSO-E limits are per key; the Open-Meteo hourly limit already
  failed two weather points). FMI and Syke are not used by those backfills.
- **FMI open data WFS** (no key, CC BY 4.0; limits 20,000 download requests/day and 600
  requests/5 min, from the FMI WFS manual):
  - Hourly observations `fmi::observations::weather::hourly::*` with `TA_PT1H_AVG`,
    `WS_PT1H_AVG`: data from 2023-01-01 exists (Kumpula 4.2 °C at 2023-01-01 00Z). Limit
    744 hours per request (a 90-day request gave "No more than 744 hours allowed"). Several
    `fmisid` in one request work. The `multipointcoverage` form is about 10× smaller than
    `simple` (3 stations × 1 month: 168 KB in 7.7 s).
  - Edited forecast `fmi::forecast::edited::weather::scandinavia::point::*`: default 72 h;
    with `endtime` it reaches about D+9.5 (2026-11-11 00Z on 2026-11-01). `timevaluepair` has
    `om:resultTime` (2026-11-01T10:44:46Z), so a vintage has a real issue time. One request can
    mix `fmisid` and `latlon` points. Parameters `temperature`, `windspeedms` (10 m),
    `totalcloudcover`. No radiation (NaN).
  - The edited forecast covers the neighbour points EE01, EE02, DK01, DK02, DE01, SE01, SE02,
    SE03 to D+9.5, but not DE02 Niedersachsen ("No data available"). FMI's
    `ecmwf::forecast::surface::point::*` covers DE02 to D+9.5 with `WindUMS`/`WindVMS` (10 m);
    its `WindSpeedMS`, radiation and cloud parameters are all NaN.
  - Radiation observations `fmi::observations::radiation::*`: `GLOB_1MIN` (1-minute W/m²),
    168 hours per request (6.3 MB `simple`, 0.7 MB `multipointcoverage` per station-week).
    The 7 solar sites of the reference model are FMI radiation stations.
  - Radiation forecast: only MEPS `RadiationGlobal` (about 50 h). The edited forecast has cloud
    cover to D+9.5.
- **Syke Hydrologiarajapinta 1.1** (no key): `SadantaAlue` with `Jakso_Id eq 2` is a **5-day**
  areal precipitation value (dates 11th, 16th, 21st …), latest 2026-10-21 on 2026-11-01, history
  from 1974 (275 values since 2023 for place 848). `LumiAlue` (snow water equivalent, `Arvo` is a
  string) is **twice a month** (1st and 16th, no summer values), history from 1965. So the spec's
  "daily" is wrong: these are slow features. The 20 places are northern hydro catchments
  (Oulujoki/Oulujärvi, Iijoki area, Kemijoki/Raudanjoki, Inari, Juutuanjoki); Suure 5 =
  precipitation, Suure 7 = snow. Licence page not found at the expected URL; verify before
  publication (models can use it).
- Decision: Finnish station temperature and wind (observations and forecast vintages) come from
  FMI, not Open-Meteo. Reasons: the user rule, the reference model uses these FMI stations, and
  FMI gives a real forecast issue time.

## 2026-11-01 – Source choices (user decisions) and storage design

- User decision: neighbour wind **history** (9 points, 2023→) comes from the Open-Meteo archive,
  in the existing `weather:fetch` collector, because no single non-Open-Meteo source has it. Run
  the backfill only after today's production backfills (Open-Meteo daily limit; tomorrow).
  Neighbour wind **forecasts** come from FMI (edited forecast for 8 points, FMI ECMWF U/V for
  DE02).
- User decision: the solar **forecast** comes from Open-Meteo (to D+10 at the 7 sites). Solar
  **history** comes from FMI radiation stations (the preferred non-Open-Meteo source).
- Agent decisions that follow (consistency between history and forecast of one feature):
  - Solar: FMI `GLOB_1MIN` is global *horizontal* irradiance, so the Open-Meteo forecast uses
    `shortwave_radiation` (GHI, W/m²), not the reference model's `global_tilted_irradiance`.
  - Neighbour wind: FMI forecasts are 10 m wind, so the archive stores both `wind_speed_10m`
    (same height as the forecast) and `wind_speed_100m` (the reference model's history height).
- Storage: one generic append-only table `hourly_forecast_runs` for every hourly forecast
  vintage (FMI edited/ECMWF, Open-Meteo solar, Fingrid 245): one row per source, product, point
  and distinct payload, values as `{"variable": [hourly values]}` from `first_timestamp`, with
  `issued_at` (source issue time, if known) and `fetched_at`. Reason: one reader pattern for the
  model and no parallel vintage tables; `weather_outlook_runs` is daily and member-shaped, so it
  is not reused. Observations go to per-source upsert tables (sources correct them).
- FMI open data has exactly 7 radiation stations (same set on 2023-01-15, 2024-06-15,
  2025-06-15 and 2026-10-31): 100908 Parainen Utö 59.77909,21.37479; 100968 Helsinki-Vantaa
  airport 60.32937,24.97274; 101004 Helsinki Kumpula 60.20307,24.96131; 101104 Jokioinen Ilmala
  60.81397,23.49825; 101756 Sotkamo Kuolaniemi 64.11197,28.33639; 101932 Sodankylä Tähtelä
  67.36663,26.62901; 102035 Utsjoki Kevo 69.75637,27.00678. The reference model's Jyväskylä and
  Kuopio sites are not FMI open-data radiation stations. Decision: the solar sites are these 7
  stations, and the Open-Meteo solar forecast uses the same coordinates, so history and forecast
  have the same points. Point key = FMISID as a string for every FMI station.

## 2026-11-01 – Open-Meteo hourly and Fingrid 245 built (local, no live calls yet)

- Neighbour wind history: `weather_hourly_observations`, ERA5 (`models=era5`, has 10 m and
  100 m wind; ERA5-Land has no wind). Open-Meteo hourly wind is **instantaneous** at the hour;
  FMI `WS_PT1H_AVG` is an hour mean. A model must align them (known difference, not a defect).
  Part `observed_hourly`; it now also runs in the default `weather:fetch` (about 13.5 calls/day),
  a documented behaviour change. Full 9-point backfill from 2023: about 900 calls, about 18 min;
  do not run it in the same clock hour as the pending hydro backfill (about 3,700 calls).
- Solar forecast: `solar_ghi`, model `ecmwf_ifs` (HRES 9 km, 15 days, hourly to +90 h, then
  3/6-hourly interpolated by Open-Meteo). Not `best_match` (mixes models), not IFS 0.25° (3-hourly),
  not ICON/MET Nordic/Météo-France (shorter than D+10). Radiation is the mean of the preceding
  hour, so API time T is stored at the hour that starts at T−1 h. `issued_at` comes from the
  unofficial `static/meta.json` (verify live; null when unreadable).
- Fingrid 245: separate command `fingrid:fetch-forecast-vintages`, one request per run, horizon
  72 h from the next full UTC hour, hourly mean of four quarters (an hour with fewer than 4 is
  null), `issued_at` null (no issue-time attribute; `fetched_at` is the vintage time).
- Storage estimates per year: `weather_hourly_observations` about 79k rows/12 MB (backfill
  about 300k/45 MB); `solar_ghi` twice a day about 5k rows/9 MB; Fingrid 245 hourly about
  8.8k rows/6 MB.
- Proposed schedule: Fingrid 245 hourly at minute 45 (12:45 is before the 13:00 gate);
  `weather:fetch --only=solar_ghi` at 11:45 and 23:45 Helsinki (00Z and 12Z HRES runs).

## 2026-11-01 – Syke collector and holiday calendar built (local)

- Syke API version 1.2 (syke.fi lists 1.2, 2026-05-11, as current; 1.1 is the previous version
  and returned the same rows). Configurable with `SYKE_HYDROLOGY_BASE_URL`.
- Licence: CC BY 4.0; credit for example "Source: Finnish Environment Institute (Syke),
  Hydrologiarajapinta" (https://www.syke.fi/en/environmental-data/use-license-and-responsibilities).
- Units from the `Suure` entity: 5 "Aluesadanta" mm, 7 "Lumen aluevesiarvo" mm.
- Date key: a 5-day value at date D covers **D..D+4** (dates 1, 6, 11, 16, 21, 26; the 26th
  covers to the month end). Evidence: for place 848 the monthly value (Jakso 3) equals the sum
  of the six 5-day values of that month in 22 of 22 months (2024-12..2026-09); the other reading
  matched 1 of 22. A model may use a value only after its period has ended and it is published
  (on 11-01 the latest was D = 10-21, period end 10-25). Snow is a state on D (1st and 16th,
  October–June only).
- Paging: at most 500 rows per page with an absolute `odata.nextLink`; the client follows only
  links on the same base and entity.
- Rows are upserted (Syke corrections look rare); no vintages. Acceptable for slow features;
  revisit if a model must reproduce corrections exactly.
- Local live backfill from 2023-01-01: 2,750 precipitation rows (275 per place) and 680 snow
  rows; about 900 rows per year (< 0.2 MB). Proposed schedule: daily 11:10 Helsinki.
- `App\Support\FinnishHolidays`: public holidays plus two eves (juhannusaatto, jouluaatto).
  Uudenvuodenaatto and vappuaatto are working days for most workplaces; pääsiäislauantai is a
  Saturday, so a weekday feature covers it. Years before 1991 are rejected (loppiainen and
  helatorstai were on Saturdays in 1973–1990). Tested for 2023–2027.

## 2026-11-01 – Scope extended to the model (user decision)

- User decision: the task includes the short-term model itself, because collectors without a
  purpose make no sense. Phases: data → research model → pre-declared evaluation on real
  forecast vintages → production forecaster with own vintages (no public UI) → recommendation
  on public use. Public page changes and the medium-term model stay outside this task.
- Agent proposals in spec.md, not yet confirmed by the user: target FI hourly VAT 0 €/MWh;
  one run at 11:30 Helsinki for D+1..D+7; strict as-of inputs; baselines incl. the
  nordpool-predict-fi vintage at issue time; household-oriented metrics (cheapest/most expensive
  3 hours). Runtime (Python job on Railway vs a PHP-runnable model) is an open user decision
  for phase 4, to decide with the phase 3 result.
- Reason for the pre-declared holdout: forecast vintages exist only from 2026-11-01, so any
  earlier backtest uses observed weather as a perfect-forecast stand-in and overstates skill.
- The folder name `own-forecast-model-data` is kept for now (commit c8846e7 and memory refer to
  it); rename it when phase 1 is committed if the user wants.

## 2026-11-01 – Two models, no plain replication (user decisions)

- User decision: build both a short-term model (hourly D+1..D+7, the same job as
  nordpool-predict-fi) and a medium-term model (the next 2–3 months).
- User decision: do not only replicate the current model. nordpool-predict-fi is a reference
  and a baseline to beat. spec.md lists agent-proposed differentiators per model (calibrated
  ranges, inputs the reference does not use, a level/shape structure for D+4..D+7, household
  outputs scored directly, a public track record; for M: fundamentals-adjusted futures, a
  weather-scenario range, the household-type cost translation). They are hypotheses for the
  phase 3 evaluation, not confirmed features.
- Order: S first (its data and holdout are faster); M phase 1 checks start now because most M
  inputs already exist. Honest limit for M: monthly targets give few points and FI futures exist
  only from 2026-04-08, so weekly targets carry most of the evidence.

## 2026-11-01 – FMI collector built; integration

- FMI hourly values are labelled at the END of the hour (proved: Kumpula 2026-10-31
  `TA_PT1H_AVG/MAX/MIN` at label 08:00 = 7.2/7.3/7.1 equals the 10-minute `t2m` in
  (07:00, 08:00]; all 11 hours 04:00–14:00 match). The importer stores label T at T−1 h. So the
  earlier note "Kumpula 4.2 °C at 2023-01-01 00Z" is the hour 2022-12-31 23:00–24:00 UTC.
- `GLOB_1MIN` minute direction is not proved (FMI documents it nowhere; a clear-day symmetry test
  could not resolve ±30 s). Minute-ending-at-T is used, the same as the proven hourly rule; the
  worst error is 1 of 60 minutes per hour.
- Forecast arrays (FMI and Open-Meteo wind) are instants at the valid time; FMI observations are
  hour means of [H, H+1). The model must align them (known, documented).
- Station 101268 Kristiinankaupunki lighthouse has no observations since some date between
  2025-06 and 2026-03; its forecast works. It stays; the model may need a replacement station.
- Radiation night values are slightly negative (to about −7 W/m²); stored raw.
- Live smoke (scratch database): daily window 4,031 observed + 504 radiation rows, 37 edited +
  1 ECMWF vintages; a rerun stored no duplicates.
- Backfill from 2023-01-01: about 46 observed requests (12 min) + 200 radiation requests
  (2.4 h, about 950 MB download), about 2.0 M rows. Yearly: about 0.56 M rows / 115–140 MB in
  `fmi_hourly_observations`; FMI vintages twice a day about 28k rows / 0.1–0.2 GB.
- Schedule (manager): all forecast vintages are fetched at 11:05 Helsinki (FMI, Open-Meteo solar)
  so that a model run at about 11:30 has them before the 13:00 gate; a second set at 23:05 for the
  12Z runs; Fingrid 245 hourly at :45; Syke daily 11:10. FMI is fetched twice a day, not four times,
  to halve storage; add more only if the model needs them.
- The user confirmed the honest limit for the medium-term model (beating the futures on the mean is
  hard; the value is the range, the cold-and-calm risk and the household cost).

- Full suite after integration: 3,172 tests passed (25,708 assertions); Pint and `git diff --check` clean. Nothing committed or deployed.

## 2026-11-01 – Medium-term model purpose (user decision)

- User decision: the medium-term forecast is for consumers, not for business decisions. It tells
  which way prices are likely to move in the next 2–3 months and roughly by how much; nobody makes
  production decisions on it. Consequence (agent): keep the model and the output simple —
  monthly mean, change from a level consumers know, a five-step direction class and a p10–p90
  range. Main metrics are the direction hit rate and the share of wrong-way calls; weekly
  targets are no longer a primary output.
- The direction is against the reference level and includes the normal seasonal change, because
  that is the movement a consumer feels.
- User accepted (2026-11-01) the default `weather:fetch` behaviour change (hourly neighbour wind window, about 14 calls/day) and the unproved GLOB_1MIN minute direction (no real effect on the models).
- User rule (2026-11-01): local work and commits may continue; no production deploy until the running production backfills have finished (about 17:30 Helsinki).

## 2026-11-01 – First baseline level for S (local data, read-only)

- `research/baselines.py` scores baselines (a) same hour last week and (b) last known day
  repeated on the local FI history (repaired, complete 2022–2026), issue days 2023-01-08..
  2026-10-25 (1,387 days), EUR/MWh VAT 0. Output: `research/baselines-local-20261101.txt`.
- Level to beat (all seasons): (b) hourly MAE 33.2 (D+1), 43.9 (D+4), 44.0 (D+7); (a) 44.0 at
  every horizon. Daily-mean MAE (b) 27.7 at D+1, about 40 at D+4..D+7. Cheapest-3 hit rate
  0.30–0.33 (random 0.125). After D+3 the naive baselines are no better than last week's prices,
  so the D+4..D+7 tail is where an own model can add the most value (differentiator 3).
- Baseline (c), the nordpool-predict-fi vintage, exists only from 2026-11-01. Idea to check: the
  git history of its published prediction file could give older vintages for scoring only (not as
  a model input).

## 2026-11-01 – Historical seasonal forecasts for M (web check, no API calls)

- **C3S SEAS5 on the Copernicus CDS** (`seasonal-monthly-single-levels`, also
  `seasonal-original-single-levels` daily/6-hourly): 1° grid, 7-month forecasts, released on the
  **6th of each month at 12 UTC** (use that as the as-of time, not the 1st). Real-time forecasts
  from 2017 (system 4 Sept–Oct 2017, system 5 from Nov 2017, system 51 from Nov 2022; the agent
  saw system 51 listed "up to April 2026" – a possible newer system after that is not verified).
  Hindcasts 1981/1993–2016 with 25 members, produced later, so before Nov 2017 they are a
  "hindcast proxy", not as-of evidence. Monthly variables include 2 m temperature, 10 m wind speed,
  precipitation, snowfall and snow depth (water equivalent). Licence CC BY 4.0 (commercial use
  allowed), DOI 10.24381/cds.68dd14c3. Access needs a free CDS account, a token and the `cdsapi`
  package; area subsets work. Rough size: Nordic box, 5 variables, 6 leads, about 110 starts, all
  members about 200 MB GRIB; ensemble means only a few MB. About 12–40 requests.
- **ECMWF S2S (EC46-type extended range)** in the ECMWF Data Store: real-time from 2015 plus
  reforecasts, 1.5° grid; current S2S licence rev. 4 puts ECMWF data under CC BY 4.0 with a 48 h
  delay (older revisions were non-commercial with a 3-week delay). Useful for the first 1–1.5
  months of M.
- Not verified: CDS queue/size limits, system 51 hindcasts for 1981–1992, exact lead-month
  definition, which ECMWF S2S parameters exist.
- Agent recommendation: CDS SEAS5 monthly ensemble statistics are a low-effort, non-Open-Meteo
  source for as-of seasonal features from Nov 2017; S2S covers weeks 1–6. Both need a user-owned
  account (registration is the user's action).
- User created the CDS and ECMWF Data Store accounts (2026-11-01). The two sites share one ECMWF account and one key (checked by hash, values not printed). Decision: one variable `ECMWF_API_KEY` for both CDS (SEAS5) and the ECMWF Data Store (S2S). Production needs the same Railway variable later (production variable write: explicit approval).
- Key test (2026-11-01): `ECMWF_API_KEY` gives HTTP 200 on both `/api/retrieve/v1/jobs` (401 without it). One tiny job each (SEAS5 monthly ensemble mean 2 m temperature, Nov 2025 start, lead 1; S2S ECMWF control 2 m temperature 2025-11-03 00Z, +24 h; area 70N/20E/60N/30E) was accepted (201), finished `successful` within minutes and gave a valid GRIB file (336 and 304 bytes). Auth header `PRIVATE-TOKEN`; the same API path works on both hosts. Decoding needs ecCodes (for example the `eccodes`/`cfgrib` Python packages); not installed yet.
- User approved installing ecCodes (2026-11-01). Research virtual environment `research/.venv` (git-ignored), Python 3.13, `eccodes` 2.50.0 (binary wheel with the library). Both test files decode: SEAS5 `2t` monthly ensemble mean for Nov 2025, 10×10 points at 1°, mean 0.3 °C (−6.5..7.0); S2S `2t` 0–24 h mean for 2025-11-03, 7×7 points at 1.5°, mean 5.0 °C. Values are plausible for the 60–70N, 20–30E box.

## 2026-11-01 16:25 Helsinki: Fingrid 245 live check passed; deploy waits for the ENTSO-E chain

- The production Fingrid backfill finished at 15:55 Helsinki with EXIT 0, so the shared Fingrid key is free again.
- `fingrid:fetch-forecast-vintages` ran locally two times (1 request each). Run 1: "new vintage stored", exit 0. Run 2: "unchanged", exit 0 (the payload hash stops a duplicate).
- Stored row: first hour 2026-11-01 15:00 UTC, 72 hours, 72 values, no empty hour, 339.75..6221.6 MW (plausible for FI wind capacity).
- The production ENTSO-E chain still runs (new estimate 20:00–22:00 Helsinki, then reruns of 3 outage units and Turku/Tampere weather). A deploy stops the app container and would kill that chain, so the push is permitted but the deploy waits for the chain and its reruns.

## 2026-11-01 18:15 Helsinki: backfill handoff from session voltikka-87

- The session that runs the market-review backfills (voltikka-87) owns their completion. It sends one message when no chain runs, with every EXIT line, then does the reruns (outages SE1 A77, SE3 A77, SE1->FI A78; Turku/Tampere weather) and the local SQLite sync, and sends a second message when the local copy is fresh.
- New ENTSO-E chain estimate: about 23:00-01:00 Helsinki (generation was running; then neighbour spot, JAO, NVE, gas storage).
- This task does not poll production, does not run scripts/sync-production-database.sh and does not deploy until those messages arrive. Backup signal only: the status file named in that message (first word RUNNING, DONE or TIMEOUT).

## 2026-11-02 – SEAS5 archive fetcher (M phase 1)

- User approved building it (2026-11-01). Implemented as a local research script, `research/seas5_fetch.py`, not a Laravel collector: the CDS archive is permanent and never revised, so the as-of rule is met by the release time alone and nothing has to be captured live. Production already stores live SEAS5/EC46 vintages through Open-Meteo. Where a production M model reads SEAS5 stays the phase-5 runtime decision.
- CDS constraints (checked 2026-11-01): ECMWF system 5 has real-time starts to 2022-10, system 51 from 2022-11 (2026 has starts 01–10; 2026-11 is released on 2026-11-06 12 UTC). System 4 only Sept–Oct 2017. 100 m wind speed does not exist for ECMWF; 10 m wind is used.
- Request per start: `monthly_mean` (all 51 members), variables 2m_temperature, 10m_wind_speed, total_precipitation, runoff, leads 1–6, area 71N/4E/57N/32E at 1°. Model climate: `hindcast_climate_mean` per system and start month (24 files, 1993–2016 hindcast period). Units as delivered: K, m/s, and m/s rates for precipitation (`tprate`) and runoff (`mrort`).
- Regions (cos-latitude weighted grid means, no land mask): `fi` 60–70N 21–30E, `no_south` 58–63N 5–11E (NO1/NO2/NO5 hydro), `nordic_north` 63–68N 13–21E (SE1/SE2/NO4 hydro, northern wind). As-of time for each start: day 6, 12:00 UTC.
- Full run 2017-11..2026-10: 108 starts + 24 climate files, 0 failures, 123 MB GRIB (git-ignored `research/data/seas5/`), `seas5_regions.csv` 396,576 rows (108 × 4 variables × 6 leads × 3 regions × 51 members). About 6 minutes per 25 requests at 4 workers.
- Checks: runoff for start 2025-11 was about 2× the model climate in all members; over all starts the median forecast/climate ratio is 0.84–1.14 per region and lead, so that start was a real wet anomaly, not a unit or join error. Temperature anomalies against the 1993–2016 model climate are positive in 92–100 % of starts at leads 3–6 (+0.5..+1.0 K): this is the warming since the hindcast period. The M model must therefore not read the raw anomaly as "warmer than normal"; use anomalies against a recent reference (for example the leave-one-year-out mean of the real-time starts, or the observed recent normal).
- Not yet done: a skill check of SEAS5 against observed monthly temperature. The local snapshot has Helsinki ERA5 only to 2017 (it was synced before the production backfill), so it waits for the fresh sync from session voltikka-87.

## 2026-11-02 – Backfill chains complete (message 1 of 2 from session voltikka-87)

- No production chain runs since 2026-11-01 20:04 Helsinki. EXIT 0: Fingrid, ENTSO-E reservoirs and generation, neighbour spot (SE1, SE3, EE, DE-LU), JAO, NVE, gas storage. EXIT 1: weather observed history (Turku, Tampere) and ENTSO-E outages (SE1 A77, SE3 A77, SE1->FI A78).
- That session now reruns the failed parts in production and then syncs the local SQLite copy. This task still does not deploy (a deploy stops the reruns), does not read the local database and sends no ENTSO-E or Open-Meteo requests until message 2.

## 2026-11-02 04:23 Helsinki – Sync failure and deploy route

- Session voltikka-87: production reruns all EXIT 0 (Turku/Tampere weather 13,083 rows each; outages SE1->FI 373, SE3 A77 233, SE1 A77 1,876 rows). The local sync failed at 21:50 because production (3c03af7) does not have this task's 4 new tables; the local database is unchanged (2026-11-01 13:36 copy).
- User decision (this session): deploy route. Commit b155d20 pushed; image `deploy-000015-b155d208ea65` built and pushed (PHP 8.4.27, the 4 migrations present; the entrypoint runs `migrate --force`). Not deployed yet: the build finished at 04:23, inside the EEX/morning-consumer window.
- Deploy window: after today's eex_futures, morning_retail and morning_forecast checkpoints have finished and before 12:30 Helsinki (hydro weather backfill by voltikka-87; a deploy would stop it). After /up and the 4 tables are verified, message voltikka-87 so it runs the sync.

## 2026-11-02 06:15 Helsinki – Deployed deploy-000015-b155d208ea65

- User approved the deploy, then asked to deploy before the morning consumers instead of after them. `contracts:fetch` started at 06:00 and was `pending_completion` until `ready` at 06:13; `eex_futures` was `ready` at 05:43; `morning_retail`/`morning_forecast` had no row yet (start 07:15/07:30). Deployed at 06:15 with `--morning-checks-passed` on the user's instruction (the flag's documented condition, both consumers finished, was not literally met; the gap protected the same runs).
- Deployment f254926d-1cc7-4095-9f34-aebceb8cfdfe: SUCCESS 06:15:40. `source.image` read back as the exact tag. `/up` 200, `/` 200. Rollback tag: deploy-000014-3c03af788a1b.
- Read-only checks: the 4 new tables exist (0 rows), 0 pending migrations, 5 new schedule entries registered.
- Lesson: a wait loop must deploy only on `ready`; `pending_completion` is not finished (first loop stopped on it, the deploy did not run then).
- Messaged session voltikka-87 to run the local sync. Still to check: today's morning_retail and morning_forecast reach a finished state after the deploy, and the first scheduled vintages appear (Fingrid 245 at 06:45; FMI/solar 11:05; Syke 11:10).

## 2026-11-02 – SEAS5 skill for Finland is near zero beyond the start month

- Fresh local sync (session voltikka-87, 06:22 Helsinki, against deploy-000015): ERA5 daily for the 6 Finnish points from 1991; hydro points not yet.
- `research/seas5_skill.py` (read-only) compares the SEAS5 `fi` region with the mean of the 6 Finnish ERA5 points, starts 2017-11..2026-10, anomalies against the sample calendar-month mean. Result in `research/seas5-skill-local-20261102.txt`.
- Temperature: correlation of the ensemble-mean anomaly with the observed anomaly is 0.74 at lead 1 (0.82 Nov–Mar), then 0.12, −0.09, −0.14, 0.10, −0.11 at leads 2–6 (n 102–107). Precipitation: 0.52 at lead 1, then 0.16, 0.20, −0.06, −0.01, 0.07. Lead 1 is the start month, about 5 days of which are past at the as-of time.
- p10–p90 coverage of the member anomalies is 0.64–0.75 (target 0.80): the ensemble is a little too narrow.
- Consequence for M: for months 2–3 ahead, SEAS5 gives about no information on the Finnish weather mean. The M model must not present a SEAS5 signal as a weather forecast for those months; use the observed climatological distribution (recent years) for the weather part of the range, and the current month from EC46/S2S and the days already observed. SEAS5 stays a candidate feature only if phase 2 shows value (for example Nordic runoff vs NVE inflow, not yet checked); otherwise it is dropped (simplest model). This supports the honest limit in the spec: the realistic value of M is direction and size from the current state (reservoirs, snow, futures, season), not from a weather forecast.
- User decision (2026-11-02): agreed. The M model uses the observed weather of previous years for the weather part of its range instead of a seasonal weather forecast. SEAS5 stays only if the runoff vs inflow check shows value.
- 2026-11-02, session voltikka-87: the production hydro weather backfill starts at 12:30 Helsinki (about 3,700 Open-Meteo calls, about 75 min, user-approved there). No deploy from 12:30 until its "hydro done" message. The scheduled solar_ghi/observed_hourly calls stay as they are. This task's local Open-Meteo live checks wait until after that backfill, and not in the same clock hour.
- 2026-11-02 07:45 Helsinki, read-only checkpoint check after deploy-000015: eex_futures, morning_retail and morning_forecast are all `ready` for 2026-11-02. Retail and forecast became ready on their first scheduled ticks (07:15 and 07:30 Helsinki), so the 06:15 deploy did not delay the morning consumers.

## 2026-11-02 – S phase 2: first as-of backtest (local, read-only)

- User request (2026-11-02): start S phase 2 and do all the local work that is possible.
- Scripts in `research/`:
  - `s_export.py`: read-only CSV export of the S inputs from the local snapshot, with pins in
    `s-inputs-20261102.json` (the CSV files are git-ignored under `data/s-export-20261102/`, 12 MB).
  - `s_thirdparty_history.py`: baseline (c) from the git history of nordpool-predict-fi
    `deploy/prediction.json`: 4,454 vintages, 2024-02-23..2026-11-02, 900,878 hourly values,
    read with `git cat-file` from a bare blob-less clone in `data/npf-history/` (15 MB). No code
    from the repo is checked out or run. As-of time = committer time (a later push is a small,
    accepted risk). VAT removed with the rate of the target date (24 % to 2024-08-31, then 25.5 %).
    Scoring only; it is never a model input.
  - `s_backtest.py`: as-of feature table, baselines, candidates, quarterly expanding refit from
    2024-01, metrics. Output: `s-backtest-20261102.txt` and `s-backtest-results-20261102.json`.
- As-of rules used (documented in the script): prices up to the end of D; Fingrid observations
  for hours that start before D 10:00 Helsinki (production fetches at 10:20, so a phase 4 run must
  keep a fetch before it); outage plans = the latest revision of each document created at or
  before 11:30, cancelled revision removes the document; FI month futures with trade date ≤ D−1.
- Check of the outage logic: the as-of nuclear plan follows realised Fingrid 188 output
  (correlation 0.84 at D+1, MAE 203 MW against 785 MW for a flat 4,372 MW; annual revisions visible).
- Candidates (hyperparameters fixed before the first run): `h_asof` = gradient-boosted quantile
  model (scikit-learn HistGradientBoosting, p10/p50/p90) on as-of inputs only; `p_standin` = the
  same + weather stand-ins (stored Fingrid 246 wind forecast for the target hour, made about one day
  before it; realised ERA5 daily temperature of the target day). `p_standin` is an upper bound with
  overstated skill by construction. `*_cal` = the same p50 with conformal p10/p90 shifts from the
  last 91 training days of each block.
- Results, test issues 2024-01-01..2026-10-24 (1,028 days), hourly MAE EUR/MWh VAT 0, D+1..D+7:

  | Model | All | Winter | Summer | Same rows as (c) | cov80 (cal) |
  |---|---|---|---|---|---|
  | a same hour last week | 44.3 | 53.1 | 38.9 | 42.7 | 0.78 |
  | b last day repeated | 42.3 | 49.3 | 38.0 | 41.0 | 0.78 |
  | c nordpool-predict-fi vintage | 28.6 | 30.3 | 27.7 | 28.6 | 0.81 (residual range) |
  | h_asof (honest, no weather forecast) | 37.2 | 44.3 | 32.8 | 36.9 | 0.75 |
  | p_standin (weather stand-ins) | 23.5 | 23.9 | 23.2 | 22.7 | 0.74 |

  On the rows of (c) per horizon: (c) 21.8 at D+1 and 31–33 at D+4..D+7; h_asof 34–39;
  p_standin 22–23 at every horizon. Pinball (cal): h_asof 12.2, (c) 10.0, p_standin 7.5.
- Conclusions:
  1. Without a weather forecast, our model beats the naive baselines (37 against 42–44) but loses
     clearly to the reference (37 against 29). The reference uses weather forecasts; wind is the
     main driver (Fingrid 246 vs realised wind MAE 401 MW).
  2. With near-perfect weather, our model beats the reference at every horizon (about 23 against
     22–33), most at D+3..D+7. The real gain lies between these two bounds. Only real forecast
     vintages can show where (phase 3, collectors deployed 2026-11-02).
  3. Ranges: raw quantile models cover only 0.51 (target 0.80). The conformal shift gives 0.75
     (summer 0.81, winter 0.65) and a lower pinball. Winter ranges are still too narrow. A first
     ratio-based calibration made the factor explode (×30 after the autumn 2023 spikes); it was
     replaced by the additive form.
  4. The direction of tomorrow's daily mean vs today (household output "tomorrow cheaper"): h_asof
     0.64 at D+1, (c) 0.78, p_standin 0.77.
- The first runs used all CPU cores and loaded the user's machine. `s_backtest.py` now limits
  OpenMP to 2 threads by default (`OMP_NUM_THREADS`) and is run with `nice`.
- These are phase 2 research numbers (design choices were made while looking at them). The phase 3
  holdout on real vintages decides; its candidates and rule must be frozen first.
- Proposal (needs a user decision; Open-Meteo, and not during the hydro backfill): an honest
  historical weather-forecast input for the backtest. Candidate: the Open-Meteo Previous Runs API
  (forecasts as issued 1–7 days before each hour; start date and models to verify). No non-Open-Meteo
  source with archived D+1..D+7 forecasts is known (FMI does not archive its forecasts; ECMWF
  MARS needs a licence). Without it, phase 3 must wait for about 2–3 months of our own vintages.

## 2026-11-02 – Historical weather forecasts from Open-Meteo (user decision)

- User decision (2026-11-02): Open-Meteo may be used for the historical weather forecasts of the
  S backtest. Source: Previous Runs API (`<variable>_previous_dayN` = the value predicted 24·N h
  before the valid time, N = 1..7; most models archived from January 2024; web docs read
  2026-11-02, no API call yet).
- `research/s_weather_previous_runs.py`: `probe` (which model has all leads in 2024-01 and 2026-10,
  6 tiny requests), `fetch --model M` (one request per point and year, cached raw JSON in
  `data/om-previous-runs/`, 15 s pause, stops on HTTP 429), `reduce` (group means per hour and lead
  into `data/s-export-<stamp>/weather_previous_runs.csv`, pinned in `s-inputs-<stamp>.json`).
- Points = the live collector points: 29 FMI stations (2 m temperature, 10 m wind, as the FMI
  forecast parameters) and the 9 neighbour wind points (10 m wind). Estimate: 114 requests,
  about 3,670 weighted calls (free tier 10,000/day, 5,000/hour). Planned model: `ecmwf_ifs`, the
  same as the live `solar_ghi` product, if the probe shows the archive from 2024-01; else
  `ecmwf_ifs025`.
- Timing: the calls start after the production hydro backfill (session voltikka-87, from 12:30)
  has sent "hydro done", and not in the same clock hour.
- As-of rule in `s_backtest.py`: per target hour, the smallest N with
  valid time − 24·N h + 7 h (publication lag) ≤ issue time; N > 7 leaves the features empty. At
  11:30 this covers every hour to about D+7 05:00 Helsinki.
- Phase 3 note: training uses Open-Meteo IFS forecasts, while production stores FMI forecasts at the
  same points. Before phase 3 decide whether the candidate runs on live Open-Meteo values or on the
  FMI vintages (and measure the difference).
- The fetch request header carries no personal data (the user's email was removed before any
  request).
- Range calibration, second step (same run setup, 2 threads, 400 s): `*_scal` shifts p10/p90 by the
  0.9 quantile of earlier blocks' out-of-sample misses (same season and horizon, targets in the
  365 days before the block start; fallback `*_cal`). Coverage h_asof 0.75 → 0.77 (winter
  0.65 → 0.68, summer 0.81 → 0.82); p_standin 0.74 → 0.78 (winter 0.69 → 0.74). Pinball h_asof
  12.43 → 12.31. Winter ranges are still too narrow; the winter price spikes are the main cause.
  Keep `*_scal` as the candidate range method for phase 3.
- Level/shape candidate `ls_asof` (differentiator 3; same as-of inputs; daily-level quantile models on
  one row per target day + hourly-shape quantile models on the deviation from the day mean;
  range = both spreads as independent errors, then `*_scal`): hourly MAE 39.5 against 37.2 for
  h_asof, worse at every horizon, also D+4..D+7 (40.0 against 37.7 at D+7); pinball 12.7 against
  12.3. But the hour order is better: cheapest-3 hit rate 0.40 and most-expensive-3 0.41, against
  0.36/0.37 (h_asof) and 0.37/0.39 (reference). Consequence: the split is rejected as the level
  model; the separate shape model stays a candidate for the household "cheapest hours" output
  (combination: level from the main model, hour order from the shape model). Decide with the
  weather-forecast run.
- 2026-11-02 11:12 Helsinki, read-only production check of the first collector rows after
  deploy-000015 (times UTC): Fingrid 245 vintages hourly from 04:45 (5 by 08:45, 72 h); FMI edited
  forecast 37 points (29 stations + 8 neighbour points) and FMI ECMWF 1 point (DE02) at 09:05,
  232 h; Open-Meteo solar_ghi 7 points at 09:05, 240 h; FMI observations from 2026-10-30 09:00
  (temperature and wind 28 stations, radiation 7); Syke 10 places for both series; Open-Meteo hourly
  ERA5 observations 9 points to 2026-10-27. All as designed (28 observation stations: Kristiinankaupunki
  lighthouse has no observations, known). The production backfills (FMI from 2023, neighbour wind,
  Syke history) are still not run; they need user approval.

## 2026-11-02 – Production backfills for the own models (user approval)

- User approval (2026-11-02): the production backfills FMI from 2023, Open-Meteo neighbour wind and
  Syke history, and the local Open-Meteo Previous Runs fetch.
- Syke: `php artisan syke:fetch --from=2023-01-01`, 09:29 UTC, EXIT 0: 2,760 precipitation_5d and
  690 snow_water_equivalent rows (log `/app/storage/logs/backfill-20261102-fmi-syke.log`).
- FMI, first try in the same chain: EXIT 255 after 11 s, "Allowed memory size of 134217728 bytes
  exhausted" (PHP CLI limit 128M) on the first 744 h observation piece (28 stations x 3
  parameters builds many date objects). The daily 72 h window is not affected (11:05 run OK).
  Restarted at 09:30 UTC as `php -d memory_limit=1024M artisan fmi:fetch --from=2023-01-01
  --only=observed --only=radiation` (container limit 32 GB, 1.1 GB in use; the process uses about
  240 MB). Log `/app/storage/logs/backfill-20261102-fmi.log`. Lesson: test a full backfill piece
  locally under the production memory limit (`php -d memory_limit=128M`) before a production run.
- `disown` does not exist in the container shell (dash); `setsid nohup ... &` alone detaches.
- Neighbour wind (`weather:fetch --only=observed_hourly --from=2023-01-01`, about 900 calls) and
  the local Previous Runs fetch wait for the "hydro done" message of session voltikka-87 and the
  next clock hour. voltikka-87 was informed (09:31 UTC).
- Cause of the FMI 128M failure (local measurement, in-memory test DB, synthetic 744 h ×
  29 stations × 3 parameters = 64,728 rows): `FmiObservationImporter::record()` called `now()`
  per row and kept the Carbon object twice (created_at, updated_at): 208 MB extra peak. Local fix:
  the stamps are formatted once per import (same stored strings): 68 MB extra peak. FMI tests pass
  (11), Pint clean; `Fmi/AGENTS.md` updated. Not deployed: the running production backfill uses
  `-d memory_limit=1024M`, and the daily 72 h run was never affected. The fix goes out with the
  next approved deploy.
- FMI backfill result: EXIT 0 at 11:52 UTC (2 h 22 min): 1,916,704 hourly observation rows
  (temperature and wind) and 234,592 radiation rows.
- voltikka-87 sent "hydro done" at 11:48 UTC (rerun of no5_aurland and se2_storuman, EXIT 0).
  Neighbour wind backfill started 12:00 UTC with `php -d memory_limit=1024M artisan weather:fetch
  --only=observed_hourly --from=2023-01-01` (log `/app/storage/logs/backfill-20261102-weather.log`):
  8 of 9 points stored 33,504 hourly rows each; se01_markbygden failed (RuntimeException, the
  same transient failure as in the hydro run); EXIT 1 at 12:16 UTC. Rerun for that point only
  (`--point=se01_markbygden`) at 13:21 UTC.
- The rerun failed too (ConnectionException after 3 retries, EXIT 1 at 13:24 UTC). Both failures
  were on the 2023 piece. One local request for the same piece (13:3x UTC) got HTTP 503
  "The service is overloaded" from archive-api.open-meteo.com in 0.8 s: the failures are on the
  service side, not in the point or our code. The log keeps only the exception class, so the
  exact source of the first RuntimeException (unexpected payload or missing variable/unit in a
  200 response) is not proved. Next try at 14:00 UTC.
- Shell note: `echo EXIT $? $(date)` keeps the right status in dash (tested); `$?` must come
  before any command substitution. In zsh, `$R args` with R="railway ssh ..." in a variable does
  not word-split, so a local watcher written that way fails on every check (it did here; the
  logs were then read directly).
- 14:00 UTC se01_markbygden third try: RequestException after 11 s, EXIT 1. Local Previous Runs
  probe 14:03–14:09 UTC: all 6 requests failed (HTTP 503 or timeout, models ecmwf_ifs,
  ecmwf_ifs025, best_match). Open-Meteo is overloaded this afternoon; no more Open-Meteo calls
  today. Tomorrow: se01_markbygden (4 requests, production) and then the local probe and fetch.

## 2026-11-02 – External modelling advice compared with the plan

- The user pasted two external opinions on S and M modelling. Both match the current design
  (quantile gradient boosting, outage plans, neighbour prices, weather as main input, 2023+
  training; M: futures as mean, historical weather years for the range).
- Agent proposal for the next S run (local only, with the weather-forecast candidate): (1) a
  per-target-hour net-load / tightness feature (expected demand minus expected wind, over planned
  nuclear plus border capacity); (2) a load-weighted southern Finland temperature in addition to
  the 29-station mean (the 29 stations are mostly west-coast wind sites, weak for heating demand);
  (3) a linear quantile (LEAR-type) baseline. Deferred: ECMWF ensemble spread (no long archive to
  backtest; collect live first), merit-order + ML residual, SE1/SE3 price forecasts, deep models.
- M: no dispatch simulation or VECM (consumer purpose). The futures risk premium cannot be
  estimated (FI futures only from 2026-04-08), so the M range must stay wide.
- User decision (2026-11-02): "Let's try this" for items (1)–(3), and retry Open-Meteo after an
  hour (probe again at 15:10 UTC).
- Implemented locally (not yet run on real forecasts):
  - `s_weather_previous_runs.py reduce` writes `fi_temp_south`: population-weighted temperature of
    Helsinki Kumpula, Tampere-Pirkkala, Turku airport, Utti and Lappeenranta (weights = approximate
    region population in millions: 1.75, 0.53, 0.49, 0.16, 0.12; needs 80 % of the weight).
  - `s_backtest.py`: `add_netload` fits, per block on training rows only, a linear stage-1 model
    of realised net load (Fingrid 124 − 75) from last week's consumption at the same UTC hour
    (`cons_hod_7`, as-of), the southern temperature forecast, its change from last week
    (`temp_south_7`, from lead-1 forecasts already public), a cold term below −10 °C, wind power
    proxy × installed capacity, weekend, holiday and hour. Features `netload_exp`, `resid_load`
    (minus planned nuclear MW) and its day mean. The fit MAE per block and horizon is saved in the
    results JSON (`netload_fit`) as a check of stage 1. Border capacity is not in the ratio
    (no nominal capacity series in the export); the border outage features stay separate.
  - New candidates: `w_south` (w_forecast + southern temperature), `w_netload` (+ net load), and
    `l_linear` (LEAR-type: one ridge model, alpha 1 fixed, on asinh(price/10), price inputs also
    asinh, one-hot hour/weekday/month/horizon, median fill + missing flags; range from training
    residual quantiles per horizon). LEAR proper fits one LASSO per hour; this is simpler.
  - Smoke test with synthetic weather (blocks to 2024-09, 30 iterations): all columns filled,
    no errors. Real scores need the Previous Runs fetch.
- Border capacity (user request 2026-11-02: "import / export data from Fingrid ... an important
  factor"). Findings: Fingrid has measured flows (55 EE, 60 SE1, 61 SE3, 194 net; realised, so only
  usable as lagged issue-time inputs) and official day-ahead NTC datasets 24 SE1-FI, 25 SE3-FI,
  26 FI-SE1, 27 FI-SE3, 112 EE-FI, 115 FI-EE. The NTC datasets have values until the flow-based
  start (e.g. 2024-03-04: SE1-FI 1,560, SE3-FI 1,200, EE-FI 358 = EstLink 1 only) and zeros or
  nothing after it. JAO `maxBorderFlow` (local table from 2024-10-29) continues the series.
  `research/s_border_capacity.py` joins them into `border_capacity.csv` (pinned; 201,738 rows;
  Fingrid history cached in data/fingrid-ntc, 6 requests). p50 SE1-FI 1,245 MW (NTC) and 1,432 MW
  (JAO). The two methods differ (maxBorderFlow is not an NTC); the feature `flow_based` marks it.
- Backtest features (as-of: capacity is published the day before delivery, so a run on D knows it
  through the end of D): per border the median of the last 7 known days, import borders limited by
  the A78 plans (`imp_se1`, `imp_se3`, `imp_ee`, `imp_cap`), export sum `exp_cap`, and in
  `add_netload` `import_need = resid_load - imp_cap` (what domestic hydro, CHP and reserves must
  cover after full import). New candidate `w_capacity` (w_south + capacity) between w_south and
  w_netload, so each step shows its own effect. Smoke test OK.
- User decision (2026-11-02): JAO capacity values (or their change from earlier periods) may be
  mentioned when we explain model results, to the degree that they help explain the forecast or
  the report. Recorded in `laravel/app/Services/Jao/AGENTS.md` with the limits (single values or
  changes in text, credit "JAO Nordic publication tool"; tables, charts or downloads of JAO data
  still need JAO's written permission).
- 15:10–15:16 UTC second Previous Runs probe (user: "try again in an hour"): all 6 requests again
  HTTP 503 or timeout. Third try at 17:00 UTC; if it fails, the fetch moves to 2026-11-03 morning.
- User decision (2026-11-02): retry Open-Meteo once an hour until it works. A local loop runs the
  6-request probe at the start of each UTC hour from 17:00 UTC (at most 36 tries) and stops at the
  first response with data. Then: se01_markbygden rerun in production, the Previous Runs fetch,
  reduce, the border-capacity pin check and the full S backtest.
- User decision (2026-11-02): after reading the Open-Meteo terms and the Creative Commons
  non-commercial definition, the current use is non-commercial while the website is not
  monetised; monetisation moves it to commercial use (paid plan). Recorded in root AGENTS.md
  (External APIs) and `laravel/app/Services/OpenMeteo/AGENTS.md`, with the tier finding: the
  current collection (archive, ensemble, seasonal) needs "API Professional"; "API Standard" is not
  enough. Open for phase 3: the S model's daily weather source (FMI, free, versus the Open-Meteo
  forecast API, the same source as the training data).

## 2026-11-02 – S weather source: FMI against Open-Meteo

- User (2026-11-02): maybe use FMI data and train with it; at least test FMI against Open-Meteo.
- Constraint: FMI serves only the current forecast (no archive). Our FMI vintages start
  2026-11-01, so a model cannot be trained on FMI forecasts until about a year of vintages exists
  (at least one winter; autumn 2027). Training now uses historical ECMWF IFS forecasts from the
  Open-Meteo Previous Runs API.
- Finding: FMI's open data also has the ECMWF surface forecast (`ecmwf::forecast::surface::point`)
  at any point, to about D+9.5. Test 2026-11-02: one request for all 38 points (29 FMISIDs + 9
  latlon) returned 38 positions x 240 h x 3 parameters in 2.6 s (718 kB), 6.2 % NaN (the last
  hours). So production can feed the model with the same model family as its training data, free
  and without Open-Meteo.
- Local change (not deployed): the FMI `ecmwf` product now covers all stations and neighbour
  points (`latlon_points` accepts a list of config keys). FMI tests 11 passed, Pint clean.
  Deploy needs user approval (with the FMI memory fix).
- Planned comparison once vintages accumulate (4–8 weeks, ideally with a cold spell): (1) weather
  level: FMI ECMWF, FMI edited and Open-Meteo ECMWF IFS (Previous Runs for the same days) against
  FMI observations, per lead day (MAE, bias); (2) price level: the trained S model with each source
  as input on the same issue days. Open point: which Open-Meteo model matches FMI's ECMWF grid
  best (ecmwf_ifs 9 km or ecmwf_ifs025); the probe compares both.
- Deploy (user approval 2026-11-02: "deploy all of the current changes, as long as nothing is
  running in prod"): checks at 17:41–17:47 Helsinki: only the scheduler and the queue worker ran,
  the queue was empty, and all four checkpoints of the day were `ready`. voltikka-87 confirmed it
  had nothing running and that its commits de79fae, 1a862c0 and c25e6cc were safe to ship. Full
  suite 3,181 passed, asset build OK, `git diff --check` clean. Commits b0712ff (FMI ECMWF at every
  point, import memory fix) and 799a532 (research and docs); pushed b155d20..799a532. Image
  `deploy-000016-799a5327cd18` (PHP 8.4.27); deployment acea5a59 SUCCESS at 15:50 UTC; /up 200,
  home 200; production config shows the ECMWF product with all stations and point lists, and the
  importer has `stamps()`. First all-point ECMWF vintage: the scheduled 23:05 Helsinki run.
- Meteostat (user question 2026-11-02): not adopted. It aggregates station observations (for
  Finland mostly FMI, which we already take from the source), has no forecasts (the main model
  input), and its station data has gaps where ERA5 is complete. Licence: CC BY 4.0, commercial
  use allowed (user check 2026-11-02; an earlier agent guess of CC BY-NC was wrong), so it stays
  an option if station data is ever needed. If neighbour-country station data is needed later, prefer
  the national sources (SMHI open data, MET Norway Frost API); licence terms not yet checked.
- Open-Meteo answered again at 16:00 UTC (probe loop, try 2). se01_markbygden rerun: EXIT 0 at
  16:02 UTC, 33,504 hourly rows (2023-01-01..2026-10-27), equal to the other 8 points. The
  neighbour wind backfill is complete.
- Previous Runs archive (probe 2026-11-02, point fmi_101267, leads 1–7): `ecmwf_ifs` (9 km) has
  no data on 2024-01..2025-07 test dates (only recent data), so it cannot cover the backtest;
  `ecmwf_ifs025` (0.25°) has leads 1–2 on 2024-02-05 and all leads from 2024-03-05 at the latest
  (none on 2024-01-10). The fetch uses `ecmwf_ifs025` (114 requests, about 3,670 weighted calls),
  started 16:01 UTC. FMI's ECMWF copy may have a finer grid; the source comparison covers it.

## 2026-11-02 – S backtest with honest weather forecasts (ecmwf_ifs025 Previous Runs)

- Run 17:00–17:27 UTC (1,693 s, 2 threads). Weather file: 167,832 rows, all leads from
  2024-02-10. Fair comparison = `thirdparty_rows` (same 6,171 target days as the reference).
  MAE / pinball / p10–p90 coverage / cheapest-3 / dearest-3 hit rate, all horizons:

  | Model | MAE | Pinball | Cov80 | Cheap3 | Dear3 |
  |---|---|---|---|---|---|
  | c_thirdparty (nordpool-predict-fi, range from its residuals) | 28.58 | 10.04 | 0.81 | 0.37 | 0.39 |
  | w_south_scal (best MAE of ours) | 29.84 | 9.96 | 0.81 | 0.38 | 0.44 |
  | w_netload_cal (best pinball of ours) | 30.18 | 9.85 | 0.77 | 0.37 | 0.42 |
  | w_forecast_scal | 30.11 | 10.08 | 0.81 | 0.39 | 0.44 |
  | w_capacity_scal | 30.49 | 10.27 | 0.80 | 0.39 | 0.43 |
  | h_asof_scal (no weather) | 36.91 | 12.05 | 0.79 | 0.37 | 0.39 |
  | p_standin_scal (perfect weather, upper bound) | 22.70 | 7.52 | 0.79 | 0.49 | 0.51 |

- Per horizon (MAE, thirdparty_rows), reference vs w_south_scal: D+1 21.8 vs 26.1, D+2 26.6 vs
  27.5, D+3 29.4 vs 28.3, D+4 31.1 vs 29.9, D+5 30.2 vs 31.0, D+6 30.9 vs 33.5, D+7 32.6 vs 36.1.
  We are better at D+3–D+4 and worse at D+1 and D+5–D+7.
- D+1 gap is partly a backtest handicap: the Previous Runs API only gives runs 24·N h before the
  valid time, so with the 7 h publish lag most D+1 hours use a run about 2 days old, while the
  reference (and our production run with the latest FMI ECMWF run) uses the D 00Z run. The
  backtest therefore understates live skill most at D+1. D+7 often has no lead ≤ 7 forecast.
- Honest weather forecasts are worth 7 €/MWh MAE (36.9 → 29.8); perfect weather would give a
  further 7 (22.7): forecast error dominates, as both external sources said.
- South temperature: small gain (30.11 → 29.84). Border capacity: no overall gain (30.49);
  winter bucket 37.40 → 36.97. Net load: overall 30.18, but best winter MAE (36.08 vs 37.40) and
  best pinball; consistent with tightness mattering in winter. The differences between these
  variants are small (≈ 0.3–0.6) and may be noise; no variant is a clear winner yet.
- Stage-1 net-load estimate is weak: test MAE 750–1,510 MW per block (worst in winter). Likely
  causes: 10 m wind at 0.25° with a crude power curve. Improvement: 100 m wind, fitted power curve.
- l_linear (LEAR-type): on `weather_period` MAE 30.50 (as good as the best tree), but it explodes
  in early blocks and winter (MAE 127; sinh of extrapolated values). Needs clipping in the asinh
  space before it is a usable baseline.
- Range: calibrated ranges cover 0.81 at a pinball equal to or better than the reference's
  residual-based range; the reference itself publishes no range.
- Winter bucket comparisons with the reference are not fair (different rows: 2,133 vs 2,723
  days); use thirdparty_rows.

## 2026-11-02 – Iterative model experiments (user instruction)

- User (2026-11-02): work iteratively without asking; per result form a hypothesis, test it,
  keep or discard it, track the experiments, use Codex as a second opinion, for S and M.
- Log, protocols and the current best models: `research/experiments.md`. Decision rules were
  fixed before the experiments (S: paired MAE on the reference rows, DEV 2024-07..2025-06 decides,
  HOLD 2025-07.. confirms, 90 % day-bootstrap; M: MALE on DEV 2019–2022 and HOLD 2023–, wrong-way
  limit). Both HOLD periods have been used in selection; only future issues are a clean test.
- S, kept: south temperature (ablation E5), stage-1 net load (E1c) with a stage-1 wind model from
  100 m wind (E7a), separate models for D+1 and D+2–D+4 (E4), wind/solar inputs for D+1 (E7b),
  SE3 nuclear plans for D+2–D+4 (E18), a bounded per-hour linear model blended 50/50 into the p50
  of D+2–D+7 (E12b, E15b), smaller trees for D+5–D+7 (E22). Consolidated as `s_best` in
  s_backtest.py (reproduces the composite exactly). Against nordpool-predict-fi on the same rows:
  DEV −3.97 EUR/MWh (better), HOLD +0.88 (worse; better at D+3–D+4, level at D+1, behind at D+2 and
  D+5–D+7). Discarded: recency weights, per-day models, more capacity, risk-band range, linear with
  wind inputs, hourly medians beyond D+1, very small trees, horizon blends.
- Linear-model guard: no linear prediction when < 30 % of its training rows have weather forecasts
  (2024 Q1 MAE 389 without it); outside DEV/HOLD, no effect on the decisions.
- M (`m_backtest.py`, `m_prices.py`, `m_export.py`; ENTSO-E prices 2015–2022 fetched and checked
  equal to local 2022 data): best `m_best` = 0.85 × [0.7 ridge correction of the seasonal change
  (Nordic/FI reservoirs, DE/FI level, FI trend, temperature and snow anomalies) + 0.3 seasonal
  change] + 0.15 × calendar-month median level (log space); direction only where it agrees with the
  seasonal baseline; range from 2.5/97.5 % of earlier out-of-sample log errors. MALE 0.273 / 0.458
  (seasonal baseline 0.322 / 0.550), wrong way 0.02 / 0.13, coverage 0.82 / 0.81. The direction
  strictness trade-off for phase 3 is in experiments.md (M26).
- Open: E8, issue-time weather from the Open-Meteo Single Runs API (ECMWF HRES 00 UTC run of the
  issue day; 962 days × 38 calls, fetched within the free daily limit from 2026-11-02 19:00 UTC
  over about 4 days). It removes the D+1 forecast-age handicap and the D+7 gaps and matches the
  production source (FMI ECMWF). Expected to matter most where we still trail at D+2 and D+5–D+7.
- First production run with deploy-000016 (scheduled `fmi:fetch --only=forecast`, 23:05 Helsinki
  2026-11-02): FMI ECMWF 38 new vintages (all points, 232 h, issued 20:02 UTC = the 12 UTC run) and
  edited 37 vintages. The live FMI-vs-Open-Meteo comparison data starts here.

## 2026-11-06 – Production orchestration goes to the Temporal task

- The production runs of S and M (daily S run after the D+1 day-ahead result, weekly S refit from
  E30, weekly/monthly M run, scoring) are planned as Temporal workflows in
  `tasks/temporal-orchestration/spec.md`, "Forecast workflows". The runtime decision (Python on
  Railway) is taken together with that task's phase 0.
- User decision 2026-11-06: a production forecast runs only when every input is ready; the
  orchestration layer checks it (`tasks/temporal-orchestration/decisions.md`). The production
  model code therefore needs no fallback for a missing input. A backtest note: the research
  backtest still fills a few gaps with an older weather source (Previous Runs where no single run
  exists, mostly before 2024-03-14); in production those issues would get no forecast.

## 2026-11-06 – Research stops after E32; next step is production

- User decision: stop the model research after E32; move further development to a new task
  (`tasks/own-forecast-model-improvements/`), because the user wants the models in production
  soon.
- Production candidates: S = `s_best` with the weekly refit (E30; issue time from E32);
  M = `m_best`. The open research items of tasks.json moved to the new task's backlog.
- Next: freeze both models (phase 3), the runtime decision (phase 4, with
  `tasks/temporal-orchestration/` phase 0), then the production forecaster with live scoring.

## 2026-11-06 – E32: production issue time and design

- One S run per day at D 14:30 Helsinki, after the D+1 FI prices are stored (user request: the
  forecast comes right after the spot prices are published). D+1 shows the published prices.
- D+2 comes from `best_d1` fitted on the afternoon view (prices through D+1, Fingrid before
  14:00, outage plans and the third-party vintage at 14:30). D+3..D+7 come from the E30 parts on
  the morning view (inputs as of D 11:30, Fingrid before 10:00). Weather in both views: the 00 UTC
  ECMWF run of D. Weekly refit. No D+8.
- Reason (E32, `research/experiments.md`): the afternoon view helps D+2 (HOLD −1.37 €/MWh without
  April 2026, CI below 0) but is worse at D+3..D+7 in both periods (DEV +0.47, CI above 0). The
  combined design is HOLD −0.35 [−0.51, −0.20] against E30 and −1.42 [−2.05, −0.79] against the
  third party at its 14:30 vintage.
- One run builds both views: the morning-view inputs are a subset of what is stored at 14:30,
  because every vintage is stored append-only with its time. The four model parts stay four:
  only the D+2 part uses the afternoon view.
- The morning-view inputs must be exactly the 11:30 vintages, not newer ones, because the D+3..D+7
  models were trained and scored on them.
