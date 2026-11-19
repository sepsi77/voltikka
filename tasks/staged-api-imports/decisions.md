# Decisions

## 2026-11-19 – Task created

User decisions (2026-11-19):

- Every import from an external API saves the response as JSON first and writes the database from that file.
- The two steps are separate Temporal activities.
- Past failed imports are not recovered, and a payload is not replayed for a past date.

## 2026-11-19 – Incident record: the morning imports of 11-17 and 11-19 (Helsinki times)

**2026-11-17.**
- The 06:00 `contracts:fetch` import succeeded, but its completion step failed at 06:10:23 with `price_cache_refresh` (`LockTimeoutException`).
- Price-cache maintenance (`contracts:warm-cache --pending`) held the shared producer lock from 06:10:00 to 06:10:47. The completion step waits 10 s for that lock, and a lock timeout is a terminal failure.
- Retail premiums and the forecast waited until noon and did not run.
- The 11-18 retail run collected the 7 price periods first seen on 11-17. The 11-17 forecast is missing. A dry run of `forecasting:run-fixed-contracts --as-of=2026-11-17` produces it, and the same dry run for 11-18 matched the 9 stored 11-18 forecasts exactly.
- On 11-18 the same collision happened at 06:05 and 06:10, but the maintenance job lost both times.

**2026-11-19.**
- `contracts:fetch` failed at 06:00:27 with a `QueryException`. The safe failure report kept only the class, and MySQL keeps no error record (no deadlock, `performance_schema` off).
- The recovery chain (`morning-chain-recovery-2026-11-19`, the old chain terminated with approval) failed the same way at 07:57.
- A diagnostic run of `ContractImporter::import()` inside an outer transaction that was always rolled back gave `SQLSTATE 22001 / 1406 Data too long for column 'product_link'`. Helen sent three 455-character product links; the column was `varchar(255)`.
- PR #28 changed `order_link` and `product_link` to `text`. It was merged without the CodeRabbit round (user decision) and deployed as `deploy-000029-e9172189e356` to the web role, whose entrypoint ran the migration.
- Price-cache maintenance was paused during the completion step, so the 11-17 collision could not repeat. A standalone `ContractImport` workflow completed at 08:20, and maintenance was resumed.
- The recovery chain's EEX step ended at 09:39. Retail (117 rows) and the forecast (9 rows) completed at 09:39.
- The 07:57 payload is saved in `storage/app/contract-payloads/` on the VPS volume.

## Raw bodies, parsed in the store step

The payload keeps each response body verbatim (XML and CSV as strings) inside the JSON envelope. The alternative was to save the parsed rows. Raw bodies make a parser fault replayable as well as a database fault, and they show exactly what the source sent.

## Both containers share the payload directory

On the VPS, the web and worker containers both mount the `voltikka-storage` volume on `/app/storage` (checked 2026-11-19 with `docker inspect`). Temporal does not guarantee that a fetch activity and its store activity run in the same worker. The design works because every container that runs activities (today one worker) mounts the same volume; a second worker must mount it too (S12). The web role can read the files for diagnosis.

## 2026-11-19 – Plan review (manager check against the code, user decisions)

The manager checked the spec against all 15 commands, the Temporal jobs and the completion lock. User decisions:

- **Retention:** 14 days on the volume, no GCS copy and no backup of payload files. They serve same-day replay and diagnosis only.
- **Contract interpretation:** out of scope. Its input is the stored, immutable source snapshot; a failed write costs one more model call, and the queue retries the job.
- **S17 first, as its own PR** (`fix/completion-producer-lock`). It does not depend on payload files, and the collision happened on 11-17 and again twice on 11-18.
- **Everything else in one PR.**
- **NDJSON in gzip** instead of one JSON envelope (S1, S3, S4): the store step must stream under PHP's 128 MB. FMI radiation is about 5 MB of XML per request, NVE 4 MB, and backfills far more.
- **D7 relaxed:** daily and hourly jobs within 24 hours, weekly jobs within 7 days, SEAS5 by one manual `--fetch-only`.
- **One transaction per store step (S21)**, asked by the user: a failed store writes nothing, and the replay writes the whole run. Today the collectors upsert in chunks of 500 with no transaction, so a failure leaves part of a run written.

Manager additions to the spec from the code check:

- **S5 wider:** the fetch step fixes every data-meaning time (window ends, import date, NVE `currentYear`, EEX effective date), not only `fetched_at`. Bookkeeping times stay real time; otherwise a contract replay would start with an expired completion deadline.
- **S19:** the fetch step may read the database to plan its requests. `ecmwf_00z` fetches only missing points, and `entsoe:fetch` decides its outage-history requests from the parsed first pages and the stored revisions.
- **S20:** a JSON result line from every command. The collectors print none today, so Temporal Issues say only `no_json_error`.

Considered and rejected: record every HTTP response of a normal run and replay it with `Http::fake`. It needs less refactoring, but a command that writes per response (EEX) and stops on a database error leaves a partial recording, so the 1.7-hour EEX fetch would have to run again.

Size of the work (survey 2026-11-19): every client except FMI decodes the response and returns parsed data, so each needs a raw-body method. Most commands are small changes. Larger ones: `contracts:fetch` (import date, checkpoint ownership), `futures:fetch-eex` (FI proof and retry passes inside the fetch loop), `entsoe:fetch` (S19 history), `weather:fetch` (seven sources with their own control flow; `solar_ghi` and `ecmwf_prev_day1` already fetch everything before they store).

