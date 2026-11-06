# Data inventory for the monthly market review

Status: draft for user review, 2026-11-01. Collected locally since 2026-11-01: G1, G2, G3, G4, G4b, G5, G5b, G7 flows (see decisions.md).

Legend:
- **Have** = Voltikka collects it now. **Gap** = we must add a collector or a manual step.
- Frequency = how often we must collect it so that the monthly numbers are complete.
- Priority: **P1** = needed for the first review, **P2** = makes the "why" section strong,
  **P3** = nice to have.

## 1. What drives the Finnish price (summary of the three reports)

All three reports agree on this ranking:

| Driver | Days–weeks | ~6 months | Why it matters for a monthly review |
|---|---|---|---|
| Wind output (≈9.5 GW installed) | 5 | 2–4 | Explains most daily swings and the cheap/negative hours |
| Temperature / heating demand | 4–5 | 3–4 | Sets the size of winter spikes; about 150 MW per °C (Fingrid) |
| Nuclear availability (OL1–3, Loviisa) | 4–5 | 3–4 | One unit out = 0.5–1.6 GW less cheap supply; outages are planned months ahead |
| Interconnectors SE1 (Aurora), SE3, EE | 4–5 | 3–5 | Decide if FI follows cheap SE1 or expensive SE3/Baltics/continent |
| Nordic hydro balance, reservoirs, snow | 2–3 | 5 | Main 6-month level driver through water values; spring flood sets Q2 |
| Gas (TTF) and carbon (EUA) | 2–3 | 4 | Sets the price of imported power in calm hours |
| Futures (FI = SYS + EPAD) | 1–2 | 4 | Best single 6-month estimate, but it missed the cold/calm tail in Q1 2026 |
| Flexibility (e-boilers ~1.5→3 GW, batteries ~1 GW) | 3 | 3 | Lifts the floor, cuts the peaks; fewer negative hours |
| Seasonality, weekday, hour | 3 | 4 | Predictable; must be removed before we say "prices rose" |
| Structural demand (data centres, industry) | 1 | 2 | Slow; mention only when a real load starts operation |
| Market rules, taxes, VAT | 1–2 | 1 | Explain changes such as 15-min MTU, electricity tax, VAT 25.5 % |

The reports also agree that the price is non-linear: the extreme months come from a
**combination** (cold + calm + outage + import limit + dry hydro). The review must therefore
show the drivers side by side, not one at a time.

## 2. Data that Voltikka has now

| Data | Where | Frequency | Notes for the review |
|---|---|---|---|
| FI day-ahead price, 15-min and hourly | `spot_price_quarters`, `spot_price_hours` (ENTSO-E A44, FI only) | hourly import | Full history. Basis for all spot metrics |
| Spot averages (daily, monthly, yearly, rolling) | `spot_price_averages` | after import | Reuse; add the extra monthly metrics in §4 |
| EEX futures EOD: FI, Nordic System, all Nordic zones; month/quarter/year | `electricity_futures_eod_prices` | daily 04:00 | FI history from 2026-04-08 only. FI − SYS gives the EPAD-like spread |
| Contract prices: daily snapshots, unit statistics, annual cost (v3), seller-set energy-price index | `contract_price_snapshots`, `contract_price_daily_statistics`, `contract_price_annual_costs` | daily | Medians by type; fixed 6/12/24 months; spot margin; monthly fees |
| Contract arrivals, withdrawals, payload changes | `contract_source_observations`, `contract_source_snapshots`, `replaced_by_linked_on` | daily | New and withdrawn offers; price changes per seller |
| Market-reset (monthly/quarterly) products and their new period prices | canonical pricing + statistics segment `quarterly` / `market_reset` | daily | Shows how retail follows the futures at quarter boundaries |
| Our fixed-term price forecasts and their evaluation | `fixed_contract_price_forecasts` | daily 07:30–12:00 | Direction for 6/12/24-month contracts; track record |
| Third-party spot forecast (nordpool-predict-fi) | `spot_price_forecasts` | every 6 h | **Only the latest value per hour is kept** (upsert). No vintages → we cannot score it later |
| Retail premium observations | `retail_premium_observations` | daily | **Private.** Context only, not a published series |
| Weekly offers | weekly-offers service | weekly | Can feed an "offers this month" box |

## 2b. What the nordpool-predict-fi model fetches

