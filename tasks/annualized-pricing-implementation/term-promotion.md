# Current term and promotion unit

## Result

Implemented locally. Focused policy tests pass. Two integration tests need manager work with the parallel energy-episode change. No production access, network call, LLM call, new migration, configuration flag, stored method, commit, or push was used. Existing policy documents and other executors' files were not changed by this unit. The manager owns root and local policy documentation integration.

## Implementation

- `CanonicalContractPriceCalculator::calculate()` now defaults to `ComparisonPolicy::Current`. Every costable short fixed term uses its real no-overflow term boundary and one `12 / term_months` factor, even with complete future coverage. Current short-term calculation copies omit phases outside the term before component inheritance, offer baseline selection, and costing. Future Spot continuation does not require Spot evidence or affect the term result. Hybrid keeps its base-only verdict and the same term arithmetic.
- `usesSpotPricing()` accepts an optional comparison date and the same explicit policy. The current orchestrator does not request a Spot estimate only because a short fixed contract has a post-term Spot phase.
- `AsOfAnnualCostCalculator` explicitly passes `ComparisonPolicy::Historical` to both Spot detection sites and shared calculation. This retains the former ordinary short-term coverage rule and unknown-promotion hold rule for v1/v2. There is no date-based policy switch and no stored-row rewrite. Dedicated historical pricing never uses the new current-source prose reader.
- `PromotionTermsAssessment` checks component introductory roles, actual/normal discounts, unscoped introductory phases, and narrowly identified source campaign energy rates before Hybrid, short-term, incomplete-status, and unknown-tail exceptions. A promotion needs a finite resolved end and a known normal amount or a continuous canonical chain to a known normal component. A fixed-energy introduction can continue into a known Spot margin. Ordinary expiry, packages, unknown wholesale, and ordinary resets do not themselves identify a promotion. Explicit component promotion scope protects unchanged energy in a fee-only offer.
- Insufficient terms use `ExcludedIncomplete` plus the neutral `insufficient_promotion_terms` assumption, not a fabricated source conflict or an intent finding. Exact-period pricing also fails closed with `PeriodPricingUnavailableReason::InsufficientPromotionTerms`. The current period wrapper keeps the annual exclusion and also checks the factual requested window, so a short annual window cannot permit an incomplete post-term promotion in a longer factual bill. Period arithmetic still receives realized Spot facts, not annual projected prices.
- `CurrentSourcePromotionEvidence` makes one joined query per pointed batch. It matches the supplied contract's observation/publication pointers, contract identity, exact source snapshot, published status, and empty validation errors. Broken or changed pointers fail closed. Pointer-free legacy/local fixtures keep existing behavior. No arbitrary newest snapshot or relational `price_components` is read. The input builder's text normalizer handles entities/HTML/spacing.
- The source safety check recognizes explicit `kampanjahinta`, `kampanjan energiahinta`, and `energian kampanjahinta` wording paired with the same numerical canonical energy component. Generic `tarjous`, `vain`, and `superdiili` do not qualify. Source values are exclusion evidence only, never billed numeric fallback. A dated expired canonical introduction does not turn a later ordinary reset at the same rate into a new promotion.
- The Hehku-shaped source fixture is excluded despite a complete fee waiver. The Cheap-shaped 0.32 margin and complete four-month fee waiver remain available. This rule has no contract/company/ID special case.
- Detail hero, consumption-table note, and mobile price label use `Vuositasolle laskettu vertailuhinta` when the typed result has `contract_term`. Ordinary 12-month wording remains. Existing card term explanations already state the annualization formula and exclude post-term promises.

## Consumption convention

The real term uses the selected annual profile without normalization to half the annual kWh. The completed term euro result, including fees and one-time charges, is then multiplied once by `12 / 6`. Thus a winter-heavy first half can have more than half of annual consumption and a higher annualized comparison than a full-year bill. This is intentional, per the manager's clarification. A test supplies 12,000 annual heating kWh with 9,000 in January–June: energy at 10 c/kWh plus six EUR 5 fees gives EUR 930 for the real term and EUR 1,860 for comparison. No new 24-month policy was added.

## Interfaces

- New enum: `ComparisonPolicy::{Current, Historical}`.
- `calculate(..., ?SpotEstimate $spotEstimate = null, ComparisonPolicy $policy = ComparisonPolicy::Current)`.
- `usesSpotPricing(CanonicalContractData $data, ContractContext $context, ?CarbonInterface $startDate = null, ComparisonPolicy $policy = ComparisonPolicy::Current)`.
- `CanonicalContractData` adds optional `sourceCampaignEnergyRates = []` and `withComparisonEvidence(?array $phases = null, ?array $sourceCampaignEnergyRates = null)`. VAT and zero-effect calculation copies preserve the evidence. Assessment occurs before VAT normalization.
- New `PeriodPricingUnavailableReason::InsufficientPromotionTerms`.
- Public orchestrator signatures do not change. `evaluate()` now uses its existing shared batch parser/anchor path, so source checks agree with metrics, current statistics, custom API, and period wrappers.

## Verification

Final passing command, from `laravel/`:

```sh
php artisan test --filter='CurrentPromotionTermsTest|CanonicalContractPriceCalculatorTest|AsOfAnnualCostCalculatorTest|CanonicalPeriodPricingTest|BillComparisonCanonicalPricingTest|ContractPricingIntegrityServiceTest|ContractDetailPresenterTest'
```

