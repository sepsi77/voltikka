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
