# Staged external API imports: save the response first, then write the database

User decision 2026-11-19. Every scheduled import from an external API runs in two steps:

1. **Fetch**: call the API and save the complete response as a JSON payload file.
2. **Store**: read that payload file, parse it and write the database.

In Temporal, the two steps are two separate activities.

## Why

On 2026-11-19 the contract import failed at 06:00 and again at 07:57. Helen sent 455-character `ProductLink` values into a `varchar(255)` column. MySQL rejected the row, the whole import transaction rolled back, and the fetched data existed only in the memory of the failed process. The failure log recorded only the exception class, so finding the cause needed a diagnostic run on production (incident record in `decisions.md`; fix in PR #28).

With a saved payload, a parse or database failure loses nothing. After a fix, the same payload is stored again without a new API call.

On 2026-11-17 the contract import completion failed with a `LockTimeoutException`: the 5-minute price-cache maintenance held the shared price-cache producer lock, and the completion step waited only 10 s. That fix is part of this task because it belongs to the same morning import path.

## Scope

The scheduled commands that read an external API and write the database:

| Command | Source | Runs in |
|---|---|---|
| `contracts:fetch` | Azure Consumer API | `ContractImport` (MorningChain) |
| `futures:fetch-eex` | EEX public API | `EexFutures` (MorningChain) |
| `futures:fetch-eex-europe` | EEX public API | collectors |
| `spot:fetch` | ENTSO-E day-ahead (XML) | spot-hourly |
| `spot:fetch-neighbours` | ENTSO-E day-ahead (XML) | spot |
| `spot:fetch-forecast` | nordpool-predict-fi | spot |
| `entsoe:fetch` | ENTSO-E transparency (XML) | collectors; S forecast outage fetches |
| `fingrid:fetch` | Fingrid open data | collectors; S forecast |
| `fingrid:fetch-forecast-vintages` | Fingrid open data | collectors |
| `fmi:fetch` | FMI open data (WFS, XML) | collectors |
| `syke:fetch` | Syke | collectors |
| `weather:fetch` (every `--only` source) | Open-Meteo | collectors |
| `gas-storage:fetch` | GIE AGSI | collectors |
| `jao:fetch` | JAO | collectors |
| `nve:fetch-reservoirs` | NVE | collectors |

Out of scope:

- `logos:download`: it stores the downloaded file itself and writes no import rows.
- Outbound calls that import nothing: social posting (PostFast), Sentry.
- Calls made for one visitor request: PVGIS, Digitransit.
- Contract interpretation (OpenRouter LLM calls in queue jobs): user decision 2026-11-19, see `decisions.md`.
- Recovery of data from past failed imports, and replay of a payload for a past date (user decision 2026-11-19). A replay is for the same day, after a fix.

## Requirements

### Payload files

- **S1** The fetch step saves the raw response bodies exactly as the API returned them (JSON, XML or CSV text) in one gzip-compressed NDJSON file per run (user decision 2026-11-19): a header line (source, command and options, run ID, `fetched_at` (UTC) and the other fixed facts of S5), one line per request (method, URL without credentials, query parameters without keys or tokens, status code or connection failure, raw body), and a closing line with the completeness facts. Binary bodies (ENTSO-E outage ZIP files) are base64. Error responses whose body carries meaning are saved with their body (Open-Meteo HTTP 400 "not available", AGSI HTTP 200 with an `error` field). The store step reads the file line by line, so it never holds the whole payload in memory (PHP 128 MB; FMI radiation is about 5 MB of XML per request), and it parses the raw bodies, so a parser fault can also be replayed.
- **S2** No secret reaches a payload file: API keys, tokens, `Authorization` headers and credentials in URLs are removed before the file is written. A test proves this for every source that uses a key (ENTSO-E, Fingrid, GIE and any other keyed source).
- **S3** The file is written atomically (temporary file, then rename) under `storage/app/import-payloads/<source>/<YYYY-MM-DD>/<run-id>.ndjson.gz`. Its SHA-256 goes in the payload reference and in `<run-id>.sha256` beside it. The fetch step finishes only after both files are complete.
- **S4** A store step verifies the SHA-256 (from the reference, or from the `.sha256` file for a manual `--from-payload`) and refuses a missing, changed or incomplete file (no closing line).
- **S5** The fetch step fixes every time that gives the data meaning and records it in the header: `fetched_at`, the import date, window ends (for example the Fingrid end hour, the FMI current hour), the NVE `currentYear` and the EEX effective date. The store step takes them from the header and never reads the clock for data meaning (vintage issue times, observation times, `last_observed_at`, `price_date`). Append-only vintages keep the time of the fetch, not the time of the store. Bookkeeping times stay real time: claims, leases, deadlines and `created_at`. A contract replay two hours after the fetch must not start with an expired completion deadline.
- **S19** The fetch step may read the database to decide which requests to make, and never writes import rows. This covers `weather:fetch --only=ecmwf_00z` (it fetches only the points still missing) and the `entsoe:fetch` outage history (the fetch step parses the outage IDs of the first pages, looks up missing revisions and fetches their history). The header records the request plan.
- **S6** A scheduled cleanup deletes payload files older than 14 days and temporary files left by a killed fetch (user decision 2026-11-19). It never deletes a file that a not-yet-closed run still references. Payload files are not backed up.

### Commands

- **S7** Each command in scope has three modes and one code path for parsing and writing:
  - default (no option): fetch, save the payload file, then store from that file, in one process. `console.php` and manual runs use this mode.
  - `--fetch-only`: fetch and save, then print one JSON line with the payload reference (path, SHA-256, size, `fetched_at`, completeness facts). Writes no import rows.
  - `--from-payload=<path>`: store from a saved file. Makes no API call.
- **S8** Results and failure reports stay as they are now: the same rows, checkpoints, Sentry Issues and safe aggregate logs. The failure report names the step (`fetch` or `store`). A failed store reports the SQLSTATE and the driver error code (for example `22001`/`1406`) and the table, never row values or bindings. The table comes from the SQL text, which has no bound values.
- **S20** Every command in scope prints one closed JSON result line on success and on failure (for example `{"step":"store","error":"store_failed","sqlstate":"22001","driver_code":1406,"table":"electricity_contracts"}`), so a Temporal Issue names the step and the closed reason. Today the collectors print none, and Temporal sees only `no_json_error`.
- **S21** Each store step writes its rows in one database transaction: a failed store writes nothing, and a replay writes the whole run. Work outside the database (cache invalidation, queue dispatch, statistics cache warming) runs after the commit. `contracts:fetch` keeps its existing import transaction and its separate post-import stages. A backfill over several chunks (`--from`/`--to`) commits once per chunk. Consequence, accepted: a store fault in one unit (for example one Fingrid dataset) now keeps the whole run's rows out until the replay, because the payload keeps the data and the fault needs a code fix anyway.
- **S9** Date-bound imports refuse a payload from another Helsinki date: `contracts:fetch` (its rows carry the import date) and the EEX imports whose checkpoints are per date. Other imports must state in their tests whether an older same-day payload is accepted.
- **S10** A partial fetch (some requests failed) still saves the payload with its failed requests listed, and the store step keeps today's partial-acquisition rules (for example `contracts:fetch` with `complete=false`).
- **S11** The checkpoint rules of the morning imports stay: `running` at the start of the fetch step, the final status from the store step. A failed fetch writes the same failed checkpoint as today. A failed store leaves a `failed` checkpoint whose metadata holds the payload reference.

### Temporal

- **S12** Every job step in scope becomes two activities: `run_command` with `--fetch-only`, then `run_command` with `--from-payload=<path>` from the first activity's result. Only the reference passes through Temporal, never the payload. Every worker that polls the task queue must mount the payload directory (today one worker container and the web container share the `voltikka-storage` volume); Temporal does not guarantee that two activities run in the same worker.
- **S13** Retry rules stay: a lost attempt is retried, a failed command is not. A failed store never starts a new fetch.
- **S14** Interruptible steps (EEX) restart their fetch on a worker deploy as they do today. A store step is not interruptible: it finishes within the drain.
- **S15** The `ContractImport` workflow runs fetch, store, then its completion loop. `MorningChain` keeps its producer and consumer semantics.
- **S16** A replay workflow (`ImportReplay`) runs only the store activity for a given command and payload path. It is started by hand, after a fix, with the user's approval. It refuses a payload from another date where S9 applies. For a contract import replay, it then runs the completion loop like `ContractImport`.

### Contract import completion lock

- **S17** When the price-cache producer lock is busy during `price_cache_refresh`, the completion step defers to its next check (within its existing 60-check, two-hour limit) instead of failing.

### Documentation

- **S18** Root `AGENTS.md`, `orchestration/AGENTS.md`, `laravel/AGENTS.md` and the closest service `AGENTS.md` files describe the two steps, the payload location and retention, the replay workflow and the rule that a new import must follow this pattern. The recovery paragraph in root `AGENTS.md` names the replay workflow.

## Done when

- **D1** Every command in the scope table has the three modes, and a feature test per command proves that default mode and `--fetch-only` followed by `--from-payload` write the same rows from the same faked HTTP responses.
- **D2** A test per command proves that a store failure writes no row of the run (S21), leaves the payload file in place, and that a later `--from-payload` run of the same file succeeds without an HTTP call (`Http::preventStrayRequests()`).
- **D3** The secret-removal test of S2 passes for every keyed source.
- **D4** Orchestration tests (pytest) prove the two activities per step, the reference passing, no new fetch after a failed store, the `ContractImport` order and the `ImportReplay` date refusal.
- **D5** A test proves the completion defers on a busy producer lock (S17) and reaches `ready` on the next check.
- **D6** The failure report and the JSON result line (S20) of a store `QueryException` contain SQLSTATE, driver code and table, and no binding values (test).
- **D7** On production, after the deploy, every scheduled job in scope has written at least one payload file and its rows match the expected run (checkpoints `ready`, Temporal workflows completed): daily and hourly jobs within 24 hours, weekly jobs (NVE, EC46) within 7 days. SEAS5 (monthly, on the 8th) is checked by one manual `--fetch-only` right after the deploy, which writes no rows, and its first scheduled run is noted in `decisions.md` (user decision 2026-11-19). One `ImportReplay` trial on a same-day payload runs with the user's approval and changes no row that the first store wrote.
- **D8** The documentation of S18 is updated, and `CLAUDE.md` mirrors stay correct.
