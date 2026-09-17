# Current reset missing-reference premium integration — 2026-09-15

## Result and limits

Implemented locally. The manager final gate remains pending. This is not a release or full-policy
acceptance. Shared calculated-cost schema remains 18. Feature flags, beta configuration, and the
configured annual v2 method are unchanged. No network, production, application LLM, migration
command, history repair, commit or push ran. Tests use isolated SQLite fixtures; they do not use
the ignored local database. Existing uncommitted work and the separate fee-helper correction were
retained. The reset unit did not edit those fee helpers or CurrentSupplierPremiumIntegrationTest.

Still outside scope: real energy-promotion normal baselines, unknown gaps before known future
energy spans, general future gap plans, Hybrid premium projections, and full-policy public copy.
The calculator rejects these as reset-premium candidates rather than creating a baseline. Their
existing own-reference or fallback billing paths remain separate. Current policy still removes the
old fallback-to-today shortcut from all current reset estimator calls; Historical retains it.

## APIs and implementation

- `CanonicalContractPriceCalculator::resetPremiumCandidate(contractId, data, context, start)` returns
  `?ResetPremiumCandidate`. This final readonly frame contains complete named energy rates,
  metering, VAT basis, cadence, period start/month, exact tail start and required tail month keys.
  It uses the existing rate, inheritance, timeline, reset-tail and reference-start helpers. It has
  no consumption input and does not calculate a bill. Known initial coverage is required. Redundant
  unchanged rates and fully disclosed fee-only phases qualify. Ambiguous tariffs, unsupported
  components, Spot, packages, Hybrid and real energy promotions do not qualify.
- `CurrentPremiumEvidenceLoader::forCandidates(candidates, contracts, anchors, asOf,
  resetCandidates = [])` remains the single loader. The existing supplier API is compatible. Its
  one request-local peer universe includes eligible reset offers. It reuses pointed source and
  publication proof, full canonical fields, actual retained last-observed dates, the shared trusted
  lineage resolver and the pure selector. No persisted premium table or raw current price loader
  was added. Parse failures in one proven peer cannot remove valid alternatives.
- Reset targets with an original reference do not need peers. Missing-reference targets need a
  fresh, complete current curve only for actual tail months. Known months and unused months after
  a six-month term do not gate the price. Donor reference reads do not require donor future tails.
- Donor premium = each normalized billed energy rate minus its VAT-matched exact period reference.
  References use the existing cadence preference: month, or quarter then quarter-month average.
  The vintage bound is `min(period start, asOf)`, not today's curve for an old period. Exact month
  and quarter intervals stay exact. Seasonal/other and non-calendar intervals are explicit proxies.
- `PremiumObservation::pricingDate` retains its existing supplier meaning. For reset evidence it is
  the model reference bound, not an invented seller pricing event. The bound cannot follow the
  retail period start or target date. Its trade must be earlier. Already announced future periods
  can have model dates outside delivery, while the retail observation must independently be known
  by as-of. Supplier proxy rules remain strict. Reset proxies must contain the retail period end
  in the model interval and carry lower confidence. The selector rejects future model bounds,
  observations and trades. The actual last-observed date is not replaced with today's date.
- The existing resolver keeps own lineage, same company, then company-balanced market priority.
  Family, cadence, VAT, metering and complete named tariff buckets must match. Replacement and
  fee/ID clones do not gain extra variant weights.
- The orchestrator keeps separate supplier and reset premium maps. `calculate(..., premium: ...,
  resetPremium: ...)` has separately named typed optional inputs. Single, metrics, multi-consumption
  and period-wrapper annual entry points use the same preparation. Exact-period costs never receive
  projected rates. Retry clears the shared request-local evidence and market-provider state.
- `ResetEstimateRequest::policy` defaults to Historical for old direct callers. Current core calls
  pass Current explicitly. A valid original reference keeps its old formula and financial method.
  Current missing-own-reference order is selected premium, seasonal, then hold. Historical ignores
  supplied current premium evidence and retains the old fallback-to-today calculation.
- `ResetEstimate::offsetForMonthKey(month, bucket = null)` supports named per-bucket adjustments.
  The formula is `beta * (F_month * VAT + selectedPremium[bucket] - anchorRate[bucket])`.
  At beta 1, each projected bucket is futures plus that bucket's premium. The shared existing tariff
  mapping supplies bucket names. No weighted-average premium substitutes for Time or Season rates.
- Actual, normal and structured costs use the same adjustment and exact tail-start guard. Core
  segment splitting protects a known mid-month span. The premium plausibility guard uses actual
  whole-window and tail bucket weights. Zero floors remain. Displayed equivalents use billed energy
  divided by actual costed profile kWh. Real-term costs annualize once; ordinary locked 6/12/24-month
  pricing and Historical short-term routing are unchanged. A current active reset tail stays an
  estimate even when a source marks an open-ended phase as exact.
