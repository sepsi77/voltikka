# Decisions

## 2026-05-01

- Production had `price_components` through `2026-05-01`, but both `contract_price_snapshots` and `contract_price_daily_statistics` stopped at `2026-04-29`.
- `storage/logs/contracts-fetch.log` showed the daily import reached `contracts:calculate-percentiles` and then crashed/stopped before daily statistics were calculated.
- Manually backfilled production with:
  `php artisan contracts:backfill-price-statistics --from=2026-04-30 --to=2026-05-01 --overwrite`.
- Code fix: in `FetchContracts`, calculate daily contract-price statistics before recalculating percentile thresholds. The statistics page is public and time-sensitive; percentile badges are optional UX metadata.
- Code fix: in `CalculateContractPercentiles`, do not eager-load all `priceComponents` for all active contracts. Process active contracts in chunks and use `getLatestPriceComponentsForCalculation()` per contract to avoid loading full historical price-component history into memory.
