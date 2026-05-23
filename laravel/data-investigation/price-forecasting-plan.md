# Fixed-term contract price forecasting plan

Status: initial production backend implemented; no public forecasting UI has been implemented yet.

Local data status: one-off production-to-local sync for forecasting exploration was completed on 2026-05-23 into the local SQLite database. Synced tables include companies, electricity contracts, active contracts, filtered price components/snapshots/daily statistics from 2026-01-01, futures EOD prices from 2026-01-01, and spot hourly/quarterly/averages from 2024-12-01. This was a session-local/manual sync, not a durable sync command.

Local exploration status: an initial `uv`-run Python script lives at `data-investigation/price-forecasting/simple_fixed_term_forecast.py`. It builds retail target series, FI futures-implied hedge costs, spot risk features, an EWMA premium/gap backtest, and a latest median-price direction outlook from the local SQLite data. Generated CSV/Markdown outputs are written under `data-investigation/price-forecasting/outputs/` and ignored by git because they are derived from the local production-data sync. First run on 2026-05-23 built 1,098 rows, 405 with complete hedge cost; this is enough to smoke-test the pipeline but too little for reliable 30/60-day validation because FI futures history starts on 2026-04-08. The first latest-outlook result is neutral/slightly falling for 6/12/24 month fixed contracts, with low confidence and expected 30-day median moves near zero (-0.03, -0.01, and -0.00 c/kWh respectively).

Production backend status: v1 PHP services and commands now live under `app/Services/PriceForecasting/`. Forecasts are persisted in `fixed_contract_price_forecasts` through `App\Models\FixedContractPriceForecast`, and matured forecasts can be evaluated against realized `contract_price_daily_statistics` rows with `php artisan forecasting:evaluate-fixed-contracts`. Daily forecast/evaluation commands are scheduled in `routes/console.php`. This remains backend-only; no public UI is implemented yet.

## Goal

Build a Voltikka price-forecasting feature that estimates short-term movements in Finnish fixed-term electricity contract prices using:

1. Voltikka's historical retail contract prices.
2. EEX electricity futures settlement history.
3. Finnish spot-price history for shape/risk features.

Initial product scope is deliberately narrow:

- Contract types: fixed-term only.
- Durations: 6, 12 and 24 months.
- Targets: market-level price indices, not supplier-specific forecasts.
- First forecast horizon: 30 days, with 7/14/60 day horizons used in backtests.
- First implementation should run locally/research-first before any public UI.

## Existing Voltikka data to reuse

### Retail contract prices

Relevant tables/models:

- `price_components` / `App\Models\PriceComponent` — raw daily imported price components from the Azure contract API.
- `electricity_contracts` / `App\Models\ElectricityContract` — contract metadata, including `contract_type`, `fixed_time_range`, `pricing_model`, `target_group`, and replacement links.
- `contract_price_snapshots` / `App\Models\ContractPriceSnapshot` — one normalized contract-day observation.
- `contract_price_daily_statistics` / `App\Models\ContractPriceDailyStatistic` — aggregate daily market stats.

Existing fixed-term segment keys from `ContractPriceStatisticsService`:

| Duration | `fixed_time_range` | `segment_key` |
| --- | --- | --- |
| 6 months | `Fixed6` | `fixed_term_6` |
| 12 months | `Fixed12` | `fixed_term_12` |
| 24 months | `Fixed24` | `fixed_term_24` |

MVP retail targets:

- `median_value`, `p20_value`, and `p80_value` for `metric_key = energy_price`.
- Use `contract_price_daily_statistics` for quick experiments.
- Use `contract_price_snapshots` when we need custom filtering, chain-linked indices, discount exclusion, or offer-level diagnostics.

Important convention to verify during the data audit: retail prices shown to users are consumer-facing c/kWh values. Futures are EUR/MWh before VAT, so the hedge-cost series must be converted to the same VAT basis as the retail target.

### Futures

