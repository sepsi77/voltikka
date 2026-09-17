# Source review fixes

## Result

Both verified source-layer faults are corrected locally. V5 remains an explicit test/profile choice. No default, financial activation, DTO API, historical behavior, or version number changed.

## P1: names are source text

`EnergyRuleSourceProof` now checks contract_name and pricing_name through the same complete sentence/clause parser and conflict checks as descriptions. A supported clause in a name can prove a fact with its own exact citation. A contradictory amount, unsupported guarantee, or detached condition fails known proof. Ordinary alphabetic product names without pricing/condition signals remain valid. The name exception does not loosen positive pricing grammar. Numeric or ambiguous names remain conservative.

Unit coverage checks all six source text fields for the four reviewed unsafe claims. Publication coverage checks both name fields and verifies that rejected input changes no contract, interpretation, price-component, or active-contract rows. Valid ordinary names and complete cited name clauses pass.

## P2: adjustable quote applicability is not chronology

Current adjustable rules and normal bases use starts `contract_start` with null value and ends `none`. The source prover retains analysis_date only as internal point context for conflict checking. This does not assert an ongoing price lock, allow arbitrary earlier rule dates, or supply a pricing anchor. Immutable observations remain the sole source chronology. Fixed guarantees and discounts keep their exact periods.

Prompt v20 and the shared EnergyRulesFixture use the corrected bounds. The existing adjustable-rule unit fixture has the same one-line correction. No DTO files changed. The batch-proof service and SourceValidatedEnergyRulesTest were not edited. A commentary notice warned that the shared fixture changed and batch tests need a rerun; the final related test run includes those tests.

New feature coverage proves profile-5 A→B→A recurrence across September 15–17 reuses the same stored output, rematerializes publication, passes the one-query current batch proof, and makes no model call or queue dispatch. An expired UntilDate discount instead gets one observation-bound fallback job, without a real model call. Unit coverage keeps expired fixed periods invalid and proves that a conflicting fixed normal quote overlaps only the internal adjustable observation date, not an indefinite lock.

## Verification

Commands ran from laravel with `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config`; PHPUnit uses isolated SQLite memory databases and HTTP/queue fakes.

- `php artisan test --filter='EnergyRuleSource|SourceValidatedEnergyRules'`: 47 passed, 336 assertions.
- `php artisan test --filter='EnergyRuleSource|SourceValidatedEnergyRules|ContractInterpretationProfile|ContractInterpretationPipeline|Historical'`: 244 passed, 1554 assertions, 5.24 seconds.
- `vendor/bin/pint --test app/Services/ContractInterpretation/EnergyRuleSourceProof.php tests/Support/EnergyRulesFixture.php tests/Unit/EnergyRuleSourceContextTest.php tests/Unit/EnergyRuleSourceProofTest.php tests/Feature/EnergyRuleSourceReviewTest.php`: passed.
- `git diff --check`: passed.

No network, production operation, real LLM call, migration command, history rewrite, commit, or push ran. Test database setup is isolated. No CSS/JS changed; no asset build was needed. Common task JSON and root documentation remain manager-owned.
