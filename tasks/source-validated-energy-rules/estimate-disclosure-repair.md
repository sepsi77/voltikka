# Current V5 estimate disclosure repair

## Result

Both local metadata corrections are complete. No financial formula, floor value, estimator
selection, method identity, default profile, cache version or Historical path changed.
No network, LLM, migration, production operation, commit or push was used.

## Implementation and context for manager consolidation

- `CanonicalContractPriceCalculator::costWindow()` collects actual and normal billing fee rates
  from the evaluated segments. These are separate timelines. Variation in either timeline sets
  the new internal `EnergyRuleComparison::disclosedFeeChanges` flag. Different constant fees
  between the two sides do not set it. Zero fees remain real rates. Post-term phases do not count.
- The trailing optional flag defaults to false. `EnergyRuleComparison::toArray()` overrides only
  `monthly_fee_assumption` on the nested normal supplier estimate with the existing
  `disclosed_phases` / `held_flat` values. It preserves the estimate's genuine current
  `monthly_fee` input. It adds no JSON field, reset fee field or actual projection object.
  Normal availability, actual/normal certainty and SourceEnergyRules selection are unchanged.
- `EnergyRulePlan::rates()` now also records an estimated negative baseline-plus-offset clamp.
  This includes direct adjustable prices and projected normal prices beside an exact actual lock.
  It records the flag after fixed-price overrides, so a discarded projection does not imply a
  billed model clamp. Existing formula/source floors stay separate. A source floor on actual
  energy does not hide an independent model clamp on the normal comparison.
- The manager owns shared context and status consolidation. Add these details to the DTO context;
  its older statement that only estimated formula clipping sets the flag is now incomplete.

## Verification

Commands run from `laravel/`:

- `php artisan test --filter=EnergyRuleEstimateDisclosureTest`: final result 3 passed,
  122 assertions. Earlier attempts found a reserved PHPUnit helper name and an unbounded
  test fee promotion. Both test setup defects were corrected before the final run.
- `php artisan test --filter='EnergyRuleEstimateDisclosureTest|EnergyRuleKernelTest|EnergyRulePublicOutputTest'`:
  51 passed, 479 assertions.
- `vendor/bin/pint --test app/Services/CanonicalPricing/CanonicalContractPriceCalculator.php app/Services/CanonicalPricing/DTO/EnergyRuleComparison.php app/Services/CanonicalPricing/DTO/EnergyRulePlan.php tests/Unit/CanonicalPricing/EnergyRuleEstimateDisclosureTest.php`:
  passed (including the final repeat).
- Repository-root `git diff --check`: passed. Final diff and `git status --short` reviewed;
  unrelated existing and concurrent work was retained.

Dedicated tests cover actual/normal fee variation, equal phases, zero fees, different constant
fees, real six-month clipping, unchanged expected totals, strict payload round trips, reset
field absence, exact actual with estimated negative normal, direct adjustable rates, positive
controls, source-floor distinction, and null actual projection provenance. Existing kernel and
public tests were read and run but not edited. Shared fixtures were not edited.
