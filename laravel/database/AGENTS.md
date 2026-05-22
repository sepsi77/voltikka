# AGENTS.md

Database/migration notes for Voltikka.

## `electricity_futures_eod_prices`

Stores EEX electricity futures end-of-day settlement history collected by `php artisan futures:fetch-eex`.

Important semantics:
- unique rows are keyed by exchange/commodity/pricing/product/area/short_code/maturity/trade_date
- reruns update existing settlement prices and metadata because the EEX public endpoint may revise recent values
- `maturity` is stored in EEX request format `YYYYMM`; monthly maturities use the delivery month, quarterly maturities use the quarter start month, and yearly maturities use January (for example `202701`)
- keep `electricity_futures_eod_area_maturity_date_idx` for chart/query access by bidding zone and delivery year

## `price_components` latest-calculation lookup

`ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()` uses a window-function query over `price_components` partitioned by `(electricity_contract_id, price_component_type)` and ordered by preferred non-zero price plus newest `price_date`.

Keep `price_components_latest_calc_idx` on `(electricity_contract_id, price_component_type, price_date)` in place. It supports the large `WHERE electricity_contract_id IN (...)` bulk lookup without eager-loading full price history on listing/cache rebuilds. The `CASE WHEN price > 0` ordering expression is still computed by MySQL, but this composite index gives the optimizer the correct filtering and partition locality.
