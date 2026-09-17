# Nullable validation-success anchor repair

## Result

Local reader repair complete. Production release approval remains blocked until the manager reviews the combined changes and repeats the isolated comparison and performance checks. No fresh-data replay ran in this unit.

## Change

`CurrentPriceEpisodeResolver::sourceCandidate` now accepts SQL NULL, valid JSON null, and an empty JSON array as successful validation. Non-NULL values use `JSON_THROW_ON_ERROR`. Decoding without associative conversion keeps an empty JSON object distinct from an empty array. Malformed JSON, nonempty arrays, objects, booleans, numbers and strings fail closed.

The owner, status, publication pointer, analysis observation, stored profile, source proof, chronology and full energy-signature guards remain unchanged. No Historical implementation changed. No abstraction, query, migration, producer activation or cache version change was added.

## Regression coverage

`CurrentNormalEpisodeEvidenceTest` now checks ordinary V4 actual evidence and V5 normal evidence against the same success/error representation matrix. SQL NULL and JSON null produce the same full anchor as JSON []. Malformed, nonempty and scalar values produce no anchor. NULL success does not bypass publication pointers, interpretation ownership, scoped observation ownership, status or V5 profile checks. Existing chronology, source-proof, complete-tariff and Historical tests remain in the related test run.

A separate test runs the real `AnalyzeContractSourceSnapshot` job with a fake HTTP response and real validation/publication. It proves that successful publication writes SQL NULL and supplies the normal anchor. No real LLM or network call occurs. Existing episode fixtures still store []; they were not changed to conceal the defect. The test setup no longer installs a catch-all empty HTTP fake; stray requests remain prohibited, and the job test supplies its explicit response.

## Verification

All commands ran from `laravel` unless stated otherwise. PHPUnit forces SQLite `:memory:`; the active local SQLite file was not used.

- `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='CurrentNormalEpisodeEvidenceTest|CurrentPriceEpisodeResolverTest|CurrentPriceEpisodeChronologyTest'`: 41 passed, 336 assertions, 2.60 seconds.
- First attempt of that command: 40 passed, 1 failed, 331 assertions. The existing catch-all HTTP fake returned an empty response before the job-specific fake. Removing that catch-all corrected the test setup; product code did not change for this failure.
- `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='CurrentNormalEpisodeEvidenceTest|CurrentPriceEpisode|CurrentEpisodePricingIntegrationTest|CurrentSupplierPremiumIntegrationTest|CurrentEnergyRuleFinancialIntegrationTest|SourceValidatedEnergyRulesTest|Historical|AsOf'`: 311 passed, 5174 assertions, 38.39 seconds. Log: `/tmp/anchor-related.log`.
- `vendor/bin/pint --test app/Services/CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php tests/Feature/CurrentNormalEpisodeEvidenceTest.php`: passed.
- Repository-root `git diff --check`: passed.

## Limits and handoff

Only the resolver, the focused episode test file and this note changed in this unit. The large intentional working tree remains. Shared context and task status updates are reserved for the manager's consolidated documentation unit. No production access, active SQLite write, standalone migration command, fresh-data replay, commit or push ran. In-memory test schema setup used the existing RefreshDatabase test harness. Remaining work is the manager's combined review, replay, performance diagnosis and release decision; these test results are not production-impact evidence.
