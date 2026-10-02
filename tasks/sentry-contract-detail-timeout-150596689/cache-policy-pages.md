# Public-page consumer unit

## Result

Done. The final focused gate passes for every edited page/local test class. No network, production command, commit, or deployment occurred. This is not a claim that the complete PHP suite or overall retained-cache policy is complete; other units remain manager-owned.

## Implementation

- `PublicPriceCalculationPolicy` exposes `allowsCalculation()` and `allowUserAction()`. Its permission belongs to the current Request object, never Livewire public state. HTTP GET/HEAD cannot calculate, even after permission is requested. A non-HTTP context with no request method and no route can calculate. POST initialization cannot calculate without an explicit hook.
- AppServiceProvider binds that policy as scoped.
- ContractListingPipeline always uses shared annual metrics, including local cards. Missing or excluded IDs do not trigger whole-collection calculation. A cached empty set is valid. Latest legacy display components load separately.
- An unprepared custom GET listing throws ContractPriceCacheUnavailable. It must not show an empty-list or zero-contract claim. The shared renderer returns the existing honest Finnish HTTP 503 with Retry-After. A custom set prepared by an explicit action is read normally on GET and gives exact priced cards.
- LocalContractsService retains its location, audience, and postcode rules and uses the shared pipeline.
- ContractDetail reads all supported tiers through the shared accessor. Missing/custom GET and inactive GET annual prices remain null. A guard-generated `cached_source_evidence_is_not_current` exclusion is treated as unavailable pricing, not a financial calculation. A permitted explicit action can instead evaluate current canonical source data; genuine cached financial exclusions remain cached. Integrity and comparability resolve the same cached metric or one request-local explicit evaluation; there is no separate evaluate fallback.
- ContractDetail lookup no longer loads full legacy component history for annual calculation. Historical presentation still owns its historical queries.
- Fixed a real consumer error: missing selected pricing previously rendered EUR 0 in the hero and mobile sticky CTA. The hero now has an explicit unavailable notice with the consumption control still present. The sticky price CTA requires a real total. The notice copy is produced in PHP, not calculator arithmetic in Blade. Prepared detail view-data key is now **v21** (previous existing key was v20), because the payload has a new notice field.
- CompanyDetail reads one shared set per consumption in both modes. Its typed pricing map can contain null. Missing/excluded rows remain visible with null totals and sort last. Household/business filters remain unchanged. Latest legacy display components load in one batch instead of full history. When no selected annual price is available, the hero states that fact and gives a consumption-control next step.
- Consumption actions, validated direct-input hooks, inline listing calculator, filter/pagination actions, and bill recomputation/clear actions grant request-local permission. Mount/boot/lazy initialization do not grant it.
- Fixed a real blur-action error: after an exact custom value, a blank/zero direct input could cause a fresh POST render to lose its price or throw unavailable. The explicit direct-input hook now grants permission in its rejected-input branches after validation, so the previous accepted consumption can still be calculated. The rejected value is never sent to annual calculation. GET/HEAD remain denied.

## Existing test fixture changes

All warming is explicit and local to these test classes, after fixture creation and before initial GET/Livewire mount. No global TestCase warming, GET calculator exception, or blanket POST permission was added.

- CompanyDetailPageTest and CompanyDetailSectionsTest: private producer/mount/GET helpers. Query-count tests exclude producer setup from their request query log.
- LocalContractsServiceTest: two explicit full producer refresh calls after all local/regional fixture contracts and postcode links exist. Existing bulk-query and postcode eligibility assertions remain.
- ContractDetailPageTest, ContractDetailPresenterTest, and ContractDetailBillComparisonTest: explicit fixture warming before initial requests. The presenter cache-tier test now requires all four shared cached tiers. The retained-date test still checks retained payload equality and dated rank copy on a cached tier; an unprepared custom GET now checks the unavailable notice instead of requiring a calculated custom price.
- BillComparisonCanonicalPricingTest: two explicit annual producer calls before initial page mounts. Period mathematics and all financial assertions are unchanged.
- ContractsListPageTest and SeoContractsListTest: explicit fixture warming before requests. Prepared custom-query state tests explicitly prepare that exact custom profile in a temporary permitted action Request, then restore the original Request before GET. Consumption-limit tests use the real setConsumption action instead of directly changing an internal URL property. Financial, eligibility, metadata, control-state, and audience assertions remain.

## New test coverage

PublicPagePriceCachePolicyTest has 9 passing tests with 203 assertions:

