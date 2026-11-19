# laravel-reviewer — staged-api-imports
Range: 3ed8b55f31b3f945c1c9211c5a63b7439942d921..cdc93650e77e1db73f7f9067fee987a73de59c62 · Result: 7 findings

Static review only. No tests, API calls, production access or writing Artisan commands were run. PHP syntax checks passed for the changed PHP files; `git diff --check` passed. No earlier review reports existed in this task folder. `/AGENTS.md` was absent; repository root instructions were supplied in the review context.

## Spec checklist
| # | Spec item | Verdict | Notes |
|---|---|---|---|
| S1 | Raw, streamed, replayable payload bodies | ⚠️ Partial | Streaming/raw-body foundation exists; parser-rejected answered bodies become permanently skipped (LAR-006). |
| S2 | No payload/log secrets | ✅ Pass | Query redaction and omitted request headers inspected; safe SQL diagnostic extraction uses placeholder SQL, not bindings/messages. No confirmed leak found. Test execution delegated. |
| S3 | Atomic payload and SHA-256 sidecar | ✅ Pass | Temporary files, rename, sidecar last; reference includes hash/size. |
| S4 | Verify hash and closing structure before store | ✅ Pass | Reader verifies hash and scans structure before the store transaction. |
| S5 | Fetch-fixed data times; real bookkeeping times | ⚠️ Partial | Imported observations/vintages use saved times; Spot rolling-average date still comes from the store clock (LAR-001). |
| S6 | Retention and abandoned temporary-file cleanup | ⚠️ Partial | Age-based cleanup and dry-run default exist. Protection relies on the documented same-day-run assumption, not a Laravel open-reference registry; workflow lifetime assessment belongs to platform review. |
| S7 | Three modes; store makes no API call | ❌ Fail | All commands have the modes; contract replay can download logos (LAR-005). |
| S8 | Preserve outcomes and safe failure diagnostics | ⚠️ Partial | Existing partial-acquisition outcomes generally retained. A final EEX checkpoint database failure loses its SQL facts from the JSON outcome (LAR-002). |
| S9 | Date-bound refusal | ✅ Pass | Contracts and both EEX commands check the saved effective date before import writes. |
| S10 | Preserve partial acquisitions, including failed requests | ⚠️ Partial | Partial runs are retained; an all-failed contract acquisition discards its recorded responses (LAR-004). |
| S11 | Morning checkpoint ownership and failed-store reference | ❌ Fail | Most paths retain ownership/reference, but EEX error handling can take over a newer run (LAR-003); final-checkpoint exceptions can leave running state (LAR-002). |
| S12 | Two Temporal activities and shared files | ➖ N/A | Platform reviewer owns workflow/volume verification; Laravel interface inspected. |
| S13 | Failed store never refetches | ⚠️ Partial | No automatic fetch restart in the Laravel trait; the no-network replay guarantee has the logo exception (LAR-005). Temporal retry policy delegated. |
| S14 | Interruptible fetch, drained store | ➖ N/A | Platform reviewer. |
| S15 | ContractImport and MorningChain sequencing | ➖ N/A | Platform reviewer; PHP completion behavior is preserved outside the authoritative import transaction. |
| S16 | Manual replay and contract completion | ⚠️ Partial | Laravel same-date refusal exists; contract store-only operation is not network-free without `--skip-logos` (LAR-005). Workflow sequencing delegated. |
| S17 | Busy producer-lock deferral | ➖ N/A | Explicitly excluded; separately shipped in PR #30. |
| S18 | Context/recovery documentation | ✅ Pass | Root, Laravel, orchestration and service documentation updated. |
| S19 | Read-only fetch planning | ✅ Pass | Missing weather points and outage revisions are read for planning; no import-row writes found in fetch paths. Morning start checkpoints remain intentional. |
| S20 | Closed final JSON on every outcome | ❌ Fail | Five collectors still have plain early failure returns (LAR-007). |
| S21 | Atomic database store; external effects after commit | ❌ Fail | Spot averages escape the transaction (LAR-001); EEX final-checkpoint errors can be swallowed before the outer commit (LAR-002). |
| D1 | Per-command default/staged equivalence tests | ➖ N/A | Test-reviewer owns coverage/quality; no tests run. All 15 implementations inspected below. |
| D2 | Failed store writes nothing and replays offline | ❌ Fail | Implementation counterexamples in LAR-001, LAR-002 and LAR-005; test assessment delegated. |
| D3 | Key-removal tests | ➖ N/A | Test-reviewer; implementation boundary inspected under S2. |
| D4 | Orchestration contract tests | ➖ N/A | Platform/test reviewers. |
| D5 | Lock deferral test | ➖ N/A | S17 excluded. |
| D6 | Safe SQL facts in JSON and log | ⚠️ Partial | Shared exception path is safe; swallowed EEX checkpoint exceptions bypass JSON SQL facts (LAR-002). |
| D7 | Production first-run/replay verification | ➖ N/A | Explicitly excluded; no production access. |
| D8 | Documentation and mirrors | ✅ Pass | Relevant `CLAUDE.md` files are symlinks to canonical `AGENTS.md`, including the new payload subtree. |

