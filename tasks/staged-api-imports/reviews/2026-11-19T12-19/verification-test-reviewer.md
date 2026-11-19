# test-reviewer — staged-api-imports verification
Range: `cdc93650e77e1db73f7f9067fee987a73de59c62..743f1c14a0066e6e65ac042bc529ff91ef877a5c` · Result: **12 Fixed / 5 Partially fixed / 0 Not fixed; 3 new findings (2 blocking, 1 normal)**

Verification of TST-001..005 and regression coverage for LAR-001..007, PRC-001..002 and PLT-001..003 only. No source/test/config changes, commits, live APIs or production access. Other reviewers' untracked reports/prompts are not implementation changes. `/AGENTS.md` is absent; repository-root guidance was used.

The targeted runs passed: **149 Laravel tests (1,271 assertions), 83 Python tests**. Counterfactual failure against the original implementation was assessed from the fix/test diffs, not by editing or reverting source. Passing sequential tests do not prove concurrent store ordering.

## Per-finding verification

| Finding | Verdict | Test evidence and assessment |
|---|---|---|
| TST-001 | **Fixed** | `laravel/tests/Feature/ImportPayload/StagedImportTest.php:55,71` compares unordered key sets. `ImportPayloadCleanupTest.php:50-64` decodes JSON and no longer pins prose/member order. New progress-wording assertions elsewhere are TST-101 below. |
| TST-002 | **Fixed** | `laravel/tests/Feature/FetchWeatherStagedCommandTest.php:54-65,69-161,338-359` adds EC46/SEAS5 to both acceptance scenarios. Real Artisan/service/database runs assert nonempty mode parity, fetch-time/model metadata, a later-insert failure with zero surviving run rows, safe SQL diagnostics, retained payload and offline replay. Both seasonal products passed both cases. |
| TST-003 | **Fixed** | `laravel/tests/Feature/FetchEexFuturesCommandTest.php:712-762,965-986` now fails year inserts after month/quarter statements and compares a seeded pre-run row, including its original price. `FetchEexEuropeFuturesCommandTest.php:144-184` fails the last maturity after earlier upserts. Both preserve payloads and replay through real services with HTTP prohibited. Removing the store transaction would change the pre-run comparison and leave earlier rows. |
| TST-004 | **Fixed** | `laravel/tests/Feature/FetchSpotStagedImportTest.php:28-39,98-205` has no averages-service binding. Three Artisan tests use the real importer/averages/database, compare persisted prices and averages, reject writes during fetch-only, fail the average table after successful price statements, and replay without HTTP. The queue alone is faked at the unrelated warm boundary. |
| TST-005 | **Fixed under the recorded replacement design** | `orchestration/tests/test_jobs.py:568-624` checks the cross-language retention constant, oldest-safe/first-unsafe dates, actual scheduled store timeouts and expiry refusal in scheduled/replay workflows. `test_s_forecast.py:145-149,210-220` covers S stores. `laravel/tests/Feature/ImportPayload/ImportPayloadCleanupTest.php:27-100` executes real cleanup and proves directory-date retention of both files, including old mtimes. This is coverage of the approved bound-plus-age-guard mechanism, not an open-reference registry; that design decision is not reopened. |
| LAR-001 | **Partially fixed (recorded scope)** | `laravel/tests/Feature/FetchSpotStagedImportTest.php:137-174` forces an actual average INSERT error after price persistence, checks prices/averages absent, one safe Issue, no warm job, retained payload, and successful replay. This fails with after-commit averages. The same-day rerun at `:183-205` also exposes the SQLite lookup defect repaired by `whereDate`. Rolling dates still use the store clock by the explicit manager decision; no cross-date fetch-time guarantee is claimed. |
| LAR-002 | **Fixed** | `laravel/tests/Feature/FetchEexFuturesCommandTest.php:843-877` fails the final ready checkpoint with a database trigger, after price writes. It requires unchanged pre-run rows/demand, final failed metadata with payload/SQL facts, and one Issue. The old swallowed exception would instead commit price rows and omit JSON SQL facts. |
| LAR-003 | **Partially fixed — required failure-chain test substitutes an internal service** | `laravel/tests/Feature/FetchEexFuturesCommandTest.php:783-810` is a useful real-database regression: the running and failed records must retain one replay owner. The central newer-run/after-commit-failure scenario at `:813-840` replaces our cache service; see TST-102. The code correction is not disputed, but full real-chain proof is incomplete. |
| LAR-004 | **Fixed** | `laravel/tests/Feature/FetchContractsCommandTest.php:1227-1254` checks the retained file, all 30 error responses/failed units, failed checkpoint and single acquisition Issue. `ImportPayload/StagedImportTest.php:140-167` additionally opens the real reader and checks body, sidecar/end facts and no rows. The old abandon path fails the file assertions. |
| LAR-005 | **Fixed** | `laravel/tests/Feature/FetchContractsCommandTest.php:1257-1276` replays a real payload without `--skip-logos`, for a new company with a remote logo and no local file. It asserts no HTTP request and no local-logo mutation; the default-mode download would be recorded by the explicit logo HTTP fake. |
| LAR-006 | **Partially fixed (recorded scope)** | `laravel/tests/Feature/FetchJaoMaxBorderFlowsCommandTest.php:197-221` and `FetchNveReservoirsCommandTest.php:208-230` exercise real fetch-only and store parsing: rejected answered bodies are saved without becoming permanent fetch failures, and current parser rejection remains visible. Those assertions expose the original defect before any substitute parser is installed. Their final recovery portions (`:224-244`, `:233-246`) simulate a future parser fix with subclasses; they are not proof that the production parsers support those invented envelopes. Existing D1/D2 tests separately exercise supported-body offline stores with the real parsers. Paged-source recovery remains deliberately narrower by the manager's decision. |
| LAR-007 | **Fixed** | Real invalid-selector/date command cases now assert the final `invalid_options` JSON, no HTTP and no payload: `FetchFmiDataCommandTest.php:396-415`, `FetchGasStorageCommandTest.php:168-185`, `FetchSykeHydrologyCommandTest.php:134-151`, `FetchJaoMaxBorderFlowsCommandTest.php:123-135`, `FetchNveReservoirsCommandTest.php:251-260`. The old prose-only exits fail JSON decoding/assertions. |
| PRC-001 | **Partially fixed — sequential regression only** | `laravel/tests/Feature/FetchContractsCommandTest.php:1278-1307` runs real A/B imports, checks the 12 c/kWh relational row, refuses replay A without checkpoint/row changes, and accepts replay B idempotently. This fails without the new guard. It cannot expose A-read → B-store → A-write; see TST-103. |
| PRC-002 | **Partially fixed — sequential regression only** | `laravel/tests/Feature/FetchSpotForecastCommandTest.php:259-324` checks the newer overlapping/trailing display values and provenance, older archive retention, cache preservation and a real Livewire page read; it also covers an unchanged newer refetch. The old unconditional display write fails these assertions. No test interleaves the recency read and write; see TST-103. |
| PLT-001 | **Fixed** | `orchestration/tests/test_s_forecast.py:66-71,183-207,296-307` raises production-shaped retryable `CommandFailed`, requires one failed fetch/store attempt, and separately proves lost-store-only repetition. These run real workflows on the time-skipping server with process activities faked. The old policy retries and violates the recorded sequence. |
| PLT-002 | **Fixed** | `orchestration/tests/test_s_forecast.py:183-198,223-229,296-307` and `test_jobs.py:547-558` assert the existing single Issue's closed error, fetch/store step and replay reference. Old reporting loses these facts. Refit never trains/activates after input-store failure. |
| PLT-003 | **Fixed under the recorded replacement design** | Same safety coverage as TST-005. Actual workflow-scheduled activity options, not just helper output, carry the bound; expired references execute no store. Both old-history and new-history replay tests passed. No registry is required by the manager's selected implementation. |