Studied at commit f600ab7 (2026-11-01). Project licence: MIT. The repo states no data
licences. The model is XGBoost; target = FI hourly spot price incl. VAT (c/kWh); horizon =
next hour to end of D+7. It retrains on each run on hourly data from 2023-01-01.

| Provider | Data | Used for | Key |
|---|---|---|---|
| Fingrid | 188 nuclear production; 181 wind production; 245 wind forecast; 268 installed wind capacity | features | free key |
| ENTSO-E | A80 (generation units, Olkiluoto) and A77 (production units, Loviisa) unavailability, nuclear only | future nuclear MW = 4,372 MW − outages | our key |
| JAO Nordic | `maxBorderFlow` SE1→FI, SE3→FI, EE→FI (replaced Fingrid capacity data after flow-based start) | import capacity features | none |
| FMI open data | temperature and wind speed, observations + forecast, 29 stations | features | none |
| Open-Meteo | wind speed at 9 points in SE/EE/DK/DE; solar irradiance at 7 FI sites | features | none, but free API is non-commercial |
| Syke hydrology API | areal precipitation and snow water equivalent, 10 FI catchments | features | none |
| sahkotin.fi | FI spot price | target | none, terms unknown |
| pyhäpäivä.fi | holidays | feature | none |

Published files in `deploy/` (read via raw.githubusercontent.com): `prediction.json`,
`averages.json`, `windpower.json`, `nuclear_outages.json`,
`import_capacity_daily_average.json`, `weather.json`, `narration*.md/json`, and
`data/dump.csv` (all features + actual and predicted price since 2023).

Conclusion: the model's inputs are the same short-term drivers as in §1. It has no hydro
reservoirs, no gas/carbon, no futures, and no neighbour prices, because its horizon is 7
days. For our monthly review we collect the short-term inputs ourselves from the primary
sources (Fingrid, ENTSO-E, JAO, FMI, Syke). We do not depend on the hobby project's
derived files, except possibly as a cross-check.

## 3. Gaps: data to add

### 3a. Daily, automatic (P1/P2)

| # | Data | Source | Resolution | Prio | Notes |
|---|---|---|---|---|---|
| G1 | Keep spot-forecast vintages | existing nordpool-predict-fi fetch | per fetch | P1 | Smallest change: store each fetch (or the 10:00 fetch each day) instead of overwrite. Needed to score "forecast vs outcome" honestly |
| G2 | Neighbour day-ahead prices: SE1, SE3, EE, DE-LU (+ maybe SE2, LV/LT) | ENTSO-E A44, same API key and `EntsoeService` | 15 min / hourly | P1 | Explains coupling: share of hours FI = SE1, FI = EE; FI − SE1 spread |
| G3 | FI consumption (124, 15 min), production (74, 15 min), net import (194, 3 min) | Fingrid open data, CC BY 4.0 (key: `FINGRID_API_KEY`) | 15 min | P1 | Monthly TWh and YoY; peak demand MW |
| G4 | FI production by type: nuclear 188, wind 181 (or 75, 15 min), hydro 191, district-heat CHP 201, industrial CHP 202, reserve/small-scale 205 | Fingrid open data | 3 min | P1 | Tested: 188 gave 1,717 MW on 2026-10-31 (OL3 out). Store hourly means, not 3-min rows. No measured solar series; use solar forecast 247 + capacity 267 as an estimate |
| G4b | **Household consumption profiles from Datahub**: 364 average hourly kWh per accounting point by user group (BE01 apartment block, BE02 electrically heated small house, BE03 not electrically heated small house, ...); 361 by annual-consumption band; 358 consumer vs company | Fingrid open data (Datahub) | hourly, lag 4 days, corrections within 11 days | P1 | Tested. This gives the **real consumption-weighted spot price for each house type**. Freeze the month after day 12 of the next month, or mark it preliminary |
| G5 | Wind forecast 246 (once a day) / 245 (15 min); installed wind capacity 268; solar capacity 267 | Fingrid open data | 15 min / hourly | P2 | Forecast error explains surprise days |
| G5b | Flexibility: electric boiler consumption 371; battery capacity 424; battery charge/discharge 398/399 | Fingrid open data | 1–3 min | P2 | Shows how flexibility lifts the floor in cheap hours |
| G6 | Temperature and wind speed: history (ERA5 archive, 1940–) for normals; observed month | Open-Meteo archive API (free tier, tested); Fingrid 178/182/185/196 (Helsinki, Jyväskylä, Rovaniemi, Oulu) as a check | daily | P1 | One weather source for history, forecast and outlook (G22). Use a few points weighted by population; deviation from 1991–2020 normal; heating degree days |
| G7 | Measured flows 60 (SE1), 61 (SE3), 55 (EE) – **collected**; day-ahead capacity from JAO `maxBorderFlow` (SE1→FI, SE3→FI, EE→FI) – gap | Fingrid open data; JAO | 15 min / hourly | P2 | Fingrid official DA capacity datasets (24–27, 112, 115) are zero or empty since flow-based coupling (checked 2026-11-01), so capacity needs JAO |
| G8 | Unavailability of generation and transmission (REMIT), **FI and SE1–SE3** | ENTSO-E A80/A77 (generation; same key, same method as the forecast model), A78 (transmission); Nord Pool UMM as a cross-check | event | P2 | OL1–3, Loviisa, and Swedish nuclear (Forsmark, Ringhals, Oskarshamn); Estlink, Fenno-Skan, Aurora outages; also the calendar for next month |
| G8b | ~~Finnish precipitation and snow water equivalent~~ (dropped: Open-Meteo snow and precipitation cover it) | Syke hydrology API (open) | daily | – | Finnish run-of-river hydro and the spring flood; Nordic reservoirs matter more (G10, G11) |
| G9 | Gas storage EU and Germany | GIE AGSI+ (free key) | daily | P3 | Supports the gas/winter narrative |

