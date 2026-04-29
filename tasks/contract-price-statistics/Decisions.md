# Decisions / open questions

## Daily fetch cadence and missing data

Decision: Voltikka fetches all contracts daily. Historical backfill should treat `price_components.price_date` as the source of truth for whether a contract participates in statistics for that date.

If a contract is missing price-component data for a given day, treat it as missing data and exclude it from that day's calculations. Do not carry prices forward. Missing rows are expected to be rare and should not materially affect aggregate trends.

## Snapshot design direction

Implemented with two tables:
- `contract_price_snapshots`: one daily row per included contract with normalized component prices and annual-cost estimates for 2000/5000/18000 kWh.
- `contract_price_daily_statistics`: daily aggregate min/p20/average/p80/max rows by segment and metric.

For future data, `contracts:calculate-price-statistics` calculates daily snapshots after `contracts:fetch` using current `active_contracts`. For historical data, `contracts:backfill-price-statistics` backfills from dated `price_components` rows. Both flows use `ContractPriceStatisticsService`.

## Spot contract handling

Decision: spot contract statistics use stored spot-price history. For spot contracts, track both:
- supplier margin from the contract price component
- total energy price = spot market energy price + supplier margin

This gives a realistic comparison against fixed/hybrid/open-ended contract types. The page labels these distinctly so users understand margin and total spot-based energy price are different metrics.

If spot data is missing for a spot-contract date, spot annual-cost observations are left null rather than calculated as margin-only.

## Statistics page UI

Decision: the statistics page should not hide metrics behind segment/metric dropdowns. On page load it shows all main price tables together:
- energy prices, spot margins, spot total prices, and monthly fees by segment
- annual-cost tables for 2000, 5000, and 18000 kWh/year
- trend cards for key segments at 5000 kWh/year

Only the time aggregation is controlled with compact buttons (`Kuukausi`, `Viikko`, `Päivä`).

## Historical backfill direction

Historical contract availability is inferred from `price_components`: if a contract has price component rows dated for a given day, treat it as active/available for statistics on that day.

Command examples:

```bash
php artisan contracts:backfill-price-statistics --from=2025-01-01 --to=2026-04-29 --overwrite
php artisan contracts:calculate-price-statistics --date=2026-04-29 --overwrite
```

## Aggregation semantics

Daily observations include one row per active household/both contract with price components for the date. Weekly/monthly UI values are derived by averaging daily statistics.

For example, monthly p20 for 12-month fixed-term annual cost is the average of each observed day’s p20 in that month. This keeps the trend market-day weighted rather than contract-row weighted.