Paths abbreviated in a cell retain the preceding directory.

## New findings — pass 1: over-specification

### TST-101 — Strengthened EEX rollback tests unnecessarily pin progress wording
- **Severity**: Low
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: none
- **Evidence**: `laravel/tests/Feature/FetchEexFuturesCommandTest.php:725`; `laravel/tests/Feature/FetchEexEuropeFuturesCommandTest.php:159`.
- **What is wrong**: The new tests require the exact `FI quarter FNBQ 202607: fetched 2, upserted 2` and `DEBM 202703: 1 settlements.` prose. Those strings are not requirements. The later-unit trigger, seeded overwritten row and final database comparison already establish the rollback scenario.
- **User-impact scenario**: No visitor defect; a harmless CLI wording change breaks otherwise correct atomic-import acceptance tests.
- **Fix**: Remove those two progress-string assertions only. Retain the later-unit failure, unchanged pre-run rows, safe structured outcome and offline replay assertions. Blast radius: these two test methods only.

## New findings — pass 2: coverage

### TST-102 — EEX's newer-owner failure regression replaces our cache service
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S11; LAR-003; real-chain test rule (fake external boundaries, not our services).
- **Evidence**: `laravel/tests/Feature/FetchEexFuturesCommandTest.php:813-840`, especially `:817-824`; actual cache-demand path: `laravel/app/Services/Caching/ContractPriceCacheLifecycle.php:64-74`.
- **What is wrong**: `test_a_failed_replay_never_replaces_a_newer_run_that_started_before_its_failure_handling` mocks `ContractListCacheService::bumpVersion()`, pins its call count, installs run B and throws inside the mock. Thus the one regression for the after-commit interleaving never executes the real cache service/lifecycle. `test_a_replay_whose_store_fails_takes_the_checkpoint_over_as_failed` exercises a different, pre-commit database failure; the success/demand assertions in the normal EEX tests do not supply the missing after-commit failure chain.
- **User-impact scenario**: An actual cache-demand failure or a change in its placement can interact with a newer EEX owner differently from the mock. The operator can receive stale failed readiness while the mock-based ownership scenario remains green. This test is useful as an isolated handler test, but it does not meet the requested acceptance standard.
- **Fix**: Add/replace with `test_a_real_post_commit_cache_failure_preserves_the_newer_eex_owner`: run the real Artisan command, service, lifecycle and in-memory test database; fake EEX/time only. Use a controlled cache-write event/failure boundary (for example a listener on the real demand-key write) to install the newer checkpoint and throw after the real cache path is reached. Assert the closed after-commit result, committed price rows and B's unchanged checkpoint. Remove the own-service mock/call expectation from the acceptance test. Blast radius: the one EEX regression and test-only failure injection; no production abstraction needed.

