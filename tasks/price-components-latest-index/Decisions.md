# Decisions

- The existing `price_components` migration only declares an index on `price_component_type`; MySQL/InnoDB will generally also create/use an index for the `electricity_contract_id` foreign key, but there was no composite index matching the bulk latest-component query's `WHERE electricity_contract_id IN (...)`, `PARTITION BY electricity_contract_id, price_component_type`, and newest `price_date` access pattern.
- Added `price_components_latest_calc_idx` on `(electricity_contract_id, price_component_type, price_date)` to reduce row lookup/sort work for `ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()` without eager-loading full component history.
- Did not add a generated/functional index for `CASE WHEN price > 0` yet. That could further optimize ordering, but the portable composite index is the safest first production migration; use `EXPLAIN ANALYZE` after deployment before adding a more intrusive expression/generated-column index.