### 3b. Weekly, automatic or manual (P2)

| # | Data | Source | Prio | Notes |
|---|---|---|---|---|
| G10 | Norwegian reservoir filling % vs median, by zone | NVE Magasinstatistikk API (open) | P2 | Week number; NO total + NO1–NO5 |
| G11 | Reservoir filling by country (NO, SE, FI) | ENTSO-E A72 | P2 | One open series for all Nordic countries |
| G12 | Nordic hydrological balance (TWh vs normal) | Commercial (Montel, Energy Quantified, Refinitiv) | P3 | No open source. Quote a published figure with its date and source, by hand |

### 3c. Daily market prices outside electricity (P2)

| # | Data | Source | Prio | Notes |
|---|---|---|---|---|
| G13 | TTF gas front-month | EEX public chart API (EEX lists TTF futures) or a manual monthly value | P2 | Check the EEX terms and the product code first |
| G14 | EUA carbon price | EEX (EUA spot/futures, primary auction results are public) | P2 | Same as G13 |
| G15 | German baseload futures (Phelix DE month/quarter) | EEX public chart API (same collector) | P3 | Shows the continental level that FI imports in calm hours |

### 3d. Monthly or event-based, manual with a source link (P2/P3)

| # | Data | Source | Notes |
|---|---|---|---|
| G16 | Planned nuclear outage calendar (next 6 months) | TVO, Fortum releases; UMM | One small table, updated when a release appears |
| G17 | Planned interconnector maintenance | Fingrid outage plans; UMM | Same table as G16 |
| G18 | Capacity changes: new wind/solar MW, batteries, e-boilers, Meri-Pori reserve | Fingrid quarterly reviews, Suomen Tuulivoimayhdistys, Fingrid news | Quarterly is enough |
| G19 | Official monthly statistics: consumption/production | Energiateollisuus monthly statistics; Tilastokeskus (StatFin, CC BY 4.0) | Cross-check of G3/G4; published with delay |
| G20 | Consumer price index: electricity sub-index | Tilastokeskus (StatFin) | Lets us compare "what households paid" with an official index |
| G21 | Taxes and rules: electricity tax, VAT, market rule changes | Vero, Fingrid, Nord Pool | Event log only |
| G22 | Weather outlook: ECMWF 15-day ensemble (50 members) and seasonal forecast (50 members, 183 days): temperature, wind, precipitation in FI, NO, SE | Open-Meteo ensemble and seasonal APIs (free tier, tested 2026-11-01) | We do not build a weather model. Show as a probability (warmer/colder than normal) |
| G23 | Neighbour-zone real wind production (SE, DK, DE, EE) | ENTSO-E A75 (same key) | Better than wind speed for the "why" section |

### 3e. Added after the second review (2026-11-01)

