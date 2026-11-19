# test-reviewer — staged-api-imports
Range: 3ed8b55f31b3f945c1c9211c5a63b7439942d921..cdc93650e77e1db73f7f9067fee987a73de59c62 (+ local changes: none) · Result: 5 findings

First pass: one normal finding for over-specification. Second pass: four blocking coverage findings. All tests run passed; passing does not resolve the coverage gaps below. S17/D5 and D7 are outside this review.

## Spec checklist
| # | Spec item | Test(s) | Verdict | Notes |
|---|---|---|---|---|
| S1 | Raw bodies, request/end envelope, binary bodies, streaming reader | `ImportPayloadWriterReaderTest::test_round_trip_writes_header_requests_and_end_atomically_and_streams_them_back`; command equivalence tests below; ENTSO-E ZIP and FMI XML feature tests | ✅ Pass | Raw text/binary round trips and real parsers exercised. No production-size memory measurement was run in this review. |
| S2 | Remove credentials | Per-command secret tests below; `ImportPayloadWriterReaderTest::test_abandon_removes_the_temporary_files_and_redaction_drops_user_info` | ✅ Pass | ENTSO-E, Fingrid and GIE covered. |
| S3 | Payload path, temporary files, SHA sidecar | `ImportPayloadWriterReaderTest::test_round_trip_writes_header_requests_and_end_atomically_and_streams_them_back`; command fetch-only tests | ✅ Pass | Date boundary, final file pair, digest and absence of leftover temporary files checked. |
| S4 | Reject missing, changed and incomplete files | `ImportPayloadWriterReaderTest::test_reader_refuses_a_missing_changed_or_unfinished_file`; `test_reader_refuses_a_file_without_end_line_or_with_lines_after_it`; `StagedImportTest::test_from_payload_refuses_wrong_sha_options_source_and_missing_files` | ✅ Pass | Includes options/source refusals through Artisan. |
| S5 | Fetch-time data meaning; real-time bookkeeping | Command equivalence tests below; `FetchContractsCommandTest::test_store_failure_writes_no_row_reports_safe_sql_facts_and_a_replay_takes_over_the_checkpoint` | ✅ Pass | Delayed store preserves vintages; contract replay gets a fresh completion deadline. NVE exercises a year change. |
| S6 | Cleanup after 14 days; abandoned temporary files; protect open references | `ImportPayloadCleanupTest::test_dry_run_reports_and_apply_deletes_expired_payloads_old_tmp_files_and_empty_directories` | ⚠️ Partial | Ordinary age cleanup/dry run covered; no open-run protection case (TST-005). |
| S7 | Three command modes, common parsing/writing | Per-command matrix below | ⚠️ Partial | Spot internal mock; EC46/SEAS5 absent (TST-002, TST-004). |
| S8 | Preserve rows, reports, checkpoints; safe SQL diagnostics | Per-command failure tests below; `DataFetchFailureReporterTest::test_store_query_exception_names_step_sqlstate_driver_code_and_table_without_bindings` | ⚠️ Partial | Good SQL/privacy coverage; Spot averages are excluded from the real-chain proof (TST-004). |
| S9 | Wrong-date refusal; other commands state same-day behavior | Per-command date tests below; `test_a_date_bound_replay_of_another_date_is_refused_before_php` | ✅ Pass | Three date-bound commands refuse; other command classes explicitly state/test older same-day acceptance. |
| S10 | Partial acquisition retains failed units and prior rules | `FetchContractsCommandTest::test_partial_fetch_keeps_its_failed_postcode_through_the_payload`; `FetchEexFuturesCommandTest::test_a_payload_whose_month_discovery_failed_stores_the_same_not_ready_outcome`; partial tests in neighbour, weather, Fingrid, ENTSO-E, FMI, Syke, GIE, JAO and NVE classes | ✅ Pass | Includes successful-unit persistence, contract active-row preservation and no duplicate contract partial-fetch Issue. |
| S11 | Running fetch; final store checkpoint; payload on failed store | `FetchContractsCommandTest::test_full_import_is_running_during_acquisition_and_failed_after_unexpected_exception`; `test_failed_fetch_writes_the_failed_checkpoint_and_no_payload`; `FetchEexFuturesCommandTest::test_full_scheduled_scope_records_ready_checkpoint`; `test_unexpected_exception_marks_the_owned_full_run_failed`; both command store-failure tests | ✅ Pass | Fetch-only remains running; store/replay final outcomes and new ownership covered. |
| S12 | Two activities and reference-only passing | `test_a_staged_step_runs_fetch_then_store_and_passes_only_the_reference`; `test_exactly_the_external_api_imports_are_staged`; `test_runs_model_only_after_every_input_is_ready` | ✅ Pass | Includes data options, path/SHA passing and S-model fetches. Existing shared-volume architecture unchanged. |
| S13 | Failed commands not retried; lost attempts retried | `test_a_failed_fetch_skips_the_store_with_one_issue`; `test_a_failed_store_never_starts_a_new_fetch`; `test_a_lost_store_attempt_repeats_the_store_not_the_fetch` | ✅ Pass | Time-skipping Temporal server, real workflows, fake process activities. |
| S14 | Interruptible fetch and protected store/drain | `test_a_staged_step_runs_fetch_then_store_and_passes_only_the_reference`; `test_claim_based_steps_finish_during_a_deploy_drain`; `test_a_deploy_kills_an_interruptible_command_and_the_new_worker_repeats_it`; `test_a_deploy_lets_a_claim_based_command_finish` | ✅ Pass | Store flag/timeouts and process deploy behavior covered. |
| S15 | Contract fetch → store → completion; morning semantics | `test_the_contract_import_runs_fetch_store_then_the_completion_loop`; morning consumer/noon tests in `test_jobs.py` | ✅ Pass | Existing producer/consumer cases continue passing. |
| S16 | Manual store-only replay, date refusal, contract completion | `test_a_replay_runs_only_the_store`; `test_a_contract_replay_runs_the_completion_loop`; `test_a_failed_replay_store_fails_the_workflow_without_a_fetch`; parametrized date-refusal tests | ✅ Pass | All three date-bound command names tested. |
| S17 | Busy completion lock deferral | — | ➖ N/A | Shipped separately in PR #30; excluded by caller. |
| S18 | Document staged imports and replay | Root/Laravel/orchestration and nearest service `AGENTS.md` files | ✅ Pass | Documentation inspection; no runtime test required. |
| S19 | Read-only fetch planning from DB | `FetchWeatherStagedCommandTest::test_ecmwf_00z_fetch_plans_only_points_missing_from_the_database`; `FetchEntsoeTransparencyDataCommandTest::test_a_stored_previous_revision_means_no_history_request`; its equivalence test | ✅ Pass | Plan recorded, missing revisions fetched, existing DB rows unchanged during fetch. |
| S20 | Closed last JSON line on outcomes | Per-command success/failure tests; `StagedImportTest` | ⚠️ Partial | Covered command forms except EC46/SEAS5 (TST-002). |
| S21 | Atomic store; post-commit work; chunk exceptions | Per-command rollback/replay tests; `StagedImportTest::test_store_query_exception_rolls_back_the_run_reports_safe_sql_facts_and_the_payload_replays`; backfill tests in Fingrid/FMI/weather | ⚠️ Partial | EEX failures happen before the first successful SQL write, so cannot prove rollback (TST-003). |
| D1 | Default and split modes yield identical rows per command | Per-command matrix | ⚠️ Partial | TST-002, TST-004. |
| D2 | Failure rolls back, payload remains, replay uses no HTTP | Per-command matrix | ⚠️ Partial | TST-002, TST-003, TST-004. |
| D3 | Secret-removal test per keyed source | Per-command matrix | ✅ Pass | All keyed sources represented. |
| D4 | Workflow sequencing, references, failures, replay dates | `orchestration/tests/test_jobs.py`, `test_s_forecast.py` | ✅ Pass | Targeted pytest passes, including old-history replay. |
| D5 | Busy-lock deferral acceptance | — | ➖ N/A | S17 excluded. |
| D6 | Safe SQL facts in JSON and report per command | Per-command matrix | ⚠️ Partial | Existing command cases assert SQLSTATE/driver/table and absence of bindings; missing weather sources (TST-002). |
| D7 | Post-deployment production acceptance | — | ➖ N/A | Explicitly excluded; not attempted. |
| D8 | Documentation and mirrors | Changed `AGENTS.md`/`CLAUDE.md` pairs | ✅ Pass | Every changed context file has a sibling `CLAUDE.md` symlink to `AGENTS.md`. |

