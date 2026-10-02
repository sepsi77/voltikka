# Independent retained-price review

## Method and limits

The read-only reviewer inspected cache lifecycle, shared/company reads, page policy and actions, API, weekly profiles, widget and bill initialization. No runtime tests, network calls or production operations were run. Its saved final result was recovered from the output transcript after the agent registry lookup failed. Findings are code-path findings, not runtime reproductions.

## Findings accepted for focused repair

1. **Retained price/current mechanism mismatch.** `ContractListCacheService::currentAvailability()` records scalar classification only. Card categories and SQL pricing buckets use current reset/consumption-effect JSON mechanisms. A changed mechanism with unchanged scalar fields can attach a new category to an old annual estimate. Add bounded mechanism projections to the existing classification guard. Price/period-date changes alone must still retain prices. Older absent/partial additive metadata needs explicit compatibility handling, not emptying the generation on release.
2. **Unbounded custom handoff storage and client result state.** `ConsumptionCalculator::calculationResult` is public mutable Livewire state. The compare action accepts every positive integer, and exact handoff profiles are stored forever in the active generation. Protect server-produced result state; validate the existing listing upper domain before preparation; bound only non-preset custom handoff profiles to 30 minutes and 64 live profiles with owned expired-key cleanup. Preserve exact valid unusual values. Reject unavailable capacity honestly instead of evicting live users or approximating.

Both fixes are complete in separate core/caller units. The manager checked their actual diffs; focused core 85 tests / 693 assertions and caller 45 / 286 pass. The settled full suite passes 2,953 tests / 24,174 assertions. See `cache-policy-review-core.md`, `cache-policy-review-actions.md` and `cache-policy-final-verification.md` for implementation details and limits. These bounds do not change annual pricing mathematics or authorize production release.

## Reviewed expected behavior

- GET/HEAD denies calculation even after an action grant; POST alone gives no grant.
- Preset misses request coalesced repair and typed unavailability; unprepared custom GET does not calculate.
- Company wrappers aggregate cached prices only.
- Promotion checks stored digests, starting pointer, demand revision and producer lease. Failed builds retain the active generation.
- Custom writes reject pointer promotion races. A later promotion can make the redirect custom profile unavailable; profiles are intentionally not copied.
- Complete stored facts and timestamps survive typed transport; excluded/missing values do not become zero.
- API reads retained prices; weekly profile reads check generation coherence; widget initial state is cached; initial standalone bill GET does not calculate the example.
- Older payloads without additive classification metadata have a documented availability-only compatibility limit.
- The known demand-revision metadata-loss repair was not duplicated.

The reviewer found no other synchronous annual calculation path in the reviewed GET consumers. This is not exhaustive runtime or production proof.
