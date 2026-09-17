# Current estimator release checks

## Result

Local implementation is complete with verification exceptions. Release is not approved.
The new tests pass. Four existing tests use an invalid Supplier reference vintage and fail
under the required Current guard. Their fixture corrections need manager approval because
this unit was limited to new tests. Public floor copy remains a separate manager-owned change.
No replay, full suite, build, network, production operation, LLM call, migration command,
configuration change, commit or push was run. Existing unrelated changes remain.
Shared context and task status files are unchanged, as requested for manager review.

## Implementation

- `SupplierAdjusted/DTO/SupplierAdjustedEstimateRequest.php:28-29`: explicit policy defaults
  to Historical. The optional seasonal anchor is request-only. Policy does not depend on
  whether the energy-rate map is empty.
- `SupplierAdjusted/SupplierAdjustedPriceEstimator.php:27-29,63`: Current hold reports the
  effective beta 0; Historical keeps configured beta. The reset enable flag still does not
  disable Supplier estimation.
- Supplier estimator lines 74-108 and 257-264: Current rejects same-day/future curve dates,
  nonfinite reference/forward prices and offsets, and missing/invalid/non-prior reference
  dates. The reference bound is min(episode start, as-of). Exact ISO calendar-date validation
  avoids exceptions and rejects normalized invalid dates such as February 30. Finite negative
  wholesale prices remain valid. Historical guards and forward arithmetic are unchanged.
- Supplier estimator lines 198-222: Current requires finite positive reference/tail seasonal
  indices and uses the optional full-profile anchor. Its current-price DTO scalar stays the
  original representative. Historical ignores the override.
- `MarketReset/MarketResetPriceEstimator.php:46,50,76,127-157,388-395`: Current hold beta is 0;
  Current reference date validation is safe on malformed data; Current own forward prices
  and offsets must be finite. Existing Reset seasonal guards already reject invalid indices,
  so those guards were not changed. Historical behavior stays unchanged.
- `MarketReset/DTO/ResetEstimate.php:42-47`: optional hold beta defaults to the existing 1.0.
  Direct legacy calls and Historical Reset holds retain 1.0. The earlier premise that Reset
  already used zero was incorrect.
- `CanonicalContractPriceCalculator.php:2905-2934`: derive the Current seasonal anchor from
  candidate normalized energy rates mapped through `SupplierAdjustedEstimate::energyBucket`
  over the full selected profile. Use the same profile basis as Reset, not only tail usage.
  Pass policy explicitly. Stable 15/9 and 5/7 representative fields, full-tariff identity,
  fees, own-forward offsets and normal-map validation do not change.
- Calculator lines 611-781,1129,1133,1207,1356,1372-1378,1726,1759-1763: pass policy to the
  existing cost window (default Historical). At the existing max(0, rate + offsets) billing
  operation, record a floor only when a nonzero Supplier/Reset offset makes a bucket negative
  AND the segment costs positive usage in that bucket. Respect the exact Reset tail date.
  Current actual-bill outcomes and the relevant nested estimate flags carry
  `estimated_energy_nonnegative_model_floor_applied`. Historical transport and finance do
  not change. No second financial calculation, DTO mutation or fabricated actual projection
  is added. Unused negative buckets do not set the flag. SourceEnergyRule's separate existing
  actual/normal floor tracking remains intact.

## Bounded short-duration decision

`CanonicalContractPriceCalculator.php:488-490` excludes Current fixed-term `Below6` and
`Between711` with `ExcludedIncomplete` and `unknown_short_fixed_term_duration`, before promotion
assessment. These ranges prove a short term but not its exact duration. A phase end of 3 or
9 months, a promotion end, or a range midpoint does not establish the contract duration.

Exact Fixed6/12/24, known-long Between1323/Over24, and Historical retain their behavior.
Other/null ambiguous duration support is not broadened; no new exact-month evidence is added.

Keep post-term phase clipping. A fee-only six-month term cannot inherit its first energy rate
from a post-term Normal phase. An explicit in-term energy rate plus a fee normal_amount can
produce the real-term and annualized comparison, independent of a different post-term price.
These are evidence limits, not new source guarantees or broader inheritance rules.

## New tests

`laravel/tests/Unit/CanonicalPricing/CurrentEstimatorConsistencyTest.php` covers:

- Invalid/missing/reference-bound dates, same-day/future curve dates, nonfinite prices and
  offset overflow; finite negative wholesale remains valid.
- Current zero hold beta; Historical Supplier configured hold beta and Reset 1.0.
- Explicit policy with an empty energy-rate map; default Historical request behavior.
- Reset-only enable flag scope.
- Invalid seasonal indices; skew Time and Season full-profile anchors; VAT basis; preserved
  representative scalar; Historical anchor separation.
- Equal month reference and profile gives equal Supplier/Reset seasonal offsets. Different
  month/quarter reference periods intentionally differ.
- Scalar own-curve and per-bucket premium offset floors at billing, unused negative buckets,
  exact Reset tail protection, Current outcome/nested flags, positive-rate controls, and
  equal Current/Historical billed totals when the financial inputs are valid.