- Financial identity: method `recurring_forward_premium`, basis `forward_premium`, policy
  `recurring_forward_premium_v1`. Typed source, counts, bucket premiums, dates and confidence survive
  view/API serialization. Strict view validation reuses the supplier premium validator with a
  distinct required policy identity. Finnish reset copy states current futures plus comparable
  retail-price evidence. Seasonal copy does not falsely claim that futures are absent when the
  missing evidence is a defensible premium.

## Tests

`CurrentResetPremiumIntegrationTest` uses real SQLite source observations/publications, active
contracts, trusted replacement links and fake curves through the current service. It covers:

- Old pre-history own reference, a usable current FI curve, own lineage, same company and market.
- A valid original reference first; today's old-period reference cannot bypass peers.
- Company-balanced clone weights; incompatible family/cadence/VAT/metering; incomplete tariffs;
  malformed peers, missing proof, future publication/observation and invalid model reference dates.
- General, Time and Season rates against an independent fully disclosed two-price timeline.
  Actual, normal, structured and monthly costs reconcile. Company VAT converts once.
- Known current period then unknown tail; rejection of an initial gap before a known future phase.
- Fee-only target and donor variants; original measured fee savings remain intact.
- Exact announced quarters and lower-confidence seasonal/non-calendar proxies with model bounds.
- Known quarter and mid-month boundary, real six-month term, no futures for unused post-term months.
  The manual term arithmetic uses actual calendar profile fractions, not an assumed 2,500 kWh.
- Honest seasonal/hold fallback, beta, negative floor and the broad profile plausibility guard.
- Historical old-today fallback without current peer queries, exact-period actual-cost equality,
  every current entry point, public API serialization, strict view policy/curve validation and retry.

`PremiumObservationProxyTest` adds exact announced-quarter and non-calendar reset model-bound
checks while retaining supplier tests. The old fallback-to-today unit test now explicitly selects
Historical. The real-EEX container wiring fixture now supplies an original pre-period reference;
it no longer depends on the removed current shortcut. The seasonal-copy test uses honest wording.

## Query evidence and release limit

The integration test also uses real `electricity_futures_eod_prices` rows and the real EEX provider.
Eight same-period targets at three consumptions reuse the first calculation's peer universe and
EEX reference/curve reads: the later batch adds no peer-universe or EEX SQL. It reads no relational
`price_components`. This proves reuse for repeated identical keys, not constant SQL across arbitrary
pricing dates. `EexMarketReferenceCurveProvider` caches `(asOf, month, kinds)` references, while
`VintageAwareReferencePriceService` reads each distinct reference. A release performance check
must measure distinct-vintage costs against fresh data. No EEX query-system refactor was attempted.

The existing ignored local snapshot is too old for a current production price-impact decision.
No production fetch or sync was performed. The manager owns any root project-context summary and
full-suite final gate; no production mutation is authorized by this task.

## Verification history

- First reset/supplier/surface run: 99 passed, 3 failed. Two old fixtures expected the removed
  current fallback-to-today path; one expected the old misleading seasonal copy. These fixtures
  were corrected without changing retained Historical expectations.
- Initial new integration runs found test DTO property-name errors and a reference fixture that
  did not actually remove the target reference. These were corrected. The six-month equivalent
  check was corrected to use actual costed profile kWh; the billed term total was already correct.
- Expanded premium/proxy integration: 65 passed, 391 assertions, 1.36 seconds.
- First wide scoped gate: 531 passed, 5025 assertions, 20.50 seconds.
- Further API, known-tail, floor/beta and strict payload checks passed in the 31-test integration
  suite: 187 assertions, 1.49 seconds.
- Subsequent wide scoped gate: 537 passed, 5045 assertions, 19.10 seconds. Final checks are recorded
  below after the last estimate-classification assertion and formatting review.

Scoped command:

```bash
cd laravel
php artisan test --filter='CanonicalPricing\\|CurrentResetPremiumIntegrationTest|CurrentSupplierPremiumIntegrationTest|CurrentPriceEpisode.*Test|CurrentEpisodePricingIntegrationTest|CurrentPromotionTermsTest|AsOfAnnualCostCalculatorTest|CurrentAsOfAnnualCostParityTest|BillComparisonCanonicalPricingTest|CanonicalOfferSurfacesTest|CanonicalPricingListingTest|ContractApiCanonicalPricingTest|ContractPricingReadModelTest|ContractCardPresenterTest|ContractDetailPresenterTest|MarketResetEstimateSurfacesTest|EexMarketReferenceCurveProviderTest'
```

