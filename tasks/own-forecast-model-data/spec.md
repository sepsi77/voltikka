# Data for own spot price forecast models

Status: not started (created 2026-11-01). Read `tasks/AGENTS.md`, the root `AGENTS.md` and
`tasks/monthly-market-review/` (decisions.md, data-inventory.md) first.

## Goal

The user wants Voltikka to run its **own short-term (days) and medium-term (months) Finnish spot
price forecasts** in the end, instead of the third-party forecast it now shows on `/spot-price`
(`vividfog/nordpool-predict-fi`, imported by `spot:fetch-forecast`). Reason: a third-party
forecast is not unique to the site; own models make the pages and the monthly market review
distinctive, and Voltikka can keep and score its own forecast vintages.

**This task is only the data part:** collect the inputs that a short-term model needs and that
Voltikka does not yet collect. Building the model is a later, separate task. Do not build a model
here. Do not change `/spot-price`.

## What a short-term model needs (reference: nordpool-predict-fi)

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

## Requirements

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

## Constraints

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

- The forecast model itself (feature table, training, evaluation, vintage scoring).
- A medium-term (months) model; its inputs are mostly already collected for the monthly review
  (futures, hydro reservoirs, gas, carbon, weather outlooks).
