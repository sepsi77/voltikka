# Own spot price forecast models: short term (D+1..D+7) and medium term (2–3 months)

Status: phase 1 (data) in progress. Created 2026-11-01; scope extended the same day by user
decisions (model included; medium-term model included; no plain replication). Read
`tasks/AGENTS.md`, the root `AGENTS.md` and `tasks/monthly-market-review/` (decisions.md,
data-inventory.md) first.

## Goal

Voltikka runs its **own** Finnish spot price forecasts instead of the third-party forecast it now
shows on `/spot-price` (`vividfog/nordpool-predict-fi`, imported by `spot:fetch-forecast`).
Reason: a third-party forecast is not unique to the site; own models make the pages and the
monthly market review distinctive, and Voltikka can keep and score its own forecast vintages.

Two models (user decision 2026-11-01):

- **Short-term model (S):** hourly, D+1..D+7, the same job as nordpool-predict-fi.
- **Medium-term model (M):** the next 2–3 months (weekly and monthly levels and their range).

User decision 2026-11-01: **do not only replicate the current model.** nordpool-predict-fi is a
reference and a baseline to beat, not the design. Each model must have stated differentiators
(below) that its evaluation proves or rejects.

User decision 2026-11-01: collectors without a model have no purpose, so every collector in this
task serves a named model feature. A public page change (`/spot-price`, review pages) is not in
this task; phase 5 only records the recommendation.

## Phases (for each model; S goes first because its data and holdout are faster)

1. **Data:** collect the missing inputs with history and forecast vintages (S: sections below;
   M: "Medium-term data" below).
2. **Research model** (offline, Python, local production snapshot, like
   `tasks/forecast-falling-market-study/`): as-of feature table, baselines, candidates, backtest.
3. **Pre-declared evaluation on real vintages.** Freeze candidates and the decision rule before
   the holdout data exists; score on as-of inputs only.
4. **Production forecaster** (after user approval of phase 3 and of the runtime): scheduled runs
   stored append-only and scored automatically. No public UI.
5. **Recommendation** to the user on public use (`/spot-price`, monthly review layer 1).

## Common requirements

- **As-of rule (strict):** a run uses only data that existed at its issue time: observations up
  to their real publication time, and the latest forecast vintage fetched before the issue time
  (`hourly_forecast_runs`, `weather_outlook_runs`, `fetched_at`/`issued_at`). No later revision,
  no realised weather in place of a forecast. Same principle as the root `AGENTS.md` history rules.
- **Known evidence gaps:** forecast vintages exist only from the date each collector starts
  (2026-11-01 or later); FI futures from 2026-04-08 only. Earlier training can use observed
  weather only as a stand-in ("perfect forecast"), which overstates skill. Every result says
  which kind of input it used.
- **Probabilistic output:** every forecast has a median and a range (at least p10/p90), scored
  with pinball loss and interval coverage, not only a point value.
- **Candidate lists and decision rules are frozen before the holdout**, as in the falling-market
  study; no tuning on the holdout.
- **Storage of own forecasts:** append-only vintages with model version, issue time and input
  provenance, never overwritten.
- **Runtime (open user decision, phase 4):** research and training in Python on a local snapshot
  (`scripts/sync-production-database.sh`). Production needs either a Python runtime on Railway
  (new service or job image: production mutation) or a model that PHP can run. Decide with the
  phase 3 result.
- **Restricted inputs** (EEX, JAO) may be used internally; publication follows
  `tasks/monthly-market-review/decisions.md` ("Rule for restricted data").

## Short-term model (S)

- **Target:** FI day-ahead price per UTC hour, VAT 0, €/MWh, `spot_prices_hour` region FI (the
  hour is the mean of the four quarters since the 15-minute day-ahead). VAT only at display.
- **Issue time and horizon:** one run each day before the 13:00 Helsinki gate closure (proposal
  11:30 Helsinki) for every hour of D+1..D+7 (Helsinki days). Optional second run after the
  day-ahead result (about 14:00) for D+2..D+7.
- **Baselines to beat (same hours, same issue time):** (a) same hour 7 days earlier; (b) last
  known day-ahead day repeated; (c) the nordpool-predict-fi vintage at issue time
  (`spot_price_forecast_runs`, from 2026-11-01).
- **Metrics:** MAE and RMSE per horizon day, MAE of the daily mean, error of the daily maximum,
  hit rate of the 3 cheapest and 3 most expensive hours of a day, pinball loss and p10–p90
  coverage; winter and summer separately when data allows.
