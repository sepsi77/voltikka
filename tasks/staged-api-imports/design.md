# Design: staged external API imports

Implementation contract for `spec.md`. Every command in scope follows it. Change it only with a
dated note in `decisions.md`.

## 1. Payload file (S1–S4)

Path: `storage/app/import-payloads/<source>/<YYYY-MM-DD>/<run-id>.ndjson.gz`, where the date is the
Helsinki date of `fetched_at` and `<run-id>` is a UUID. Beside it: `<run-id>.sha256` (hex SHA-256
of the `.ndjson.gz` bytes, one line). Both are written to `*.tmp` first and renamed; the `.sha256`
file is renamed last, so its presence means the payload is complete.

Gzip-compressed NDJSON, one JSON object per line:

1. Header: `{"type":"header","version":1,"source":"fingrid","command":"fingrid:fetch",
   "options":{...},"run_id":"…","fetched_at":"2026-11-19T08:20:01Z","facts":{...}}`
   - `options`: the command's data options as given (never secrets).
   - `facts`: every time and plan fixed by the fetch step (S5, S19): for example
     `effective_date`, window bounds, `first_hour`, `current_year`, the request plan.
2. One line per request: `{"type":"request","unit":{...},"method":"GET","url":"https://host/path",
   "query":{...},"status":200,"body":"…"}`
   - `unit`: closed identifiers that tell the store step what the body is (`{"postcode":"00100"}`,
     `{"dataset":245,"page":1}`, `{"zone":"SE1","document":"A80","offset":0}`).
   - `url` without query string and without user info; `query` with secret parameters removed
     (S2). Request headers are never recorded.
   - Body: `body` (UTF-8 text, verbatim) or `body_base64` (binary, for example ENTSO-E ZIPs).
   - A failed request: `"status":503` with its body when the body carries meaning, or
     `"connection_failed":true` with no body. A request that was never answered after retries is
     still one line, so the store step sees the same partial acquisition (S10).
3. Closing line: `{"type":"end","requests":N,"failed_units":[{...}],"facts":{...}}`.

The store step reads the file line by line (never the whole file in memory). A file without a
header, with a request line after the end line, or without the end line is incomplete.

## 2. Shared code (`laravel/app/Services/ImportPayload/`, with `AGENTS.md`)

- `ImportPayloadWriter`: `open(source, command, options, facts): self`;
  `record(array $unit, Response $response, ?string $bodyEncoding = null)` for an answered request
  (status, body and the redacted effective URI come from the Laravel `Response`);
  `recordFailure(array $unit, string $method, string $url, array $query, Throwable $e)` for a
  request with no answer; `setFact(key, value)` until the end; `finish(array $endFacts):
  ImportPayloadReference`. Redaction is done here, in one place.
- `ImportPayloadReader`: `open(string $path, ?string $sha256 = null)` verifies the SHA-256 (given,
  else from the `.sha256` file) and the complete structure, then exposes `header()`,
  `requests(): Generator`, `end()`. Refuses a missing, changed or incomplete file with
  `ImportPayloadInvalid` (closed reason: `missing`, `sha256_mismatch`, `incomplete`,
  `wrong_source`, `wrong_date`).
- `ImportPayloadReference`: path, sha256, size, `fetched_at`, source, run ID, completeness facts;
  `toArray()` for the JSON line and checkpoint metadata.
- Redaction (S2): query parameter names matched case-insensitively against one list
  (`securityToken`, `token`, `access_token`, `api_key`, `apikey`, `key`, `x-api-key`, `subscription-key`,
  `password`, `secret`), plus URL user info. Header-borne keys (Fingrid `x-api-key`, GIE `x-key`)
  are never recorded because headers are not recorded.
- `ImportPayloadCleanup` + command `import-payloads:cleanup` (dry run by default, `--apply`
  deletes): payload and `.sha256` files older than 14 days by their date directory, `*.tmp`
  files older than 24 hours, then empty date directories (S6).

## 3. Command modes (S7, S20, S21)

One trait, `App\Console\Concerns\StagedImport`, used by every command in scope:

- Options: `--fetch-only`, `--from-payload=<path>`, `--payload-sha256=<hex>`.
- `runStaged(Closure $fetch, Closure $store, bool $transactional = true): int`
  - default: `$fetch(ImportPayloadWriter)` → `finish()` → open the written file with the reader →
    `$store(ImportPayloadReader)`. The store always reads the file, never in-memory responses.
  - `--fetch-only`: fetch and finish only.
  - `--from-payload`: reader only; no HTTP call. Data options given on the command line must equal
    the header's `options` or the command refuses (`payload_invalid`, reason `options_mismatch`).
  - With `$transactional`, the trait runs `$store` inside one `DB::transaction` (S21). `$store`
    returns a result object with counts and optional after-commit closures (cache invalidation,
    queue dispatch, statistics warming), which the trait runs after the commit. A chunked
    backfill and `contracts:fetch` (own import transaction) pass `false` and own their
    transactions.
- The **last stdout line** is one JSON object (S20; `orchestration` reads only the last line):
  - fetch-only success: `{"status":"fetched","step":"fetch","payload":{reference}}`
  - store success: `{"status":"stored","step":"store","payload":{"path":…,"sha256":…},"counts":{…}}`
  - failure: `{"status":"failed","step":"fetch|store","error":"<closed key>", …}` with exit 1.
    Closed keys: `fetch_failed`, `fetch_incomplete` (when the command treats partial acquisition
    as a failure today), `store_failed`, `payload_invalid` (+ `reason`), and command-specific
    keys that exist today. A store `QueryException` adds `sqlstate`, `driver_code` and `table`
    (S8, D6); the table comes from the SQL text (`insert into`, `update`, `delete from`,
    `replace into`), never from bindings or the message.
  - Commands that exit 0 on partial acquisition today keep that exit code (S8) and say
    `"complete":false` in the line.