### Converted-command audit
| Command | Store transaction / time / network assessment |
|---|---|
| `contracts:fetch` | Importer owns the authoritative transaction; import date and observations use payload facts. Separate post-import stages retained. Logo network exception: LAR-005. |
| `futures:fetch-eex` | Trait transaction; saved effective date and request plan; cache bump after commit. Checkpoint exception/ownership defects: LAR-002/003. |
| `futures:fetch-eex-europe` | Trait transaction, saved instruments/window, same-day check; no store HTTP found. |
| `spot:fetch` | Raw rows transactional, but database averages run after commit and use today's clock: LAR-001. |
| `spot:fetch-neighbours` | One transaction across answered regions; saved window; no store HTTP found. |
| `spot:fetch-forecast` | Display rows and append-only vintage share one transaction; saved fetch time; cache forget after commit. |
| `entsoe:fetch` | Daily transaction / per-query backfill transactions; saved fetch time; outage history planned read-only. Parser-failure skip sibling: LAR-006. |
| `fingrid:fetch` | Daily transaction / per-window backfill transactions; saved fetch time/window. Parser-failure skip sibling: LAR-006. |
| `fingrid:fetch-forecast-vintages` | One transaction; saved first hour/horizon/fetch time; no store HTTP found. Parser-failure skip sibling: LAR-006. |
| `fmi:fetch` | Daily transaction / per-piece backfill transactions; saved window and vintage times; no store HTTP found. Early outcome defect: LAR-007. |
| `syke:fetch` | One transaction, saved series plan/fetch time; no store HTTP found. LAR-006/007. |
| `weather:fetch` | Daily transaction / per-point-year backfill transactions; saved per-request vintage times and exact-run plan; no store HTTP found. |
| `gas-storage:fetch` | One transaction; saved range/fetch time; no store HTTP found. LAR-006/007. |
| `jao:fetch` | Daily transaction / per-window backfill transactions; saved fetch time; no store HTTP found. LAR-006/007. |
| `nve:fetch-reservoirs` | One transaction; saved ISO year/range/fetch time; no store HTTP found. LAR-006/007. |

## Findings

### LAR-001 — Spot database averages are outside the atomic store and still read the clock
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S5, S21, D2; `decisions.md:47` (user-requested atomic store).
- **Evidence**: `laravel/app/Console/Commands/FetchSpot.php:131-147`; `laravel/app/Console/Concerns/StagedImport.php:86-99`; `laravel/app/Services/SpotPriceAverageService.php:31-38,82-102,227-235`.
- **What is wrong**: `calculateAllAverages()` is an after-commit closure, although it writes `spot_price_averages` through many `updateOrCreate()` calls. An average write failure therefore cannot roll back the already committed hourly/quarterly rows or earlier average writes. The trait calls this `after_commit_failed`, but it is still a failed database store with partial output. Rolling averages also choose `Carbon::today()` at store time instead of a fetch-fixed date.
- **User-impact scenario**: A daily/monthly/rolling average write fails after official prices were inserted. The command exits 1 while price displays and average-based consumers see different generations of data. A store crossing midnight can additionally write the rolling rows under the next day's identity despite using the previous fetch's window.
- **Fix**: Keep all database-producing average work inside the same transaction and pass the payload's fixed Helsinki date. Leave only cache warming/dispatch after commit. Target shape:
  ```php
  $importer->import($prices);
  $averages->calculateAllAverages(asOf: $savedHelsinkiDate);
  return new ImportStoreResult($counts, [$warmCaches]);
  ```
  Blast radius: `FetchSpot`, the average service's rolling-date API, current average consumers, and `FetchSpotCommandTest` (its average service is currently mocked, including the staged rollback scenario at lines 439-477). Preserve default date behavior for other average-service callers.