| # | Data | Source | Prio | Notes |
|---|---|---|---|---|
| G24 | Finnish holidays and working days | Calculated in code (fixed rules) | P1 | Needed to compare months fairly (working days, Christmas). No external source |
| G25 | Cross-check of the FI futures | Euronext Nord Pool closes (manual, once per review) | P2 | The reports quote Euronext; we collect EEX. If the two differ, say which one we use |
| G26 | Official household electricity price | Tilastokeskus StatFin "Energian hinnat" (quarterly), CC BY 4.0 | P3 | Official comparison point for our contract medians |
| G27 | Voltikka's own visitor behaviour | First-party `contract_order_clicks`, page feedback, GTM/Plausible aggregates | P3 | Share of seller clicks by pricing category (spot vs fixed). Aggregates only; no new collection needed |
| G28 | Network tariff and tax events | Manual event log from distributor and Vero announcements | P3 | Only if the review covers the full bill. Default: the review covers the energy price only |

Checked and not available as open data (quote published figures by hand, with date and
source, when needed):
- actual Nordic System Price day-ahead (Nord Pool licence) – use SYS futures for the spread;
- Nordic hydrological balance in TWh (commercial) – see G12;
- share of households on spot vs fixed contracts – no open dataset found.

## 4. Monthly metrics to calculate from the data

These are the candidates for the frozen monthly snapshot. The user selects the final set.

**Spot (from our data):**
- mean price (c/kWh incl. VAT and €/MWh excl. VAT), median, p10/p90;
- **consumption-weighted mean** for each house type (weight = Datahub profile, G4b: apartment,
  electrically heated house, not electrically heated house) and for all of Finland (G3) –
  this is what spot customers really pay, and it differs from the simple mean in winter;
- month-on-month and year-on-year change;
- cheapest and most expensive day, hour and quarter-hour;
- number of negative hours, hours below 1 c/kWh, hours above 20 c/kWh (incl. VAT);
- average daily spread (max − min) and the hour-of-day profile (weekday × hour heat map);
- day/night and weekday/weekend difference.

**Spot customer vs fixed contract (unique to Voltikka):**
- what a typical spot contract (median margin + monthly fee) cost last month at 2,000 / 5,000 /
  18,000 kWh per year, compared with the median 12-month fixed contract. Reuse the
  bill-comparison period engine;
- rolling 12-month version: "a fixed 12-month contract signed one year ago vs spot".

**Drivers (from G2–G11):**
- wind TWh, capacity factor, and YoY; nuclear availability % and the outage days;
- consumption TWh and YoY; peak demand MW; net import TWh;
- mean temperature and deviation from normal; heating degree days;
- share of hours with FI = SE1 and FI = EE price; mean FI − SE1 and FI − SE3 spread;
- reservoir level vs median at month end.

**Futures and outlook:**
- FI curve (next 1–6 months, next quarters, next year) at the first and last trade date of the
  month, and the change; FI − SYS spread; converted to c/kWh incl. VAT;
- realised month vs its month-ahead future (track record; available from April 2026).

**Contracts:**
- median energy price of 6/12/24-month fixed contracts and the seller-set index, start vs end
  of month;
- median spot margin and monthly fee;
- number of offers by pricing category; new and withdrawn contracts; number of sellers that
  changed prices;
- new period prices of quarterly/monthly market-reset products.

## 5. Requirements that make the review citable

- **Frozen numbers.** Write each published monthly metric once to a snapshot table with the
  method version and the data timestamp. A later correction adds a new version and a visible
  note. Do not let a recalculation change a cited figure silently.
- **Score our own outlook.** Store the published outlook values (futures strip, our
  fixed-term direction, spot forecast) so that the next review can show the result.
- Stable URL per month, a CSV download, a short method section, and a source list with
  licences (Fingrid and FMI: CC BY 4.0 with attribution).
- Licence check before we publish third-party numbers: EEX settlement prices, ENTSO-E data,
  Nord Pool UMM, GIE, NVE.

## 6. Additional content ideas

1. **Spot vs fixed scorecard** (see §4): the strongest media angle that only Voltikka can
   calculate from its own data.
2. **Forecast accountability box**: last month's outlook vs the outcome.
3. **Next-month calendar**: known outages, maintenance, quarter-boundary resets, tax or rule
   changes, holidays, daylight-saving change.
4. **Contract market movements**: new offers, withdrawals, promotions, price changes at the
   start of the month.
5. **"Month in one chart"** for the video: daily price bars with wind and temperature lines.
6. **FI vs neighbours** chart: why Finland was cheap/expensive compared with Sweden and the
   Baltics.
7. **Best hours to use electricity** (heat map) – useful and simple for consumers.
8. **Scenario band** for the outlook: futures as the central case, plus a cold/calm upside
   case (the reports show that futures underestimated Q1 2026).
