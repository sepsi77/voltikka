# Decisions

## 2026-11-01 – Data basis study

- The three reports in `research/electricity-price-drivers/` agree on the driver ranking:
  wind, temperature, nuclear and interconnectors for days–weeks; Nordic hydro, gas/carbon,
  futures and planned outages for ~6 months. The data list follows this ranking.
  `Finland Electricity Price Factors.md` is 334 KB because of embedded base64 images; the
  text is about 60 KB.
- nordpool-predict-fi (MIT, commit f600ab7) uses only short-term inputs (7-day horizon).
  We collect the same inputs from the primary sources, not from its derived `deploy/` files,
  because the project is a hobby project with no stated data terms.
- Open-Meteo's free API is for non-commercial use only. Voltikka is operated by a company,
  so we do not use the free Open-Meteo API. FMI covers Finnish weather. (Superseded below.)
- A paid Open-Meteo plan is an option (user note, 2026-11-01). It is not needed for the
  review of the past month: FMI gives Finnish observations and normals, and ENTSO-E A75
  gives the real wind production of the neighbour zones, which is better than wind speed.
  It helps the outlook: one simple API for ECMWF ensemble (2–6 weeks) and seasonal
  forecasts, and for precipitation and snow in Norway and Sweden (hydro). Decide when we
  build the outlook section (P2). Show these forecasts as probabilities, because their skill
  after about 10 days is low.
- Found: `spot:fetch-forecast` upserts on (source, region, timestamp), so only the latest
  forecast per hour is kept. To score the forecast in a review we must keep vintages (G1).
- Found: `EntsoeService` fetches FI only (A44). Neighbour prices (G2) and REMIT
  unavailability (G8) use the same API and key, so they extend the existing service.
- Nordic hydrological balance (TWh) has no open source. Use open reservoir data (NVE,
  ENTSO-E A72) and quote published balance figures by hand with their date and source.
- The retail premium dataset stays private; it can inform text only.
- Open: licence terms for publishing EEX settlement prices, ENTSO-E, JAO, GIE and NVE data.

## 2026-11-01 – API access tested

- User decision: use the **free Open-Meteo API**. The site makes no money and has no way to
  make money yet, so the user treats the use as non-commercial. Check this again before the
  site gets any income (ads, affiliate links, paid features).
- Open-Meteo tested: archive (ERA5), ECMWF 15-day ensemble (50 members) and seasonal
  (50 members, 183 days) all respond. Open-Meteo replaces FMI as the computed weather
  source, so one collector covers history, normals and outlook.
- Fingrid key is in `laravel/.env` as `FINGRID_API_KEY` (renamed by the user; tested).
  Production needs the same Railway variable later; that is a production variable write and
  needs explicit approval. The key works. Fingrid returned HTTP 429 for fast consecutive
  requests ("try again in 2 seconds"): a collector must wait between calls.
- Fingrid publishes official day-ahead capacities (24–27, 112, 115) and measured flows, so
  JAO is not needed for the review of the past month.
- Fingrid Datahub datasets 358–365 give real hourly household consumption by user group
  from 2023-08-01. They let us calculate the real consumption-weighted spot price per house
  type. Lag 4 days; corrections within 11 days.

## 2026-11-01 – Completeness review before building

- Added G24–G28 (holidays, Euronext cross-check, Tilastokeskus price, own visitor data,
  tariff/tax events) and extended G8 to Swedish nuclear. Dropped Syke (Open-Meteo covers
  snow and precipitation). FMI and JAO are not needed. The source list is complete for the
  first review; G25 and G28 are manual steps.

## 2026-11-01 – First collectors built (G1, G2, G3/G4/G4b), local only

- G1: new append-only `spot_price_forecast_runs`, one row per distinct feed (unique source +
  SHA-256 of the body), written in the same transaction as the display upsert. Chosen over a
  per-point vintage table because one JSON row per feed is smaller and keeps the evidence exact.
- G2: neighbour zones go into the existing `spot_prices_quarter/hour` tables with their own
  region code. Reason: the tables already have a (region, timestamp) key, and every public
  reader filters `region = 'FI'` (all 15 reader files checked). Neighbour rows get `vat_rate` 0.
  Separate command `spot:fetch-neighbours`, so a neighbour failure cannot affect the FI import.
