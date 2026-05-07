# Decisions


## 2026-05-07

- Audited high-risk relation access in Livewire components, Blade card/detail views, and services using contract/company/source/price relations.
- `ContractsList` / `SeoContractsList`, `LocalContractsService`, `CompanyListCacheService`, and weekly-offer code already eager-load or batch-load the relations they iterate.
- `ContractDetail` had an additional active-page repeated-query path: rank-related computed properties resolved fresh `ContractRankingService` instances. Fixed separately in `ContractDetail` by memoizing computed values and reusing one service instance.
- Found `CompanyDetail` recalculated the same company contract collection from multiple computed properties during one render (`contracts`, stats, schemas, H1, hero, title/meta). Added per-render memoization and eager-loaded `company` so card logos do not depend on lazy loading.
- Added focused query regression tests for `ContractDetail` and `CompanyDetail`.
