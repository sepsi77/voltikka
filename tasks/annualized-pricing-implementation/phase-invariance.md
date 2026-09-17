# Unchanged-energy phase invariance — 2026-09-15

## Scope and result

The bounded fee-only invariance unit is complete locally. This is not full-policy completion,
manager acceptance, a release, or production validation. Complete energy promotions, real future
energy spans, reset-loader integration, Hybrid projections, and full-policy copy remain pending.
Schema remains 18. No commit, push, network, production access, application LLM call, migration,
or history rewrite was performed. Existing unrelated uncommitted work was retained.

## Implementation

- `CanonicalContractPriceCalculator::supplierAdjustedCandidate` takes an optional comparison date
  and explicit policy. Historical calls the unchanged `SupplierAdjustedEligibility::candidate`.
  Current derives an ordinary synthetic candidate only from safe, complete, identical full actual
  and normal energy maps. It uses the existing phase timeline, effective billed components, and
  rate resolver. It does not use consumption or calculate euros.
- General, Time, and Season retain the same forecast with redundant phases, equal energy normal
  metadata, and completely disclosed fee-only promotions. The synthetic candidate is used only
  for energy estimation. Original phases still supply all actual and normal billing.
- Chronological inheritance uses applicable phases. A finite typed fee-only introduction can
  inherit its adjacent typed Normal baseline. This is a disclosed unchanged baseline, not a
  genuinely Future energy change borrowed into an initial gap. Unknown initial energy, ambiguous
  energy duplicates, unsupported units/mechanisms, packages, actual energy promotions, and real
  full-bucket price changes are rejected. A weighted average cannot hide offsetting bucket changes.
- The current fee is the actual resolved signup fee, not the future maximum. Fees and carrier IDs
  do not enter energy signatures. A fee-only boundary is not an energy guarantee. The current
  calendar-month remainder stays exact; later energy months use the same selected adjustments.
- The orchestrator, current peer loader, and immutable source episode resolver share extraction.
  Existing publication/source/VAT/date and finite-promotion guards remain. Query batching remains;
  there is no raw current fallback, per-contract database read, or additional whole-history query.
- Current annual normal billing preserves ordinary fee changes. Only a typed introduction uses a
  disclosed normal continuation as its phase-only offer baseline. The same energy curve applies
  to actual, normal, and structured billing. Promotion-free clone checks retain the same method.
- The typed payload accepts `monthly_fee_assumption=disclosed_phases` for changing resolved fees.
  Receipt text states that disclosed fee changes and offers are included instead of claiming flat
  fees. Plain fees retain `held_flat`. No broader public-policy copy changed.
- Dedicated AsOf candidate preparation and calculator-internal preparation pass Historical
  explicitly. Historical candidates, pricing, and no-current-peer behavior remain strict.

## Regression evidence

`CurrentSupplierPremiumIntegrationTest` now covers all three tariffs against plain pricing:
redundant phases; zero fees for three months; fee component normal amounts; phase-only offers;
inherited unchanged energy from an overlapping base and an adjacent Normal baseline; equal
normal metadata; ordinary fee changes; actual and normal monthly/annual costs; exact fee savings;
independent promotion-free clones; current signup fees; every current entry point; and explicit
Historical rejection of the wider phase forms. The only donor in the equivalence tests has a fully
disclosed fee promotion and immutable source proof. Actual energy-changing offers stay ineligible.
Existing incomplete source-campaign peer rejection remains unchanged.

`CurrentPriceEpisodeChronologyTest` adds real immutable fee-phase predecessor/current lineages for
General, Time, and Season. Fee/ID changes keep the old energy anchor. Real energy changes invalidate
that evidence. Existing offsetting Time-rate, VAT, malformed source, raw-history, date, and query
count regressions remain in the scoped run.

The period batch test now permits seven total queries rather than three: its ordinary `normal`
phase fixtures now qualify for four shared energy-episode evidence reads. Eight contracts still
share the batch, and no relational `price_components` query runs.

## Verification

All commands were local. Tests used isolated fixtures, not the ignored local database.

1. Initial targeted command:
   `cd laravel && php artisan test --filter='CurrentSupplierPremiumIntegrationTest|CurrentPriceEpisodeChronologyTest|SupplierAdjustedEligibilityTest|CanonicalContractPriceCalculatorTest'`
   Result: 122 passed, 745 assertions, 2.28 seconds.
