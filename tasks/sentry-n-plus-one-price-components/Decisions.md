# Decisions

- The `price_components` Sentry N+1 on city SEO pages is actionable. It can happen when listing metrics are rebuilt or city-local sections calculate prices: each contract called `getLatestPriceComponentsForCalculation()` separately.
- Avoided eager-loading full `priceComponents` history because that was previously documented as a memory risk for active contract lists.
- Added `ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()` using one window-function query to select the latest calculation component per contract and component type, preserving the existing preference for latest non-zero prices.
- Updated `ContractsList`, `SeoContractsList`, `LocalContractsService`, `ContractListCacheService`, and `ContractRankingService` to use the bulk loader for batch calculations.
- Cached `SeoContractsList::$localContractsDataCache` for a render/request so title/meta/view/exclusion code does not repeatedly recalculate the same local contract sections.
- Also made discount helpers relation-aware because SEO JSON-LD/card paths often already have `priceComponents` loaded and should not re-query per contract.