Relevant table/model:

- `electricity_futures_eod_prices` / `App\Models\ElectricityFuturesEodPrice`.

Relevant EEX instrument coverage already configured:

- `area = FI` for Finland.
- `area = NP` for Nordic System Price as a secondary reference.
- `maturity_type in (month, quarter, year)`.
- `settlement_price` is EUR/MWh.
- `maturity` is EEX `YYYYMM` format:
  - month: delivery month;
  - quarter: quarter start month;
  - year: January of the delivery year.

Caveat: the public EEX endpoint only exposes roughly 45 days of history. Production history will therefore only be as deep as Voltikka has been collecting it, unless a paid/alternative historical source is added later.

### Spot prices

Relevant tables/models:

- `spot_prices_hour` / `App\Models\SpotPriceHour` — hourly Finnish spot prices before VAT plus stored VAT rate.
- `spot_prices_quarter` / `App\Models\SpotPriceQuarter` — 15-minute prices; optional for forecasting MVP.
- `spot_price_averages` / `App\Models\SpotPriceAverage` — daily/monthly/rolling averages.

MVP use:

- 7-day and 30-day FI spot averages.
- 30-day volatility.
- Count of extreme positive and negative price hours.
- Later: household shape premium estimates from hourly spot history.

## Production-to-local data pull plan

### Principle

Create a local-only, read-only-from-production sync path so forecasting experiments can be repeated without copying the entire production database.

Do not commit production credentials or dumps. Use a read-only Railway/MySQL credential where possible.

### Data needed locally

Current local status: completed as a one-off manual sync on 2026-05-23. Local SQLite row counts after import were: `companies` 38, `electricity_contracts` 2,792, `active_contracts` 443, `price_components` 132,735, `contract_price_snapshots` 41,976, `contract_price_daily_statistics` 7,329, `electricity_futures_eod_prices` 8,576, `spot_price_averages` 745, `spot_prices_hour` 11,523, and `spot_prices_quarter` 21,600. `PRAGMA foreign_key_check` returned 0 rows after import.

Minimum tables for forecasting experiments:

1. Parent/reference data
   - `companies` — needed by `electricity_contracts.company_name` FK.
   - `electricity_contracts` — fixed-term metadata and replacement chains.
   - `active_contracts` — useful for current-state diagnostics.
2. Retail history
   - `price_components` filtered by `price_date >= :from`.
   - `contract_price_snapshots` filtered by `snapshot_date >= :from`.
   - `contract_price_daily_statistics` filtered by `stat_date >= :from`.
3. Futures history
   - `electricity_futures_eod_prices` filtered by `trade_date >= :from`.
4. Spot history
   - `spot_prices_hour` filtered by `utc_datetime >= :from - 400 days` if shape/rolling features are needed.
   - `spot_price_averages` filtered by `period_start >= :from - 400 days`.
   - `spot_prices_quarter` is optional for MVP; hourly data is enough.

Recommended default `:from`: `2026-01-01`, or the earliest date available in production for all three sources.

### Preferred implementation: Artisan sync command

Implement a command such as:

```bash
cd laravel
php artisan forecasting:sync-production-data \
  --from=2026-01-01 \
  --spot-lookback-days=400 \
  --dry-run

php artisan forecasting:sync-production-data \
  --from=2026-01-01 \
  --spot-lookback-days=400 \
  --truncate
```

Implementation outline:

1. Add a read-only production DB connection in `config/database.php`, driven by env vars such as `PROD_DB_HOST`, `PROD_DB_PORT`, `PROD_DB_DATABASE`, `PROD_DB_USERNAME`, `PROD_DB_PASSWORD`, or a parsed `PROD_DATABASE_URL`.
2. Command reads from the production connection and writes to the default local connection.
3. Copy parent tables first, then dependent tables.
4. Use chunked reads and `upsert`/`insertOrIgnore` to avoid memory spikes.
5. In `--truncate` mode, clear only the forecasting-related local tables, with foreign-key checks disabled during truncation.
6. In `--dry-run` mode, print row counts and date ranges without writing.
7. Never copy operational/private tables such as cache, sessions, jobs, failed jobs, personal analytics, or secrets.

