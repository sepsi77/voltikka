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