2. New integration test run initially found a test DTO property-name error; corrected to
   `measuredDiscountSavingsTotal`. The next run passed: 43 tests, 628 assertions, 3.71 seconds.
3. Related Historical/promotion/VAT run:
   `cd laravel && php artisan test --filter='CurrentPriceEpisodeChronologyTest|SupplierAdjustedPricingTest|CurrentPromotionTermsTest|AsOfAnnualCostCalculatorTest|CurrentAsOfAnnualCostParityTest|VatIntegrationTest'`
   Result: 71 passed, 762 assertions, 1.56 seconds.
4. Additional exact-period normal assertions exposed the pre-existing ordinary future-fee baseline
   described below. The regression now pins the unchanged behavior rather than changing that path.
5. First wider scoped run: 348 passed, one failed, 3495 assertions. The only failure was the
   period-batch query ceiling described above; the expected batched ceiling was updated.
6. Final scoped command:
   `cd laravel && php artisan test --filter='CanonicalPricing\\|CurrentSupplierPremiumIntegrationTest|CurrentPriceEpisode.*Test|CurrentEpisodePricingIntegrationTest|CurrentPromotionTermsTest|AsOfAnnualCostCalculatorTest|CurrentAsOfAnnualCostParityTest|BillComparisonCanonicalPricingTest|CanonicalOfferSurfacesTest|CanonicalPricingListingTest|ContractApiCanonicalPricingTest|ContractPricingReadModelTest|ContractCardPresenterTest|ContractDetailPresenterTest'`
   Initial final run: 468 passed, 4027 assertions, 9.65 seconds. After the adjacent-normal-fee
   regression below, the same command passed **471 tests, 4042 assertions, 9.56 seconds**.
7. Pint ran on the ten PHP files changed by this unit: calculator, orchestrator, current episode
   resolver, supplier receipt copy, peer loader, AsOf calculator, typed pricing view data, and the
   three changed feature test files. It corrected formatting in three files. Final
   `vendor/bin/pint --test` on all ten files passed. `git diff --check` passed.

No full five-minute suite or asset build ran. No CSS or JavaScript changed.

## Final adjacent-normal-fee check

A phase-only zero-fee introduction must use its first normal fee, not a more expensive normal
fee disclosed later. Current unchanged-energy annual normal costs and offer terms now select
that first normal continuation. Explicit component normal amounts remain primary. General,
Time, and Season tests cover zero fee for three months, 4 EUR/month for three months, and
8 EUR/month thereafter: actual cost differs from plain pricing by +12 EUR; normal cost differs
by +24 EUR; savings remain exactly 12 EUR; the offer term states 4 EUR, not 8 EUR. This final
focused integration run passed 46 tests and 784 assertions in 3.91 seconds before the final
471-test scoped run. At this stage Historical and exact-period baseline rules had not changed.
The exact-period normal-fee defect was then corrected by the follow-up below.

## Remaining limits

- The confirmed exact-period normal-fee defect was corrected by the authorized follow-up below.
  Actual factual-period bills remain unchanged and receive no annual energy projection. Broader
  energy-changing period normal rules remain outside this unchanged-energy scope.
- The current extraction remains conservative. The final fee-expiry correction below adds a
  full-energy proof for expired absolute-end phases only. Unknown past identity, different old
  energy, and out-of-window future disclosures still fail closed. Immutable current coverage and
  full-map proof replace the former blanket absolute-start guard. This does not implement general
  future energy-price plans or actual energy-promotion baselines.
- Production impact cannot be checked from the local data. The manager's independent read-only
  check reports ignored `laravel/database/database.sqlite` at 675 MB, 4006 contracts, latest
  snapshot 2026-08-11, and latest FI futures trade 2026-08-10. Those data are too old to validate
  September 15 production price impact. No production fetch or sync was performed.

## Authorized exact-period normal-fee correction — 2026-09-15

The follow-up instruction now permits correction of the false period savings within the same
unchanged-energy scope. This unit is complete locally.

`CanonicalContractPriceCalculator::unchangedEnergyNormalRates` is the shared annual/period helper.
It preserves each ordinary fee segment, prefers explicit component normal amounts, and uses the
first normal continuation only for a typed introduction. `calculatePeriod` selects this helper
through the same consumption-free current candidate extraction, after its source-promotion guard.
It does not call an estimator or apply annual offsets. Candidate-ineligible energy-changing or
energy-promotion period rules retain their previous branch. Historical annual policy is unchanged.

