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
- **Contract fetch failure.** The fetch fails only when every postcode failed (`ImportFetchFailed`, payload kept). A full-scope run with no contracts fails (`no_contracts`); a postcode-scoped run with no contracts and failed postcodes exits 1 with `fetch_incomplete`, as before the staged change (PR #31 round 1 restored it).
- **Window facts in `weather:fetch`.** The windows are computed from the clock just before the writer reads `fetched_at`, and both are recorded in the header; the store reads only the header, so S5 holds.

Not acted on: `ContractReplacementLinker` writes `replaced_by_linked_on` from the clock, not from `effective_date`; the two differ only for a store that runs across midnight, which S9 already refuses for a replay. Contract `ImportReplay` runs the completion loop but not the forecast evaluation.

## 2026-11-19 – Spec review round 2026-11-19T12-19

Reviewers: refactor, Laravel, pricing, test, platform (Pi). Frontend and copy skipped: no views or public copy. 19 findings; all must-fix and small items fixed by six executors on disjoint files, each fix with a test that fails without it. Details and dispositions: `reviews/2026-11-19T12-19/summary.md` and `actions.md`.

Manager decisions under delegated authority:

- **S6 without a reference registry.** Every store activity has a schedule-to-close of at most one day, and Temporal refuses to schedule a store on a payload whose date directory is older than today − 13 days. Cleanup deletes only date directories older than 14 days, so no open run can use a deleted file. A registry of open references would add a table and a closing protocol for the same guarantee.
- **Paged sources keep fetch-side parsing (LAR-006 part).** GIE, Syke, Fingrid and ENTSO-E parse each page during the fetch because the next request depends on it. A page that fails to parse leaves the run genuinely incomplete; replaying it cannot invent the missing pages. JAO and NVE (not paged) now parse only in the store, so a parser fix replays their saved bodies.
- **Spot averages inside the store transaction, date identity unchanged (LAR-001).** The averages recompute from the stored rows; their rolling date stays today, which a same-day replay shares.
- **Superseded contract payloads (PRC-001).** The store refuses a payload older than the newest stored source observation. The check, the replay takeover and the importer transaction run under one shared cache lock, `contract_import_store_v1` (lease 65 min, wait 5 min; Redis is shared by web and worker), so a newer store cannot commit between the check and the write (verification residual). A lock timeout is the closed store error `store_busy`: nothing written, payload kept, one Issue. Accepted side effect: if a manual scoped import finishes during the few seconds of a morning fetch, that morning store is refused as superseded.
- **Spot-forecast display (PRC-002).** An older replay still archives its vintage but leaves the display alone when a newer feed (run row or display row) is stored. The recency check, the display upsert and the run insert run in one transaction inside the lock `spot_forecast_store_v1:<source>:<region>`, which commits before the lock is released (lease 65 min, wait 60 s); timeout → `store_busy`. SQLite tests prove the order (the check reads after the lock is held), not cross-process blocking; the shared lock provides that.
- **`SpotPriceAverageService` row lookup.** Its `updateOrCreate` keys now match `period_start` with `whereDate`: on SQLite the date cast stores `Y-m-d H:i:s`, so the old `Y-m-d` key missed the row and a second calculation hit the unique index. MySQL `DATE` columns behave as before.
- **Lock leases (LAR-201).** Both store-lock leases are 65 minutes, above the 60-minute Temporal store timeout at which `run_command` kills the PHP process, so a lease cannot expire under a live scheduled writer. Accepted cost: a store killed while holding the lock blocks the next store up to 65 minutes, which then fails `store_busy` with its payload kept. A transaction-scoped database mutex was the alternative; it would move the superseded check inside the importer transaction for the same guarantee.
- **Fetch owner in the payload (LAR-202).** A `--fetch-only` fetch of `contracts:fetch` and `futures:fetch-eex` writes its checkpoint owner UUID into the header (`checkpoint_run_uuid`). A split store that fails before its takeover marks only that owner failed, and only while its row is still `running`, so a newer owner or a settled run is never changed. Deferred: a payload refused by the shared reader before the store step (`sha256_mismatch`, `incomplete`, `missing`, `wrong_source`, `options_mismatch`) still leaves the fetch's `running` marker; only a corrupted or foreign file causes that, and the Temporal Issue names it.

## 2026-11-19 – PR #31 external review, round 1 (head e0515a2; Greptile Plus, score 4/5)

CodeRabbit: 6 inline, 4 outside the diff, 24 minor. Greptile: 1. Manager verdicts:

- **Fixed — generic replay rule (manager decision):** a `--from-payload` store is refused as `superseded` when a newer usable payload (same command and data options; not one in which every request failed) exists. This answers the "older payload replaces newer data" findings for EEX (4628088410, outside-diff EEX checkpoint), continental EEX and the collectors in one place; the contract observation check and the forecast display check stay for default-mode overlap. The S9 tests that accepted an older same-day payload now assert the refusal.
- **Fixed — checkpoint takeover (4628088395 and the EEX twin):** a replay may take over only an absent, non-running or own-fetch-owned checkpoint; a live newer run gives `checkpoint_busy`.
- **Fixed:** EEX lost-owner rollback (4628088416), ENTSO-E unreadable unit keeps the others (4628088422), FI spot store lock around prices and averages (4628088426), contracts date recheck under the lock, reader requires the `.sha256` file, FMI parameters and weather model identity from the header, scoped contract exit code restored, repeated-option dedupe (ENTSO-E, Fingrid), store-detected parser errors named `invalid_response`/`store` (FMI, NVE, JAO), GIE page limit, NeighbourSpot non-2xx as failed regions, Syke links against the recorded base URL, weather failed-request count, header source/command check, payload reference in store failure lines, Greptile scoped `ImportReplay` (completion loop only for full scope), local hard deadline in `run_command`, root `AGENTS.md` wording (exceptions to one transaction; replay after the chain closed), three test fixes.
- **Rejected (👎 with reply):** same-second contract fetches (4628088404) and same-second forecast feeds: Temporal overlap SKIP and the store locks exclude two fetches of one source in the same second.

Round 1, implementation notes: `EntsoeService::fetchDayAheadResponse()` now throws for any non-2xx answer, so a 3xx is a failed unit for `spot:fetch`, `spot:fetch-neighbours` and `spot:backfill` alike (before, a 3xx was stored as an answered request and skipped silently). The FI spot store lock `spot_store_v1:FI` covers `spot:fetch` only; `spot:backfill` and `spot:averages` are manual and do not take it. A fetch that fails as a whole (`ImportFetchFailed`) is marked `fetch_failed` in its header and never supersedes an older payload. Payloads fetched before this round cannot be replayed for FMI, Syke and weather (no recorded parameters, base URL or models); none exist in production yet.

## 2026-11-19 – PR #31 external review, round 2 (head 566d0c0)

CodeRabbit: 8 inline, 1 outside the diff, 1 minor; all valid, all fixed (👍).

- **One store lock per command** (`staged_import_store_v1:<command>`, lease 65 min, wait 5 min) held from a replay's newest-payload check through the store commit, for default and replay stores. "An older payload never rolls newer data back" now holds for every source (weather, reservoirs and every other upsert source included). Accepted side effect: two stores of the same command wait for each other, also with different options (scheduled stores of one command run at different times; backfills are manual). The command-level locks of `contracts:fetch`, `spot:fetch` and `spot:fetch-forecast` stay as inner locks; they are now redundant, and removing them is a later cleanup.
- **Recovery of an abandoned store:** a replay of the same payload (same SHA-256 recorded in the `running` checkpoint at takeover) may take over a `running` row; holding the command's store lock proves the old store ended. Deferred: a store killed in one-process default mode leaves `running` under the fetch owner without a payload SHA, so a replay of its payload gets `checkpoint_busy`; Temporal always runs split mode.
- Also fixed: set-valued options compare as sets; a damaged newer payload never supersedes; trait-level `store_busy`/`superseded` finalize the fetch owner; ENTSO-E retries a 3xx inside the retry loop; Fingrid vintages record the variable; `full_contract_scope` uses the last `--postcodes` occurrence.

## 2026-11-19 – PR #31 external review, round 3 (head 1273097)

CodeRabbit: 3 inline, 3 outside the diff, 2 minor (all Minor).

- **Fixed:** lock-backend errors in `runStaged()` give the closed `store_failed` line; `SpotPriceImport/AGENTS.md` names `recordFailure()` for the neighbours only; a non-2xx EEX answer is a failed unit that enters the bounded retry pass; Fingrid `--dataset` values are normalized in the payload identity; a Fingrid vintage payload stays replayable after its dataset left the config.
- **Rejected (👎 with reply), 4629143870:** an older weather replay with a manual option subset can overwrite newer observed rows. The replay must give its payload's options, so this needs a rare manual option set; the daily default run re-fetches the 21-day window and restores the values.
- **Not fixed (review-body minors, no thread; CodeRabbit rated both "poor tradeoff"):** a repeated store attempt after a dead store fails `store_busy` instead of waiting out the 65-minute lease (the payload stays; a replay after the lease recovers), and `ImportReplay`'s completion outcome can read a newer run's checkpoint in a narrow window. Rule added to `.greptile/rules.md` so both reviewers stop flagging narrow manual-replay races.

## 2026-11-19 – PR #31 external review, round 4 (head 0437182)

CodeRabbit: 2 outside the diff.

- **Fixed:** a failed store-lock release no longer replaces the store outcome; it logs one safe warning with the payload reference, and the lease frees the lock.
- **Rejected:** "`fingrid:fetch` runs from both `console.php` and Temporal". `console.php` skips every group listed in `TEMPORAL_SCHEDULE_GROUPS`, and production lists `collectors`; `WebSchedulerTest` and `test_console_groups.py` keep both sides equal on purpose. Added to `.greptile/rules.md` (no thread for outside-diff comments).

## 2026-11-19 – PR #31 external review, round 5 (head 242983f): clean

CodeRabbit: no actionable comments. Every CodeRabbit thread is fixed or answered (threads resolved); the PR's one Greptile finding (scoped `ImportReplay`) was fixed in round 1 and covered by the later CodeRabbit reviews. Ready to merge on the user's request; the deploy, the Schedule re-apply and D7 need separate approval.

## 2026-11-19 – Merge, deploy and Schedule re-apply (user approval 2026-11-19)

- **Merge.** PR #31 merged at 18:07 Helsinki (`38c1913`); branch, worktree and local branch deleted.
- **Deploy.** Image `deploy-000031-38c19132fc24` (built from `38c1913`), both roles, 18:09–18:11 Helsinki, 130 s; `https://voltikka.fi/up` 200; `voltikka-web-deploy-000031-…` and `voltikka-worker-deploy-000031-…` healthy. The web scheduler still lists only `logos:download` and `backup:run --only-files`.
- **Schedules.** `python -m voltikka_orchestration.schedules --apply --group collectors --group spot --group maintenance` in the worker container (`-w /opt/orchestration`, `.venv/bin/python`; there is no `schedules` entry point): 25 updated, `import-payloads-cleanup` created. Read back by ID: all 26 active; `staged` on the 16 collectors, `spot-hourly`, `spot-fetch-neighbours` and `spot-fetch-forecast`, and on no other Schedule.
- **First staged runs.** `spot-fetch-neighbours` 18:25 (1,056 records, 4 regions) and `fingrid-forecast-vintages` 18:45 (1 vintage) stored from their payloads.
- **SEAS5** (D7). `weather:fetch --only=ecmwf_seas5 --fetch-only` in the worker at 18:13: `fetched`, 11 requests, 0 failed, 511,604 bytes, `complete`. No rows. First scheduled run: 2026-12-08 11:00.
- **`ImportReplay` trial** (D7). `import-replay-2026-11-19-1` on the 18:45 Fingrid vintage payload (`fingrid/2026-11-19/e78d3acf-….ndjson.gz`): `succeeded`, `vintages_inserted: 0`. `hourly_forecast_runs` before and after: 40,429 rows, max id 40,439, max `updated_at` 16:45:03 UTC, same SHA-256 of the latest 100 rows.
- **Storage.** `voltikka-storage` is on the root disk: 156 GB free of 197 GB. Payloads after the first runs: 516 KB (mostly SEAS5).
- Payload files are owned by root (the worker user); the web role only reads them.
- **Open (D7):** first payload and completed workflow for every daily and hourly job by 2026-11-20 18:15 (including the staged EEX, contract import and S forecast paths on the morning of 2026-11-20), EC46 on 2026-11-23, NVE on 2026-11-25. Root `AGENTS.md` "Dated follow-ups" holds the check.
- **Review instructions.** `laravel-reviewer` item 2 now asks for the behavior list of a rewritten command (exit code per scope, failed-unit isolation, option normalization, output lines), from three findings the external review caught in PR #31.

## 2026-11-20 – D7 check (read-only, 12:30–12:45 Helsinki)

Production since 2026-11-19 18:11: `deploy-000031-38c19132fc24` on both roles, healthy.

- **Staged jobs (check 1).** Every staged `JOBS` entry except SEAS5 ran fetch then store; 17 of 18 have a completed workflow whose outcome is `stored` with a payload path, and every one of those files exists with its `.sha256`: `fingrid-fetch`, `gas-storage-fetch`, `weather-fetch`, `weather-fetch-ec46` (hand-triggered 2026-11-19), `entsoe-fetch`, `fmi-fetch`, `fmi-fetch-forecast`, `weather-fetch-solar-ghi`, `weather-fetch-ecmwf-00z`, `weather-fetch-ecmwf-prev-day1`, `fingrid-forecast-vintages` (18 runs), `eex-europe-fetch` (658 points), `jao-fetch` (760 hourly rows), `nve-fetch-reservoirs` (hand-triggered), `spot-hourly` (18 runs), `spot-fetch-neighbours` (19 runs), `spot-fetch-forecast` (3 runs).
- **`syke-fetch` failed (check 1, not passed).** 2026-11-20 09:10 UTC: both requests answered HTTP 404 (an HTML page). Syke's `Hydrologiarajapinta/1.2/odata` answers 404 even at its base URL and `$metadata`, while `…/1.1/odata` answers 200 with the same `LumiAlue` data. Our base URL is unchanged since the collector was written, and every run from 2026-11-12 to 2026-11-19 succeeded, so the cause is Syke's API, not the staged code. The staged path behaved as specified: payload kept (`syke/2026-11-20/d2a9e33c-….ndjson.gz`), store refused `fetch_incomplete`, one Issue with the payload reference. The payload holds only the 404 bodies, so no replay recovers data; the next fetch after a fix covers its 90-day window (from 2026-08-22), so nothing is lost if it is fixed within that window.
- **Morning (check 2).** `morning-chain-2026-11-20T02:00:00Z`, `eex-futures-2026-11-20` and `contract-import-2026-11-20` completed. EEX: `--fetch-only` (409 requests, 0 failed), then `--from-payload` stored 8,576 points. Contract import: `--fetch-only` (30 requests, 6.6 MB), then `--from-payload` stored 371 contracts (333 active, 0 failed postcodes) at 06:01:40 Helsinki. Checkpoints for 2026-11-20: `eex_futures`, `contract_import`, `morning_retail`, `morning_forecast` all `ready`. Completion took 25 ticks (ready at about 06:28) against 8–12 on 2026-11-17/18/19; three `contracts:warm-cache --pending` runs failed (`no_json_error`) at 06:05–06:20 during it, as on 2026-11-17 (one) and 2026-11-18 (two) before this deploy. Cause of the longer completion unconfirmed (looked at the workflow histories only).
- **S forecast (check 3).** `s-forecast-2026-11-20T08:55:00Z` completed; its `entsoe:fetch --dataset=outages` ran fetch then store. It made no forecast (`inputs_missing: day_ahead_d`, DE-LU 1/24 hours), as every day since at least 2026-11-13 for other missing inputs (the pilot has no public output).
- **DE-LU 2026-11-20 is missing.** The ENTSO-E answer in the neighbour payload holds DE-LU 2026-11-20 and 2026-11-21 only as `classificationSequence` position 2; `parseDayAheadPrices()` keeps sequence 1 only (rule of 2026-10-31, unchanged by PR #31). The one-process command would have dropped it too. Whether sequence 2 is now the coupled price is unconfirmed (not compared with SMARD/Energy-Charts). The raw answers are in the payloads (retention 14 days), so a parser fix can replay them.
- **Failures since the deploy (check 4).** Only `syke-fetch-2026-11-20T09:10:00Z` and the three `price-cache-maintenance` runs above ended Failed; nothing TimedOut or Terminated.
- **Storage.** 9.5 MB of payloads after the first day (contract import 6.4 MB, open-meteo 0.9 MB, NVE 0.6 MB); 156 GB free of 197 GB.
- **Result.** D7 passes for every job except `syke-fetch`; the last `tasks.json` item stays open for it, and root `AGENTS.md` holds its follow-up instead of the general D7 entry. SEAS5's first scheduled run is 2026-12-08 11:00 (its manual `--fetch-only` already passed on 2026-11-19).
