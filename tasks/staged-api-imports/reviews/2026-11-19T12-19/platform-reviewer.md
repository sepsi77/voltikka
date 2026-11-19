# platform-reviewer — staged-api-imports
Range: `3ed8b55f31b3f945c1c9211c5a63b7439942d921..cdc93650e77e1db73f7f9067fee987a73de59c62` (+ local changes: none) · Result: 3 findings

Read-only static review. No tests, live APIs, Artisan commands or production access were run. No earlier review rounds exist in this task folder. PHP domain correctness and per-command test adequacy remain with the Laravel, pricing and test reviewers.

## Spec checklist
| # | Spec item | Verdict | Notes |
|---|---|---|---|
| S1 | Raw, streaming payloads; 128 MB | ⚠️ Partial | Shared reader/writer stream requests; FMI releases parsed pieces. Documented synthetic FMI memory measurement inspected, not independently executed; no production-size memory claim. |
| S2 | No payload secrets | ➖ N/A | PHP/source-redaction review delegated. |
| S3 | Atomic files and SHA-256 | ✅ Pass | Writer finishes payload before publishing sidecar. |
| S4 | Integrity and completeness checks | ✅ Pass | Reader verifies hash and scans complete structure before store. |
| S5 | Fetch-fixed data times | ➖ N/A | PHP meaning-time correctness delegated; Temporal passes original reference and options. |
| S6 | Cleanup protects open-run references | ❌ Fail | PLT-003: age alone authorizes deletion. |
| S7 | Three command modes | ➖ N/A | PHP command implementation delegated. |
| S8 | Existing reporting, named step | ⚠️ Partial | Generic staged jobs retain failure step/reference; S forecast and replay reporting lose them (PLT-002). |
| S9 | Date-bound refusal | ✅ Pass | ImportReplay checks date and supplies activity-boundary date; PHP checks payload effective date. |
| S10 | Partial acquisition | ➖ N/A | PHP behavior delegated. |
| S11 | Checkpoint lifecycle | ➖ N/A | PHP ownership and checkpoint transactions delegated. |
| S12 | Two activities, references, shared volume | ✅ Pass | Scope table is staged in Schedules/morning/S inputs; both Kamal roles mount `/app/storage`. Existing Schedule inputs intentionally remain one-process until approved re-apply. |
| S13 | Lost attempts only; no refetch | ❌ Fail | No refetch after store failure, but S input stores retry actual command failures (PLT-001). |
| S14 | Store completes in drain | ✅ Pass | Stores noninterruptible; generic/replay stores ≤60 min, S stores 15 min; worker drain 65 min, container stop timeout 3960 s. |
| S15 | Contract order and morning semantics | ✅ Pass | Fetch → store → existing completion loop; independent consumer lanes retained. |
| S16 | Store-only replay and completion | ✅ Pass | Registered, unscheduled; contract replay runs completion without evaluation; same-day checks present. Reporting gap is PLT-002. |
| S17 | Busy producer lock | ➖ N/A | Explicitly excluded; shipped in PR #30. |
| S18 | Documentation and recovery | ⚠️ Partial | Payload/replay/cut-over guidance present. Retry and open-run protection descriptions overstate actual guarantees (PLT-001/003). |
| S19 | Read-only request planning | ➖ N/A | PHP source planning delegated. |
| S20 | Closed result → diagnostic Temporal Issue | ⚠️ Partial | Generic staged jobs name step/reason; S input failures discard reason/step, replay discards step/reference (PLT-002). |
| S21 | Store atomicity | ➖ N/A | PHP transactions delegated. |
| D1 | Per-command mode parity tests | ➖ N/A | Test reviewer; no tests run. |
| D2 | Atomic failure and replay tests | ➖ N/A | Test reviewer; no tests run. |
| D3 | Secret tests | ➖ N/A | Test reviewer; no tests run. |
| D4 | Orchestration behavior tests | ⚠️ Partial | New tests and recorded-history replays inspected; S fakes set failures nonretryable themselves and do not expose PLT-001. |
| D5 | Busy-lock test | ➖ N/A | S17 excluded. |
| D6 | SQL diagnostics/privacy test | ➖ N/A | PHP/test reviewers. |
| D7 | Production verification | ➖ N/A | Explicitly excluded. |
| D8 | Documentation and mirrors | ⚠️ Partial | Relevant documentation inspected; mirror audit delegated. See S18. |

