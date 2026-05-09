# Decisions

- The inline calculator remains implemented in `ContractsList` and inherited by `SeoContractsList`; no separate SEO calculator copy was introduced.
- `calculateFromInlineCalculator()` now reads calculator properties through safe helper methods with defaults and enum fallbacks. This prevents stale or partially hydrated Livewire requests from crashing when a browser sends calculator updates against an SEO listing page and also tolerates temporarily blank number inputs from mobile browsers.
- Added `SeoContractsListTest` coverage for calculator updates on SEO pages and blank calculator inputs.
- Updated stale `SeoContractsListTest` JSON-LD assertions to match the current `@graph` schema. The page now exposes `WebPage`, `Service`, and `ItemList` graph nodes, and contract list entries are `Product` items categorized as `Electricity Contract`.