### Per-command acceptance map

Paths below are under `laravel/tests/Feature/`. Line numbers identify the exact test declarations. The equivalence tests are named `test_default_mode_and_fetch_only_then_from_payload_write_the_same_rows` (EEX Nordic adds `_and_proof`). Each indicated failure test exercises D2 and D6 together. “Secrets” identifies D3; “date” identifies S9.

| Command | Equivalence test | Failure/replay/SQL test | Secrets; date test | Assessment |
|---|---|---|---|---|
| `contracts:fetch` | `FetchContractsCommandTest.php:1068` | `FetchContractsCommandTest.php:1109` | No key; wrong-date `:1169` | Covered |
| `futures:fetch-eex` | `FetchEexFuturesCommandTest.php:656` | `FetchEexFuturesCommandTest.php:712` | No key; wrong-date `:762` | Rollback proof partial (TST-003) |
| `futures:fetch-eex-europe` | `FetchEexEuropeFuturesCommandTest.php:118` | `FetchEexEuropeFuturesCommandTest.php:144` | No key; wrong-date `:178` | Rollback proof partial (TST-003) |
| `spot:fetch` | `FetchSpotCommandTest.php:410` | `FetchSpotCommandTest.php:439` | Secret `:480`; same-day `:498` | Internal averages mock (TST-004) |
| `spot:fetch-neighbours` | `FetchNeighbourSpotCommandTest.php:124` | `FetchNeighbourSpotCommandTest.php:150` | Secret `:192`; same-day `:216` | Covered, including partial equivalence |
| `spot:fetch-forecast` | `FetchSpotForecastCommandTest.php:161` | `FetchSpotForecastCommandTest.php:196` | No key; same-day `:259` | Covered |
| `entsoe:fetch` | `FetchEntsoeTransparencyDataCommandTest.php:329` | `FetchEntsoeTransparencyDataCommandTest.php:371` | Secret `:421`; same-day `:453` | Covered; equivalence uses S-forecast outage arguments |
| `fingrid:fetch` | `FetchFingridDataCommandTest.php:168` | `FetchFingridDataCommandTest.php:197` | Secret `:253`; same-day `:270` | Covered |
| `fingrid:fetch-forecast-vintages` | `FetchFingridForecastVintagesCommandTest.php:157` | `FetchFingridForecastVintagesCommandTest.php:185` | Secret `:221`; same-day `:237` | Covered for configured dataset 245 |
| `fmi:fetch` | `FetchFmiDataCommandTest.php:423` | `FetchFmiDataCommandTest.php:458` | No key; same-day `:554` | Covered |
| `syke:fetch` | `FetchSykeHydrologyCommandTest.php:144` | `FetchSykeHydrologyCommandTest.php:171` | No key; same-day `:232` | Covered |
| `weather:fetch` | `FetchWeatherStagedCommandTest.php:66` | `FetchWeatherStagedCommandTest.php:105` | No key; same-day `:213`, `:243` | Default, solar, 00z and previous-day covered; EC46/SEAS5 missing (TST-002) |
| `gas-storage:fetch` | `FetchGasStorageCommandTest.php:179` | `FetchGasStorageCommandTest.php:205` | Secret `:240`; same-day `:292` | Covered |
| `jao:fetch` | `FetchJaoMaxBorderFlowsCommandTest.php:129` | `FetchJaoMaxBorderFlowsCommandTest.php:154` | No key; same-day `:215` | Covered |
| `nve:fetch-reservoirs` | `FetchNveReservoirsCommandTest.php:144` | `FetchNveReservoirsCommandTest.php:170` | No key; same-day `:241` | Covered |

