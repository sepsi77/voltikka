# AGENTS.md

Database/migration notes for Voltikka.

## `price_components` latest-calculation lookup

`ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()` uses a window-function query over `price_components` partitioned by `(electricity_contract_id, price_component_type)` and ordered by preferred non-zero price plus newest `price_date`.

Keep `price_components_latest_calc_idx` on `(electricity_contract_id, price_component_type, price_date)` in place. It supports the large `WHERE electricity_contract_id IN (...)` bulk lookup without eager-loading full price history on listing/cache rebuilds. The `CASE WHEN price > 0` ordering expression is still computed by MySQL, but this composite index gives the optimizer the correct filtering and partition locality.