`laravel/tests/Unit/CanonicalPricing/CurrentShortDurationSafetyTest.php` covers both short
ranges with finite phase ends, Historical controls, exact/known-long controls, fee-only
in-term exclusion, and independent real-term/annualized fee savings with post-term prices.

## Verification

All Artisan commands ran from `laravel/` with:
`DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-estimator-no-config`.

1. Initial new-test run: 5 passed, 1 failed. The new Season/Time fixture used incorrect profile
   bucket names. Changed it to the real `DayTime`/`NightTime` and
   `SeasonalWinterDay`/`SeasonalOther` names. No implementation change was needed.
2. Initial expanded unit run: 52 passed, 4 failed / 824 assertions. One new control accidentally
   supplied an unbounded fee discount; removed normal_amount from that nonpromotion control.
   The other three failures are the existing invalid-vintage fixtures below.
3. `php artisan test --filter='CurrentEstimatorConsistencyTest|CurrentShortDurationSafetyTest'`:
   final **12 passed / 128 assertions / 0.18 s**.
   Log: `/tmp/voltikka-executor-estimator-final-new.log`.
4. `php artisan test --filter='Supplier|Reset|SourceValidatedEnergyRules|EnergyRule|Historical'`:
   **527 passed, 4 failed / 6970 assertions / 39.55 s**. This run preceded the final added Reset
   output test. Log: `/tmp/estimator-related.log`.
5. `php artisan test --filter='CurrentEstimatorConsistencyTest|CurrentShortDurationSafetyTest|MarketResetForwardShiftTest|Historical|SourceValidatedEnergyRules|EnergyRule'`:
   **298 passed / 3089 assertions / 14.49 s**.
   Log: `/tmp/voltikka-executor-estimator-passing-scope.log`.
6. Pint formatted the seven PHP files changed in this unit. Final `vendor/bin/pint --test`
   on those same seven files passed. `git diff --check` passed. Final source changes and
   `git status --short` were reviewed. No CSS/JS change requires a build.

## Remaining manager actions

- Correct only the reference-date fake in `SupplierAdjustedPricingTest.php:451`. It currently
  returns the forward trade date 2026-06-30 even for a June 1 episode reference bound. Return a
  date strictly before the supplied reference bound, while retaining forward trade date,
  prices and all price assertions. Failing tests are at lines 54, 105 and 234.
- Correct the reference-date fake in `VatIntegrationTest.php:29` in the same way. Its static
  June 30 reference is invalid for the June 1 Supplier episode; the failure is at line 86.
  Retain VAT prices and financial assertions. Run both tests and the related scope again.
- Add controlled public copy for `estimated_energy_nonnegative_model_floor_applied` using
  existing typed assumptions. State that Voltikka's estimate clamps a projected energy rate
  at zero. Do not call it a contractual minimum or seller guarantee. Current public copy
  only recognizes `energy_rule_nonnegative_model_floor_applied`; no claim of completed public
  disclosure is made here.
- Review these changes, update the manager-owned shared context/status files, and run the
  combined acceptance gate. No new replay was run by this executor.

## Manager review corrections — completed locally

This section supersedes the fixture and public-copy blockers above. Release approval still
belongs to the manager. No network, production, LLM, default-profile, commit or push operation ran.
Shared context and task-status files remain unchanged as instructed.

### Changes

- Calculator lines 1371–1385 now serialize each estimate once. They preserve the serialized
  flags, including Supplier anchor flags, and append the controlled billed-floor flag only
  for Current when a floor was applied. An existing flag is not duplicated. Historical
  serialization does not replace flags. Premium data is not serialized a second time.
- `SupplierAdjustedPricingTest.php:449` now returns the day before the requested reference
  bound. `VatIntegrationTest.php:29` uses May 31 instead of June 30 for its reference vintage.
  Forward trade dates, prices and all existing financial assertions are unchanged.
- `ContractCardCopy.php:187–190,250–262` adds one controlled model-floor note to the estimate
  popover. `ContractDetail.php:1512–1515` uses the same note in the receipt notes. Either floor
  assumption activates it; both assumptions still produce one note per surface. Finnish copy:
  `Voltikan laskentamalli rajasi nollan alittavan arvioidun energiahinnan arvoon 0 c/kWh. Tämä ei ole myyjän asettama vähimmäishinta tai hintatakuu.`
  No unflagged estimate gets this note. Known source prices retain their existing descriptions.
  Source-rule financial behavior and seller-floor offer copy are unchanged. Inspection found
  no existing public literal for `energy_rule_nonnegative_model_floor_applied`; the shared
  note now recognizes it too, without changing the separate source-rule price explanation.
- `CurrentEstimatorConsistencyTest.php:215–269` adds own-curve and per-bucket premium tests
  with anchor and estimator flags, positive and floored rates, byte-equivalent Historical
  estimate JSON (with the existing billed annual-equivalent replacement), typed transport,
  public popover disclosure and unchanged Current/Historical totals.