## Findings

### PLT-001 — S forecast input stores automatically retry a command that has already failed
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S13; D4; `orchestration/AGENTS.md` retry rule; `design.md` §7. The implementation decision to stage S inputs does not authorize a different retry rule.
- **Evidence**: `orchestration/src/voltikka_orchestration/s_forecast.py:43-47`, `:128-143`, `:251-255`, `:321`; `orchestration/src/voltikka_orchestration/activities.py:138-146`; contrast `orchestration/src/voltikka_orchestration/jobs.py:105-115`. Test fake: `orchestration/tests/test_s_forecast.py:59-60`.
- **What is wrong**: `staged_fetch()` uses `FETCH_OPTIONS` for both activities. Its three-attempt retry policy does not exclude `CommandFailed`. The real activity raises that type without `non_retryable=True`, so an exit-1 store runs again automatically. Generic jobs and ImportReplay correctly exclude this type. The S tests mask the difference by raising an explicitly nonretryable fake error.
- **User-impact scenario**: At 11:00 the outage fetch succeeds, then its store exits 1 with `store_failed` after a database rejection. Temporal starts the same failed command again at approximately 11:01 and 11:03 without a fix or approval. A partial-acquisition store that commits usable units and exits 1 is also executed repeatedly. The S run does not immediately settle to its intended missing-input outcome. The same defect affects afternoon inputs and Monday's Fingrid refit input. This is an actual command-failure path, not a lost attempt.
- **Fix**: Add the exclusion to the S input policy while retaining retries for lost attempts:
  ```python
  retry_policy=RetryPolicy(
      maximum_attempts=3,
      initial_interval=timedelta(minutes=1),
      non_retryable_error_types=["CommandFailed"],
  )
  ```
  Blast radius: S daily/refit input activities and their tests only; no model/features change. Test with the production-shaped retryable `ApplicationError(type="CommandFailed")`, asserting one fetch/store invocation on failure and store-only repetition for a lost store. Retain history replay checks.

### PLT-002 — Some new store failures lose the step and payload context before reaching their Issue
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S8, S20; `design.md` §7; `orchestration/AGENTS.md` staged-failure reporting contract.
- **Evidence**: `orchestration/src/voltikka_orchestration/s_forecast.py:128-143`, `:182-184`, `:253-256`, `:273-280`, `:327-338`; `orchestration/src/voltikka_orchestration/jobs.py:158-184`, `:463-466`; `orchestration/src/voltikka_orchestration/activities.py:138-146`.
- **What is wrong**: The generic staged path adds `step`, `payload_path` and `payload_sha256` to an Issue. The S helper instead discards the `ActivityError` in `_fetch()` and reports only `inputs_missing`/the input name; even `store_failed` is lost. Refit retains the error key but no step/reference. ImportReplay passes a `staged=False` store into `run_steps()`, so its failure reporting also omits step/reference. These collector commands log safely but do not self-report an Issue, so there is no richer companion Issue.
- **User-impact scenario**: An ENTSO-E store parser/database fault after a successful 11:00 fetch produces an Sentry Issue indistinguishable from a missing upstream input. The operator cannot tell from the Issue that acquisition succeeded and the saved file needs a store replay. A failed approved JAO replay similarly reports only `store_failed`, without identifying its store step or saved payload in structured facts. Temporal history still contains the reference; the failure is visible, but its promised diagnostic context is lost.
- **Fix**: Preserve closed failure facts across the helper and include them in the existing single Issue, rather than emitting another Issue:
  ```python
  facts = {"command": command, "step": "store", "error": error_key(error),
           "payload_path": reference["path"], "payload_sha256": reference["sha256"]}
  ```
  Blast radius: `s_forecast.py` staged-helper/caller reporting, `jobs.py` replay reporting, and corresponding tests. Reuse the generic staged reporting shape; never include bodies, raw exceptions or SQL bindings. Keep the S no-forecast outcome and one-Issue rule unchanged.