## Findings

### TST-001 — JSON object ordering and cleanup prose are unnecessarily pinned
- **Severity**: Low
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: none
- **Evidence**: `laravel/tests/Feature/ImportPayload/StagedImportTest.php:54`, `:70`; `laravel/tests/Feature/ImportPayload/ImportPayloadCleanupTest.php:52-59`.
- **What is wrong**: Ordered `array_keys()` equality requires a particular JSON object member order, which neither the spec nor Temporal requires. The cleanup test additionally pins the human-readable `Dry run: ...` sentence and serialized JSON spelling/order, despite already checking the files. These assertions can fail for behavior-preserving presentation changes.
- **User-impact scenario**: No visitor behavior is wrong; maintainers receive false failures when reordering JSON fields or improving CLI progress text.
- **Fix**: Compare key sets without ordering, and decode the final cleanup JSON line before asserting its status/counts. Remove the exact prose assertion. Keep file-preservation/deletion and privacy assertions intact. Blast radius: only these test assertions; no production change.

### TST-002 — EC46 and SEAS5 have no required staged-import acceptance tests
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: Scope includes `weather:fetch` “every `--only` source”; D1, D2, D6; `design.md` §8.
- **Evidence**: `laravel/tests/Feature/FetchWeatherStagedCommandTest.php:54-61`, `:66-105`, `:297-321`; `laravel/app/Console/Commands/FetchWeather.php:43`; `laravel/config/open_meteo.php:142-149`; `laravel/tests/Feature/WebSchedulerTest.php:28-29`.
- **What is wrong**: The staged scenarios contain the default set, `solar_ghi`, `ecmwf_00z` and `ecmwf_prev_day1` only. The default set includes the ensemble, not EC46 or SEAS5. Searching all Laravel tests found those two source names only in scheduler assertions. Neither `FetchWeatherCommandTest::test_outlook_runs_are_append_only_and_deduplicated` nor the staged default scenario executes their configured source forms; the fake API has no seasonal response branch. Thus their three-mode equivalence, rollback/replay and safe SQL diagnostics are unproved.
- **User-impact scenario**: A seasonal endpoint/variable/model mismatch or a source-specific replay error ships while every daily weather test passes. Operators discover the missing weekly/monthly vintage only when that infrequent collector runs.
- **Fix**: Add `ecmwf_ec46` and `ecmwf_seas5` cases to the existing D1/D2/D6 data provider (with their own valid variables, units, model metadata and endpoint fakes). Invoke the real Artisan command and importer against `RefreshDatabase`; compare nonempty stored rows across default and split modes, fail a later insert to prove rollback, preserve the file, and replay with a fresh HTTP factory plus `Http::preventStrayRequests()`. Assert safe SQL fields and no binding values. Blast radius: weather acceptance fixtures/provider and assertions only.

