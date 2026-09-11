# Core implementation

API agreement: `AnnualCostMethodVersion::AsOfV2 = annual_cost_as_of_v2` is now available. `isAsOf()` accepts v1 and v2. Calculator `calculate(date, methodVersion = AsOf)`, writer `preview(date, results, methodVersion = AsOf)` and `write(date, results, methodVersion = AsOf)` keep v1 defaults. Current canonical factory emits v2 only. Current service passes v2 explicitly. Public configuration is not changed.

Core implementation is complete, with the evidence limits below. No production action or historical apply was run.

Relational fallback decision, updated by parent instruction: `ContractPriceCalculator` now has immutable constructor option `uniformSeasonalConsumption = false`. The AsOf calculator owns one explicit uniform instance for v2 only. This constructor option gives v2 the shared flat default consumption profile without changing the legacy algorithm or mutating a shared calculator between v1 and v2 calls. Legacy/v1 still use the old default. V2 relational rows retain `ObservedRelationalComponents` and add `observed_relational_uniform_monthly_consumption`. Fee-only or missing tariff energy evidence is unavailable; explicit zero energy remains valid.

Dated consumption recovery: the resolver batch-loads only observed immutable source snapshot payloads, then reads the selected source's `Details.ConsumptionLimitation`. Both `MinXKWhPerY` and `MaxXKWhPerY` keys must exist, with non-negative integer or explicit null bounds. Missing/malformed limits do not prove unrestricted eligibility. V2 canonical rows can recover old null masks within proven bounds. Out-of-range and unknown eligibility have distinct reasons. A selected source with `TargetGroup=Company` causes a household conflict exclusion. No current contract limits or audience query is used. Dedicated retrospective evidence without this selected source payload cannot recover a null mask; this remains an explicit unknown diagnostic.

Snapshot storage check: the annual-only table has no price-snapshot foreign key. The old snapshot ID is inside provenance JSON. A current same-day rewrite leaves complete v1 annual and aggregate records unchanged, but deletes and replaces the unversioned compatibility snapshot identity. Its old ID inside v1 provenance can then be stale. A regression test proves both facts. No FK nulling or storage redesign occurs.

Verification completed:

```sh
cd laravel
php artisan test --filter='AnnualCostStatisticsWriterTest|AsOfAnnualCostCalculatorTest|CurrentAnnualCostStatisticsIntegrationTest|CurrentAsOfAnnualCostParityTest|VersionedRelationalAnnualProfileTest|ContractPriceCalculatorTest|AsOfHistoricalInterpretationIntegrationTest|ContractAnnualCostPersistenceTest|HistoricalPriceEpisodeResolverTest|HistoricalMarketInputBoundaryTest|ContractPriceStatisticsCanonicalSourceTest|AsOfSpotAssumptionsProviderTest'
```

Result: 196 tests passed, 1164 assertions, 2.58 seconds. This includes v2 writer isolation/idempotence/cleanup; mixed, Legacy-target, duplicate, wrong-date, empty, and incomplete batch rejection; writer insert rollback; outer transaction rollback after v2 annual insert; current exact outcome reuse; historical current parity for fixed/short/Hybrid/package/reset; monthly/quarterly and genuine announced future reset phase arithmetic; accepted dated supplier seasonal estimation; bounds/null recovery; Company conflict; flat relational profile; and component-scoped discounts. Earlier checks found a strict floating-point test assertion and a false test assumption that historical Spot shape equaled the separate current fixture shape. Both assertions were corrected; current persisted Spot parity remains exact.

`git diff --check` passed. Final diff and `git status --short` were reviewed. Existing uncommitted work remains. No CSS/JS, migration, public configuration, production, commit, or historical-apply action was made.

## Parent review correction: supplied-rate energy guard

The v2 relational guard now follows the engine's actual positive-rate precedence: General, Time, then Season. It does not require slots based on snapshot metering metadata. `SeasonalWinter` and `SeasonalWinterDay` map to the same winter slot, with last supplied value winning as in the engine. A selected Time or Season tariff requires both finite buckets, so an absent counterpart cannot become free energy. A fully disclosed zero General, Time, or Season tariff remains valid. A zero General row cannot hide a positive incomplete Time tariff.