### PLT-003 — Retention can delete a payload still referenced by a queued store
- **Severity**: High
- **Likelihood**: Low
- **Class**: backlog
- **Spec rule**: S6. The 2026-11-19 retention decision authorizes 14-day deletion, but S6 explicitly protects not-yet-closed runs; same-day manual replay is not a bound on queued activity lifetime.
- **Evidence**: `laravel/app/Services/ImportPayload/ImportPayloadCleanup.php:8-11`, `:29-58`; `orchestration/src/voltikka_orchestration/jobs.py:105-115`, `:173-174`; `orchestration/src/voltikka_orchestration/schedules.py:80`. Protection tests contain only age/file cases: `laravel/tests/Feature/ImportPayload/ImportPayloadCleanupTest.php:26-80`.
- **What is wrong**: Cleanup consults only directory age and file type; it has no open-reference check. Its comment incorrectly infers that same-day replay means no open run can reference an old file. Generic store activities have a start-to-close timeout, but no schedule-to-close timeout: they can remain queued while their workflow is open for longer than retention. These are already-started runs, not prohibited past-date manual replays.
- **User-impact scenario**: Rare prolonged worker unavailability: a weather collector fetch completes on November 19, then its store waits for a worker. On December 4 or later, recovery starts processing the backlog. If the queued daily cleanup runs before that store, it unlinks the November 19 payload and sidecar. The store then fails `payload_invalid/missing`; this already-fetched run cannot be stored, and its raw vintage is permanently lost because payloads are deliberately not backed up. No 15-day outage is claimed to be common.
- **Fix**: Exclude durable references owned by open runs from retention, fail closed if their status cannot be determined, and release protection when the owner closes:
  ```php
  if ($expired && ! $openReferences->contains($file)) {
      // Delete the payload and sidecar together under the reference guard.
  }
  ```
  This is a required target shape, not an existing service. Blast radius: payload reference ownership/closure, cleanup, orchestration integration and a queued-store retention test. Keep the approved retention period and no-backup policy; no user ruling needs reversal.

## Files inspected
- Task inputs: `tasks/staged-api-imports/{spec.md,design.md,decisions.md,tasks.json}`, `reviews/` inventory; `tasks/temporal-orchestration/phase2-plan.md`.
- Guidance: root `AGENTS.md`; `orchestration/AGENTS.md`; Laravel/service guidance loaded for `Orchestration`, `MorningFreshness`, `ImportPayload`, `ContractImport`, `Fmi`, `OpenMeteo`, `Nve`, `Support`; `.agents/skills/spec-review/SKILL.md`.
- Runtime: `orchestration/src/voltikka_orchestration/{jobs.py,staged.py,s_forecast.py,activities.py,schedules.py,worker.py}`, `orchestration/pyproject.toml`; installed Temporal `workflow_patch()` implementation checked for memoization of the old branch.
- Orchestration test source/diffs: `orchestration/tests/{test_jobs.py,test_s_forecast.py,test_console_groups.py}`; five `tests/histories/*.json` additions identified in the range, and their Replayer wiring inspected. Histories were not executed.
- Payload code: `laravel/app/Services/ImportPayload/{ImportPayloadWriter.php,ImportPayloadReader.php,ImportPayloadReference.php,ImportPayloadCleanup.php}`, `laravel/app/Console/Concerns/StagedImport.php`, `laravel/app/Console/Commands/CleanupImportPayloads.php`, `laravel/config/import_payloads.php`, `laravel/tests/Feature/ImportPayload/ImportPayloadCleanupTest.php`.
- Command/memory/reporting boundary reads: `laravel/app/Console/Commands/{FetchContracts.php,FetchEexFutures.php,FetchSpot.php,FetchFingridData.php,FetchFmiData.php,FetchWeather.php,FetchNveReservoirs.php}`, `laravel/app/Services/Fmi/FmiMultiPointCoverageParser.php`, `laravel/app/Services/Nve/NveReservoirService.php`, `laravel/app/Support/DataFetchFailureReporter.php`; contract-command test diff.
- Operations: `config/deploy.yml`, `Dockerfile`, `docker-worker-entrypoint.sh`, `scripts/deploy-image.sh`, `laravel/routes/console.php` diff, `laravel/config/backup.php` relevant source configuration. Shared-volume/default-mode compatibility and separately approved Schedule re-apply documented; no production operation attempted.
