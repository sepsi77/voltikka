# Import payloads (staged external API imports)

Every scheduled command that reads an external API and writes the database runs in two steps (user decision 2026-11-19; spec and binding contract in `tasks/staged-api-imports/spec.md` and `design.md`):

1. **Fetch**: call the API and save the raw responses in one payload file.
2. **Store**: read that file, parse it and write the database.

Reason: on 2026-11-19 the contract import failed twice on one bad column, and the fetched data existed only in the failed process. With a payload file, a parse or database failure loses nothing, and after a fix the same payload is stored again without a new API call.

**Every new external import must follow this pattern**: use `App\Console\Concerns\StagedImport`, record every request with `ImportPayloadWriter`, and parse only from `ImportPayloadReader` in the store step. Do not add a parallel save/replay mechanism.

## Files

- `ImportPayloadWriter.php`: `open(source, command, options, facts)` starts a run and reads the clock once (`fetched_at`, UTC, whole seconds). `record(unit, Response, ?bodyEncoding, failed)` for an answered request; `recordFailure(unit, method, url, query, Throwable)` for a failed one (a `RequestException` keeps its status and body; anything else is `connection_failed`); `setFact(key, value)` until `finish(endFacts)`, which returns an `ImportPayloadReference`; `abandon()` drops an unfinished run. Redaction happens here and only here.
- `ImportPayloadReader.php`: `open(path, ?sha256)` requires the `.sha256` file (even with a given digest), verifies the SHA-256 (given, else the `.sha256` file) and the whole structure before anything is read; then `header()`, `fact(key)`, `fetchedAt()`, `complete()`, `end()`, `requests()` (a generator) and `body(requestLine)` (text or decoded base64).
- `ImportPayloadReference.php`: path, sha256, size, `fetched_at`, source, run ID, request count, failed-unit count, `complete()`; `toArray()` is the `payload` object of the fetch JSON line and of checkpoint metadata.
- `ImportPayloadInvalid.php`: refusal with a closed `reason`: `missing`, `sha256_mismatch`, `incomplete`, `wrong_source`, `wrong_date`, `options_mismatch`, `superseded` (a `--from-payload` store refuses a payload when a newer complete payload of the same command and options exists, see "Replay stores only the newest payload"; `contracts:fetch` also refuses a payload older than evidence it already accepted).
- `ImportFetchFailed.php`: a fetch closure throws it (closed `reason`: `all_failed`, plus end facts) after it recorded every request but got nothing usable. `StagedImport` then finishes the payload file instead of dropping it, so the error bodies stay (design 7a), and fails the fetch step with exit 1.
- `ImportStoreResult.php`: what a store closure returns: `counts`, `afterCommit` closures, and an optional closed `error` (+ `errorStep`) for a command that keeps the rows of a partial acquisition and still exits 1.
- `ImportPayloadCleanup.php` + `import-payloads:cleanup` (`App\Console\Commands\CleanupImportPayloads`): retention (S6).
- `../../Console/Concerns/StagedImport.php`: the three command modes, the store transaction and the JSON result line.
- Config: `config/import_payloads.php` (`path`, default `storage/app/import-payloads`). `tests/TestCase.php` points it at a per-test temporary directory, so no test writes into `storage/`.

## File format (version 1)

`<path>/<source>/<YYYY-MM-DD>/<run-id>.ndjson.gz` (Helsinki date of `fetched_at`, UUID run ID) and `<run-id>.sha256` beside it (hex SHA-256 of the `.ndjson.gz` bytes and a newline). One gzip stream, one JSON object per line:

