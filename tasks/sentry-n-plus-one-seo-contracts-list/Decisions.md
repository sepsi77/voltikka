# Decisions


## 2026-05-07

- `SeoContractsList` already batches main-list `price_components` for calculations and eager-loads visible card relations, but card Blade components still accessed `company`, `electricitySource`, and discount helpers in a way that could lazy-load per contract when given slim/rehydrated models.
- Updated `contract-card` and `featured-contract-card` to use only already-loaded relations and fallback scalar fields (for example `company_name`) instead of triggering lazy relation queries.
- Updated SEO JSON-LD generation to avoid lazy-loading `company` or `priceComponents`; detailed discount text is included only when `priceComponents` is already loaded in bulk.
- Updated `LocalContractsSection` to bulk `loadMissing(['company', 'electricitySource'])` before rendering cards so city-page local/regional sections keep logos and energy badges without per-card queries.
- Added a focused SeoContractsList query-count regression test for card relation lazy-loading.
