# Reader and CLI implementation notes

## Scope and status
Reader and command implementation is complete. The parent approved the shared AGENTS documentation pass, and it is now applied. Overall task completion still waits for parent integration review. No production command, deployment, commit, or apply against actual local data was run. Test applies use an isolated test database.

## Reader decisions
- Both AsOf methods use the same public compatibility policy. The display key includes the actual method version. Dominant estimate-method transitions still create gaps.
- Company annual queries and both consumption-calculator queries select the active method. The seller-set index diagnostic accepts an explicit method and otherwise uses the active method. Its index mathematics is unchanged.
- The statistics page gives annual data a separate latest eligible active-method endpoint, bounded by the unit endpoint. Expected-basis and mixed-evidence guards remain. Unit panels and minimum samples remain unchanged. Retained annual values show their date and a historical note.
- An AsOf company comparison behind the latest same-basis unit date is historical. It cannot supply current Spot benchmarks. This is a data-endpoint check, not a clock-age rule for ordinary yesterday data.
- The necessary company copy is in `resources/views/partials/company-market-comparison.blade.php`, not in the company detail root template. Only dated fallback sentences change there.
- Statistics prepared cache schema advances to v21; company comparison payload schema advances to v11.

## CLI decisions
- Candidate defaults to v2. Baseline defaults to the active public method. Invalid methods and partial apply options fail before date queries. Apply accepts v2 only.
- Read the stored baseline and compare it before writing, including baseline=target. Diagnostic recalculation of v1 is not frozen original mathematics.
- Date selection includes retained baseline-only dates. Partial previews select one sorted contract-ID union from baseline and candidate, including baseline-only IDs. Both sides use that universe.
- Coverage counts refer to contract-consumption identities, not distinct contracts. Full-date aggregate deltas use stored baseline medians. Partial deltas rebuild baseline medians from selected stored annual values; they are not a comparison to the full market.
- AsOf available contract totals come only from the exact-method annual table. Legacy totals come from snapshot annual columns. Historical AsOf unavailable contract rows were not persisted, so their baseline count is unknown, not zero.
- Empty apply is a failure. A date with no current evidence can still report lost baseline coverage in preview; the writer refuses the empty apply.

## Approved AGENTS.md changes — applied
The parent approved this documentation-only pass after implementation review. Root, Laravel,
ContractStatistics, CompanyStatistics, and Livewire context files now include these changes and the
core evidence limits from `core-notes.md`. Public configuration and pricing cache schema v16 remain
unchanged. No code or tests changed in this pass. The parent owns final integration and task status.

1. Root annual-statistics brief: corrected current collection writes v2 beside retained v1; public configuration is not switched by deployment. Stored v1 supports dated rollback, not ongoing v1 recomputation. No automatic history rebuild.
2. ContractStatistics AGENTS: document explicit v2 target/baseline options, pre-write baseline capture, scoped partial coverage, v2-only full-date apply, method-specific display keys, active index diagnostic method, and the separate annual endpoint/cache v21.
3. CompanyStatistics AGENTS: both AsOf methods select only active annual rows; retained canonical annual comparisons use dated historical copy and suppress current Spot benchmarks; cache v11.
4. Livewire AGENTS: both consumption queries filter the active annual method; statistics annual panels retain a separately dated eligible endpoint while unit collection advances. Preserve expected-basis and sample/regime guards.
5. Laravel AGENTS command examples: make `--method=annual_cost_as_of_v2 --baseline=annual_cost_as_of_v1` explicit and link the reviewed rollout guide.

## Verification
- Final focused reader, CLI, compatibility, company, consumption, article/home/insight, index, and method-isolation suites passed: 153 tests, 882 assertions (`php artisan test --filter='AnnualMethodReaderStagingTest|AnnualSeriesCompatibilityTest|RebuildAnnualCostStatisticsCommandTest|ContractPriceStatisticsPageTest|CompanyDetailSectionsTest|ConsumptionCalculatorTest|StatisticsBasisConsumersTest|HomePageContractTrendTest|ArticleSpotElectricityStatisticsQueryTest|SellerSetEnergyPriceIndex|ContractStatisticsMethodIsolationTest'`).
- Final full suite passed: 2,268 tests, 10,388 assertions (`cd laravel && php artisan test`, 93.01 seconds). Log: `/tmp/annual-full-final.log`. Focused log: `/tmp/annual-focused-final2.log`.
- The empty-baseline regression found that mapping an empty Eloquent collection could retain model-collection set semantics and fail on string identities. Baseline records now convert to a base collection before mapping.
- `git diff --check` passed. No CSS or JS changed, so no asset build was needed for this unit.
- Pint check found existing formatting in statistics-page index closures and one existing test helper comment. These are outside this change and were not reformatted. New command and regression-test files pass Pint.

## Documentation-pass verification
- Checked all five AGENTS/CLAUDE pairs: each CLAUDE remains a symlink to AGENTS and resolves to identical bytes.
- `git diff --check` passed. Documentation diff and working-tree status were reviewed.
- Tests were not rerun for this documentation-only pass. Earlier test results above describe the implementation run, not later parent changes.

## Company chart copy correction
The parent requested a distinction between observed seller inputs and calculated annual estimates.
Historical chart copy now always calls the points dated annual-cost estimates. Older canonical-chart
points are described as estimates based on observed seller prices. Dates and layout stay unchanged.
Updated the affected copy assertion. `cd laravel && php artisan test --filter='CompanyDetailSectionsTest|CompanyDetailPageTest'`
passed: 77 tests, 278 assertions. `git diff --check` passed. The parent owns the next full-suite run.

## Snapshot storage warning
The annual table has no compatibility-snapshot foreign key. A same-day current snapshot replacement can leave old snapshot IDs in v1 provenance unresolved. Company joins use date plus contract ID, so a newly excluded identity can disappear from those joins. No snapshot schema migration is needed. Avoid an overwrite of the last retained v1 date and require an approved full database backup before mutations.
