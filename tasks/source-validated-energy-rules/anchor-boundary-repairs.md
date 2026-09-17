# Current anchor boundary repairs

## Result

Both additional reader repairs are complete locally. Release acceptance and a fresh isolated replay remain with the manager. This unit did not run a replay or read production data.

## Changes

- `CurrentPriceEpisodeResolver::historicalTariffCandidate` accepts absent discount metadata and the exact inactive representation: NULL or `NoDiscount` type, NULL/false/0/`'0'` flags, NULL or finite numeric zero amounts/windows, and a NULL end date. Numeric database zero strings are supported. Active or unknown flags, unknown types, nonzero windows, malformed numbers and every non-NULL date remain rejected. No truthy string conversion is used.
- After all immutable intervals are gathered, the resolver adds an event on the Helsinki calendar day after each inclusive end only if that day is no later than as-of and another interval covers it. This detects removal of conflicting or unknown evidence inside proven coverage. It adds no observation in an uncovered gap or after the last coverage. Dates deduplicate with existing events.
- The full energy tuple, units, identity, household VAT convention, company exclusion, pre-immutable cutoff, normal-evidence guards and fee independence remain unchanged. Raw history cannot establish V5 normal semantics. The recent SQL NULL validation-success guard is unchanged.

## Synthetic regression coverage

`laravel/tests/Feature/CurrentPriceEpisodeChronologyTest.php` uses its existing isolated in-memory database, real resolver, shared lineage query and canonical calculator. Five new tests cover:

- Identical complete Time/Season anchors from January 21 through the July 23 immutable transition for NULL, inactive NoDiscount/zero, and NULL-type/zero metadata. The supported upstream unit spelling remains accepted.
- A different predecessor tariff followed by the current Time/Season tuple on June 15. The anchor stays June 15, not January or July 23.
- Nineteen unsafe metadata cases, both without and with later valid immutable evidence.
- Different and unknown predecessor overlaps ending September 9. The valid remaining run starts September 10, not September 16. As-of September 9 has no future anchor. An identical overlap does not restart the run. UTC-to-Helsinki end conversion is checked.
- No new observation in uncovered gaps or after final coverage. Actual gap-proxy flags remain.

The related test run also covers existing normal strict-gap rules, pointed publication continuity after midnight, NULL-success handling, source proof, integration and Historical behavior.

## Verification

Commands ran from `laravel`, except Git commands from the repository root. No test used the active SQLite file.

1. `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='CurrentPriceEpisodeChronologyTest|CurrentNormalEpisodeEvidenceTest|CurrentPriceEpisodeResolverTest'`
   - 46 passed, 412 assertions, 2.58 seconds. Log: `/tmp/anchor-boundary-focused.log`.
2. `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='CurrentNormalEpisodeEvidenceTest|CurrentPriceEpisode|CurrentEpisodePricingIntegrationTest|CurrentSupplierPremiumIntegrationTest|CurrentEnergyRuleFinancialIntegrationTest|SourceNormal|SourceValidatedEnergyRulesTest|Historical|AsOf'`
   - Final: 316 passed, 5252 assertions, 36.60 seconds. Log: `/tmp/anchor-boundary-related.log`.
   - Earlier run before extending the June 15 test to Season: 316 passed, 5250 assertions, 35.93 seconds.
3. `vendor/bin/pint --test app/Services/CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php tests/Feature/CurrentPriceEpisodeChronologyTest.php`
   - Passed on both runs.
4. `git diff --check`
   - Passed. Final diff and status reviewed. Earlier intentional changes remain intact.

## Limits and handoff

Only the resolver, the dedicated chronology test and this note were changed in this unit. Shared AGENTS/task status documents remain reserved for the documentation agent. No configuration, cache version, Historical implementation, producer activation, production/network/LLM operation, active SQLite write, standalone migration command, commit or push changed.

The remaining Lammaisten July 23 missing interpretation MUST stay unknown. These repairs must not bridge that missing evidence. The manager must review the changes before running the fresh isolated replay; local synthetic tests do not establish production price impact or release approval.