Suggested copy order:

1. `companies`
2. `electricity_contracts`
3. `active_contracts`
4. `price_components`
5. `contract_price_snapshots`
6. `contract_price_daily_statistics`
7. `electricity_futures_eod_prices`
8. `spot_price_averages`
9. `spot_prices_hour`
10. `spot_prices_quarter` only if explicitly requested

### Quick alternative: filtered SQL dump

For one-off local exploration, a filtered dump is acceptable, but less maintainable than the Artisan command.

Example shape, adjusted for real credentials and shell quoting:

```bash
mysqldump "$PROD_DATABASE" companies electricity_contracts active_contracts \
  --single-transaction --quick --no-tablespaces > /tmp/voltikka_forecast_refs.sql

mysqldump "$PROD_DATABASE" price_components \
  --where="price_date >= '2026-01-01'" \
  --single-transaction --quick --no-tablespaces > /tmp/voltikka_forecast_price_components.sql

mysqldump "$PROD_DATABASE" contract_price_snapshots \
  --where="snapshot_date >= '2026-01-01'" \
  --single-transaction --quick --no-tablespaces > /tmp/voltikka_forecast_snapshots.sql

mysqldump "$PROD_DATABASE" contract_price_daily_statistics \
  --where="stat_date >= '2026-01-01'" \
  --single-transaction --quick --no-tablespaces > /tmp/voltikka_forecast_daily_stats.sql

mysqldump "$PROD_DATABASE" electricity_futures_eod_prices \
  --where="trade_date >= '2026-01-01'" \
  --single-transaction --quick --no-tablespaces > /tmp/voltikka_forecast_futures.sql

mysqldump "$PROD_DATABASE" spot_price_averages \
  --where="period_start >= '2024-12-01'" \
  --single-transaction --quick --no-tablespaces > /tmp/voltikka_forecast_spot_averages.sql

mysqldump "$PROD_DATABASE" spot_prices_hour \
  --where="utc_datetime >= '2024-12-01 00:00:00'" \
  --single-transaction --quick --no-tablespaces > /tmp/voltikka_forecast_spot_hours.sql
```

Because different spot tables use different date columns, the Artisan command is less error-prone than a hand-written dump script.

## Data audit after local sync

Add a local audit command/report:

```bash
php artisan forecasting:audit-data --from=2026-01-01
```

Audit checks:

- Date ranges and row counts for all source tables.
- Missing retail/futures/spot days.
- Contract counts per fixed-term segment per day.
- Median/P20/P80 sanity ranges for 6/12/24 month energy prices.
- Futures coverage by `area`, `maturity_type`, `maturity`, and `trade_date`.
- Spot average/volatility availability for each forecast date.
- VAT basis consistency between retail targets, spot features, and futures hedge costs.
- Whether discounted offers materially distort P20/median targets.

Useful SQL probes:

```sql
select min(price_date), max(price_date), count(*) from price_components;

select segment_key, metric_key, min(stat_date), max(stat_date), count(*)
from contract_price_daily_statistics
where segment_key in ('fixed_term_6', 'fixed_term_12', 'fixed_term_24')
  and metric_key = 'energy_price'
group by segment_key, metric_key;

select area, maturity_type, min(trade_date), max(trade_date), count(distinct maturity) maturities, count(*) rows
from electricity_futures_eod_prices
where area in ('FI', 'NP')
group by area, maturity_type;

select region, min(utc_datetime), max(utc_datetime), count(*)
from spot_prices_hour
where region = 'FI'
group by region;
```

## Target construction

For each forecast date `t` and duration `d in {6, 12, 24}`:

