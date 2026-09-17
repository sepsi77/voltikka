# Bounded current Hybrid base-price projection — 2026-09-15

## Result and scope

Implemented, locally verified and accepted by the manager for this bounded local scope only.
This is not full-policy acceptance or a release. Authoritative final test, build and 66-file style
results are in `final-verification.md`. Shared schema stays 18; flags, beta and configured annual v2 are unchanged.
No network, production access, application LLM call, migration command, history repair, commit or
push ran. Tests use isolated SQLite fixtures. Existing intentional uncommitted work was retained.

Eligible Current contracts have explicit canonical consumption effect `present=true`
and `applies_to=base_contract`. Supplier-adjusted projection stays OpenEnded-only. Active canonical
resets also support the existing FixedTerm contexts, because the reset mechanism proves that a
fixed contract term does not guarantee a locked energy price. Raw Hybrid or FixedPrice follows that mechanism. Complete known
General/Time/Season base components and canonical structured status `complete` remain required.
An Unsupported calculation status can then describe the excluded effect without blocking the
base forecast. No source status, source context or effect number is rewritten. An incomplete or
conflicting structured source does not gain candidate eligibility from the effect alone.

The scope is unchanged base energy across supported phases and existing known reset periods.
Real energy promotions, future energy plans and gaps remain outside this unit. Fully disclosed
fee-only phases, equal normal metadata and the completed absolute-expiry proof remain supported.
Fixed-term guarantees and six-month real-term annualization are unchanged. Historical remains on
its original strict candidate and pricing paths, without current peers. Exact-period actual bills
never use annual projections or a consumption effect.

## Implementation and APIs

- `SupplierAdjustedEligibility::candidate` adds optional `currentBaseHybrid=false`. It retains
  the same component validator and is strict for default/Historical callers. `isBaseHybrid` is
  the shared explicit-mechanism predicate. The current calculator passes the mode only after its
  existing timeline/full-energy proof. The normalized candidate mechanism is Hybrid even when
  the source enum is FixedPrice. The hold-flat test double accepts the optional argument too.
- The calculator reuses `withoutZeroBaseEffectPlaceholders` before candidate proof. Explicit
  ConsumptionEffect components are removed from the calculation copy; all effect numbers remain
  disclosure data, never costs. Only the existing proven zero Other placeholders can be omitted.
  Other unknown charges, missing rates, unsupported units and packages cannot become candidates.
- Both candidates reuse `candidateApplicablePhases`, effective components and actual/normal rate
  equality. No second billing or calendar resolver was added. The immutable current episode
  resolver gains Hybrid signatures through that shared helper, not a new raw fallback. General
  snapshots and Time/Season raw rows still cannot prove pre-immutable Hybrid base episodes.
- Current eligible Hybrid supplier costs use the same `costWindow` and estimate assumptions for
  actual, normal and structured passes. Current reset Hybrids receive the selected reset premium
  through the existing reset path and flag. Known reset spans use their original tail/reference
  dates. No reset uses a supplier episode-month date instead.
- `PremiumFamily` adds SupplierAdjustedHybridBase and MarketResetHybridBase. `isReset()` applies
  the same cadence/model-date guards to both reset families. The single existing loader query
  includes Hybrid; proven canonical mechanism controls eligibility. Ordinary and mandatory-effect
  rates cannot mix. Own references remain first, followed by trusted lineage, company and
  company-balanced market evidence with unchanged dates, VAT, deduplication and confidence.
- ResetPremiumCandidate and both estimate requests add optional `pricingMechanism='FixedPrice'`.
  Current calls pass the normalized mechanism. Estimators reject the wrong premium family too.
  This is an internal API extension, not a new public payload field or premium loader.
- BaseOnlyHybrid and effect exclusion remain. Forward/seasonal methods describe the actual
  forecaster. Supplier hold-only results retain HybridBaseOnly while their existing estimate
  payload explains the fallback. Fixed-term and Historical routing remains unchanged.
- ContractPricingViewData accepts existing supplier/reset estimate methods with BaseOnlyHybrid.
  It retains strict record validation and exact payload round trips. No public field was renamed.
  Shared card copy states both that future base prices are estimates and that the consumption
  effect is excluded. The Hybrid supplier band does not claim a fixed-price guarantee. Category
  precedence, published current rates, annual equivalents and explicit Spot margins stay separate.

## Regression evidence

The real-service SQLite tests use pointed immutable source/publication fixtures and fake curves.
They cover General/Time/Season, raw Hybrid and canonical base-effect FixedPrice, own references,
lineage/company/market fallback, clone weights, exclusion of ordinary peers and mismatched VAT,
reset cadence and known quarter, and missing-premium hold without an invented zero premium.

They compare actual/normal/structured/monthly costs, fee-only variants, normal metadata, current
fees, all current service entry points, public API serialization and controlled popover copy.
Hybrid fee-expiry tests retain the real predecessor energy anchor before and after expiry.
Effect expected/min/max numbers and explicit effect components cannot change cost. Unsupported
charges, missing base, packages, optional fixing, Spot and wrong reset fee units are rejected as
candidates. Company VAT and heating profiles match ordinary controls; the negative floor remains.
Fixed6/12/24, Historical no-query controls, and factual periods under a wild curve remain unchanged.
A dedicated raw-history test rejects both raw FixedPrice and Hybrid snapshots for Hybrid targets.

