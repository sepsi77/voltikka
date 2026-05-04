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