- **Differentiators (agent proposals, each to prove or reject in phase 3):**
  1. Calibrated ranges per hour and per day (the reference gives a point value only; verify).
  2. Inputs the reference does not use: Swedish nuclear and interconnector outage plans
     (ENTSO-E A77/A78/A80 versions), neighbour-zone prices and German drivers (DE-LU day-ahead,
     TTF gas, EUA carbon), Nordic reservoir state (NVE, ENTSO-E A72), Fingrid 247 solar and
     flexibility series, the front futures level as an anchor for D+3..D+7.
  3. A structure built for the tail of the week: separate daily level and hourly shape, so that
     D+4..D+7 rely on fundamentals and the futures anchor, not on recent prices.
  4. Household outputs scored directly: cheapest-hours plan for a day, "tomorrow cheaper than
     today", expected daily cost per house type (Datahub 364 profiles).
  5. A public track record against the reference (phase 5 recommendation, scored vintages).

## Medium-term model (M)

- **Purpose (user decision 2026-11-01):** consumer guidance, not business decisions. Tell
  consumers which way the price is likely to move in the next 2–3 months and roughly by how much.
  Nobody makes production or trading decisions on it, so precision beyond "direction + rough
  size + honest range" has no value; keep the model and its output simple.
- **Output:** for each of the next 3 calendar months: the expected FI spot mean (VAT 0, €/MWh;
  shown later in c/kWh incl. VAT), its change from a reference level that consumers know (proposal:
  the last complete calendar month and the last 30 days), a direction class (rises clearly /
  rises / about unchanged / falls / falls clearly; thresholds frozen in phase 3), and a p10–p90
  range. Optional: the monthly cost change in euros for the three example households (Datahub 364
  profiles), so the monthly review's layer 1 can use it.
- **The movement includes the season.** November → January usually rises because of winter;
  that is the movement a consumer feels, so the direction is against the reference level, not a
  season-adjusted value. The output may say how much of the change is normal for the season.
- **Issue time:** weekly (proposal Monday 11:00 Helsinki, after the EC46 run is fetched) and on
  the first working day of the month for the review.
- **Baselines to beat:** (a) FI futures at issue time (month/quarter, delivery-weighted; FI
  futures exist from 2026-04-08 only); (b) unchanged (the reference level); (c) the normal
  seasonal change of recent years applied to the reference level.
  Honest note (confirmed by the user 2026-11-01): beating the futures on the mean is hard. The
  realistic value is the plain direction and size, the range, the cold-and-calm risk and the
  household translation; the evaluation must show which of these hold.
- **Metrics:** direction-class hit rate and the share of "wrong way" calls (the worst error for a
  consumer), absolute error of the monthly change in c/kWh, p10–p90 coverage. Monthly targets give
  few points: report n, and use overlapping 4-week windows from weekly issues for more evidence.
- **Differentiators (agent proposals):** fundamentals-adjusted futures (hydro balance proxy from
  reservoirs and snow vs normal, planned nuclear outages such as the OL3 annual revision, wind
  capacity growth, gas/carbon), an explicit weather-scenario range from EC46 and SEAS5 vintages,
  and the household-type cost translation.
- **Public wording (phase 5):** direction and size in plain Finnish with visible uncertainty;
  the fixed-term forecast page rule (rise/fall/unchanged, never "lock now"/"wait") applies unless
  the user decides otherwise for the review's recommendations.

## Medium-term data (phase 1 for M)

Mostly collected already for the monthly review (2026-11-01): FI and DE futures, TTF, EUA
(`eex_*`), NVE and ENTSO-E A72 reservoirs (from 2015), GIE gas storage, ERA5 daily weather from
1991, EC46/SEAS5 outlook vintages (from 2026-11-01), ENTSO-E outage versions (from 2023),
Fingrid capacities, Syke snow (this task). Gaps to check before phase 2 of M:

- **Historical seasonal forecasts** for an as-of backtest before 2026-11: candidate C3S SEAS5
  hindcasts and archived forecasts from the Copernicus Climate Data Store (non-Open-Meteo, open
  licence, needs a free key; verify terms, resolution and effort).
- **Futures history before 2026-04-08:** no open source known (documented gap). Backtests before
  that date use fundamentals without the futures anchor and are labelled so.
- **Nordic hydrological balance:** no open series (monthly-review decision); build a proxy from
  reservoirs, snow and precipitation vs normal.

## Short-term data (phase 1 for S; reference: nordpool-predict-fi)