### LAR-002 — EEX final-checkpoint database exceptions are swallowed, allowing a failed store to commit
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S8, S11, S20, S21, D2, D6; `decisions.md:47`.
- **Evidence**: `laravel/app/Console/Commands/FetchEexFutures.php:404-405,486-504,598-627`; `laravel/app/Console/Concerns/StagedImport.php:85-92,102-114`; `laravel/app/Services/MorningFreshness/MorningJobFreshnessService.php:44-66`.
- **What is wrong**: The final checkpoint is attempted after the futures upserts, inside the trait's transaction. `finishCheckpoint()` catches every exception from its nested transaction and returns `false`. The store then returns an ordinary `ImportStoreResult(error: 'checkpoint_failed')`, so the outer transaction commits instead of rolling back. A statement-level database failure that does not abort the entire database transaction can leave all price rows committed and the original checkpoint `running`. The aggregate reporter retains the exception's SQL facts, but the final JSON result does not: no exception reaches `stagedFailure()`.
- **User-impact scenario**: The futures inserts work but saving the final freshness metadata fails, for example because of a checkpoint schema/constraint fault. Temporal sees a failed store, the morning consumers remain closed, and an operator cannot rely on the promised zero-row failure or on SQL facts in the activity's result.
- **Fix**: Propagate checkpoint storage exceptions through the outer store transaction; after rollback, record the failed outcome using the existing owner's compare-and-set and payload reference. Treat an ownership refusal separately from a database exception. Target shape:
  ```php
  // Inside the store transaction: do not convert storage exceptions to false.
  $this->writeOwnedFinalCheckpointOrThrow($metadata);
  ```
  Blast radius: full-scope EEX default/store-only paths, start/final checkpoint helpers, safe error JSON, and `FetchEexFuturesCommandTest`. The existing rollback tests fail the price insert, not the later checkpoint write. Continental EEX has no checkpoint and does not share this defect.

### LAR-003 — A failed EEX replay takes ownership a second time and can overwrite a newer run
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S11; design section 7a (`design.md:134`); `decisions.md:93` (new UUID must prevent superseded stores from overwriting newer runs).
- **Evidence**: `laravel/app/Console/Commands/FetchEexFutures.php:169-173,310-315,563-586`; `laravel/app/Console/Concerns/StagedImport.php:90-99`.
- **What is wrong**: For a failed `--from-payload` invocation, `reportStagedFailure()` calls `startCheckpoint()` again before recording failure. That creates a fresh UUID and unconditionally replaces the current date's owner. It bypasses the protection in `finishCheckpoint()`: an old replay can first steal a newer run and then successfully mark that stolen owner failed. This applies to database-store failures and to after-commit cache failures. It is additional to the explicitly recorded initial replay takeover, not a request to reverse that decision.
- **User-impact scenario**: Replay A commits, then its cache bump fails or waits. Run B starts or finishes before A's failure handler executes. A's handler replaces B's running/ready checkpoint with its own failed outcome, closing both morning consumers even if B successfully acquired and stored the required data.
- **Fix**: Establish the replay's owner durably once, after payload/date validation and before the data transaction, then keep that UUID through success and failure. Failure reporting must only finish that owner, never claim a new one:
  ```php
  $owner = $this->startReplayOnce($date); // outside the data transaction
  try { DB::transaction($writeRowsAndFinalFact); }
  catch (Throwable $e) { $this->failOnlyIfStillOwner($owner, $payload, $e); throw $e; }
  ```
  Blast radius: EEX replay transaction placement and failure helper; the normal initial fetch ownership and contract importer already use durable start markers. Inspect `FetchEexFuturesCommandTest`'s replay-failure and old-owner cases together; the replay-failure assertion currently requires another owner without interleaving a newer run.

