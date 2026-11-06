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

## 2026-11-02 – Backfill results

- All chains stopped on 2026-11-01 at 20:04 Helsinki. EXIT 0: Fingrid (15:55), ENTSO-E
  reservoirs, generation (1,223,035 rows), the 4 neighbour zones, JAO, NVE and GIE.
- EXIT 1, rerun with EXIT 0 the same evening (log `backfill-20261101-rerun.log`): weather Turku
  and Tampere (the points before and after them worked, so not the daily limit); outages SE1 A77,
  SE3 A77 and SE1->FI A78 (request errors). `--zone` cannot select a document type, so SE1 and SE3
  A80 ran again and added 0 rows.
- Outages took 5 h 11 min for about 3,700 documents (about 5 s each): one extra mRID query per
  document for its earlier revisions, and slow ENTSO-E answers. Daily runs are small. A future
  outage backfill can skip the old revisions if the as-known history is not needed.
- A remote `nohup ... &` without `setsid` stopped when the local SSH session was stopped. Start
  long production jobs with `setsid nohup ... & disown`.
- Local sync at 06:22 on 2026-11-02, after deploy-000015 (the own-model tables; the sync needs
  the same tables on both sides, so it failed the evening before): outages 14,526, Fingrid
  1,150,773, spot SE1/SE3/EE/DE-LU 33,648 hours each, JAO 140,928, NVE 9,960, GIE 4,990,
  weather 78,562 daily rows for the 6 Finnish points.
- EEX with the fixed discovery: the 2026-11-02 run started 04:01 and was ready 05:43
  (1 h 42 min, 0 failures, 7,871 points). Earlier clean runs ended at about 07:35. Root AGENTS.md
  deploy-window text updated. The point count fell from 8,113 (2026-11-01) to 7,871 with the
  same latest trade date. Probable cause: the about 45-day window lost one old trading day and
  gained none over the weekend (about 270 points per trading day). Check again after a normal
  weekday run.
- Hydro weather points (approved, started 12:30 Helsinki on 2026-11-02, log
  `backfill-20261102-hydro.log`): no2_blasjo and se1_akkajaure complete; no5_aurland (piece 1996)
  and se2_storuman (piece 2012) failed with RuntimeException, the same transient Open-Meteo
  failure as Turku/Tampere. The wrapper logged "EXIT 0" by mistake: it read `$?` after `$(date)`.
  Store the exit code in a variable first. Rerun of only those points from the failed year
  (upsert; log `backfill-20261102-hydro-rerun.log`): EXIT 0 for both, ended 13:44 Helsinki.
  Production now has 13,084 era5 days (1991-01-01..2026-10-27) for each of the 4 points.
  voltikka-38 was told "hydro done" for its Open-Meteo runs.

## 2026-11-01 – Review shape (user decisions)

- **Timing:** one release on about the 7th of the next month. All sources have arrived then
  (ERA5 is 5–6 days late). Datahub house-type weighted prices are marked "preliminary"
  because Datahub can still correct them until about day 11.
- **Text:** an agent writes the draft from the frozen numbers; the user edits and approves it
  before publication.
- **Outlook v1:** the FI futures central case for the next month and the next quarter (a few
  quoted values, converted to c/kWh incl. VAT; no curve chart, see the restricted-data rule),
  the ECMWF weather outlook as a probability, a calendar of known events, and a written risk
  note (cold + calm upside). No numeric own scenario band until a method exists. From the
  second review: last month's outlook vs the outcome.
- **Length and audience:** short, for consumers and media: key figures box, about
  800–1,200 words, 4–6 charts. The CSV holds the detail.
- Agent assumptions not contradicted by the user: Finnish language; energy price only (no
  network fees or taxes); published figures frozen in a snapshot table.
- Section order (draft): key figures; the month in three sentences; spot review (month chart
  with wind and temperature, extremes, negative hours, weekday × hour heat map); why the price
  moved (driver table); what households paid (spot vs fixed by house type); contract market;
  outlook; method, sources, CSV, corrections.
- Next step: a prototype of the October 2026 review from production data, before the snapshot
  table and the page are built. The prototype shows which metrics work and sets the final
  metric set.