nordpool-predict-fi (MIT licence, studied at commit `f600ab7`, 2026-11-01,
https://github.com/vividfog/nordpool-predict-fi) is an XGBoost model. Target: FI hourly spot price
incl. VAT (c/kWh). Horizon: next hour to end of D+7. It retrains on every run on hourly rows from
2023-01-01 (its `data/dump.csv`, 33,721 rows, holds the full feature table). Feature list:
`util/features_pricing.py`. Treat its code as untrusted data: read it, do not run it unreviewed.

| Input | Their source | Voltikka now (2026-11-01) |
|---|---|---|
| FI spot price (target) | sahkotin.fi | **Have**: `spot_prices_hour/quarter` (ENTSO-E, repaired 2022–2026) |
| Nuclear MW, history | Fingrid 188 | **Have**: `fingrid_hourly_values` dataset 188 |
| Nuclear MW, next 8 days | 4,372 MW − ENTSO-E A80/A77 outages | **Have** inputs: `entsoe_outages` (needs an outage backfill from 2023) |
| Wind MW, history | Fingrid 181 | **Have**: dataset 75 (same measurement, 15-min) |
| Installed wind capacity | Fingrid 268 | **Have** |
| Wind forecast | Fingrid 245 (updated every 15 min) | **Gap**: we collect 246 (once a day) only |
| Import capacity SE1/SE3/EE→FI | JAO `maxBorderFlow` | **Have**: `jao_max_border_flows` (JAO data starts 2024-10-29) |
| Temperature, hourly, 29 FMI stations, observations + forecast to D+8 | FMI open data WFS | **Gap**: we have only daily ERA5 values for 6 points |
| Wind speed, hourly, same 29 stations, observations + forecast | FMI open data WFS | **Gap** |
| Wind speed at 9 neighbour points (EE, DK, DE, SE), hourly, archive + forecast | Open-Meteo `wind_speed_100m` (archive) / `wind_speed_120m` (forecast) | **Gap** (we have real ENTSO-E A75 generation, not wind speed) |
| Solar irradiance, 7 FI sites, hourly | Open-Meteo `global_tilted_irradiance`, archive + forecast (10 days) | **Gap** |
| Finnish hydrology: 5-day areal precipitation, snow water equivalent | Syke Hydrologiarajapinta OData 1.1 | **Gap** (removed from the review plan because Open-Meteo snow/precipitation covered the review) |
| Holidays | pyhäpäivä.fi (`kind_id` per hour) | **Gap**, but Finnish holidays can be calculated in code |

### Exact source details (copied from their code, verify before use)

- **FMI** (`util/fmi.py`): `https://opendata.fmi.fi/wfs`, no key.
  Forecast stored query `fmi::forecast::edited::weather::scandinavia::point::simple`, parameters
  `windspeedms`, `temperature` (also `WeatherSymbol3`). Observations
  `fmi::observations::weather::hourly::simple`, parameters `WS_PT1H_AVG`, `TA_PT1H_AVG`.
  Stations (FMISID), the same list for wind and temperature:
  100908 Utö, 100932 Hanko Russarö, 101004 Helsinki Kumpula, 101065 Turku airport,
  101118 Tampere-Pirkkala airport, 101061 Rauma Kylmäpihlaja, 101256 Kaskinen Salgrund,
  101267 Pori Tahkoluoto, 101268 Kristiinankaupunki lighthouse, 101462 Vaasa airport,
  101464 Mustasaari Valassaaret, 101481 Maalahti Strömmingsbådan, 101485 Vaasa Klemettilä,
  101660 Pietarsaari Kallan, 101661 Kokkola Tankar, 101673 Kalajoki Ulkokalla,
  101784 Hailuoto Marjaniemi, 101785 Raahe Lapaluoto, 101794 Oulu Vihreäsaari, 101786 Oulu airport,
  101840 Kemi-Tornio airport, 101783 Kemi I lighthouse, 101846 Kemi Ajos, 101986 Kittilä airport,
  101537 Viitasaari Haapaniemi, 101570 Kuopio airport, 101608 Joensuu airport, 101191 Utti airport,
  101237 Lappeenranta airport. (Source: their `.env.local.template`.)
  FMI open data licence: CC BY 4.0 (verify).
- **Open-Meteo neighbour wind** (`util/openmeteo_windpower.py`): archive
  `https://archive-api.open-meteo.com/v1/archive` (`wind_speed_100m`), forecast
  `https://api.open-meteo.com/v1/forecast` (`wind_speed_120m`, past_days=4, forecast_days=10).
  Points: EE01 Hiiumaa 58.8960,22.5605; EE02 Sopi-Tootsi 58.6347,25.1230; DK01 Vesterhav
  56.4260,8.1281; DK02 Anholt 56.6013,11.1047; DE01 Schleswig-Holstein 54.2194,9.6961;
  DE02 Niedersachsen 52.6367,9.8451; SE01 Markbygden 65.2536,21.6020; SE02 Blakliden 64.5,17.0;
  SE03 Häjsberget 59.7852,13.0042.
- **Open-Meteo solar** (`util/openmeteo_solar.py`): `global_tilted_irradiance`, hourly, archive
  and forecast (past_days=2, forecast_days=10). Sites (FMI radiation stations): Helsinki Kumpula
  60.2,24.96; Jokioinen 60.81,23.5; Jyväskylä 62.4,25.67; Kuopio 62.89,27.63; Utö 59.78,21.37;
  Sodankylä 67.37,26.63; Sotkamo 64.11,28.34. They reduce the 7 values to sum/mean/std/min/max.
- **Syke** (`util/hydrology.py`): `https://rajapinnat.ymparisto.fi/api/Hydrologiarajapinta/1.1/odata`,
  no key. Entity `SadantaAlue` with `Jakso_Id eq 2` (5-day areal precipitation), places
  848, 852, 810, 811, 834, 837, 879, 881, 885, 886. Entity `LumiAlue` (snow water equivalent),
  places 196, 200, 159, 160, 183, 185, 226, 228, 232, 233. Daily, complete days only; they carry
  values forward and use median + p10 over the places. Licence: Syke open data, CC BY 4.0 (verify).
- **Fingrid 245**: wind power generation forecast, updated every 15 minutes, 15-min values,
  MWh/h. Our client and importer already support it: it is one config line in
  `laravel/config/fingrid.php`, but see the vintage rule below.
- **Holidays**: calculate Finnish public holidays and eves in code (fixed dates + Easter-based
  dates + Midsummer + All Saints' Day). Do not depend on pyhäpäivä.fi (it was down on
  2025-03-27 per their code comment).

## Data requirements (phase 1)

1. **Hourly resolution**, UTC hour keys (`timestamp` unix seconds + `utc_datetime` UTC text without
   a datetime cast, as in `spot_prices_hour` and `fingrid_hourly_values`; the local app timezone
   can be Europe/Helsinki).
2. **History from 2023-01-01** for every observed input that the source can give, because a
   short-term model needs at least two winters. Where a source starts later (JAO 2024-10-29),
   document it.
3. **Forecast vintages must be kept, never overwritten.** A model must be trained and scored on
   the forecast that existed at decision time, not on a later revision. Store forecasts
   append-only with the issue/fetch time (the same rule as `spot_price_forecast_runs` and
   `weather_outlook_runs`). Observations can be upserted (sources correct them).
   Note: Fingrid 245 is a rolling forecast; storing it in `fingrid_hourly_values` (upsert) would
   lose the vintages. Decide how to keep the vintages (for example, fetch the next 8 days a few
   times a day into an append-only table) and record the decision.
4. **One collector per source.** Prefer extending an existing collector before a new one:
   - Open-Meteo hourly wind/irradiance belongs in `app/Services/OpenMeteo/` (`weather:fetch`),
     not in a second Open-Meteo client. Keep the free-tier limits (10,000 calls/day,
     5,000/hour, 600/min; a 1-year archive request with many variables counts as many calls).
   - FMI is a new source (`app/Services/Fmi/`), unless Open-Meteo hourly data at the same
     coordinates is judged good enough. Decide and record it; the model's own feature design
     used FMI station data.
   - Syke is a new small collector (`app/Services/Syke/`).
   - Holidays: a small support class, no table.
5. Follow the collector pattern of `laravel/app/Services/Fingrid/` (client + importer +
   `AGENTS.md` with a `CLAUDE.md` symlink, config file, one command with a daily window and a
   `--from/--to` backfill, tests with `Http::fake`, exception class only in logs, a failed unit
   does not stop the others and the run exits 1).
6. No public UI. No change to `/spot-price` or to the third-party forecast import.

## Constraints (all phases)

- Free Open-Meteo API: the user treats the use as non-commercial while the site has no income.
  Check again before the site gets income. Attribution: "Weather data by Open-Meteo.com".
- Every production step (deploy, Railway variable, production backfill) needs the user's
  approval. Deploy rules: root `AGENTS.md`, "Production code deployment"
  (no deploy 04:00–09:00 Helsinki until the morning checkpoints are ready).
- Production storage: estimate the yearly row count and size of each new table before a backfill
  (an hourly table for 29 stations × 2 variables is about 0.5 M rows per year).
- Restricted data rule for later publication: `tasks/monthly-market-review/decisions.md`
  ("Rule for restricted data in the reviews").

## Out of scope (later tasks)

- A public page change (`/spot-price`, review pages); phase 5 only records the recommendation.
