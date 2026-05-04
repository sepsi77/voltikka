# Decisions

- Cache prepared view data rather than full HTML first, to avoid Livewire/session token risk.
- Scope initial implementation to canonical/default GET states: no query string, page 1, no interactive filters/search input.
- Listing cache keys include route/filter context and `ContractPageCacheVersion::hash()`.
- Contract detail cache keys include contract id, default/clamped consumption, and `ContractPageCacheVersion::hash()`.
- Expiry uses `Carbon::tomorrow()`, matching the broad strategy used by the contract price statistics page.
- Page-level prepared-data caching is disabled when `app()->runningUnitTests()` to prevent cross-test cache pollution with the array cache driver.
- Public cache headers now include contract detail pages, in addition to existing listing/SEO pages.