## Earlier scoped verification (run history)

These run facts precede the fixed-term reset correction below. Current acceptance and the final
integrated checks are recorded at the top of `final-verification.md`.

All test commands use this local isolation prefix from `laravel/`:

```sh
APP_ENV=testing APP_CONFIG_CACHE=/tmp/voltikka-hybrid-no-config.php DB_URL='' DATABASE_URL='' DB_CONNECTION=sqlite DB_DATABASE=:memory:
```

Final command:

```sh
php artisan test --filter='CanonicalPricing\\|CurrentResetPremiumIntegrationTest|CurrentSupplierPremiumIntegrationTest|CurrentPriceEpisode.*Test|CurrentEpisodePricingIntegrationTest|CurrentPromotionTermsTest|AsOfAnnualCostCalculatorTest|CurrentAsOfAnnualCostParityTest|BillComparisonCanonicalPricingTest|CanonicalOfferSurfacesTest|CanonicalPricingListingTest|ContractApiCanonicalPricingTest|ContractPricingReadModelTest|ContractCardPresenterTest|ContractDetailPresenterTest|ContractDetailPageTest|MarketResetEstimateSurfacesTest|EexMarketReferenceCurveProviderTest' --no-ansi
```

Result: **669 passed, 6967 assertions, 33.19 seconds**, exit 0.
Log: `/tmp/hybrid-final-scoped.log`. The earlier scoped gate passed 663 tests/6898 assertions.
Pint formatted the two integration test import sets. Final Pint test passed for all **19 PHP
files changed by this unit**; the explicit path list is `/tmp/hybrid-php-files`.
`git diff --check` passed. No full suite or asset build ran; no CSS or JavaScript changed.

Interim checks exposed the estimator's old family rejection and the typed view's old Hybrid
method whitelist; both now accept only the intended new family/method composition. One old
negative test treated exact-status explicit base effect as unproven. It now proves the current
base-only behavior instead. Test DTO/property mistakes and the hold-flat override signature were
corrected; no financial assertion was relaxed. The last incomplete-source test first lacked its
`insufficient_evidence` issue code. The old continuation guard uses issue codes, not free-form
missing-fact names; the corrected fixture now proves the new Hybrid branch does not bypass that
guard. The intermediate scoped result was 668 passed/1 failed. The final run above supersedes it. The reset tail transport field remains `Y-m`, not
an invented full-date value.

## Manager review correction: fixed terms with active resets

The manager identified two omissions: the Hybrid reset candidate allowed only OpenEnded, and the
short Hybrid reset call did not pass the selected premium. Both are corrected. The active canonical
reset and complete known-base guards remain mandatory. This does not widen the supplier path or
change fully locked Hybrid Fixed6/12/24 guarantees. Historical still ignores current peers.

Six real-service cases cover General/Time/Season at Fixed6 and Fixed12. Ordinary and Hybrid targets
use separate compatible peer families with equal base rates. Their actual term costs, annualized
costs, base costs and monthly costs agree. The known quarter stays exact; no futures after the real
term are required. Current Hybrid output identifies `recurring_forward_premium` and retains
BaseOnlyHybrid, effect exclusion and null expected effect. Historical has no selected premium.

- Reproduction: `php artisan test --filter=test_fixed_term_hybrid_reset_matches --no-ansi`:
  **6 failed, 12 assertions** before the fix (`/tmp/hybrid-term-before.log`).
- After the fix, the Fixed12 cases passed. Three Fixed6 checks initially multiplied already
  annualized Historical months again. Removing that test-only duplicate factor preserved the
  exact known-quarter comparison.
- Scoped command: `php artisan test --filter='CurrentResetPremiumIntegrationTest|CurrentSupplierPremiumIntegrationTest|AsOfAnnualCostCalculatorTest|CurrentAsOfAnnualCostParityTest|CanonicalPricing\\' --no-ansi`:
  **373 passed, 5813 assertions, 25.43 seconds** (`/tmp/hybrid-term-scoped.log`).
- Full stable command: `php artisan test --no-ansi`: **2602 passed, 16393 assertions,
  127.75 seconds**, exit 0 (`/tmp/voltikka-hybrid-term-full.log`). All tests used the isolation prefix
  above. This supersedes the manager's pre-correction 2596-pass/16249-assertion full run
  (`/tmp/voltikka-hybrid-full.log`, 129.13 seconds). The manager's pre-correction asset build passed
  in 815 ms; no assets changed or new build ran in this correction.
- Pint test passed for the calculator and reset integration test. `git diff --check` passed.
  No full-policy completion, release, production access, network, commit or push is claimed.

## Remaining limits

Manager acceptance is complete for the bounded local scope; the corrected local full suite passed.
The user must decide component price guarantees and discount formulas before the larger
energy-changing plan. `phase-semantics.md` remains a proposal, not approved new policy.
The ignored local production snapshot remains
too old for a current price-impact decision; no refresh was authorized. Existing distinct-vintage
EEX query costs still need the previously documented fresh-data release check. Structured-incomplete
sources remain conservative non-candidates even when their disclosed base can be held for display.
The existing `onlyFuturePricingUnknown` predicate uses issue codes and billed components rather
than free-form missing-fact names; this broader old policy was not changed. Future energy plans,
energy-promotion normal baselines, general gaps and full-policy public-copy acceptance remain pending. This unit adds no calibration rule, threshold, flag or history writer.