## 2026-11-01 – Prototype: September 2026 (user choice)

- User chose September 2026 as the first prototype month and asked to leave open every point that
  depends on the running backfills. Files and findings: `prototype/README.md`.
- Main findings: the mean alone misleads (mean 7.15, median 4.52 c/kWh); raw new/withdrawn offer
  counts are mostly ID churn (392/419 raw, 37/37 without trusted replacements); a one-month spot vs
  fixed comparison misleads, so the scorecard uses year-to-date with consumption weighting; price,
  wind and temperature go in panels with one time axis (no dual axis).
- Open decisions for the user are listed at the end of `prototype/README.md`.

## 2026-11-01 – Review reshaped for two audiences (user decisions)

Replaces the shape decision above where they conflict ("Review shape", same date).

- Reason (user): the past-month review alone is not interesting for consumers. They want to know
  how the market will affect their bill in the coming months, and whether they should change their
  behaviour (high-usage spot customers in particular).
- **Two audiences, one page, one URL.** Layer 1 for consumers at the top; layer 2 for journalists
  below, with more detail and the CSV. A short monthly media email points to the page.
- **Layer 1 (consumers):** (1) the expected monthly energy cost for the next 3–6 months for three
  example households (apartment 2,000 kWh, house without electric heating 5,000 kWh, electrically
  heated house 18,000 kWh) on spot and on a fixed contract, with a cold-and-calm bad case;
  (2) "what certainty costs now": the futures-implied 12-month spot cost vs the median new
  12-month fixed contract, in euros per year per house type; (3) for spot customers: the usually
  cheap and expensive hours of the coming month and what a shift of flexible use saves in euros.
- **Layer 2 (journalists):** key figures box (6–8 citable numbers, including the new
  forward-looking Voltikka figures), last month's spot review, the driver table, the contract
  market, the detailed outlook (quoted futures values, weather outlook, events calendar, last
  outlook vs result), method, sources, CSV, corrections.
- **Timing:** 1st–2nd working day of the month. Late data (ERA5 weather, Datahub weights for the
  past month) affects only layer 2 details; mark them preliminary or add them on about the 7th.
  This replaces the "about the 7th" decision.
- **Bill scope:** energy + electricity tax. Network fees are not included, and the page says so.
  Verify the current electricity tax (class I, incl. the security of supply fee and VAT) at
  implementation; the code has no tax constant now.