- Both canonical and legacy warm rendered GET detail/company pages, every detail reference tier, local and regional city tiers, and actual city GET/HEAD.
- Real GET and HEAD for detail at 2,000/5,000/10,000/18,000 kWh, company pages, custom query inputs, a newly active contract with valid source prices but no retained metric, and inactive historical detail.
- Calculator expectations reject evaluate, metricsForContracts, and legacy calculate in cache-only public paths.
- Unsupported custom listings return honest 503; detail/company keep explicit unavailable copy rather than fabricated EUR 0. The missing detail price cannot enter the sticky numeric CTA.
- Cold GET/HEAD return 503 with Retry-After and no calculator fallback.
- An explicitly prepared custom profile is read on GET with its exact 7,654 kWh total.
- Canonical and legacy explicit Livewire custom action/direct hooks keep exact totals. Rejected blank edits keep the accepted custom price.
- Permission cannot cross Request objects; GET/HEAD cannot use action permission.

## Final verification

`cd laravel && php artisan test tests/Feature/PublicPagePriceCachePolicyTest.php tests/Feature/CompanyDetailPageTest.php tests/Feature/CompanyDetailSectionsTest.php tests/Feature/LocalContractsServiceTest.php tests/Feature/ContractDetailPageTest.php tests/Feature/ContractDetailPresenterTest.php tests/Feature/ContractDetailBillComparisonTest.php tests/Feature/BillComparisonCanonicalPricingTest.php tests/Feature/ContractsListPageTest.php tests/Feature/SeoContractsListTest.php tests/Unit/FormInputBlurPolicyTest.php`

**375 passed, 1,612 assertions, 10.96 seconds.**

Separate final `php artisan test tests/Feature/PublicPagePriceCachePolicyTest.php`: **9 passed, 203 assertions**.

Focused Pint on all owned implementation files and edited tests: passed. Final `pint --test` on the same files: passed. `git diff --check`: passed. Final diff and git status reviewed. Existing unrelated dirty work remains intact. No CSS or JS was changed, so no npm build was needed.

## Integration notes for the manager

- The earlier two local and 52 company failures were cold fixtures/in-progress shared-reader changes. The edited classes now pass; do not restore GET calculation to satisfy old fixture behavior.
- Root, Livewire, and cache contexts are manager-owned and were not edited here. They need the detail v21 key, missing-price notice, rejected-blur action permission, and explicit page-fixture setup details recorded. ContractListing/AGENTS.md is updated; its CLAUDE.md remains the existing symlink.
- Shared cache keys, retained guards, repair demand, ContractPriceCacheUnavailable, and its HTTP renderer remain owned by the shared-reader unit. Consumers do not read raw cache to bypass guards.
- Standalone calculator handoff remains separately assigned. This unit did not edit that calculator, its view, or routes.

## Guard-placeholder action follow-up

Manager review found that the detail accessor returned null for a guard placeholder before it checked action permission. The consumption notice therefore offered an action that could not calculate a new active uncached contract at a supported preset.

Changed only ContractDetail.php and PublicPagePriceCachePolicyTest.php, plus this report. Guard placeholders no longer enter the cached financial-result branch. They reach the existing permission gate: GET/HEAD remain null, but an explicit permitted action can use the unchanged current canonical evaluator. This does not change producer guards, use raw cache, add canonical relational fallback, or recalculate genuine cached financial exclusions.

Added tests for a newly active valid uncached contract and a valid changed Household-to-Both classification. Initial real GET, HEAD, and Livewire mount have zero evaluation calls. The explicit setConsumption(5000) action shows the exact EUR 300 annual total, with one selected-tier evaluation and no zero-price or unavailable notice. A third test proves that a genuine cached excluded_unknown_future financial outcome remains excluded after the same action, with no evaluation fallback.

Final command:
`cd laravel && php artisan test tests/Feature/PublicPagePriceCachePolicyTest.php tests/Feature/ContractDetailPageTest.php tests/Feature/ContractDetailPresenterTest.php tests/Feature/ContractDetailBillComparisonTest.php tests/Feature/BillComparisonCanonicalPricingTest.php`

**156 passed, 959 assertions.**

Separate policy class command: **12 passed, 240 assertions.** Focused Pint and final pint --test on the two changed PHP files passed. git diff --check passed; final diff/status reviewed. An intermediate test expected excluded_incomplete, but the actual unchanged financial outcome was excluded_unknown_future. That assertion was corrected and the complete focused gate rerun successfully.

No full suite, root/shared context edit, network request, production action, or commit occurred.