Measured size: the 11-19 contract payload is 2.6 MB uncompressed. Measure the other sources, above all the Open-Meteo ensemble and seasonal sources and FMI radiation, in the first implementation step, and check the free space on the `voltikka-storage` volume.

## 2026-11-19 – S17 implemented (PR `fix/completion-producer-lock`)

`ContractImportCompletion::required()` now defers on a `LockTimeoutException` in `price_cache_refresh` after statistics, for the initial run and for a tick, like a typed cache conflict. The 60-check, two-hour bound still applies. Test: `DeferredContractImportCompletionTest::test_busy_price_cache_producer_lock_defers_initial_and_tick_then_completes` (fails without the fix).

## 2026-11-19 – PR #30 review, round 1 (head 898118c; Greptile Plus, score 4/5)

| Finding | Reviewer (comment) | Verdict | Reaction |
|---|---|---|---|
| The deferral matched every `LockTimeoutException` in `price_cache_refresh`, also the one `retire($candidate, required: true)` rethrows. A written candidate could then miss cleanup tracking, and a later tick would succeed over it. | CodeRabbit 4626166171, Greptile 4626151236 (same finding) | Valid, fixed | 👍 both |

Fix: `ContractListCacheService::refresh()` acquires the producer lease outside the build and throws the new `ContractPriceCacheProducerBusy` (a `LockTimeoutException` subclass, so other callers see no change) only when that acquisition times out. The completion defers only on that type. New test `test_lock_timeout_inside_the_cache_build_stays_terminal` fails with the round-1 code.

Spec-review lesson: owner `laravel-reviewer`; cause: no spec review ran for this PR (S17 shipped before the task's spec review), and the checklist did not name the class. Changed: `.pi/agents/laravel-reviewer.md` item 5 (and its `.claude/agents/` copy): a `catch` that turns a failure into a deferral, retry or success matches only its intended source.

## 2026-11-19 – PR #30 review, round 2 (head 8af878a)

CodeRabbit: no actionable comments. The round-1 finding of both reviewers is fixed and covered by this CodeRabbit review. Threads resolved. Full Laravel suite on the worktree: 3183 passed, 44 failed; the same 44 fail without this PR's changes (the worktree has no Vite build and no local `database.sqlite`). The review loop is clean; the merge waits for the user's request.

## 2026-11-19 – S17 merged and deployed

User approved the merge of PR #30 (with PR #26 and #27 from the VPS operations thread) and the deploy of both roles. PR #30 merged as `72465ae`; `deploy-000030-3ed8b55f31b3` (origin/main `3ed8b55`, PHP 8.4.27) deployed to web and worker at 11:40 Helsinki after today's morning checkpoints were all `ready`. Both containers healthy, `/up` 200. The worker had lagged at `deploy-000028`; both roles now run the same image.

## 2026-11-19 – Implementation contract

`design.md` fixes the payload format, the shared classes, the command modes and result line, the Temporal contract and the per-command tests, so the parallel implementation of 15 commands stays uniform. Work order: the shared foundation and one reference command (`spot:fetch-forecast`) first, then the command groups in parallel, then Temporal, documentation, spec review and the PR.

## 2026-11-19 – Implementation (manager)

Phase 1 (shared foundation and `spot:fetch-forecast`) by one executor, reviewed and committed as `41da647`. Then seven executors in parallel on disjoint files: contracts, EEX, spot, ENTSO-E/Fingrid, FMI/Syke/GIE/JAO/NVE, Open-Meteo weather, Temporal. Each reported its tests; the manager reviewed the shared code, made the shared-file changes the groups asked for, and ran the full suites. Implementation notes that change or extend `design.md` are in its section 7a.

Choices worth keeping:

- **Replay ownership.** A `--from-payload` store of `contracts:fetch` and `futures:fetch-eex` starts its own `running` marker with a new run UUID before writing. Resuming the fetch's UUID would let two concurrent stores of one payload both resume.
- **Failed-unit recovery.** A later answered line of the same unit (EEX retry pass) removes it from `failed_units`, so a fully recovered EEX run reports `complete: true`.
- **Patching.** `EEX_STEP`, `CONTRACTS_STEP` and the S forecast fetches change behind `workflow.patched("staged-api-imports")`, with replay tests on recorded pre-change histories. A deploy-time rule could not work: hourly staged jobs and a manual `MorningChain` recovery can run at any hour. `ArtisanJob` steps read `staged` from the Schedule input, so the collector and spot jobs become staged only when their Schedules are re-applied (`schedules.py --apply --group collectors|spot|maintenance`, a production change that needs approval). Until then they run the default one-process mode, which also saves payloads.
- **Contract fetch failure.** The fetch fails only when every postcode failed. Before, it failed when the last postcode failed and nothing had been parsed. A full-scope run with no contracts still fails (`no_contracts`); only a manual postcode-scoped run whose earlier postcodes answered empty and whose last one failed now exits 0 with a warning instead of 1.
- **Window facts in `weather:fetch`.** The windows are computed from the clock just before the writer reads `fetched_at`, and both are recorded in the header; the store reads only the header, so S5 holds.

Not acted on: `ContractReplacementLinker` writes `replaced_by_linked_on` from the clock, not from `effective_date`; the two differ only for a store that runs across midnight, which S9 already refuses for a replay. Contract `ImportReplay` runs the completion loop but not the forecast evaluation.
