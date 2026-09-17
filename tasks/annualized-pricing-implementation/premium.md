# Shared forward premium selection — completed

## Owned scope

Added only `laravel/app/Services/CanonicalPricing/ForwardPremium/`,
`laravel/tests/Unit/CanonicalPricing/ForwardPremiumResolverTest.php`, and this progress file.
Existing core, estimator, model, orchestrator, and old RetailPremium code are unchanged by this unit.
Other agents have concurrent working-tree changes; these were not overwritten.

## Exact API

Namespace: `App\Services\CanonicalPricing\ForwardPremium`.

`ForwardPremiumResolver::resolve(PremiumTarget $target, iterable<PremiumObservation> $observations): ?PremiumEstimate`

New files: `ForwardPremiumResolver.php`, immutable `PremiumTarget.php`,
`PremiumCompatibility.php`, `PremiumObservation.php`, `PremiumEstimate.php`, and enums
`PremiumFamily.php`, `PremiumVatBasis.php`, `PremiumSource.php`. `AGENTS.md` documents the full
constructor contract, date boundaries, clone signature requirements, and selection rules.
`CLAUDE.md` is a symlink to `AGENTS.md`.

The target supplies contract ID, trusted lineage/root ID, exact source company, strict
family/cadence/metering/bucket/VAT compatibility, and CarbonImmutable as-of date. Observations supply
energy-only normalized c/kWh by canonical ComponentType name, full retail energy offer signature,
actual observation date, prior reference trade date, retail price-period and reference delivery
bounds, and provenance. The caller must provide trusted identities and valid normalized evidence;
there is no matching or canonical rate calculation here.

Malformed DTO input throws InvalidArgumentException (unsupported enum/null values fail the typed
constructor). Date-ineligible and incompatible evidence is filtered. Reference delivery must match
the observation's supplied retail price period exactly, with a strictly earlier trade date. Historical
periods need not equal a target future month: premiums are transferable estimates. Reference trades
must also be strictly before the target date; observed retail evidence may be on that date.

Selection: newest compatible evidence per trusted lineage; reject conflicting newest-day evidence
without reviving old prices; own lineage first; then full retail-energy signature clone deduplication
within exact company; same-company median; then company-balanced market median. One independent
variant is sufficient. Counts, sparse/lower-confidence flags, source identities, evidence date bounds,
reference dates, delivery periods, and evidence provenance remain in the typed result. Negative
finite premiums are valid. No defensible evidence returns null, never invented zero.

## Verification

- `cd laravel && php artisan test --filter=ForwardPremiumResolverTest`: initial 27 tests / 197
  assertions passed. Final expanded run: **35 tests / 207 assertions passed**.
- `cd laravel && vendor/bin/pint --test app/Services/CanonicalPricing/ForwardPremium tests/Unit/CanonicalPricing/ForwardPremiumResolverTest.php`:
  first run reported spacing and PHPDoc style differences in three new files. Scoped `vendor/bin/pint`
  fixed them. Final check passed.
- `git diff --check`: passed.
- `readlink` plus `cmp` check for ForwardPremium/CLAUDE.md: passed.
- Final source review and `git status --short`: owned changes are limited to the new directory,
  new test, and this file. Concurrent changes are left intact.

The tests cover hierarchy, sparse fallback, date safety, historical-month transfer, newest evidence,
same-day lineage/signature/clone conflicts, strict compatibility, negative/nonfinite values, unknown
VAT/family/cadence, forbidden fee/Spot/Hybrid components, missing buckets, replacement and clone
weights, distinct retail signatures with equal premiums, market balance, all 120 permutations of a
five-observation market sample, null evidence, and finite median overflow protection.

## Integration boundary

No evidence loader or estimator wiring is included. Later adapters must supply the verified retail
energy signature and energy-only evidence. SpotForward keeps its explicit margin. Supplier/reset
billing adapters can use this one resolver. No production, network, LLM, configuration, migration,
cache, persistence, commit, or push action was made. No CSS/JS build or full suite was needed for this
standalone pure unit.