Before: the full period could use the last future 8 EUR fee as the normal fee, including earlier
ordinary 4 EUR fee segments. This fabricated savings from the ordinary increase. After: the 0/4/8
fee schedule retains each applicable normal fee; only the introductory zero-fee segment saves
against its disclosed 4 EUR fee. With a July 15 start, the three-month introduction spans 92 days,
so factual-period savings are `4 × 92 / 30 = 12.266667 EUR`, not the annual model's 12 EUR. For the
full 365-day period, the former 36.8 EUR saving becomes 12.266667 EUR. Actual fee cost remains
60.533333 EUR. An ordinary fee increase without an introduction now has zero period savings.

Tests updated the expectations that had pinned the defect. Added General/Time/Season checks for
1-, 4-, 7-, and 12-month real periods from a mid-month start, including both fee boundaries and
Household/Company VAT. Each compares actual totals with `assertSame` against the original period
branch on identical phase data with candidate eligibility disabled; phase breakdowns must also
remain identical. Explicit normal fees of 3 EUR override the following 4 EUR fee. Savings use only
the applicable promotional days and the existing days/30 and VAT rules. No supplier forecast
assumption enters the period result.

Checks:
- `cd laravel && php artisan test --filter=CurrentSupplierPremiumIntegrationTest`: 49 passed,
  1216 assertions, 8.83 seconds.
- The full scoped command in step 6 above: **474 passed, 4474 assertions, 14.79 seconds**. It covers
  related period, phase, source-promotion, Historical, API, and presentation suites.
- Pint formatted the two changed PHP files, then `vendor/bin/pint --test
  app/Services/CanonicalPricing/CanonicalContractPriceCalculator.php
  tests/Feature/CurrentSupplierPremiumIntegrationTest.php` passed.
- `git diff --check` passed. No full suite, build, network, production operation, migration, LLM call,
  history write, commit, or push ran. Schema remains 18. The local-data release limit above remains.

## Independent review: explicit equal normal fees — 2026-09-15

The review found a defect in the shared fee helper. It used `hasNormalPriceDiscount` to decide
whether to find a later normal fee. That predicate is false for an explicit zero normal fee beside
an actual zero fee. Thus an introductory `amount=0, normal_amount=0` followed by fee 4 produced
false annual savings of 12 EUR and false 92-day period savings of 12.266667 EUR. The offer-term
fallback also invented a normal fee of 4. The prior verification did not cover equal explicit fee
normal amounts. That gap is now covered and corrected.

The current-scoped helper now distinguishes an explicit monthly-fee normal amount from a positive
discount. A later normal fee is used only when exactly one effective monthly fee exists and its
normal amount is absent. The first normal continuation must also have one unambiguous monthly
fee; multiple fee variants do not authorize borrowing a future maximum. The shared `singleMonthlyFee`
helper is used by normal-rate selection and current offer-component fallback. Energy normal amounts
are not fee evidence: equal energy metadata does not suppress a legitimate fee-only offer.
Explicit fee normal amounts of zero, equal nonzero values, lower values, and positive discounts
cannot be replaced by a later fee. The existing rate resolver's nonnegative-saving convention for
lower normal amounts is retained. No global Historical calculation or offer fallback changed.

Changes are limited to the owned normal-fee/offer helper regions in
`CanonicalContractPriceCalculator.php`, `CurrentSupplierPremiumIntegrationTest.php`, and this file.
The reset agent owns other calculator regions. No reset code, source helper, or DTO was edited.

General/Time/Season regressions now cover zero-equal-zero, nonzero-equal, lower normal amounts,
equal energy metadata with a genuine fee offer, explicit normal fee 3, and ambiguous current/normal
monthly-fee variants. Annual and 92-day/full-year factual bills assert no offer term or savings for
explicit equal fees. Actual annual totals match Historical controls exactly; actual period totals
and phase breakdowns match the original period branch exactly. The zero-equal Historical result
still has its previous 12 EUR saving and 4 EUR offer baseline, which proves that the correction is
limited to the existing current unchanged-energy control.

