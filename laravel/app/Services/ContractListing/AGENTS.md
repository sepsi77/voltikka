# Contract listing pipeline

`ContractListingPipeline.php` owns the shared mechanics for the main and SEO contract lists.

## Responsibilities

- Apply shared interactive contract-type, pricing-bucket, metering, and postcode constraints.
- Fail closed to `availability_is_national = true` with no postcode. A valid exact postcode adds only contracts linked through `contract_postcode`; nullable availability values are not proven national.
- Apply the Quarterly, TimeOfUse, and Seasonal query constraints.
- Keep the six quarterly text fragments in `QUARTERLY_PHRASES`.
- Match the same quarterly fragments in memory for historical statistics.
- Apply interactive energy-source filters and the normal-mode consumption range.
- Attach shared annual metrics from `ContractPricing\ContractMetricSet` for every listing, including local cards. Direct calculation requires `PublicPriceCalculationPolicy` permission from an explicit action or a non-HTTP producer context.
- Exclude canonical outcomes that are not listable and sort annual totals.
- Load only visible contract cards with their required relations.
- In legacy mode, load only the latest normalized price components.
- Build the manual paginator with the path and query state from the component.

## Boundaries

`ContractListCacheService::getCachedMetrics()` returns a strict typed set. The pipeline reads metrics through typed accessors and calls `pricing()->toArray()` only when it attaches the unchanged calculated-cost presentation attribute to an Eloquent contract. Listed totals and sort keys have no missing-value or infinity fallback. A malformed cached or cold canonical row fails instead of entering the ranking.

Livewire components still own audience selection, SEO route constraints, URL state, actions, city exclusions, and canonical offer timing. Bill mode still uses `BillComparisonService` for period pricing and sorting. It uses this pipeline only for visible loading and pagination.

`LocalContractsService` keeps location discovery, company distance data, and consumption filtering. It uses shared cached annual enrichment even when legacy card relations are needed. Latest legacy display components load separately, without annual calculation. Missing or excluded cached IDs are skipped, not a reason to calculate the entire collection. A cached empty list is valid. An unprepared custom GET consumption throws `ContractPriceCacheUnavailable`, which gives an honest temporary-unavailable response instead of an empty-list or zero-contract claim. A custom metric set prepared by an explicit action can be read normally on the next GET. No approximation or zero price is added. The shared accessor owns cold supported-preset availability and repair demand. Nearby local-company contracts use the visitor's actual postcode eligibility. A city page's separate regional tier uses that postcode only when its municipal code matches the page municipality, or its Finnish municipality name matches when no code is available; mismatch and invalid values leave the regional tier empty.

Historical statistics can inspect `name`, `extra_information_fi`, `short_description`, and `long_description` with `matchesQuarterly()`. Do not apply current canonical classifications to old statistics rows.