### TST-003 — Both EEX rollback tests fail the first write, so they do not prove atomicity
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S21, D2; `design.md` §8.
- **Evidence**: `laravel/tests/Feature/FetchEexFuturesCommandTest.php:712-730`, `:779-799`; `laravel/tests/Feature/FetchEexEuropeFuturesCommandTest.php:144-158`; `laravel/app/Console/Commands/FetchEexFutures.php:406`, `:897-920`; `laravel/app/Console/Commands/FetchEexEuropeFutures.php:155-169`.
- **What is wrong**: Both `fail_eex_prices` triggers unconditionally abort every insert. No earlier statement of the run succeeds. The zero-row assertion therefore also passes if the store transaction is removed and the first upsert fails under autocommit. The extra replay-store failure test has the same unconditional trigger. `StagedImportTest::test_store_query_exception_rolls_back_the_run_reports_safe_sql_facts_and_the_payload_replays` proves the shared trait, not that these multi-request command stores retain their transactional boundary.
- **User-impact scenario**: A refactor accidentally bypasses the transaction; month/quarter or early European maturities persist before a later maturity fails. The operator sees a failed import but pricing/analysis readers can see a partial run, and the EEX tests remain green.
- **Fix**: In each command's D2 test, fail a later maturity/short code only after a distinct earlier upsert has succeeded (for Nordic, the year after month and quarter; for Europe, a later month). Assert no rows from the run survive, preserve any seeded pre-run rows, then drop the trigger and replay the same file with HTTP prohibited. Keep payload, diagnostics, checkpoint and cache-demand checks. Blast radius: these EEX fixtures/triggers and assertions only; the test must fail if the command store runs without its transaction.

