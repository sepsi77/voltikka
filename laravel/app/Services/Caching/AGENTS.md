# AGENTS.md

Context for caching services under `laravel/app/Services/Caching`.

## `ContractPageCacheVersion`

Purpose:
- provides a cheap source-data fingerprint for high-traffic public contract listing and detail pages
- used by Livewire components that cache prepared view data until tomorrow, while still busting when contract/price source data changes

Important semantics:
- this is not full-response caching; components cache prepared view payloads to avoid expensive repeated contract list/detail assembly on canonical first-page loads
- `ContractListCacheService::getVersion()` is the primary import-driven invalidation signal because `contracts:fetch` bumps it after refreshing active contracts/prices
- aggregate table counts/max dates are included to protect local/manual/test data changes and spot-average-dependent price calculations
- avoid adding expensive full-table hashes here; this method runs to decide whether cached page payloads can be reused
- calculated-cost payload v6 adds typed monthly included-energy package data
- payload v7 is the card/detail canonical-only current-value and real-term offer-copy boundary
- payload v8 is the company/SEO canonical offer-membership and JSON-LD boundary
- payload v9 adds exact typed canonical offer terms
- payload v10 adds real-term totals and offer savings for short BaseOnlyHybrid outcomes
- calculated-cost schema v11 invalidates old membership after `other` cadence recurring resets became listed estimates
- `CalculatedCostPayloadSchema::VERSION` is the one calculated-cost shape version used by list, company, ranking, and prepared-page cache keys
- `ContractPageCacheVersion` keeps its own prepared-view wrapper version and also includes the shared `cs{version}` marker plus `PricingMode::cacheMarker()`