For explicit Spot evidence, the guard checks the actual first non-monthly row that the legacy engine will use as its margin. It must be a finite recognized energy/margin type, including either winter alias. An unknown first surcharge is unavailable even if a later General row exists. V1 and the default legacy resolver remain unchanged.

Added regression cases cover General with Time/Season metadata, winter alias, incomplete and fully zero tariffs, actual rate precedence, recognized Spot margins, and unknown first surcharges. Existing fee-only protection remains tested.

Guard correction verification:

```sh
cd laravel
php artisan test --filter='AsOfAnnualCostCalculatorTest|CurrentAsOfAnnualCostParityTest|CurrentAnnualCostStatisticsIntegrationTest|AnnualCostStatisticsWriterTest|VersionedRelationalAnnualProfileTest|ContractPriceCalculatorTest|AsOfHistoricalInterpretationIntegrationTest'
```

Result: 150 passed, 993 assertions, 1.37 seconds. `git diff --check` passed. Final guard diff and working-tree status were reviewed. No full suite was run; the parent will run it after CLI integration.

## Local v2 preview regression correction

Done: annual `Unsupported` calculations with explicit present base-contract consumption
effect use an immutable phase copy without zero `Other` / `cents_per_kwh` placeholders whose
normal amount is null or zero. This restores Helen Valkkysähkö on 2026-07-25 (€716.88) and
Herrfors Vakaa on 2026-08-10 (€442.60) at 5,000 kWh. Known future increases keep their chronological
coverage. Unknown consumption effects stay explicitly excluded, never predicted zero. Source DTOs,
exact-period pricing, conflicting-source rejection, other opaque rows, and fee-only rejection stay
unchanged. The v2 relational Spot-only guard also recognizes the raw `Spot` margin type without
adding it to fixed tariff energy slots.

The explicit base-effect mechanism controls this exception, not the legacy pricing-model enum.
The parent precision review removed the extra `Hybrid` enum condition. A `FixedPrice`-labelled
contract with the same typed base effect also receives the known base-only estimate. Its negative
control has an absent effect, not merely a different pricing-model enum.

Calculator tests cover both totals, source immutability, exact-period exclusion, a six-month price
increase, the FixedPrice-labelled base-effect case, and ten negative guards. AsOfV2 tests cover both records and their typed base-only estimate
method/exclusion flags, plus raw Spot margin acceptance and non-Spot rejection.

Verification: `cd laravel && php artisan test --filter='CanonicalContractPriceCalculatorTest|AsOfAnnualCostCalculatorTest'`
passed after the mechanism-gate correction: **92 tests, 657 assertions**. An initial calculator-only run had one test setup error (missing
arguments to `calculatePeriod`); this was corrected before the passing run. No full suite was run.
`git diff --check` passed. Final diff and working-tree status were reviewed. The earlier test-file
style findings were introduced by this work, not pre-existing: the parent verified that both HEAD
test copies pass Pint. Pint was then run only on `tests/Feature/AsOfAnnualCostCalculatorTest.php`
and `tests/Unit/CanonicalPricing/CanonicalContractPriceCalculatorTest.php`. The resulting diff was
reviewed and contains import/type-reference formatting only. `vendor/bin/pint --test` on these same
two files passed. The same focused Artisan command passed again after formatting: **92 tests,
657 assertions**. `AsOfAnnualCostCalculator.php` was not changed during this formatting correction;
its HEAD copy also fails style checks, as verified by the parent. The canonical calculator passed
the earlier style check.
Canonical and statistics local AGENTS files were updated; both CLAUDE files remain symlinks.
No actual database, external service,
configuration, commit, or production action was used; feature fixtures use the isolated test database.

The parent owns shared AGENTS updates and combined task status. Add the method APIs, historical bounds policy, uniform relational option, and snapshot identity caveat from this note to the shared statistics documentation.