### TST-004 — Spot's required acceptance tests replace our averages service with a mock
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S8, D1/D2 real-chain acceptance; reviewer real-chain rule; `design.md` §8.
- **Evidence**: `laravel/tests/Feature/FetchSpotCommandTest.php:39-47`, `:410-436`, `:439-477`, `:498-520`; `laravel/app/Console/Commands/FetchSpot.php:130-147`.
- **What is wrong**: Every test in this class binds a `Mockery::mock(SpotPriceAverageService::class)`. The new D1 test proves internal call count (`twice`) instead of the persisted averages, and D2/replay uses the same mock. The hourly/quarterly inserts are real, but the required after-commit chain is not. The same-day test also excludes the service whose use of today's date is its stated reason for restricting replay expectations. No other `spot:fetch` command acceptance test supplies the missing real chain.
- **User-impact scenario**: Replay reaches the averages call but calculates the wrong date or fails against the real stored rows. Tests still pass because a mock accepts the call; production can retain stale public Spot averages or report an after-commit failure after the import rows were committed.
- **Fix**: Run the staged D1/D2/S9 cases with the real averages service (prefer a separate acceptance class without the class-wide binding). Fake only ENTSO-E/time and, if necessary, the unrelated queued cache-warm boundary. Compare `spot_price_averages` outputs as well as hour/quarter rows between modes; assert fetch-only and a failed store leave averages unchanged, and a successful same-day replay produces the expected averages. Do not replace this with another internal-service expectation. Blast radius: Spot staged acceptance tests; production behavior unchanged.

### TST-005 — Cleanup tests omit the explicit open-run retention exception
- **Severity**: High
- **Likelihood**: Low
- **Class**: blocking
- **Spec rule**: S6: “It never deletes a file that a not-yet-closed run still references.” Decisions: 2026-11-19 retention ruling (14 days, no backup, same-day replay); that ruling does not waive the open-run exception.
- **Evidence**: `laravel/tests/Feature/ImportPayload/ImportPayloadCleanupTest.php:27-74`; `laravel/app/Services/ImportPayload/ImportPayloadCleanup.php:8-11`, `:42-59`; `orchestration/src/voltikka_orchestration/jobs.py:105-115`.
- **What is wrong**: The only substantive cleanup test creates unreferenced files and exercises date/mtime deletion. It never represents an open run referencing an expired payload. Cleanup currently has no reference check; its comment assumes same-day replay makes such references impossible. That assumption is not established by the workflow timeouts: these bound a started activity, not how long a store can remain queued without a polling worker. The existing dry-run/deletion test and `test_a_missing_payload_directory_is_not_an_error` cannot catch deletion of a live reference.
- **User-impact scenario**: Rare but concrete: fetch completes, its store remains queued during a prolonged worker outage, and the web-side cleanup runs after retention elapses. It deletes the only saved raw response while the workflow is still open. A later resumed non-date-bound store fails with a missing payload; the operator has lost the retained response and must fetch again.
- **Fix**: Add `ImportPayloadCleanupTest::test_cleanup_keeps_payloads_referenced_by_open_runs`, invoking the actual cleanup command with an expired referenced file and an expired closed-run sibling. Exercise the real reference-protection path (fake only the external Temporal boundary if used), assert both payload and sidecar survive while referenced, and assert normal deletion once the run closes. Coordinate the missing protection with the platform/Laravel reviewer; merely asserting age-based deletion again is insufficient. Blast radius: cleanup safety integration and its feature test, without changing the approved 14-day policy or authorizing historical replay. Removing the S6 exception instead requires user authorization.

## Tests run

All commands were local targeted tests. No live API or production access. Python used the already cached Temporal time-skipping test server; `UV_OFFLINE=1` prevented dependency network access.