- `EnergyRulePublicOutputTest.php:304–351` tests absent, individual and combined floor flags
  in the popover and detail receipt. It checks one notice, the explicit model/not-seller
  distinction, retained known-period copy, and unchanged financial transport.

### Verification

All Artisan commands used `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-estimator-no-config`
from `laravel/`. Tests use the local PHPUnit database, not production.

- First targeted run: 40 passed, 2 new-test fixture failures (missing premium evidence and
  inconsistent SourceEnergyRules certainty). Second run: 41 passed, 1 new-test fixture failure
  (missing premium curve date). Only the new fixtures were corrected.
- `php artisan test --filter='SupplierAdjustedPricingTest|VatIntegrationTest|CurrentEstimatorConsistencyTest|EnergyRulePublicOutputTest'`:
  **42 passed, 480 assertions**, 0.58 s. Log: `/tmp/estimator-review-target.log`.
- Final `php artisan test --filter='Supplier|Reset|SourceValidatedEnergyRules|EnergyRule|Historical|VatIntegrationTest|CurrentEstimatorConsistencyTest|CurrentShortDurationSafetyTest|ContractCardPresenterTest|ContractDetailPresenterTest|ContractDetailPageTest|ContractApiCanonicalPricingTest'`:
  **717 passed, 8043 assertions**, 42.99 s. Log: `/tmp/estimator-review-related.log`.
  This includes the four previously failing Supplier/VAT tests and the final flag-preservation code.
- `vendor/bin/pint` on the seven PHP files listed above formatted only the two changed test
  files. Final `vendor/bin/pint --test` on all seven files passed. `git diff --check` passed.
- Final scoped diff and working-tree status were reviewed. Existing shared changes remain.
  No CSS/JS changed in this unit, so no asset build was run. No full suite or replay was run.

The manager must merge these implementation notes into the relevant shared context files and
run the combined release gate. This bounded unit does not approve deployment.

## Additional manager-found mixed-mechanism blocker

The manager reported one active source shape with both `energy_general = 12.8` and
`spot_margin = 0.55` in the same billed phase, plus a 5.5 EUR monthly fee, on a Hybrid Fixed24
contract. It is not a sequential fixed-to-Spot change. No production or source row was changed.

- Current annual costing now examines the effective billed components of each applicable
  timeline phase, including the phase used for unknown continuations. The existing short-term
  evidence clipping and real-term horizon run first. Both a real cents/kWh energy component and
  a Spot margin cause `ExcludedIncomplete` with `ambiguous_energy_mechanisms`.
- The check uses `effectiveBilledComponents`, not raw declarations. Non-billed reference roles
  do not count. Separate fixed and Spot phases retain the existing inheritance exclusion.
  Fees are not reinterpreted, combined differently, or modified. Historical skips this guard.
- Factual period costing checks each resolved phase before costing. Ambiguity returns the
  existing typed `NoPricing` reason with `ExcludedIncomplete`, even when passed an older valid
  annual result. No new unavailable-reason enum or annual projection enters factual billing.
- Implementation: `CanonicalContractPriceCalculator.php`, Current timeline check, factual
  period loop and `hasAmbiguousEnergyMechanisms`. No strict-reader or preflight file changed.
- New `CurrentAmbiguousEnergyMechanismsTest.php` covers the reported shape, forward and
  no-forward inputs, unchanged Historical controls (593.5 EUR with the synthetic flat 10-cent
  forward curve; 706 EUR without a forward curve), factual-period rejection, sequential
  fixed-to-Spot validity, each non-billed reference role for either mechanism, and an ignored
  post-term ambiguous phase on Fixed6. These are synthetic values, not a production replay.

Verification used the same isolated Artisan environment as above:

- Initial new test: 2 passed, 1 failed because the new Historical control expected forward
  arithmetic without supplying a forward estimate. Corrected the control and added an explicit
  forward-estimate branch. No existing financial assertion was changed.
- `php artisan test --filter='CurrentAmbiguousEnergyMechanismsTest|BillComparisonCanonicalPricingTest|CanonicalContractPriceCalculatorTest'`:
  **82 passed, 600 assertions**, 1.42 s; `/tmp/ambiguous-target.log`.
- Final `php artisan test --filter='Supplier|Reset|SourceValidatedEnergyRules|EnergyRule|Historical|VatIntegrationTest|CurrentEstimatorConsistencyTest|CurrentShortDurationSafetyTest|CurrentAmbiguousEnergyMechanismsTest|BillComparisonCanonicalPricingTest|CanonicalContractPriceCalculatorTest|ContractCardPresenterTest|ContractDetailPresenterTest|ContractDetailPageTest|ContractApiCanonicalPricingTest'`:
  **794 passed, 8613 assertions**, 44.22 s; `/tmp/estimator-review-final.log`.
- Pint formatted the new test imports. Final Pint test on all eight PHP files and
  `git diff --check` passed. Final scoped diff and working-tree status were reviewed.

Manager action: update shared CanonicalPricing context with this exclusion and run the fresh
combined replay. The manager expects the active listed count to change from 367 to 366. This
executor has not verified that count. No full suite, replay, network, production, LLM, default
profile, commit or push operation ran.