- Found while testing G2: ENTSO-E sends curve type A03 (omits repeated prices) for FI too. The old
  parser did not fill the gaps. Local copy 2025-09-30..2026-10-24: 46 FI hours missing, 766 hourly
  means from 3 of 4 quarters. October 1–24: 50 wrong hours (max 0.505 c/kWh), monthly mean
  4.205 stored vs 4.184 correct (VAT0). Parser fixed; stored history unchanged (needs approval).
- DE-LU publishes two sequences; sequence 1 is SDAC (matched SMARD/Energy-Charts 254.82, 246.24,
  237.1, 225.74 €/MWh for 2026-10-31 00:00–01:00 CET).
- G3/G4: one generic `fingrid_hourly_values` table (hourly means, upsert) and one command
  `fingrid:fetch` (daily window or --from/--to backfill). 23 datasets; live run 82 s.
- Fingrid official day-ahead capacity datasets (24–27, 112, 115) are all zero or empty since
  flow-based coupling. Dropped. Day-ahead capacity needs JAO (new pending task). Congestion can
  already be shown with the FI = SE1 / FI = EE price-equality share and the measured flows.
- Sign rules verified: flows 55/60/61 and net import 194 are negative for import.

## 2026-11-01 – Remaining collectors and the FI history repair (local)

- User decision: repair the FI spot history; build all collectors before any release.
- Full local dry run of `spot:repair-history --from=2022-01 --to=2026-10`: 13,110 missing hours,
  663 changed hours, 1,961 missing quarters. 2022–2024: every second month held only its first
  day (~4,250 missing hours per year); e.g. 2022-12 stored mean 38.79 vs correct 24.63 c/kWh VAT0.
  Past rolling 30/365 rows stay unchanged (dated evidence of stored contract statistics).
- Four collectors were built in parallel by agents, each in its own directory (shared files edited
  by the manager only): `weather:fetch` (Open-Meteo ERA5 + ECMWF ENS/EC46/SEAS5 vintages),
  `entsoe:fetch` (outage versions A77/A78/A80, generation A75, reservoirs A72), `jao:fetch`,
  `nve:fetch-reservoirs`, `futures:fetch-eex-europe` (DE power DEBM/DEBQ/DEBY, TTF G3BM, EUA FEUA)
  and `gas-storage:fetch` (GIE AGSI+, schedule runs only when GIE_API_KEY is set).
- Terms found: EEX public data may not be published without EEX's written approval (Media Usage
  licence) – this also applies to the Nordic futures. JAO forbids dissemination without approval
  (contact@jao.eu). GIE allows reuse with the source credit "GIE AGSI". Open-Meteo CC BY 4.0
  ("Weather data by Open-Meteo.com"). NVE licence not stated; credit "NVE". Fingrid CC BY 4.0.
