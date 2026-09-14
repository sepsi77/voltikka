# Decisions

- The public evidence conflict is verified. The earlier required import refresh failure has no retained exception class; its cause is not proven.
- The invalid date-column diagnostic was not an application bug. Do not change schema.
- Deployment is blocked on manager review and explicit approval for branch `main`, command `git push origin main`, target Voltikka project `6d8cae01-1006-409f-8108-1d51f1abc676`, production environment `9245cef8-41d0-486e-862f-193726511dba`, app service `700d0624-fa96-4266-876c-e37640d220ea`. This push starts the automatic production deployment. No commit or push occurred in this unit. The executor is on main; any later requested commit must first follow the branch safety rule and the reviewed integration plan.
- After an approved release, match the deployment to the exact commit and verify success. Then check interpretation activity and application data read-only. A full `contracts:fetch` retry requires separate exact-context approval. Forecast generation with the futures model and `--require-freshness` requires another approval after import/EEX/application freshness is verified. No cache flush, manual checkpoint edit, or production retry occurred.

## Implementation

- One final typed conflict class has only evidence/generation factories. Only that type retries or renders a plain Finnish HTTP 503 with 30-second Retry-After and no-store. Other runtime/storage errors still fail and report normally.
- List and company cold reads each have at most two total attempts. Company owns its budget and disables nested list retries. Failed cold calculations never publish; late cold writes reject an invalidated descriptor under the existing short transition lock.
- A refresh retries the entire eight-preset/company candidate once. Failed candidates must have durable retirement before retry. Scheduled bounded cleanup removes their exact keys after grace. Independent invalidation is never reversed.
- Reset clears hydrated list metrics, canonical Spot assumptions, episode anchors, Spot estimates, and the same request-scoped EEX provider. The canonical calculator has no outcome memo. Contract models and canonical JSON reload for every build.
- Required post-import Throwable objects are retained only for safe class/closed-reason extraction. Unknown exceptions get `unexpected`. No raw message, trace, or Throwable reaches the aggregate log/Issue. Optional work and recovered conflicts remain silent.
- No schema, dependency, environment flag, view, CSS, or JS changed. The first public conflict proves neither the earlier import failure cause nor a schema bug.

## Verification (local)

- Focused command: `cd laravel && php artisan test --filter='PriceCacheConflictRecoveryTest|ContractPriceCacheLifecycleTest|AnnualConsumerConsistencyTest|ContractPostImportCoordinatorTest|ContractRequestMemoizationTest|DataFetchFailureReporterTest|FetchContractsCommandTest|FetchEexFuturesCommandTest|FetchSpotCommandTest|EexMarketReferenceCurveProviderTest'`: 120 passed, 672 assertions, 16.56 seconds.
- Final `cd laravel && php artisan test`: 2,352 passed, 11,804 assertions, 91.71 seconds. Previous full pass before the last three tests: 2,349 passed.
- Scoped `vendor/bin/pint` on all 19 changed/new PHP files: passed after automatic formatting. `php -l` on each of those files: all passed.
- `git diff --check`: passed. Final diff/status reviewed. No unrelated initial changes existed.
- No npm build: no view, CSS, or JS changes.
- Initial focused runs identified expected old one-attempt/late-write/context assertions and a missing fixture availability field. Those tests were corrected and all final checks pass.

## Limits

A source transaction is not frozen while calculations run. Continuous publication can still exhaust the bounded budget and return 503 or fail the required import stage. Existing cached evidence mismatches remain exclusions; there is no stale-price or relational fallback. The worst case is two cold attempts or two complete private candidates per call. No production validation was performed.