- **Advice:** euros and risks plus tentative recommendations. User: "The site has to be
  opinionated for it to be useful." Working rules (agent proposal): a recommendation names the
  household type, the condition and the reason in euros and risk (for example "if you heat with
  electricity and cannot stand a cold-week bill, consider a fixed contract now: certainty costs
  about X € a year"); store each recommendation with the review and score it in later reviews, so
  the opinion has a public track record. This applies to the review only. The rule of the
  fixed-term forecast page (rise/fall/unchanged, never lock/wait) is unchanged unless the user
  decides otherwise.
- Method for layer 1 (agent proposal, to validate): FI futures give the monthly base level; each
  house type's Datahub hourly profile (dataset 364, 2023–2026) gives a monthly profile cost factor
  (weighted price / base price); add the median spot margin and monthly fee. The rough flat-profile
  example on 2026-10-06: spot about 8.8 c/kWh incl. margin vs 12.0 c fixed (about 160 €/year at
  5,000 kWh); an electrically heated house has a smaller gap.
- User approved (2026-11-01) the two working rules for recommendations: each one names the
  household type, the condition and the reason in euros and risk; each one is stored with the
  review and scored in later reviews (public track record).

## 2026-11-01 – Consumer-type contract results (user idea)

- User idea: calculate what each Fingrid consumer type would have paid with the different
  contracts, "kinda like best contracts in month X".
- Plan (agent proposal, not yet built):
  - Consumer types: Datahub 364 user groups. Stored series BE01–BE14; BE01 apartment, BE02
    electrically heated small house, BE03 small house without electric heating are certain;
    BE04–BE06 look like households, BE07+ businesses. Verify Fingrid's official code list before
    use. Datahub 364 history starts 2023-08-01. Monthly kWh = the group's real consumption.
  - Engine: reuse `BillComparisonService::periodRowsForContracts()` (canonical period pricing,
    realised hourly spot, phases, promotions, fees). Add one optional hourly consumption profile
    to the canonical period request; today spot hours are flat and Time uses 85/15. No parallel
    pricing code.
  - Prices of month start: store on the 1st of each month the IDs of the contracts on sale and
    their published interpretation IDs (immutable), and price them again at month end. The
    September prototype uses the existing historical reconstruction instead.
  - Output per group: cheapest overall, cheapest and median per category, actual vs normal-price
    cost (promotion mark). Show per-category winners plus the running result since January
    (rolling 12 months from 2027), because one month favours spot in autumn and fixed in a cold
    winter.
  - The same engine with a futures-based price path gives the forward consumer item ("best
    contracts on sale now for your home type this winter").
- User decisions on this plan (2026-11-01):
  - Update the bill comparison to the more realistic Datahub profiles. Agent answer to the user's
    question: no new required field. `/maksatko-liikaa` chooses the profile from its heating
    toggle (on: BE02; off: BE01 at low and BE03 at higher annualised consumption, threshold from
    Datahub data); the listing bill mode and the contract-page module choose from annualised
    consumption only. An optional home-type choice can come later if needed. Bills before
    2023-08-01 keep the flat profile. This changes public euro figures, so it is a reviewed change
    with tests, separate from the prototype.
  - Ignore promotions in the consumer-type results: rank by the normal-price cost.
  - Show an overall winner as well as the category winners. User reason: winners can earn links and
    social media mentions from the companies. Rules must be public and stable (for example: on sale
    on day 1 of the month, national availability, normal price, the same consumer types every month).
    A later "best in month X" badge with a link back to the review is possible; never paid.
- User decision (2026-11-01): winner marks are shareable social media images, not HTML badges.
  Agent notes: render them from the frozen monthly snapshot with the existing code-rendered
  pipeline (`videos/`, HTML scenes → Playwright; or the OG image builder), in 1:1 and 9:16 (and
  1200 × 630 for link previews). Content: month, consumer type or category, contract and company
  name, the euro figure, and the review URL as text, because an image carries no link; the link
  comes when the seller posts the image with the review URL. Company names only, no seller logos
  unless permitted. Never paid.

## 2026-11-01 – Consumer layer shape (user approved)

- One household definition everywhere: the Datahub 364 groups at their real average consumption
  (apartment BE01, house without electric heating BE03, electrically heated house BE02; verify the
  code list), with one sentence that scales the euros for a reader who uses more. This replaces the
  2,000 / 5,000 / 18,000 kWh example households of the earlier layer 1 decision.
- One calculation: for each home type, one run of the bill comparison engine (with the profile
  option) over the coming months at futures-based prices. It gives the monthly bill outlook, the
  best and median spot and fixed contracts on sale now, and the cost of certainty (median fixed −
  median spot). No separate method for each item.
- Layout: one card per home type (bill for the coming months, spot vs fixed, best contracts,
  tentative recommendation, one line "last month's winner for a home like yours"); below the
  cards two short shared sections: the cold-and-calm bad case, and the cheap/expensive hours with
  shifting savings for spot customers. Last month's full winner tables go to the journalist layer
  and the social media images.

## 2026-11-02 – Consumer layer prototype (user approved the work)

- Datahub 364 codes verified on data.fingrid.fi/en/datasets/364: BE01 apartment in an apartment
  block, BE02 electrically heated small house, BE03 small house without electric heating. Value =
  average kWh of one accounting point in one hour. Annual kWh (12 months to 2026-09): 2,132 /
  11,111 / 6,843.
- Engine: `CanonicalPeriodPricingRequest` and `BillComparisonRequest` have an optional
  `hourlyConsumptionWeights` (UTC hour => weight; scaled to periodKwh). Spot per hour, General and
  package by weighted kWh, Time by real hours (07–22 Helsinki, `SpotPriceAverageService`), Season
  keeps 85/15 inside winter months. Bad weights throw. Null = old path. Full suite 3,181 passed.
  Local only; no public surface passes weights.
- Coming months: one 12-month run (Nov 2026–Oct 2027) per home type and weather analog. A
  winter-only run favoured fixed prices unfairly. Futures: month future, else the quarter level
  minus its month futures. Analogs: winters 2023-24, 2024-25, 2025-26; each target hour takes the
  source hour with the same month, day of month and local hour; price = source spot − mean of the
  mapped hours + future. Weekday matching repeated cold days (January level +3.6 c/kWh) and was
  dropped. Ranking: the mean of the three analogs.
- Past month: contracts on sale on day 1 whose current source episode covered day 1 (246 of 293
  for September; the 47 others changed terms and are excluded in the prototype). Real hourly spot.
- Rules found necessary (prototype of the public winner rules): national; the annual kWh limits
  fit the home type; normal-price cost; no overall win for consumption-effect contracts (base
  price only) or, in the forward run, market-reset contracts (the period engine holds the current
  reset price flat); a terms check before a contract can win: restricted names (existing
  customers, students, business; one seller labels a "(yritys)" product Household) and promotions
  whose normal price is unknown.
- Results (files `consumer_inputs.py`, `run_consumer.php`, `consumer_results.py`,
  `consumer-results-2026-11.json`): September winners were spot and quarterly-reset contracts
  (Korpela Kvartaali, Sähkötytöt Pörssisähkö). Coming 12 months: spot wins for the apartment
  (best 248 €, median spot 286 € vs median fixed term 310 €); fixed terms win for houses (best
  751 € without electric heating, 1,176 € electrically heated). Cost of certainty (median fixed
  term − median spot): +17..+29 € apartment, +2..+50 € house, −27..+67 € electrically heated
  house. Energy only; the electricity tax adds 2.918 c/kWh (verify on vero.fi).
- Engine fact for later: the period Spot cost floors each hour at 0 (`max(0, spot + margin)`), so
  negative hours give no credit. More weight in negative hours makes the floor matter more.

## 2026-11-06 – First complete review: October 2026 (user decisions)

- User: the September draft had the pre-reshape structure. User chose **October 2026** (edition
  2.11.2026) for the first complete review, because the consumer forward run, the first stored
  ECMWF outlook and the current forecast model match that edition. Draft:
  `prototype/2026-10-katsaus.md`; the September draft stays as the old-shape prototype.
- Inputs: local sync 2026-11-06; futures trade date 2026-10-30; contracts on sale on 2.11. with
  their exact terms of that day (`CONSUMER_FORWARD_DATE`, 271 of 284); past month October
  (245 of 279 with exact day-1 terms). ERA5 ends 30.10., Datahub October is preliminary.
- Electricity tax verified on vero.fi: class I 2.325 c/kWh VAT 0 (energy tax 2.24 + security of
  supply fee 0.085) for 1.4.–31.12.2026; 2.918 incl. VAT. 2027 assumed unchanged.
- Monthly bill outlook, bad case and cheap hours: `prototype/consumer_outlook.py`, with one
  typical spot (median margin and fee) and one typical 12-month fixed contract (median price and
  fee) on the same hourly paths. The engine run gives the 12-month rankings. Python 12-month
  totals agree with the engine medians within about 2 %.
- Fix: the analog paths map days by day of month, so their weekdays are not real. Cheap hours
  use the real source months (2023–2025 Novembers) shifted to the futures level.
- User approved three items for heavy users (from the agent's list of reader questions): a
  January reset estimate for quarterly and monthly contracts (site method, beta 1.0, pricing
  vintage reference: quarterly about +3.8 c/kWh to about 16.9; monthly January about +4.4 c/kWh
  from November), one line per contract type on each card (spot, reset, fixed term ending), and a
  flexible-user line on the electrically heated house card (1 kWh from each of the 4 dearest to
  the 4 cheapest hours a day, at most half an hour's use: about 900 kWh a year, about 77 € a
  year saved on spot).
- The draft is about 2,100 words with markdown (consumer layer about 1,000). Longer than the old
  800–1,200 target, which was for one layer.


## 2026-11-06 – October draft shortened (user feedback)

- Spot vs fixed: the typical 12-month comparison showed that the median spot contract is cheaper
  than the median 12-month fixed contract for every home type; the contracts that beat spot are
  24–36-month contracts, whose flat price includes the cheaper later years (FI year futures
  2027 8.41, 2028 7.14 c/kWh incl. VAT on 30.10.). User rule: comparing spot with longer contracts
  is fine, but the text must talk about the price over the coming winter and say that fixed costs
  more over the summer and other periods. `consumer_outlook.py` now gives winter (Nov–Mar) and
  rest-of-year sums; the bill chart shows 12 months for the two house types.
- Consumer layer: focus on the two house types; the apartment case is three sentences. Dropped
  the cheap-hours section (self-evident) and the per-card detail lines. The bad-case section is
  now "Miltä tuleva talvi nyt näyttää".
- Journalist layer: no extremes or counts for their own sake (cheapest/dearest day, max hour and
  quarter, negative hours, hours below 1 c). A price anomaly appears only with its explanation
  (30.10.: low wind and Olkiluoto 1 off line). Dropped the heat map, offer counts, fee details and
  the long events list.
- (same day, user comments) Journalist layer: "Mikä vaikutti sähkön hintaan" table has factor,
  effect (nosti/laski/vähäinen) and explanation. Key figures hold outcome figures only (spot mean,
  weighted price, October bill spot vs fixed, 12-month fixed median, coming-winter comparison); a
  figure is not repeated in a later section of the layer. "Sopimusmarkkina" reports what happened;
  predictions (futures and last outlook vs result, weather, Voltikka fixed-price forecast, reset
  estimates, events) are in their own section "Ennusteet".
- (same day, user decisions) Keep the October bill key figure (a statement of what happened);
  edition date 5.11.2026; six EEX values and two ranges are acceptable; the forecasts talk about
  quarterly contracts only (monthly-reset sentence removed).
- User rule for the text: take clear expert stances. State expected causes ("winter will be
  expensive because …"), give one recommendation per home type, and keep assumptions in the method
  note instead of caveats in each sentence. Stakes are low (consumer advice, journalists).
  Recommendations now: electrically heated house – a 24-month fixed price now (spot only with load
  shifting); house without electric heating – spot or the cheapest 24–36-month fixed, not a median
  12-month fixed; apartment – stay on spot; quarterly customers – change to fixed before 1.1.
  (quarterly median rough 12-month estimate about 1,450 € energy vs 1,176 € for the cheapest
  24–36-month fixed for an electrically heated house).
- Edition 5.11. can use 1.–4.11.: on Monday 2.11. wind was about a third of the October daily
  mean (29.8 vs 91 GWh) with Olkiluoto 3 still in maintenance; the day mean was about 26 c/kWh and
  the evening peak 51 c/kWh (incl. VAT). Used as the proof of the winter risk.
- (same day, user comments) The method note moved to the end of the page ("Näin laskimme", in
  plain steps) with the sources. The Voltikka seller-set energy price index
  (`seller_set_energy_price_index_v1`, overall and the fixed-term, open-ended and market-reset
  families) is the centre of "Sopimusmarkkina", with its own chart (`prototype/index_chart.py`):
  October +3.7 % (12.79 → 13.27 c/kWh), +17.5 % since 1.9., +36 % from the May low 9.78. The
  market-reset family (14.54) is not the quarterly median (13.12), so the text names it
  "kvartaali- ja kuukausihintaiset sopimukset".
- (same day, user) The second layer is named "Tarkemmat tiedot: <kuukausi>" on the public page, not
  "Toimittajille". It still serves journalists first, but the page does not name an audience.
- (same day, user) **The recommendation is cost against certainty, not "choose 24 kk".** Over the
  full contract period spot is cheaper (futures 30.10.2026 to Q3 2028 and Y2028/Y2029; year-2/3
  spot = each month's year-1 consumption-weighted premium over its future, applied to the later
  future): electrically heated house, cheapest 24 kk (Pohjois-Karjalan Optimi takuu 24, normal
  price) 1,502 €/yr against Herrfors Spot+ 1,564 € in year 1 and about 1,325 € in year 2, so spot
  is about 115 € cheaper over 24 months; Cheap 36 kk is about 290 € dearer over 36 months. House
  without electric heating: cheapest 24 kk 948 € vs spot about 969/830 €. The text now says: spot
  is cheaper in the long run; spot costs about 150 € (cold winter 190 €) more in Nov–Mar; fixed
  gives winter certainty but costs about 225 € more in Apr–Oct; if you want fixed, take 24 kk.
  Quarterly customers: switch to spot (or 24 kk for BE02) before 1.1. Rule added to root AGENTS.md
  ("Spot vs fixed"). Script: `prototype/contract_term.py`.
- (same day, user request) **First Paper mockup of the review page:** Paper file "Voltikka", page
  "Markkinakatsaus · sivu", desktop board built from the long-read article board (hero, contents
  column with an archive list, footer). Order: hero ("Halpa lokakuu, kallis talvi") → price strip
  (October 4.77 c, winter 11–16 c, summer 4–10 c) → winter outlook → three homes, each with a
  "Neuvomme" stance (house types get a winter/summer/year ledger) → calculator link → interlude →
  "Tarkemmat tiedot" (key figures + CSV action, factor ledger, contract market with the index
  chart and family changes, forecasts, coming events) → "Näin laskimme" and sources. Open items
  are on the "00 · Notes for review" board.
- (same day, user) **URLs.** `/markkinakatsaus` always renders the latest published review in full
  (200, not a redirect) and is **self-canonical**: one stable URL collects the ranking (user: Google
  may not follow a canonical whose target changes every month). Each review also has its own
  `/markkinakatsaus/YYYY-MM` (route constraint `\d{4}-\d{2}`; top level, not under `/sahkosopimus/`,
  which has a `{city}` catch-all). Every dated URL is self-canonical too, also while it is the
  latest; the user accepts the one-month duplicate text. The sitemap lists `/markkinakatsaus` and
  every dated URL. A draft or a future month returns 404. Each
  review reads its own frozen snapshot; a correction shows its date. An old review has one line at
  the top that links to the latest review. CSV per review: `/markkinakatsaus/YYYY-MM.csv`; chart
  and OG images per review. The "Aiemmat katsaukset" list is the archive until it gets long.
- (same day, user request "implement the mockup on the Voltikka dev site") **First site build,
  local only.** Routes, the review list (`laravel/config/market_reviews.php`), the page shell and
  the frozen October body (`resources/views/market-reviews/2026-10.blade.php`), charts and CSV in
  `resources/market-reviews/2026-10/`. The October review stays `draft => true`: production
  returns 404 for it until the user publishes it (⟦TARKISTA⟧ points in the draft are still
  open). Drafts show only with `APP_ENV=local`. The prototype chart scripts now draw with site
  tokens (spot aurora, fixed granite, index families fox/lake/granite, wind granite,
  warm fox / cold lake) and Figtree; the page embeds the SVG inline. The CSV holds the page's
  outcome and factor figures and no EEX futures values. Remaining from the notes board: a phone
  board (the build stacks the ledgers by CSS judgement), the archive page (the side list is the
  archive for now), and hero art of its own (the fireside fox is reused).
- (same day, user) **Charts must be interactive like the rest of the site, and the index must
  look like the one on /sahkosopimus/tilastot.** The first build embedded static SVGs; the index
  SVG was daily and broke at the three missing collection days (12.2., 23.7., 1.10.), while the
  statistics page shows weekly means by default. Now: Chart.js bars with tooltips (bill outlook,
  daily price/wind/temperature) and the shared uPlot index chart with weekly means, the same
  labels-to-colours rule and the "nyt" label; frozen JSON from `prototype/site_payloads.py`; every
  chart also has a data table. The figures in the text are daily values from the same rows and
  stay correct (31.10. 13,27; May low 9,78).
- (same day, user) **The review index chart shows only the review month, day by day; gaps are
  fine.** Kuva 3 is now the 31 days of October 2026 (1.10. has no value, so the line starts on
  2.10.: 12,79 → 13,27 c/kWh). The family figures under it (+5,7 / +5,5 / +1,4 %) are the same
  daily rows. The chart data is frozen: the page never reads the database, and
  `site_payloads.py` does not overwrite existing files without `--force`.
- (same day, user) **SEO: the H1 is the keyword heading, the look stays.** H1 = "Sähkömarkkinakatsaus
  lokakuu 2026", drawn exactly as the old kicker line; "Halpa lokakuu, kallis talvi" is now a
  paragraph with the same display style. No hidden text. "Voltikan" is not in the H1, because the
  visible kicker does not say it (the `<title>` ends with "| Voltikka").
- (same day, correction from the video session) **Kvartaali- ja kuukausihintaiset is +5,6 %, not
  +5,5 %.** 13,7764 (2.10.) → 14,5442 (31.10.) = +5,573 %; +5,5 % came from the rounded values
  13,78 → 14,54. Corrected in the page and the draft (earlier entries above keep the old figure).
  Rule: calculate a change from the stored values, round only the result.
- (same day, user) **A monthly index change runs from the last day of the previous month to the
  last day of the month (30.9.–31.10.), so it includes the 1.10. resets.** The earlier figures were
  2.10.–31.10. (1.10. has no value) and left the resets out. October: index +12,0 % (11,84 →
  13,27), Toistaiseksi voimassa +10,5 %, Kvartaali- ja kuukausihintaiset +45,4 % (10,00 → 13,78
  on 1.10.), Määräaikaiset +1,2 %. The text now says that most of the rise came at the turn of the
  month, when the new quarterly and monthly prices started on 1.10. Kuva 3 starts on 30.9. (a lone
  marked point before the 1.10. gap). Changed in the page, the draft and the CSV. The Paper
  mockup still has the old figures.
- (same day, user) **The review video goes at full width directly under the Preferred Sources
  strip** (not in the 860 article column: too narrow for the 1280 video; not above the strip: the
  strip stays directly after the hero on every page). Poster = the video's YouTube thumbnail; the
  YouTube player loads only after a click (youtube-nocookie); VideoObject structured data when the
  YouTube ID and upload date are set in `config/market_reviews.php`. Paper board updated.
- (same day, user) **Published 6.11.2026 with the video** (YouTube i7ZopmWpqco, 5:25, "Sähkön
  hinta talvella: pörssisähkö vai kiinteä? Sähkömarkkinakatsaus lokakuu 2026"). Final checks of
  the ⟦TARKISTA⟧ points: (1) the named contracts (Pohjois-Karjalan Optimi takuu 24 kk, Herrfors
  Spot+, Nordic Green Energy Pörssisähkö) are household contracts available nationally and on
  sale on 5.11. Optimi takuu is a time-of-day tariff, so the text now says "aikasähkö". The
  cheaper "perusmaksu 0 € ensimmäiset 12 kk" spot offers (Hehku Flex, Aalto Luotsi) have no known
  normal price, so Nordic Green Energy stays the apartment winner at normal prices. The cheapest
  national fully fixed 24 kk for the house without electric heating is Korpela Kesto Kiinteä 24 kk
  (953 € incl. tax; the earlier 948 € contract is not national); the unnamed sentence stays true.
  (2) ECMWF 1.11. raw ensemble: week 1 100 % and week 2 92 % of members warmer than normal; the
  windier-week claim is removed, because 2.11. had only 30 GWh of wind. Added the fact that
  1.–6.11. spot averaged 8,8 c/kWh (future 11,33). Figures frozen 6.11.2026.
- (same day, user) **The review has its own top-level folder `market-review/`**, because it repeats
  every month and cannot live in `tasks/`. Layout: documents at the top, scripts in `tools/`,
  one folder per edition in `editions/<YYYY-MM>/`. `compute.py`, `drivers.py`, `charts.py` and
  `site_payloads.py` now read and write `editions/<month>/`. Paths above this entry that say
  `prototype/` mean `tools/` (scripts) or `editions/<month>/` (figures and drafts).
- (same day, user approved the Paper proposal) **The home page features the latest review**: a
  row directly under the Preferred Sources strip, before "Palvelut", in the pattern of the
  "Maksatko sähköstäsi liikaa?" row: video thumbnail with the length, kicker "Uusin
  sähkömarkkinakatsaus · <kuukausi>", the review title, the edition's `teaser` line, "Lue
  katsaus →" and "Katso video". It follows the latest published review automatically.