```text
Y_d,median(t) = median retail energy price for fixed-term duration d
Y_d,p20(t)    = P20 retail energy price for fixed-term duration d
Y_d,p80(t)    = P80 retail energy price for fixed-term duration d
```

MVP target source:

```text
contract_price_daily_statistics
where segment_key in ('fixed_term_6', 'fixed_term_12', 'fixed_term_24')
  and metric_key = 'energy_price'
```

Research variants to compare:

1. Base price index from `energy_price_cents_per_kwh`.
2. Discount-excluded index from `contract_price_snapshots where has_discount = false`.
3. Cheapest-quintile/P20 index from snapshots rather than precomputed daily stats.
4. Chain-linked index that follows replacement chains to separate actual repricing from product churn.

For the first public-facing forecast, prefer median + P20 + P80. The median is stable, P20 is consumer-relevant, and P80 explains spread.

## Futures-implied hedge cost

For each date and duration, build:

```text
H_6m(t)  = futures-implied wholesale hedge cost for a 6-month fixed contract
H_12m(t) = futures-implied wholesale hedge cost for a 12-month fixed contract
H_24m(t) = futures-implied wholesale hedge cost for a 24-month fixed contract
```

### Date alignment

Avoid leakage:

- Retail observations on date `t` should normally use futures settlement from `t - 1` or the latest `trade_date < t`.
- Only use same-day futures settlements if the production contract import is known to happen after EEX settlement publication.

### Delivery window assumption

MVP assumption:

- A retail fixed-term offer observed on date `t` is hedged against the next full calendar months after `t`.
- Example: for `t = 2026-06-15`, the 12-month strip covers July 2026 through June 2027.

This is intentionally simple. Later, test partial-current-month weighting if it improves backtests.

### Curve construction algorithm

For each delivery month in the contract window:

1. Prefer FI monthly future for that month.
2. If missing, use the FI quarterly future containing that month.
3. If missing, use the FI yearly future containing that month.
4. If FI is missing entirely, either:
   - mark the hedge cost missing for MVP; or
   - phase 2 fallback: Nordic System Price (`NP`) plus a recent FI-NP basis estimate.

Weighting options:

- MVP: calendar-day/month-hour weights inside the delivery window.
- Phase 2: profile-specific monthly consumption weights.
- Phase 3: add historical hourly household shape premium.

Unit conversion:

```text
EUR/MWh / 10 = c/kWh before VAT
c/kWh before VAT * 1.255 = c/kWh with 25.5% VAT
```

The selected conversion must match the target retail price basis.

## Household shape and risk features

Do not block the MVP on detailed household load modelling. Start with futures baseload strips plus retail premium.

Phase 2 shape adjustment:

```text
shape_premium_profile,month = load-weighted historical FI spot price - baseload historical FI spot price
profile_adjusted_H_d(t) = futures_strip_H_d(t) + expected_shape_premium
```

Initial profiles:

- 2,000 kWh apartment.
- 5,000 kWh non-electric-heating home.
- 18,000 kWh electric-heating home.

Spot risk features for the MVP model:

- `spot_avg_7d`
- `spot_avg_30d`
- `spot_volatility_30d`
- `extreme_positive_hours_30d`, for example hours over 20 c/kWh with VAT
- `negative_price_hours_30d`

## MVP forecasting model

For each duration `d` and target quantile `q in {p20, median, p80}`:

```text
Y_d,q(t) = observed retail price index
H_d(t)   = futures-implied hedge cost
R_d,q(t) = Y_d,q(t) - H_d(t)
```

Estimate normal retail premium using EWMA:

```text
Rbar_d,q(t) = EWMA(R_d,q(t))
Fair_d,q(t) = H_d(t) + Rbar_d,q(t)
Gap_d,q(t)  = Fair_d,q(t) - Y_d,q(t)
```

First shippable model:

