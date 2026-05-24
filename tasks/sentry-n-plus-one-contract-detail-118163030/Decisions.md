# Decisions


## 2026-05-07

- This issue is separate from the `SeoContractsList` card fix. Contract detail already eager-loads the main contract relations, but inactive replacement redirects and history-chain discovery could still walk replacement links through repeated relation queries.
- `ContractDetail` now resolves forward replacement chains for inactive redirects with a recursive CTE and then bulk-loads candidate replacements with `activeContract`.
- Contract history now discovers backward replacement-chain IDs with a recursive CTE and then eager-loads all visible versions with `company`, `priceComponents`, and `activeContract` in fixed relation queries.
- Added regression tests that render replacement history and inactive redirect chains while asserting bounded `price_components` / `active_contracts` query counts.

## 2026-05-07 follow-up

- A second production occurrence for the same Sentry issue came from an active detail page, so the likely remaining source was not only replacement-chain history.
- `ContractDetail` now memoizes rank-related computed values and reuses one request-scoped `ContractRankingService` instance. `liveRank`, `liveTotalContracts`, and `cheaperContracts` all share `ContractRankingService::getEligibleSortedIds()` instead of resolving a fresh service and repeating the large `target_group` lookup during one render.
- Added a regression test asserting the eligible target-group query is executed at most once during a detail render.

## 2026-05-07 second follow-up

- Repeated Sentry reports may be from database-cache/source-fingerprint queries rather than lazy Eloquent relations. `ContractDetail` computed both the contract lookup cache key and the prepared view-data cache key, each resolving `ContractPageCacheVersion::hash()`.
- `ContractDetail` now memoizes the page cache-version hash per component instance so source-table fingerprint and cache-version queries run once per render.
- Added a regression test that invokes both cache-key builders and asserts `ContractPageCacheVersion::hash()` is called once.

## 2026-05-24 follow-up

- The May 2026 Sentry occurrence is from repeated database-cache spans on an active contract detail page, not from Eloquent lazy relation loading. The trace repeats `select * from cache where key in (?)` for `contract_rankings_5000kwh`, `contract_list_cache_version`, and `contract_list_metrics:v2:5000` while preparing layout SEO data and visible ranking/cost sections.
- `ContractRankingService` now memoizes the default 5 000 kWh rankings payload per service instance, so separate `priceRank` and `totalContracts` reads do not perform two identical DB-cache lookups in one render.
- `ContractListCacheService` now memoizes the cache version and per-consumption metrics payload per service instance, so `calculatedCost`, `liveRank` / `liveTotalContracts`, and `cheaperContracts` can share one metrics cache read. `warmPresetCaches()` unsets each preset after warming to avoid retaining all large metric payloads in a long-running warmer.
- Added unit coverage for both request-scoped memo layers and reran the contract detail feature suite.