- NVE reference: its "last 20 years" min/median/max is 2006–2025 (proved against the weekly
  history); week 43 2026 Norway 69.5 % vs median 82.6 % (the reports' 84.6 % is another period).
- Open issue reported by the EEX agent, not verified or changed: price-ticker now returns
  `[[null,…]]` for unlisted maturities, so discovery may treat every candidate as listed; a
  stricter check could shorten the 04:00 run from ~3.5 h to ~1 h. Needs a user decision.

## 2026-11-01 – Release deploy-000013-9f1c253202e2

- User asked to commit, deploy and run the first collection in production. Commit 9f1c253;
  image built after three transient npm ETIMEDOUT failures (one tarball from registry.npmjs.org);
  PHP 8.4.27 as before. Deployed 10:40 Helsinki after all four morning checkpoints were ready.
  Deployment e3f879c3 SUCCESS; /up, /, /spot-price and /sahkosopimus/tilastot gave 200; all 11
  migrations ran. FINGRID_API_KEY was set in Railway with --stdin and --skip-deploys.
- First manual production runs: forecast run 1; neighbour zones 192 quarters each (VAT 0);
  Fingrid 23 datasets / 8,000 rows; weather 160 days + 10 ENS runs; ENTSO-E 61 outage rows,
  4,700 generation hours, 24 reservoir weeks; JAO 760 rows; NVE 570 weeks + 318 references;
  EEX Europe 651 settlements, 0 failures. Gas storage not run (no GIE_API_KEY).
- The daily outage window reads only documents updated in the last 3 days, so the active OL3
  outage (revision from late October) appears only after an outage backfill.
- Production dry run of spot:repair-history 2022-01..2026-11: 12,934 missing hours, 589 changed
  hours, 1,130 missing quarters; 2025-11 and 2026-03 failed (ENTSO-E error) and must be rerun.
  Today's prices (2026-11) were imported before the deploy with the old parser (2 wrong hours).

## 2026-11-01 – Repair applied, data-use rule, EEX discovery, own models

- Production apply of `spot:repair-history --from=2022-01 --to=2026-11`: all 59 months fetched;
  12,942 hours and 1,329 quarters inserted, 691 hourly means corrected, averages recalculated.
  A second production dry run found 0 missing and 0 changed rows. Corrected yearly FI means:
  2022 154.0, 2023 56.5, 2024 45.6, 2025 40.5 €/MWh (VAT0), which agree with the reports.
- User decision: the reviews only refer to EEX, JAO and other restricted data (figures and
  sources in our own text); they do not republish the data sets. So no licence request now.
- GIE_API_KEY is in laravel/.env and in Railway (set with --skip-deploys, so it is active only
  after the next deployment). Local live run: EU 74.68 %, DE 61.17 % full on 2026-10-30.
- EEX discovery: confirmed that the price-ticker returns `data: [[null, ...]]` for unlisted
  maturities (FNBM 202706, 202812). Production log: discovery accepted 25/24/12 candidates and
  the run made 793 EOD requests (02:01–05:34 UTC); only 268 area/maturity pairs had prices on
  2026-10-30. In October every zone range was inside NP's range. Fixed locally (a row is listed
  only with a non-null value). Live `--area=NP --dry-run`: 7 months, 11 quarters, 10 years
  discovered, 28/28 requests with prices. Expected full run about 1 h 40 min.
- User goal: Voltikka will in the end run its own short- and medium-term spot forecasts instead
  of third-party ones. Not now. The nordpool-predict-fi inputs we do not yet collect: hourly
  weather history and 8-day forecasts at FI stations and neighbour wind points, solar irradiance,
  Fingrid 245, Syke hydrology, holidays.

## 2026-11-01 – Rule for restricted data in the reviews (user decision)

- User decision: the reviews may publish a handful of individual EEX and JAO figures, with the
  source and date named, because they do not republish the data set.
- Facts recorded with the decision: EEX's disclaimer (eex.com/en/disclaimer) forbids publication
  "in any manner without the prior written approval of EEX AG" and has no quotation exception;
  only a single personal non-commercial copy is permitted. Supporting arguments: a single price is
  a fact without copyright, and the EU database right covers only substantial parts. Not legal
  advice; residual contract risk because the data comes through EEX's own interface.
- Working rules: quote only a few individual values with source and date; no EEX or JAO series as
  tables, full curve charts or CSV; derived Voltikka values (changes, own forecasts) are ours.
  Fingrid, GIE ("GIE AGSI"), Open-Meteo ("Weather data by Open-Meteo.com") and ENTSO-E data can be
  published with attribution.

## 2026-11-01 – Production backfills (user approval)

- User approved the production backfills. Deploy-000014 (EEX discovery fix, GIE key active) is
  live; the first `gas-storage:fetch` stored 13 gas days each for EU and DE.
- Started 10:17 UTC as three background chains in the app container (logs
  `/app/storage/logs/backfill-20261101-{fingrid,entsoe,weather}.log`):
  - Fingrid 23 datasets 2023-01-01..2026-10-31 (history for the own-model task);
  - ENTSO-E outages from 2023-01-01, reservoirs from 2015-01-01, generation from 2024-01-01;
    SE1/SE3/EE/DE-LU spot from 2023-01-01; JAO from 2024-10-29; NVE `--all`; GIE from 2020-01-01;
  - Open-Meteo observed weather from 1991-01-01 for the 6 Finnish points only. The 4 hydro
    points follow on 2026-11-02 because of the 10,000 calls/day limit (root AGENTS.md, Dated
    follow-ups).
- Start dates: 2023 where the own-model task needs two winters; generation from 2024 to limit
  rows (about 1.2 M); reservoirs from 2015 and GIE from 2020 for normal and range comparisons.