Pint runs only on the 17 PHP files changed by the reset unit. The shared calculator was formatted
only after the fee-helper agent returned ownership. No full suite or asset build ran; no CSS or
JavaScript changed.

Final local gate: **537 tests passed, 5047 assertions, 19.20 seconds** with the scoped command
above. `vendor/bin/pint --test` passed on all 17 changed PHP files. `git diff --check` passed.
The final working-tree review retained the earlier uncommitted files. The manager final gate and
fresh-data release performance/impact checks remain pending.
After the final DTO comment/error-text review, `php artisan test
--filter='CurrentResetPremiumIntegrationTest|PremiumObservationProxyTest'` passed again:
36 tests, 206 assertions, 1.38 seconds. Pint test on that DTO and `git diff --check` also passed.

## Manager review correction: dated candidate energy proof

The manager found a real gap in the initial candidate guard. A billed monthly fee made the current
phase appear covered. Reset candidate validation then resolved components and rates against all
phases, so it could borrow a missing current rate from a genuinely Future phase. A Time or Season
phase could similarly borrow its missing bucket. The earlier empty-phase gap test did not prove
this case.

Reproduction used a July 15 quarterly comparison: a current-structured fee-only or partial tariff
through September 30, followed by an October 1 Future phase with complete energy and a different
fee. The future components use the real `Future` price role. Before the correction, all six new
candidate/direct-loader tests failed: all three tariffs produced candidates, and the loader used
the unsafe same-company peer instead of the valid market alternative. No test was weakened.

`CanonicalContractPriceCalculator::candidateApplicablePhases` now holds the existing supplier
candidate applicability code. Both supplier and reset candidates call this shared pure helper.
It uses the existing timeline and `applicableKnownPhaseIndex`, not another calendar or billing
engine. Reset effective components and both actual/normal rate maps use the returned applicable
phase set. Missing current energy therefore remains missing instead of coming from future terms.
The one permitted exception remains a typed fee-only Introductory phase and its adjacent typed
Normal baseline. A current-structured fee phase, genuinely Future baseline, or non-adjacent normal
phase cannot use that exception. Like supplier proof, an unrepresented phase fails closed; this
correction does not add a wider out-of-window phase model.

Only candidate evidence changed. No billed-rate resolver, normal-fee helper, offer helper, reset
calendar, estimator formula, or Historical routing was changed. The Historical regression explicitly
pins the old global-inheritance result against a control with the same rates fully disclosed in
the current phase. The broader old future-borrowing billing behavior is not repaired here.

New tests cover all three tariffs at the candidate, direct loader and current-service boundaries.
They prove rejection of the unsafe target/peer, selection of the valid market alternative, retention
of a valid adjacent fee-introduction baseline, and rejection of the three invalid baseline forms.

Verification:

- Reproduction filter covering the candidate and loader tests: **6 failed, 12 assertions** before
  the fix (`/tmp/reset-future-proof-before.log`).
- New guard and baseline tests: **9 passed, 51 assertions, 0.63 seconds**. Command:
  `php artisan test --filter='test_reset_candidate_cannot_borrow_missing_current_energy_from_future_phase|test_loader_rejects_future_borrowing_peer_and_keeps_valid_alternative|test_reset_fee_only_intro_can_use_only_its_adjacent_typed_normal_baseline'`.
- The scoped reset/phase/period/Historical/API/view command above: **546 passed, 5098 assertions,
  19.66 seconds** (`/tmp/reset-future-proof-final-scoped.log`).
- `vendor/bin/pint --test app/Services/CanonicalPricing/CanonicalContractPriceCalculator.php
  tests/Feature/CurrentResetPremiumIntegrationTest.php`: passed. Pint required no formatting changes.
- `git diff --check`: passed. Existing uncommitted work remains. No full suite, network, production,
  migration command, application LLM, history repair, commit or push ran. Schema remains 18;
  configured annual v2, flags and beta remain unchanged. Manager final acceptance is still pending.

## Final focused verification follow-up

The detail popover fixture now seeds a legitimate original June 30 FI Base quarter reference
(`202607`, `FNBQ`, 50 EUR/MWh) beside its July 24 forward curve. All existing UI assertions remain.
The no-today-reference rule is unchanged; the test once again exercises real forward pricing.

The shared candidate helper also proves expired absolute-end fee phases at their last covered
past day. It compares full energy maps and does not create a reset pricing anchor. Three reset
tariff tests retain the adjacent fee-only Introductory/Normal proof across July 15/16 and reject
changed old energy. Missing old energy and outside-window future plans remain conservative.
See `phase-invariance.md` for the service and immutable-lineage checks, and `final-verification.md`
for the stable full-suite result. This follow-up does not widen reset future-plan scope.