### TST-103 — Superseded-evidence tests cannot catch the remaining read/write interleavings
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S5/S8; original PRC-001/PRC-002 require preservation of newer evidence, not just sequential replay. The manager's recorded overlap trade-offs do not prove the public figures stay correct.
- **Evidence**: `laravel/tests/Feature/FetchContractsCommandTest.php:1278-1307`; `laravel/tests/Feature/FetchSpotForecastCommandTest.php:259-324`; recency-read/write boundaries at `laravel/app/Console/Commands/FetchContracts.php:196-207,231-237,342-348` and `FetchSpotForecast.php:97-119,149-159`. The pricing verification report independently identifies these remaining portions of PRC-001/002.
- **What is wrong**: Every added scenario completes B before starting replay A. A guard that reads once outside the authoritative mutation therefore passes all these tests even if A can pass its read first, B stores next, and A overwrites B afterward. The unchanged-feed forecast test is also sequential. No test in these files pauses at either recency boundary. This is a coverage finding for the existing pricing residuals, not a duplicate code-correctness finding or a demand for a particular locking design.
- **User-impact scenario**: Overlapping contract stores can restore 8 c/kWh after 12 c/kWh was accepted; overlapping forecast stores can publish `[8, 12]` instead of the newer `[11, 12]`. All current new tests still pass. The forecast overlap is narrow/rare; the contract race is rated Medium likelihood in the pricing verification.
- **Fix**: Add two controlled interleaving Feature tests, e.g. `test_a_contract_store_rechecks_newer_evidence_at_its_write_boundary` and `test_a_forecast_store_never_promotes_an_older_feed_after_a_newer_store`. Invoke independently resolved real Artisan stores/services against the test database, fake only upstream HTTP/time, and use a deterministic database/event barrier to pause A after its recency read, run B's store, then resume A. Assert B's contract source/price/membership and checkpoint remain; assert forecast display `[11,12]` with matching provenance, while both archives survive. Include a scoped contract B and inspect the actual page/derived outputs where prepared. The tests should expose the current residual rather than encode acceptance of stale publication. SQLite sequencing tests need not claim to prove InnoDB blocking; document that limit. Blast radius: these two command-test files and whichever bounded serialization/projection correction the pricing/Laravel reviewers accept.