Verification:
- `cd laravel && php artisan test --filter=CurrentSupplierPremiumIntegrationTest`: **52 passed,
  1474 assertions, 12.30 seconds**.
- `cd laravel && php artisan test --filter='CurrentSupplierPremiumIntegrationTest|SupplierAdjustedPricingTest|CanonicalPeriodPricingTest|BillComparisonCanonicalPricingTest|CurrentPromotionTermsTest|CurrentPriceEpisodeChronologyTest|AsOfAnnualCostCalculatorTest|CurrentAsOfAnnualCostParityTest|CanonicalContractPriceCalculatorTest'`:
  **209 passed, 2766 assertions, 14.92 seconds**.
- `vendor/bin/pint tests/Feature/CurrentSupplierPremiumIntegrationTest.php`: passed.
- `vendor/bin/pint --test app/Services/CanonicalPricing/CanonicalContractPriceCalculator.php tests/Feature/CurrentSupplierPremiumIntegrationTest.php`:
  passed. No calculator formatter write ran while the reset agent worked. No calculator style issue
  was reported by this check.
- `git diff --check` passed. Final helper regions and working-tree status were reviewed. No network,
  production operation, full suite, commit, or push ran. The release data limit remains unchanged.

## Final fee-expiry correction — 2026-09-15

The manager's exact expiry case was reproduced. An absolute July 15 fee-introduction end made
all three tariffs lose their candidate on July 16. After test setup corrections, the pre-fix
candidate regression failed in all three cases (3 assertions; `/tmp/fee-expiry-before.log`).
The service had a second related guard: immutable extraction rejected any future absolute phase
start, including the disclosed July 16 Normal fee. Thus this valid fee schedule could lose its own
energy anchor even before expiry and use a peer premium instead.

The shared `candidateApplicablePhases` helper now uses an expired absolute inclusive end as a
past coverage-check date, through the existing timeline. Only Date/ContractStart/None/Unknown
starts can use this check. It still resolves the phase's full effective energy identity against
then-applicable terms and requires equality with every other energy map. The date is local to
candidate proof; it never becomes an observed energy start, pricing vintage, or reset anchor.
Typed fee-only Introductory phases retain the adjacent typed Normal baseline exception.
Unknown past energy, changed old energy, and future declarations outside the window remain
ineligible. This is not a blanket skip of old or future phases.

`CurrentPriceEpisodeResolver` now relies on that shared coverage/full-energy proof instead of
rejecting every later absolute phase start. Single future prices cannot fill missing current
coverage. Source, publication, completion, observation, VAT, and Historical guards remain.

New real-service tests use one fake curve and immutable publications in trusted predecessor
lineages for General, Time, and Season. Date and ContractStart introductions, with complete or
inherited unchanged energy, keep the same forecast method and annual energy equivalent as plain
pricing before and after expiry. Current fees move from 0 to 4 EUR. One service instance retains
the real June 1 lineage anchor, not a July 16 false rebase. Factual period actual totals and phase
breakdowns are byte-equal to the prior arithmetic control. Historical candidate rejection stays
strict. Negative cases cover changed old energy, missing/partial old energy, outside-window future
changes, and genuinely Future rates that cannot fill current energy. Reset candidates use the
same proof and have three additional tariff regressions.

Verification before the full suite:
- New regression filter `test_absolute_fee_expiry|test_expired_phase_proof|test_reset_candidate_keeps_expired`:
  **9 passed, 330 assertions, 1.87 seconds** (`/tmp/fee-expiry-new.log`).
- The final scoped current-phase/reset/period/Historical/API/view run, including
  `ContractDetailPageTest`: **646 passed, 5737 assertions, 25.31 seconds**
  (`/tmp/voltikka-phase-reset-final-scoped.log`).
- Pint on the five changed PHP files passed; only ContractDetail fixture imports needed formatting.

The stable full suite passed: **2573 tests, 15019 assertions, 123.11 seconds**, exit 0
(`/tmp/voltikka-phase-reset-final.log`). The exact command and retained prior 2563-pass/1-failure
run and 791 ms build are in `final-verification.md`. All 64 changed PHP files passed Pint test;
`git diff --check` passed. Schema remains 18. This unit
does not complete energy-promotion, future-plan, Hybrid, or full-policy acceptance work. No network,
production, application LLM, migration command, commit, or push operation was used.
