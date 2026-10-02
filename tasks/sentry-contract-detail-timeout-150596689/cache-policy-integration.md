# Cache policy integration checkpoint

The settled local implementation and regression gate pass. Production release is not authorized or performed. See `cache-policy-final-verification.md` for final evidence and limits.

## Manager review

- Reviewed actual producer diff: invalidation records demand while retaining pointer; captured revision and producer lease are checked at verified promotion; pending minute warmer is per instance; import completion rejects unsatisfied demand.
- Reviewed actual policy/page diff: routed GET/HEAD cannot receive calculation permission, all detail reference tiers use shared metrics, city legacy-card flag no longer bypasses canonical shared prices, company cached rows may be unavailable instead of zero.
- Reviewed actual shared read diff: retained-source-only changes keep complete old financial/provenance payload; current scalar availability/classification guard is separate from strict new-build source identity guard. Unsupported custom misses remain null and do not request automatic arbitrary-profile calculation. Explicit preparation stores exact custom metrics in existing generation keys with conflict guards.
- Reviewed actual action diff: ConsumptionCalculator's existing POST compare action validates the exact positive total, grants action permission, prepares that exact profile before redirect, and retains analytics. BillComparison no longer calculates a sample bill on initial GET; explicit actions keep period mathematics and protected result state.
- Requested company `_availability` markers be compact fingerprints, not full repeated universe arrays in every company row.

## Verification evidence and limits

Each row is a separate test run; counts overlap and must not be added.

| Stage | Passing focused evidence | Regression exceptions |
|---|---|---|
| Producer | 80 tests / 817 assertions; full suite before consumer changes 2,887 / 23,115 | Earlier full-suite pass is not final integrated evidence |
| Pages | Earlier page/local gate 375 tests / 1,612 assertions; follow-up policy/detail gate 156 / 959, policy alone 12 / 240 | Manager verified placeholder branch: GET/HEAD remain unavailable; explicit actions evaluate missing valid prices; genuine cached financial exclusions do not recalculate |
| Shared reads | Final core gate 85 tests / 693 assertions across six owned classes | Demand-metadata-loss, mechanism guard and bounded custom-profile repairs verified in actual diffs; full suite passes |
| Explicit actions | Earlier calculator/bill/shared filter 62 / 413; protected-handoff follow-up 45 tests / 286 assertions | Manager checked locked results, raw shared-domain validation before permission/preparation, exact 7,312 flow and expected-only accessible unavailability; core cache bounds are complete and included in the passing full suite |
| Other GET consumers | Owned API/widget/weekly/POST gate subsequently 85 tests / 738 assertions | Earlier 34 owned failures resolved; historical full checkpoint had 218 unowned failures / 2,708 passes |
| Remaining listing fixtures | Eight classes: 122 tests / 638 assertions | Manager checked actual diffs: explicit producer setup/action hooks only, no assertion removal or financial expectation weakening; 113 historical checkpoint failures resolved |
| Remaining SEO/history/typed fixtures | Eleven classes: 282 tests / 3,993 assertions | Manager checked typed adapters, scalar-availability-only unit mock and history warming preservation assertions; original numeric/history checks remain |

Scoped Pint and diff checks passed at each reported stage; they must run again after integration. The active satisfied-revision floor now prevents demand-key loss from hiding a later invalidation, without recursive bootstrap under the transition lock. Three added tests cover next invalidation/warmer completion, repair coalescing and rejection of a candidate after lost unmet demand. Remaining fixture integration and review repairs are in progress.

Final manager gate after all agent edits: full PHP suite **2,953 passed / 24,174 assertions** (`job-57171-58.log`), all 12 JavaScript tests and production asset build passed (`job-57171-56.log`), all 70 changed PHP files passed Pint (`job-57171-59.log`), whitespace check passed, and all 50 context mirrors match. The older Browserslist warning remains; no dependency update was made.

Manager cached full-kernel city checks passed with zero pricing/donor/futures work and matching displayed prices; see cache-policy-city-get-check.md. The independent read-only review found two accepted code-path gaps: mechanism/category coherence and unbounded, client-mutable custom handoff. Both are repaired and the manager checked their actual diffs: core 85 tests / 693 assertions, caller 45 / 286; the settled full suite also passes. See `cache-policy-independent-review.md` and the core/actions follow-up records.

## Remaining work

1. Remaining listing/SEO/history/typed fixture units from the 218-failure checkpoint are complete: eight listing classes (122 tests / 638 assertions) and eleven SEO/history/typed classes (282 / 3,993). Their focused results do not replace a settled full-suite run after the core/caller review repairs. Fix existing fixture setup by explicit verified producer preparation before GET/Livewire initialization. Do not globally warm tests or permit GET calculations in test mode. Change old expectation semantics only where the user intentionally changed policy.
2. Completed: nullable pricing/unavailable states, complete transport, and both accepted review repairs. No pricing-mathematics or shared preset-retention change.
3. Completed: direct-unit HTTP contexts and fixtures are explicit; no global test warming or GET-calculation exception was added.
4. Completed: targeted gates and settled full suite pass (2,953 tests / 24,174 assertions).
5. Completed: read-only full-kernel city/detail/API checks and a prepared exact 7,312-kWh snapshot handoff. Zero annual/donor calculation in GET. Detail retains bounded source-identity metadata queries; see final verification for measured limits.
6. Completed: root/local contexts, 50 mirrors and release plan. Only separately approved production release/producer/acceptance remain pending.