1. **87 passed, 857 assertions**:
```bash
cd laravel && php artisan test --filter='(ImportPayloadWriterReaderTest|StagedImportTest|ImportPayloadCleanupTest|FetchWeatherStagedCommandTest|Fetch(Contracts|EexEuropeFutures|EexFutures|EntsoeTransparencyData|FingridData|FingridForecastVintages|FmiData|GasStorage|JaoMaxBorderFlows|NeighbourSpot|NveReservoirs|Spot|SpotForecast|SykeHydrology)CommandTest::test_(default_mode_and_fetch_only|store_failure|the_api_key|the_security_token|a_payload_from_another|store_refuses_a_payload|partial_fetch|failed_fetch|a_failed|an_older_same_day|a_replay_whose_store|retry_pass_lines))'
```
2. **76 passed**:
```bash
cd orchestration && UV_OFFLINE=1 SENTRY_DSN= SENTRY_LARAVEL_DSN= uv run pytest -q tests/test_jobs.py tests/test_s_forecast.py tests/test_console_groups.py
```
3. **195 passed, 1,432 assertions** (overlaps the first run):
```bash
cd laravel && php artisan test --filter='(FetchEexFuturesCommandTest|FetchEexEuropeFuturesCommandTest|FetchSpotCommandTest|FetchNeighbourSpotCommandTest|FetchFingridDataCommandTest|FetchFingridForecastVintagesCommandTest|FetchEntsoeTransparencyDataCommandTest|FetchFmiDataCommandTest|FetchGasStorageCommandTest|FetchJaoMaxBorderFlowsCommandTest|FetchNveReservoirsCommandTest|FetchSykeHydrologyCommandTest|FetchWeatherCommandTest|FetchWeatherHourlyCommandTest|DataFetchFailureReporterTest|WebSchedulerTest|FormInputBlurPolicyTest)'
```

Policy tests were not changed in the review range. The forced SQLite/in-memory, array-cache, pricing-method and Sentry isolation values remain in `laravel/phpunit.xml`. No source/test/config edits or commits were made.

## Files inspected

- Task: `tasks/staged-api-imports/{spec.md,design.md,decisions.md,tasks.json}` and the review prompt. No earlier review reports existed at inspection time; only this round's prompts. `.agents/skills/spec-review/SKILL.md`.
- Context: repository `AGENTS.md` (also supplied in the caller context), `tasks/AGENTS.md`, `laravel/AGENTS.md`, `orchestration/AGENTS.md`, `laravel/app/Services/AGENTS.md`, `laravel/app/Support/AGENTS.md`, and service `AGENTS.md` files under `ImportPayload`, `ContractImport`, `ElectricityFutures`, `EntsoeTransparency`, `Fingrid`, `Fmi`, `GasStorage`, `Jao`, `Nve`, `OpenMeteo`, `SpotForecasts`, `SpotPriceImport`, `Syke`. `/AGENTS.md` does not exist in this worktree environment; repository-root guidance was used. Changed context mirrors checked with `readlink`/`cmp`.
- Shared tests: `laravel/tests/Feature/ImportPayload/{StagedImportTest,ImportPayloadCleanupTest}.php`, `laravel/tests/Unit/ImportPayload/ImportPayloadWriterReaderTest.php`, `laravel/tests/TestCase.php`, `laravel/tests/Unit/DataFetchFailureReporterTest.php`, `laravel/phpunit.xml`.
- Import tests: all 15 command test files in the acceptance map (staged additions and relevant setup/helpers), plus `FetchWeatherCommandTest.php`, `FetchWeatherHourlyCommandTest.php`, `WebSchedulerTest.php`; policy-test change status and execution checked.
- Implementation: `laravel/app/Console/Concerns/StagedImport.php`, `laravel/app/Services/ImportPayload/ImportPayloadCleanup.php`, `laravel/app/Console/Commands/{FetchEexFutures,FetchEexEuropeFutures,FetchFingridForecastVintages,FetchSpot,FetchWeather}.php` (relevant sections), `laravel/config/open_meteo.php` (source/default configuration).
- Orchestration: `orchestration/src/voltikka_orchestration/jobs.py`; `orchestration/tests/{conftest.py,test_jobs.py,test_s_forecast.py}` (changed/relevant sections), and executed `test_console_groups.py`. Recorded histories were exercised by the replay tests, not manually audited event by event.