### LAR-004 — An all-failed contract fetch deletes every recorded error response
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S1, S10; binding design section 7a (`design.md:129`). Related decision: `decisions.md:96` preserves the all-failed exit failure; it does not require deleting the evidence.
- **Evidence**: `laravel/app/Console/Commands/FetchContracts.php:156-170`; `laravel/app/Console/Concerns/StagedImport.php:59-63`; `laravel/app/Services/ImportPayload/ImportPayloadWriter.php:216-228`; `laravel/tests/Feature/FetchContractsCommandTest.php:1227-1242`.
- **What is wrong**: Each failed postcode is recorded, but the last failure throws when every postcode failed. `runStaged()` then calls `abandon()`, deleting the temporary recording rather than completing the payload. The binding addition explicitly says even an all-failed multi-request fetch must keep the file/error bodies. The test currently asserts the contrary behavior.
- **User-impact scenario**: Azure returns a meaningful error body for every postcode during a full morning failure. The checkpoint and aggregate Issue remain, but none of the 30 responses is available for diagnosis; an operator has to query the upstream again to see its error.
- **Fix**: Finalize the all-failed acquisition before emitting its terminal fetch failure, preserving the existing exit-1/checkpoint/one-Issue behavior. For example, let the fetch return an `all_failed` outcome and have the staged boundary finish its writer before reporting that closed error. Blast radius: contract acquisition, the shared fetch-result boundary if needed, and the all-failed command test; do not change scoped/partial acquisition acceptance or introduce an automatic retry. This does not reverse an explicit user ruling.

### LAR-005 — Contract store-only mode still downloads missing company logos
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S7, D2; `design.md:67-68` explicitly specifies no HTTP in `--from-payload`.
- **Evidence**: `laravel/app/Console/Commands/FetchContracts.php:261-263,410-426`; `laravel/app/Services/CompanyLogoService.php:21-29`.
- **What is wrong**: The store closure calls `syncCompanyLogos()` unless the caller independently supplies `--skip-logos`. It then calls `downloadAndStore()`, which performs HTTP. Removing `skip-logos` from the data-option equality check makes that replay legal; it does not make it network-free. The scheduled workflow includes the flag, but the documented manual `--from-payload` interface must satisfy the guarantee without it.
- **User-impact scenario**: An operator replays a payload offline after fixing a database error. A new company or a previously failed logo download causes additional external requests and waits, although the operator asked for store-only behavior. The existing logo service catches download exceptions, so the command can even report success after silently attempting those requests.
- **Fix**: Keep optional default-mode logo work outside the replayable store path; store-only mode must not invoke it. Reuse the existing independent logo-download mechanism rather than fetching logos during replay. Target shape:
  ```php
  storePayloadWithoutNetwork($payload);
  // Optional default-mode work only; never for --from-payload.
  ```
  Blast radius: default/manual contract command orchestration and logo-related command tests. Preserve normal default-mode compatibility if required. Staged/replay tests currently always pass `--skip-logos`, so their offline result is narrower than the CLI contract.

### LAR-006 — Fetch-time parser failures permanently blacklist saved answered bodies from replay
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S1, S7; `decisions.md:29-31` explicitly requires parser faults to be replayable.
- **Evidence**: `laravel/app/Console/Commands/FetchJaoMaxBorderFlows.php:113-118,128-135`; `laravel/app/Console/Commands/FetchNveReservoirs.php:89-94,102-109`. Siblings: `FetchGasStorage.php:144-148,161-169`, `FetchSykeHydrology.php:135-142,156-164`, `FetchEntsoeTransparencyData.php:248-261,295-325`, `laravel/app/Services/Fingrid/FingridOpenDataService.php:118-123`, `FetchFingridData.php:139-170`, `FetchFingridForecastVintages.php:100-114` (all command paths under `laravel/app/Console/Commands/`).
- **What is wrong**: JAO and NVE fully parse/validate successful responses during fetch. Any parser exception is stored as an immutable `failed_units` entry. Their store step skips that entry before invoking the now-fixed parser. Thus saving the raw body is not enough: after a parser fix, replay still skips it forever. The paged GIE/Syke/Fingrid/ENTSO-E paths use the same permanent classification, although limited fetch-time parsing is legitimately needed there for request planning.
- **User-impact scenario**: A valid upstream response changes shape and requires a parser fix. Fetch saves the answer as a failed unit. After the fix, `--from-payload` returns `fetch_incomplete` again without attempting to parse that answer; JAO/NVE data cannot be recovered from the saved file and must be fetched again.
- **Fix**: For non-paged JAO/NVE, record successful raw responses during fetch and perform their parsing in store. Distinguish genuine transport/incomplete-pagination failures from replayable parser failures in paged sources; re-evaluate answered bodies with current parsing code, without pretending unfetched pages are complete. Target shape for the non-paged case:
  ```php
  // fetch
  $payload->record($unit, $response);
  // store: successful saved HTTP answer is parsed here, including on replay
  $rows = $parser->parse(ImportPayloadReader::body($request));
  ```
  Blast radius: the listed acquisition/skip paths and their command tests. Preserve existing HTTP/partial-acquisition exits. Test the recovery scenario with an original parser failure, not only a database insert failure.