```text
Predicted_change_30d = a
                       + b * Gap_d,q(t)
                       + c * ΔH_d(last 7 days)
                       + e * ΔH_d(last 30 days)
                       + f * spot_volatility_30d
```

Implementation path:

1. Start with a deterministic rule using EWMA premium and a manually/backtest-estimated adjustment speed `lambda`.
2. Once enough observations exist, fit ridge regression per duration/quantile/horizon.
3. Publish ranges using residual-based or conformal intervals, not single-point certainty.

Fallback rule if there is too little history:

```text
expected_change_30d = lambda_d * Gap_d,median(t)
```

where `lambda_d` is estimated from backtests when possible and otherwise starts conservatively, for example `0.2–0.4`.

## Backtesting plan

Backtest before any UI/public claim.

Horizons:

- 7 days
- 14 days
- 30 days
- 60 days

Baselines:

1. No-change forecast.
2. Last 7-day retail average.
3. Futures change passed through one-for-one.
4. Futures hedge cost + average historical retail premium.
5. EWMA premium + gap closure rule.

Metrics:

- MAE in c/kWh.
- Bias.
- Directional accuracy: rise/fall/flat.
- Interval calibration for 80% intervals.
- Segment availability/contract count at forecast time.

Rolling-origin example:

```text
train days 1..90 -> predict day 120
train days 1..91 -> predict day 121
...
```

Because the futures history may be short initially, the first backtests may be more diagnostic than statistically decisive.

## Proposed implementation components

Suggested namespace:

```text
app/Services/PriceForecasting/
```

Suggested services:

- `RetailPriceIndexService` — builds `Y_d,q(t)` from daily stats/snapshots.
- `FuturesCurveService` — resolves monthly/quarterly/yearly futures into a monthly FI forward curve.
- `HedgeCostService` — builds `H_6m`, `H_12m`, `H_24m` strips.
- `SpotRiskFeatureService` — calculates recent spot averages, volatility, and extreme-hour counts.
- `FixedTermForecastModel` — EWMA/gap model and later ridge-regression wrapper.
- `ForecastBacktestService` — rolling-origin backtests and baseline comparisons.

Suggested commands:

```bash
php artisan forecasting:sync-production-data --from=2026-01-01 --dry-run
php artisan forecasting:audit-data --from=2026-01-01
php artisan forecasting:build-hedge-costs --from=2026-01-01 --to=today
php artisan forecasting:backtest --from=2026-01-01 --horizon=30
php artisan forecasting:run --as-of=today --horizon=30
```

Suggested persistence tables once experiments stabilize:

- `fixed_contract_hedge_costs`
  - `as_of_date`
  - `duration_months`
  - `delivery_start_month`
  - `delivery_end_month`
  - `area`
  - `price_cents_per_kwh_ex_vat`
  - `price_cents_per_kwh_inc_vat`
  - `coverage_quality`
  - source/fallback metadata JSON
- `fixed_contract_price_indices`
  - `index_date`
  - `duration_months`
  - `target_quantile` (`p20`, `median`, `p80`)
  - `price_cents_per_kwh`
  - `contract_count`
  - filters metadata JSON
- `fixed_contract_price_forecasts`
  - `forecast_date`
  - `target_date`
  - `horizon_days`
  - `duration_months`
  - `target_quantile`
  - `current_price_cents_per_kwh`
  - `forecast_price_cents_per_kwh`
  - `expected_change_cents_per_kwh`
  - `interval_low_cents_per_kwh`
  - `interval_high_cents_per_kwh`
  - `hedge_cost_cents_per_kwh`
  - `retail_premium_cents_per_kwh`
  - `model_version`
  - explanation metadata JSON

For early local work, these can be generated in memory or saved to CSV before migrations are added.

## Testing plan

Unit tests:

- EEX maturity parsing for month/quarter/year products.
- Month-window construction for 6/12/24 month strips.
- Futures fallback order: month -> quarter -> year.
- EUR/MWh to c/kWh VAT conversion.
- Retail index construction from snapshots/statistics.
- No-leakage date alignment (`latest trade_date < retail_date`).
- Spot feature calculations over fixed windows.

