# Shared documentation, schema, and public methodology

## Result
Done for this unit. Shared schema is now 16. Public methodology and focused tests pass. Full-suite and VAT integration verification remain with the parent. No commit, deployment, production call, historical rewrite, or task-status edit.

## Changes
- `laravel/app/Services/CalculatedCostPayloadSchema.php`: bumped VERSION once from 15 to 16. Do not bump again for this task. Existing dependent cache keys use the shared marker.
- Root `AGENTS.md` and `laravel/AGENTS.md`: replaced contradictory annual eligibility, consumption, Spot shape, VAT, and cache policy inline. Kept historical rollout facts and stated that schema 16 does not rewrite stored history.
- `laravel/app/Services/AGENTS.md`: concise shared policy and pointers for date-keyed metrics, typed estimates, reconciled usage, and VAT.
- `CanonicalPricing/AGENTS.md`: replaced unknown-future exclusions, signup-only Hybrid holds, seasonal default consumption, mixed-VAT rejection, Company-forward restriction, and shape-required forward claims. Documented known phase priority, explicit latest-applicable/disclosed-normal assumptions, no free gaps or extended promo savings, real-term annualization, base-only Hybrid, no-overflow anniversaries/fees/bins, calendar packages, once-only charges, billed-energy equivalents, midnight cache boundaries, local/legacy Spot provenance, baseload fallback, and one-time audience VAT conversion. Kept dated old schema facts.
- `ContractPricing/AGENTS.md`: typed continuation/term/Hybrid assumptions, lower-confidence Spot hydration without shape dates, VAT transport, and schema 16 compatibility.
- `ContractStatistics/AGENTS.md`: distinguished missing historical observations from estimable future segments; documented reuse of canonical outcomes, no stored-history rewrite, and test isolation. Preserved the Spot owner's earlier additions.
- `laravel/resources/views/livewire/about-page.blade.php`: only the `menetelma` section changed. Corrected the cents-to-euros formula, audience VAT convention, default flat usage, unknown-price estimates, baseload fallback, and neutral retail spread wording. Added short-term/Hybrid/package explanations using the existing layout. No CSS/JS or design change; no LLM prose rendering.
- `laravel/tests/Feature/AboutPageMethodologyTest.php`: four focused public-page tests, including missing and available historical Spot references.
- `laravel/phpunit.xml`: added the parent-authorized forced legacy annual-method test default. Matches `config/contract_statistics.php`; AsOf tests still opt in through config. Production config is untouched.
- All existing owned CLAUDE.md symlinks remain unchanged.

## Verification
Commands ran in `laravel` unless stated otherwise.

1. `php artisan test --filter=AboutPageMethodologyTest`: final **4 passed, 31 assertions**. Initial run had one overly broad `assertDontSee('tällä hetkellä')` that matched unrelated page copy; narrowed it to the Spot parenthesis, then passed.
2. `php artisan test --filter='AboutPageMethodologyTest|ArticleSpotElectricityStatisticsQueryTest|CompanyDetailSectionsTest|ContractRequestMemoizationTest|ContractRankingTypedMetricsTest'`: **54 passed, 287 assertions** with the new forced default and no shell override. Covers shared schema cache-key tests and the known local-.env isolation failures.
3. `vendor/bin/pint --test app/Services/CalculatedCostPayloadSchema.php tests/Feature/AboutPageMethodologyTest.php`: passed.
4. `vendor/bin/phpunit --filter='AboutPageMethodologyTest|ArticleSpotElectricityStatisticsQueryTest|CompanyDetailSectionsTest'`: **49 passed, 273 assertions**. Log `/tmp/annual-docs-phpunit.log`. This is the parent's requested plain PHPUnit path.
5. Additional adversarial check with an explicit process-level `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_as_of_v1` prefix: both Artisan and direct PHPUnit still select AsOf for legacy fixtures despite the `<env force>` entry. Both runs: **25 passed, 20 failed**, 97 assertions (direct PHPUnit: 11 errors and 9 failures). Logs `/tmp/annual-docs-test-isolation.log` and `/tmp/annual-docs-phpunit-shell.log`. This does not occur with the local `.env` and plain PHPUnit. The task only authorized the normal forced-env pattern; no bootstrap, server-variable, or production config changes were made. Do not run the full suite with an explicit AsOf shell override. If process-export isolation is also required, the parent must decide whether to force the matching PHPUnit server variable too.
6. Root `git diff --check`: passed. Reviewed owned diff and final working-tree status; unrelated concurrent files were not edited. Confirmed owned CLAUDE paths remain symlinks.

No asset build was needed for this copy-only view change. The parent owns the full suite and final build.

## Integration notes
- Shared VAT docs describe the agreed full integration policy, not proof that the VAT owner's ongoing work passes. Confirm final typed `vat_basis` presentation and exact-period tax handling in the integrated suite.
- `AboutPage::spotAverageWithTax()` has an old comment that calls the trailing figure the annual estimate. The visible template now correctly labels it as fallback evidence. The PHP component was outside assigned ownership and was not changed.
