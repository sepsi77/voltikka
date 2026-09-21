# Local v3 current annual-cost producer — 2026-09-21

## Result and boundary

The current producer supports explicit `annual_cost_as_of_v3`. No active-method switch ran.
Implementation, related tests and the resumed full-suite gate are complete. The full-history
preview and release acceptance are separate checks.
The factory accepts an optional method, defaults to v2, and rejects legacy/v1 before even an
empty-input return. Result versions and compatibility keys use the same method.
`ContractPriceStatisticsService` captures v3 only when the active-method configuration is exactly
its string value. All other configurations retain the existing v2 producer behavior, including
legacy/v1 rollback configurations. The captured method is shared by factory and writer inside
the existing date transaction. A config change in the transaction cannot split their versions.

Current annual rows still adapt the exact public `CanonicalPricingOutcome` objects. There is no
historical recalculation, component query, new price formula, default change, reader change,
schedule change, or new dependency. The existing legacy compatibility aggregates still run.
Only the selected AsOf method is replaced. Tests compare every stored column of seeded inactive
v1/v2 annual and aggregate rows before and after a v3 write. They remain identical.

The same-day snapshot caveat remains: compatibility snapshots are not method-versioned. Their
replacement can leave old provenance snapshot IDs unresolved or remove date/contract joins for
excluded contracts, although retained annual financial rows do not change. Do not overwrite a
last retained rollback date without the separate backup and approval safeguards.

## Tests and bounded parity

Producer tests cover explicit v2, legacy and v1 configuration; factory default v2; explicit v3;
retired factory-method rejection; supplied-object preservation; exact supplied totals; distinct
v2/v3 compatibility; no `price_components` reads; inactive financial row preservation; all three
consumption exclusions; v2/v3 out-of-range removal; and rollback after a v3 annual insert when
aggregate insertion fails. Existing feature-off and v2 rollback checks remain.

The bounded historical/current calculator checks cover supplier premium with a dated donor,
reset premium with a dated donor, Hybrid base pricing, and Fixed6 annualization. All three
consumptions use identical selected canonical source data, full General tariffs, target date,
audience, episode anchors, curve provider and dated premium observations. They compare non-null
totals to EUR 0.00001 and exact estimate methods. Historical selection is not widened to current
pointers. No economic parity failure was found in these fixtures.

These are calculator integration fixtures, not a new full source-validator proof or a comparison
of unequal live and historical evidence stores. Their existing partial parser fixtures mock full
interpretation validation. Separate exact-source validation tests run in the related/full suite.
The first new Fixed6 fixture lacked dated snapshot classification and source-backed term proof;
it failed closed before pricing. The fixture now supplies the exact Fixed6 source term and dated
snapshot classification. No historical guard or calculator was changed to make it pass.

## Verification

Commands run from `laravel/`:

- Initial targeted run: `php artisan test --filter='CurrentAnnualCostStatisticsIntegrationTest|CurrentAsOfAnnualCostParityTest|AsOfAnnualCostCalculatorTest'`: 1 fixture failure, 52 passed. After the exact-term fixture repair: **53 passed / 734 assertions**.
- Final related run: `php artisan test --filter='AsOf|AnnualCost|HistoricalPriceEpisode|ForwardPremium|CurrentPremium|SupplierAdjusted|MarketReset|CanonicalContractPriceCalculator|ContractPriceStatisticsService'`: **296 passed / 2,859 assertions**, 4.55 seconds. This includes the final extra default/compatibility/out-of-range assertions.
- Explicit Pint on the two producer PHP files and two changed test files: passed after formatting.
- `vendor/bin/pint --dirty --test`: passed.
- Full `php artisan test`: **incomplete after the resource pause**. The log has no final summary and ends after `CurrentResetPremiumIntegrationTest`. Shell PID 37133, Artisan PID 37136 and PHPUnit PID 37165 first had state `T`; after the background completion notification, all three were absent. This unit did not resume them or start another test. Log: `/tmp/v3-current-full.log`; background job: `job-20799-16`. The job completion notification is not a full-suite pass. The full-suite gate remains pending, and compute stays paused until user approval.
- After explicit user approval, the sole compute owner ran `cd laravel && nice -n 15 php vendor/bin/phpunit`. Direct PHPUnit avoids an idle Artisan PHP parent and runs the same complete configured suite without parallel workers. Result: **2,878 tests / 21,930 assertions passed**, exit 0, 149.757 seconds, reported memory 205.00 MB. Log: `/tmp/v3-current-full-sequential.log`. No preview or apply PHP process ran concurrently. This completes the full-suite gate; it does not approve production apply or the active-method switch.
- `git diff --check`: passed. The closest `CLAUDE.md` remains a symlink to `AGENTS.md`.

No CSS/JS changed, so no frontend build was needed. No production call, database apply, active
local database write, commit, push or deployment ran. Tests use the test database. Existing
uncommitted historical work remains. Main task status and full-preview documents are owned by
the other agent and were not edited by this unit.