Result: **158 passed, 1,166 assertions**. This includes missing/fixed/Spot short-term continuations, Hybrid, no-overflow dates, seasonal usage, package limits, in-term promotions, historical v1/v2 compatibility, source evidence, period rejection, detailed custom API rejection, and detail copy. Existing unknown-duration promo arithmetic fixtures now disclose a finite 12-month duration. The old one-month 4 c/kWh current hold test now checks exclusion and explicit historical EUR 200 compatibility. The old gap test is explicitly historical; incomplete current promotions cannot use that gap hold.

Additional integration command:

```sh
php artisan test --filter='ContractApiCanonicalPricingTest|CurrentAsOfAnnualCostParityTest'
```

Result: **10 passed, 2 failed, 86 assertions**:

1. `ContractApiCanonicalPricingTest:412`: the old total query ceiling is 7; the parallel lineage/episode reader now makes 9 queries for the pointer-free eight-contract batch. No `price_components` query appears. This unit's source reader makes no query for that pointer-free batch. Manager must reconcile the batch ceiling with the new bounded resolver.
2. `CurrentAsOfAnnualCostParityTest:101`: supplier anchor expected `current_source_observation`, got `missing`. The parallel resolver now uses an explicit date/full-signature policy; the orchestrator still calls it with the former signature. Manager already owns this integration. This unit did not change its memo identity or pass a new resolver date.

Earlier focused runs found expected obsolete promo assertions and incomplete promo fixtures; those were corrected as described above. One new test used the wrong enum case name and one fixture omitted required provider/model metadata; both were corrected. Final passing results above include those tests.

`vendor/bin/pint` ran on this unit's PHP files. `git diff --check` passed. Final diff and working-tree status were reviewed. No CSS/JS changed, so no asset build was needed. The full suite was not run, as instructed.

## Manager integration and limits

- Integrate the explicit current comparison date and full energy signature into candidate resolution/memoization. Do not retain the old energy-plus-fee memo key as the new policy.
- Integrate the two failing tests with that work. Current source evidence has its own test proving one query for eight pointed contracts.
- Update root/CanonicalPricing/Laravel documentation and the common calculated-cost schema once for the full release. This unit did not touch the schema version or scheduling/configuration.
- The source check is deliberately narrow, not a new general Finnish marketing classifier. Other omitted source promotion forms need canonical interpretation/validation or a separately reviewed narrow evidence rule. It never repairs missing prices from prose.
- No premium projection implementation is included.

## Files in this unit

Core: `CanonicalContractPriceCalculator.php`, `CanonicalContractPricingService.php`, `DTO/CanonicalContractData.php`, new `PromotionTermsAssessment.php`, new `CurrentSourcePromotionEvidence.php`, new `Enums/ComparisonPolicy.php`, `Enums/PeriodPricingUnavailableReason.php`, and `ContractStatistics/AsOfAnnualCostCalculator.php`.

Copy: `resources/views/livewire/contract-detail.blade.php`.

Tests: `tests/Unit/CanonicalPricing/CanonicalContractPriceCalculatorTest.php`, `ContractPricingIntegrityServiceTest.php`, new `tests/Feature/CurrentPromotionTermsTest.php`, `AsOfAnnualCostCalculatorTest.php`, `BillComparisonCanonicalPricingTest.php`, and `ContractDetailPresenterTest.php`.

## Static-review follow-up: margin campaigns and ordinary expiry

Changed only `PromotionTermsAssessment.php`, `CurrentSourcePromotionEvidence.php`, `CurrentPromotionTermsTest.php`, and this note.

- The source reader now explicitly recognizes `kampanjamarginaali` and `marginaalin kampanjahinta`. The assessment includes typed Spot margins as well as fixed-energy components. The existing numeric evidence transport and public helper signatures remain compatible. Generic `vain` and `SUPERDIILI` still do not create promotion evidence.
- The exact stated temporary-margin fixture at 0.32 c/kWh now excludes even with a complete four-month fee waiver. Separate tests cover unknown duration with a known normal margin, known duration with an unknown normal margin, and a complete margin promotion that remains available. Unavailable factual periods retain `insufficient_promotion_terms`.
- Expiry is no longer restricted to recurring resets. Suppression now requires a dated `normal` or `continuation` phase whose start is exactly the expired promotion's exclusive end, and that normal phase must have started by the comparison date. An old matching numerical rate alone is insufficient. Current introductory phases, relative starts, and newly started current-price phases do not receive this exemption. Explicit component promotions remain checked independently.
- A non-reset March/April source campaign followed by a same-rate normal phase on May 1 remains available in September. A new September introductory phase at the same rate, with a complete fee offer but unknown normal energy continuation, excludes. Annual and factual-period regression assertions cover both cases. The existing reset-expiry, Cheap, source-pointer validity, and one-query batch tests still pass.

Verification from `laravel/`:

- `php artisan test --filter='CurrentPromotionTermsTest'`: **6 passed, 71 assertions**.
- `vendor/bin/pint app/Services/CanonicalPricing/PromotionTermsAssessment.php app/Services/CanonicalPricing/CurrentSourcePromotionEvidence.php tests/Feature/CurrentPromotionTermsTest.php`: **passed**, no formatting changes.
- `php artisan test --filter='CurrentPromotionTermsTest|CanonicalContractPriceCalculatorTest|CanonicalPeriodPricingTest|AsOfAnnualCostCalculatorTest'`: **116 passed, 939 assertions**.
- `git diff --check`: passed. Final owned-file changes and working-tree status reviewed.

No core/orchestrator/model/enum file changed in this follow-up. No full-suite, network, production, commit, or push operation was used. The manager still owns documentation and core integration.