1. Header: `type=header`, `version`, `source`, `command`, `options` (the command's data options, defaults included), `run_id`, `fetched_at` (`2026-11-19T06:00:00Z`), `facts` (every data-meaning time and request plan the fetch fixed, S5/S19, including facts set with `setFact()` after requests were recorded), `requests` and `failed_units` (counts, written at `finish()`, so a replay check can judge a file from its first line; files written before these fields have none).
2. One `type=request` line per request: `unit` (closed identifiers that tell the store step what the body is), `method`, `url` (no query string, no user info), `query` (secret parameters removed), then `status` + `body` (UTF-8 text verbatim) or `body_base64` (binary or invalid UTF-8), or `connection_failed: true` + `exception` (class name) with no body.
3. End line: `type=end`, `requests` (count), `failed_units` (list of units), `facts` (end facts).

Request lines stream into a compressed `<run-id>.body.ndjson.gz.tmp` while the fetch runs. `finish()` writes the header and copies the body into `<run-id>.ndjson.gz.tmp` as **one** gzip member (PHP `gzdecode()` reads only the first member, so concatenated members are not used), renames it, then writes and renames `<run-id>.sha256` last: the `.sha256` file means "complete".

## Rules

- **S1** Bodies stay raw (JSON, XML, CSV text); the store step parses them, so a parser fault is replayable. Error responses whose body carries meaning are recorded with `record()` (and `failed: true` when the command counts them as failed).
- **S2** No secret in a payload: request headers are never recorded (header keys such as Fingrid `x-api-key` and GIE `x-key` stay out by construction); query parameters named `securityToken`, `token`, `access_token`, `api_key`, `apikey`, `key`, `x-api-key`, `subscription-key`, `password` or `secret` (case-insensitive) are removed; URL user info is dropped. A keyed source adds a test that its key value appears nowhere in the decompressed file.
- **S3/S4** Atomic write (temporary file, rename); the reader refuses a missing file, a SHA mismatch, a missing `.sha256` file (also when `--payload-sha256` is given), a header without `source` or `command`, a file without header or end line, a line after the end line, or a request count that differs from the end line.
- **S5** The store step takes data-meaning times from the header (`fetchedAt()`, `fact()`), never from the clock. Bookkeeping times (`created_at`, claims, leases, deadlines) stay real time.
- **S19** The fetch step may read the database to plan its requests and records the plan in `facts`; it never writes import rows.
- **S21** `StagedImport` runs the store closure in one `DB::transaction` (unless the command passes `$transactional = false` and owns its transactions); after-commit closures run only after the commit. A failed store writes nothing and leaves the payload in place.
- **Replay stores only the newest payload** (manager decision, PR #31 review round 1): after the reader verified the file, a `--from-payload` store refuses it (`payload_invalid`, reason `superseded`, no write, file kept) when a newer complete payload of the same command and the same normalized data options exists. `StagedImport::refuseStagedOlderPayload()` scans the date directories of `<path>/<source>/` from the payload's Helsinki fetch date on, takes only files with their `.sha256` file, reads only each header line, and compares `command`, normalized `options` and a strictly later `fetched_at`; the payload itself never supersedes itself, so re-storing the newest payload after a fix works. Reason: replay exists to recover the newest data after a fix, and an older payload must not roll newer data back. This one rule covers every source, so no source needs its own recency check beyond `contracts:fetch`'s source-observation check and `spot:fetch-forecast`'s display check, which also cover the overlap of a default-mode store with a newer one. Default mode and `--fetch-only` do not run it. A candidate whose header says `requests > 0` and `failed_units == requests` holds nothing usable and never supersedes, so an all-failed fetch does not block the replay of the last good payload. A fetch that ends with `ImportFetchFailed` also gets the header fact `fetch_failed: true` and never supersedes, even when some of its requests were answered (ENTSO-E outage paging). A partial payload still supersedes (it is newer data), and a header without the counts (older files) counts as usable.
- **S6** `import-payloads:cleanup` (dry run unless `--apply`): payload and `.sha256` files whose date directory is older than 14 days, `*.tmp` files older than 24 hours, then expired date directories left empty and source directories left empty. A current date directory is never removed. Payload files are not backed up. It runs daily at 03:20 Helsinki as the Temporal maintenance job `import-payloads-cleanup` (`orchestration/src/voltikka_orchestration/schedules.py`), with its `routes/console.php` counterpart in the maintenance group. No open run can use an expired file: every store activity has a schedule-to-close of at most one day, and Temporal refuses to schedule a store on a payload whose date directory is older than today − 13 days (`orchestration/AGENTS.md`, "Retention").

## Command contract (`StagedImport`)

- Implement `stagedImportSource(): string` (lowercase slug, the payload directory). Override `stagedDataOptions()` only to leave out an option that changes no row; override `reportStagedFailure()` when the command has a `DataFetchFailureReporter` (call `step()`, `fail()`, `report()`), else the default writes one safe `Log::error` line.
- `runStaged(fetch, store, transactional = true)`. Options added by the trait: `--fetch-only`, `--from-payload=<path>`, `--payload-sha256=<hex>`. `--from-payload` refuses a payload of another source or command (`wrong_source`), other data options (`options_mismatch`) or one a newer payload supersedes (`superseded`). A command must not define its own method named `refuseStagedOlderPayload`. A store closure throws `ImportPayloadInvalid::because('wrong_date')` for the date rule (S9).
- The last stdout line is one JSON object (raw, printed even with `-q`):
  - `{"status":"fetched","step":"fetch","payload":{"path":…,"sha256":…,"size":…,"fetched_at":…,"source":…,"run_id":…,"requests":1,"failed_units":0,"complete":true}}`
  - `{"status":"stored","step":"store","payload":{"path":…,"sha256":…},"complete":true,"counts":{…}}`
  - `{"status":"failed","step":"store","error":"store_failed","sqlstate":"23000","driver_code":19,"table":"spot_price_forecast_runs"}` (exit 1)
  - `{"status":"failed","step":"fetch","error":"fetch_failed","reason":"all_failed","payload_path":…,"payload_sha256":…}` (exit 1): the fetch threw `ImportFetchFailed`; the payload is kept. Any other throwable from a fetch drops the unfinished file and prints `fetch_failed` without a payload.
  - Every store failure after the reader verified the file (`payload_invalid` with `wrong_source`, `options_mismatch`, `superseded` or a store-thrown reason, `store_failed`, `after_commit_failed`, and a store result's closed `error`) also carries `payload_path` and `payload_sha256`, so the failure names the file to replay. Refusals of the reader itself (`missing`, `sha256_mismatch`, `incomplete`) do not.
  - other closed errors: `fetch_failed`, `payload_invalid` + `reason`, `after_commit_failed`, `invalid_options` (`--fetch-only` with `--from-payload`), and command keys (`unsupported_source`, a store result's `error`).
- SQL facts come from `DataFetchFailureReporter::storeError()`: SQLSTATE and driver code from `errorInfo`, the table from the placeholder SQL text. Never the message or bindings.

## Converted commands

- `spot:fetch-forecast` (reference conversion; `../SpotForecasts/AGENTS.md`).
- `spot:fetch`, `spot:fetch-neighbours` (source `entsoe-day-ahead`; `../SpotPriceImport/AGENTS.md`).
- `contracts:fetch` (source `azure-consumer-api`; `../ContractImport/AGENTS.md`).
- `futures:fetch-eex` (source `eex`), `futures:fetch-eex-europe` (source `eex-europe`) (`../ElectricityFutures/AGENTS.md`).
- `entsoe:fetch` (source `entsoe-transparency`; `../EntsoeTransparency/AGENTS.md`).
- `fingrid:fetch`, `fingrid:fetch-forecast-vintages` (source `fingrid`; `../Fingrid/AGENTS.md`).
- `fmi:fetch` (`fmi`), `syke:fetch` (`syke`), `gas-storage:fetch` (`gie-agsi`), `jao:fetch` (`jao`), `nve:fetch-reservoirs` (`nve`) (each service directory's `AGENTS.md`).
- `weather:fetch`, every `--only` source (source `open-meteo`; `../OpenMeteo/AGENTS.md`).

A later answered line of a unit that failed earlier in the run (an in-run retry pass, EEX) removes it from `failed_units`; the store reads the last answered line of each unit.
