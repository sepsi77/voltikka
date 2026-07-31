# Decisions

## Initial decisions

- Create a small listing query/enrichment service, not a generic repository layer.
- Historical statistics must keep observed-data classification where current canonical facts would be retroactive.
- Keep Livewire actions and URL state in Livewire components.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed behavior and implementation design

- Baseline focused listing suite passed: 211 tests with 531 assertions.
- Base and SEO listings duplicate quarterly, time-of-use, seasonal, interactive energy, consumption, cold pricing, canonical exclusion, sorting, visible loading, and paginator code.
- Quarterly classification had drifted: the SEO listing accepted `neljästi vuodessa` and `neljä kertaa vuodessa`, while the base listing and statistics classifier did not.
- Add one small `ContractListingPipeline` service under `app/Services/ContractListing/`. It will own shared pseudo-type constraints, interactive post-query filters, cached/cold metric attachment, canonical exclusion, annual sorting, visible model loading, and manual pagination.
- Keep audience selection, SEO route constraints, SEO offer membership, city constraints/exclusions, Livewire state, and bill-period pricing in the components. Bill mode will use only the shared visible loader and paginator so its period basis does not mix with annual ranking.
- `LocalContractsService` will use the same annual enrichment and sorting method but retain its location discovery and display relations.
- One six-fragment quarterly rule will serve listing SQL and in-memory statistics classification. Statistics may inspect more historical text fields, but it will not keep a separate phrase list.

## Implemented change

- Added `ContractListingPipeline` with the six quarterly fragments and shared Quarterly, TimeOfUse, and Seasonal constraints.
- Moved shared interactive contract-type, pricing-bucket, metering, postcode, energy-source, and normal consumption constraints into the pipeline.
- Moved cached-or-cold annual enrichment, canonical exclusion, annual sorting, visible loading, and manual pagination into the pipeline.
- Kept audience and SEO route constraints in `ContractsList` and `SeoContractsList`.
- Kept bill-period calculation and sorting in Livewire. Bill mode now uses the pipeline for visible loading and pagination.
- Changed `LocalContractsService` to use the pipeline cold annual path. It keeps location discovery, consumption filtering, company distance data, and card relations.
- Changed statistics quarterly classification to use the pipeline matcher. Historical statistics still inspect four text fields and do not use current canonical classifications.
- Added local pipeline documentation and updated the service, Livewire, and statistics pointers.

## Verification

- The new phrase test failed before implementation because the base listing returned no matches. This confirmed the known rule drift.
- Focused listing suite: 117 tests passed with 263 assertions.
- Statistics consumer suite: 32 tests passed with 111 assertions.
- Additional listing route and filter suite: 186 tests passed with 417 assertions.
- Final manager listing suite: 214 tests passed with 545 assertions.
- Local listing and statistics consumers: 36 tests passed with 138 assertions.
- Pint passed for all eight touched PHP files. `git diff --check` passed. The new local `CLAUDE.md` is a symlink to `AGENTS.md`.
- Full Laravel suite: 1663 tests passed and 2 unrelated tests failed with 5903 assertions. `ContractDetailPresenterTest` has the known strict floating-point identity failure (`30.00000000000003` versus `30.0`) in isolation. `ContractDetailPageTest` creates the same monthly Spot average twice and fails its unique key in isolation. No listing test failed.