Feature/command tests:

- Production sync dry-run reports counts without writing.
- Production sync upserts chunks and respects `--from`.
- Backtest command compares model against baselines.
- Forecast command produces no output when required source coverage is missing.

Data quality tests:

- Forecast run fails or flags `coverage_quality = low` if contract counts are too thin.
- Forecast run flags missing futures months rather than silently using stale/mismatched maturities.

## UI/product plan after validation

Only expose publicly after backtests show useful signal versus no-change and simple futures baselines.

Possible first UI location:

- `/sahkosopimus/tilastot` as an experimental "Fixed-term price outlook" section.
- Later, a dedicated SEO page if the signal is strong and content can be explained responsibly.

Suggested user-facing output:

```text
12-month fixed contracts
Current median: 9.2 c/kWh
Futures-implied hedge cost: 6.1 c/kWh
Retail premium: 3.1 c/kWh
30-day outlook: slightly falling
Estimated range: 8.7–9.3 c/kWh
Why: Finnish futures have fallen over the past 30 days, but retail offers have only partly followed.
```

Required disclaimer:

```text
This is a market-implied estimate based on electricity futures and observed Finnish retail contract prices. It is not a guarantee of future offers or future spot prices.
```

## Milestones

### Milestone 1 — Local data foundation

- Add production read-only DB connection config.
- Add `forecasting:sync-production-data` command.
- Pull the relevant production subset locally.
- Add `forecasting:audit-data` report.

Acceptance criteria:

- Local DB has retail, futures, and spot history for the selected period.
- Audit report shows date ranges, missing days, and contract counts.
- No production credentials or dumps are committed.

### Milestone 2 — Hedge-cost and target series

- Build retail target series for 6/12/24 month fixed-term contracts.
- Build FI futures-implied hedge-cost strips.
- Align dates without leakage.
- Export joined dataset to CSV for inspection.

Acceptance criteria:

- One row per date/duration with `Y`, `H`, premium, futures changes, and spot features.
- Missing/fallback futures coverage is visible.

### Milestone 3 — MVP model and backtests

- Implement EWMA retail premium + gap closure model.
- Add residual/conformal-style intervals.
- Compare against baselines for 7/14/30/60 day horizons.

Acceptance criteria:

- Backtest report includes MAE, bias, directional accuracy, and interval calibration.
- Model beats no-change or clearly documents why it does not yet.

### Milestone 4 — Persistence and scheduled runs

- Add forecast persistence tables if local experiments justify it.
- Add daily forecast command after futures/contract imports.
- Store model version and explanation metadata.

Acceptance criteria:

- Forecasts are reproducible and inspectable from DB rows.
- Failed/missing source coverage does not publish stale forecasts.

### Milestone 5 — Public UI

- Add a small, careful forecast section to `/sahkosopimus/tilastot` or a gated internal page.
- Include current price, hedge cost, premium, expected change, interval, and explanation.
- Include disclaimer and avoid overconfident language.

Acceptance criteria:

- UI is understandable, honest, and backed by stored forecast rows.
- Tests cover rendering with/without forecast availability.

## Main risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Futures history too short | Start collecting daily, import production history, use simple model until enough observations exist. |
| Same-day futures leakage | Use latest `trade_date < retail_date` by default. |
| Retail target jumps due to product churn | Track both market median and later chain-linked index. |
| VAT/unit mismatch | Data audit must verify retail basis; convert futures accordingly. |
| Discounts distort target | Compare base, discount-excluded, and discounted annual-cost variants. |
| Thin segment counts | Require minimum contract count and mark forecasts low-confidence when counts are low. |
| Missing futures maturities | Store coverage quality and avoid silent fallback. |
| Overconfident public copy | Publish intervals and disclaimers; avoid promises. |