## Tests run

1. **149 passed, 1,271 assertions**:
```bash
cd laravel && php artisan test --filter='(ImportPayloadCleanupTest|StagedImportTest|FetchWeatherStagedCommandTest|FetchSpotStagedImportTest|FetchContractsCommandTest|FetchEexFuturesCommandTest|FetchEexEuropeFuturesCommandTest|FetchSpotForecastCommandTest|FetchJaoMaxBorderFlowsCommandTest|FetchNveReservoirsCommandTest|Fetch(FmiData|GasStorage|SykeHydrology)CommandTest::test_rejects)'
```
2. **83 passed** (cached time-skipping Temporal server; offline dependency resolution, Sentry disabled):
```bash
cd orchestration && UV_OFFLINE=1 SENTRY_DSN= SENTRY_LARAVEL_DSN= uv run pytest -q tests/test_jobs.py tests/test_s_forecast.py tests/test_console_groups.py
```
3. `git diff --check cdc93650..743f1c1` — passed. `laravel/phpunit.xml` and `tests/Unit/FormInputBlurPolicyTest.php` are unchanged in this fix range. Forced in-memory SQLite, array cache, pricing flags/method and Sentry isolation remain intact. No full suite or production commands run.

## Files inspected

- Task: `tasks/staged-api-imports/{spec.md,design.md,decisions.md,tasks.json}`; original test/Laravel/pricing/platform reports and their available verification reports; supplied verification prompt; `.agents/skills/spec-review/SKILL.md`.
- Context: root instructions (also supplied in full), `tasks/AGENTS.md`, `laravel/AGENTS.md` (including Test isolation), `orchestration/AGENTS.md`, `laravel/app/Services/AGENTS.md`, and nearest ImportPayload, ContractImport, ElectricityFutures, SpotPriceImport, SpotForecasts, OpenMeteo, JAO, NVE, FMI, Syke, GIE and Caching guidance. `/AGENTS.md` was checked and does not exist.
- Tests: all changed test diffs in the fix range; relevant setup/helpers and methods in every PHP/Python file named above; `laravel/tests/TestCase.php`, `laravel/phpunit.xml`, `orchestration/tests/conftest.py`. Recorded histories were exercised by Replayer tests, not manually audited event by event.
- Source: relevant fixed paths in `laravel/app/Console/Commands/{FetchContracts,FetchEexFutures,FetchSpot,FetchSpotForecast,FetchJaoMaxBorderFlows,FetchNveReservoirs}.php`, `Console/Concerns/StagedImport.php`; payload cleanup/failure types; Spot averages; JAO/NVE parsers; cache-demand lifecycle; `orchestration/src/voltikka_orchestration/{staged,jobs,s_forecast}.py`. Sibling collector validation fixes checked in the range diff.