- The payload file stays in place after any failure (D2).

## 4. Failure reporting (S8)

`DataFetchFailureReporter` gains the step (`fetch` or `store`) in its context and, for a
`QueryException` in any chain position, the reason `sqlstate_<state>` plus a safe `store_error`
context (`sqlstate`, `driver_code`, `table`). Messages, SQL bindings, URLs and bodies never enter
it. Commands without a reporter today keep their single safe log line and add the same three
fields to it.

## 5. Time and plan (S5, S19)

The fetch step reads the clock once (`fetched_at`) and fixes every data-meaning value in the
header `facts`. The store step never calls `now()`, `today()`, `Carbon::now()` for data meaning.
`created_at`/`updated_at`, claims, leases and deadlines stay real time. The fetch step may read the
database to plan requests and records the plan in `facts`; it never writes import rows.

## 6. Date rule (S9)

`contracts:fetch`, `futures:fetch-eex` and `futures:fetch-eex-europe` refuse a payload whose
`facts.effective_date` is not today's Helsinki date (`payload_invalid`, reason `wrong_date`).
Every other command states in a test whether an older same-day payload is accepted.

## 7. Temporal (S12–S16)

- `Step.staged: bool`. A staged step runs `run_command(args + ["--fetch-only"])` with the step's
  `interruptible` value, reads `result["payload"]` (`path`, `sha256`), then runs
  `run_command([command, "--from-payload=<path>", "--payload-sha256=<sha>"] + data options)`
  with `interruptible=False`. A failed fetch skips the store. A failed store never starts a new
  fetch. A missing or malformed fetch result fails the step (`fetch_result_invalid`).
- `ContractImport`: staged `contracts:fetch --skip-logos`, then the completion loop.
- `SDailyForecast`/`SWeeklyRefit` fetches use the same two activities.
- `ImportReplay(command, payload_path, sha256, date?)`: one store activity; for `contracts:fetch`
  it then runs the `ContractImport` completion loop. It refuses a payload date other than today
  for the S9 commands before PHP starts (the PHP command checks too).
- Activity inputs and results stay references only (`path`, `sha256`, counts).
- `import-payloads:cleanup --apply`: a maintenance job in `schedules.py` and its `console.php`
  counterpart (the group tests keep both sides equal), daily at 03:20 Helsinki. Applying the new
  Temporal Schedule to production is a separate approved step.

## 7a. Additions during implementation (2026-11-19)

- The header is still the first line, but the writer writes it at `finish()`: request lines stream into a temporary compressed body file and are copied behind the header as one gzip member, so `setFact()` works until the end.
- `record(..., failed: true)` marks an answered request as a failed unit; a later answered line of the same unit recovers it (EEX retry passes).
- A fetch that throws leaves no file; a multi-request fetch records its failures and does not throw, so the payload stays (S10). A fetch in which every unit failed still writes the payload, so the error bodies are kept.
- Store result class `ImportStoreResult(counts, afterCommit, error, errorStep)`: with a closed `error`, the rows are committed and the command exits 1 (for example `fetch_incomplete` with step `fetch` after a partial fetch).
- Extra closed keys: `invalid_options`, `invalid_config`, `after_commit_failed`, `unsupported_source`, `options_mismatch` (reader reason), `no_contracts`, `post_import_failed`, `checkpoint_failed`, `missing_current_run_prior_fi`, `no_instruments`, `no_complete_hour`, `forecast_points_missing`, `reference_period_unproved`, `not_configured`.
- `{"status":"skipped","step":"fetch","reason":"not_configured"}` (exit 0, no file): `gas-storage:fetch --if-configured` without a key. Temporal treats it as a successful step with nothing to store.
- `stagedDataOptions()` defaults to every non-global option; `contracts:fetch` keeps only `postcodes` (`--skip-logos` changes no row) and `gas-storage:fetch` leaves out `--if-configured`. Temporal passes the fetch's args to the store anyway.
- Checkpoint commands (`contracts:fetch`, `futures:fetch-eex`): a `--from-payload` store starts its own `running` marker with a new run UUID before writing, so a superseded or concurrent store never overwrites a newer run. Default mode keeps one UUID.
- Temporal patching: `EEX_STEP`, `CONTRACTS_STEP` and the S forecast fetches change behind `workflow.patched("staged-api-imports")` with replay tests; `ArtisanJob` reads `staged` from its Schedule input, so running jobs are unaffected and the collector/spot jobs become staged only when their Schedules are re-applied.

## 8. Tests per command (D1, D2, D3, D6)

Use `Http::fake` with fixed bodies and `Storage`-free real files under a temporary storage path.

- D1: default mode and `--fetch-only` then `--from-payload` write the same rows.
- D2: a store failure (force a `QueryException`) writes no row of the run, leaves the file, and a
  later `--from-payload` with `Http::preventStrayRequests()` succeeds.
- D3 (keyed sources): the configured key value appears nowhere in the decompressed file.
- D6: the JSON line and the safe log of a store `QueryException` hold SQLSTATE, driver code and
  table, and no binding value.
- S9: wrong-date refusal where it applies; otherwise one test says whether an older same-day
  payload is accepted.