### LAR-007 — Five collectors still omit the mandatory JSON result on validation failure
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S20; design sections 3 and 7a (`invalid_options` is an approved closed key).
- **Evidence**: `laravel/app/Console/Commands/FetchFmiData.php:63-74,89-92`; `FetchGasStorage.php:68-91`; `FetchSykeHydrology.php:52-80`; `FetchJaoMaxBorderFlows.php:58-70`; `FetchNveReservoirs.php:49-52` (same command directory).
- **What is wrong**: These early branches print human text and return `Command::FAILURE` directly, bypassing `stagedFailure()` and its final JSON line. The trait cannot fix it because `runStaged()` has not yet been called. Other converted collectors already route equivalent failures through `stagedFailure('fetch', 'invalid_options')`.
- **User-impact scenario**: A manual replay supplies an invalid date/selector, or a stale Schedule's selector becomes invalid after a configuration change. Temporal again receives no JSON error and reports `no_json_error`, the exact diagnostic gap S20 was intended to remove.
- **Fix**: Route each listed branch through the common closed-result boundary:
  ```php
  return $this->stagedFailure('fetch', 'invalid_options');
  ```
  Use the actual step when validating a store-only request. Blast radius: all five commands, their invalid-option tests, and Temporal result parsing. Preserve exit 1 and perform no fetch/store writes on these paths.

## Files inspected

- Task/context: repository root instructions; `laravel/AGENTS.md`; `laravel/app/Services/AGENTS.md`; scoped `AGENTS.md` files for ImportPayload, ContractImport, ElectricityFutures, EntsoeTransparency, Fingrid, Fmi, GasStorage, Jao, Nve, OpenMeteo, SpotForecasts, SpotPriceImport, Syke, MorningFreshness and Support; `orchestration/AGENTS.md`; task `spec.md`, `design.md`, `decisions.md`, `tasks.json`; review-directory inventory.
- All 15 converted command files under `laravel/app/Console/Commands/`: `FetchContracts.php`, `FetchEexFutures.php`, `FetchEexEuropeFutures.php`, `FetchSpot.php`, `FetchNeighbourSpot.php`, `FetchSpotForecast.php`, `FetchEntsoeTransparencyData.php`, `FetchFingridData.php`, `FetchFingridForecastVintages.php`, `FetchFmiData.php`, `FetchSykeHydrology.php`, `FetchWeather.php`, `FetchGasStorage.php`, `FetchJaoMaxBorderFlows.php`, `FetchNveReservoirs.php`; also `CleanupImportPayloads.php`.
- `laravel/app/Console/Concerns/StagedImport.php`; all new `laravel/app/Services/ImportPayload/*.php`; `laravel/config/import_payloads.php`.
- Changed service implementations/diffs: `AzureConsumerApiClient.php`, `ContractImport/ContractImporter.php`, `ElectricityFutures/EexFuturesService.php`, `EntsoeService.php`, `EntsoeTransparency/EntsoeTransparencyClient.php`, `EntsoeTransparency/OutageImporter.php`, `Fingrid/FingridOpenDataService.php`, `Fingrid/FingridForecastVintageImporter.php`, `Fmi/FmiOpenDataService.php`, `GasStorage/GieAgsiService.php`, `Syke/SykeHydrologyService.php`, `OpenMeteo/OpenMeteoService.php`, `OpenMeteo/OpenMeteoWeatherImporter.php`, `SpotForecasts/NordpoolPredictFiService.php`.
- Supporting callees: `laravel/app/Services/SpotPriceAverageService.php` (average transaction/date paths), `CompanyLogoService.php` (download path), `Fmi/FmiObservationImporter.php`, `Fmi/FmiForecastImporter.php`, `MorningFreshness/MorningJobFreshnessService.php` (checkpoint writer); `laravel/app/Support/DataFetchFailureReporter.php`.
- Targeted static test inspection/searches: command tests for Contracts, EEX, Spot, FMI, Weather, JAO and NVE; shared staged-import test search. No test execution or independent test-coverage verdict.
- Interface-only searches: `orchestration/src/voltikka_orchestration/jobs.py`, `staged.py`; cleanup schedule/mirror references. Workflow/platform review otherwise delegated.
