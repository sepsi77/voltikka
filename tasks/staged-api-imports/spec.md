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
- Contract interpretation (OpenRouter LLM calls in queue jobs): see the open decision in `decisions.md`.
- Recovery of data from past failed imports, and replay of a payload for a past date (user decision 2026-11-19). A replay is for the same day, after a fix.

## Requirements

### Payload files

- **S1** The fetch step saves the raw response bodies exactly as the API returned them (JSON, XML or CSV text) inside one JSON envelope per run. The envelope holds the source, the command and its options, `fetched_at` (UTC), each request (method, URL without credentials, query parameters without keys or tokens, status code) with its raw body, and the failed requests. The store step parses the raw bodies, so a parser fault can also be replayed.
- **S2** No secret reaches a payload file: API keys, tokens, `Authorization` headers and credentials in URLs are removed before the file is written. A test proves this for every source that uses a key (ENTSO-E, Fingrid, GIE and any other keyed source).
- **S3** The file is written atomically (temporary file, then rename) under `storage/app/import-payloads/<source>/<YYYY-MM-DD>/<run-id>.json.gz`, gzip-compressed, with a SHA-256 of the content. The fetch step finishes only after the file is complete.
- **S4** A store step verifies the SHA-256 and refuses a missing, changed or incomplete file.
- **S5** The store step uses the envelope's `fetched_at` wherever the command now uses the current time for data meaning (vintage issue times, observation times, `last_observed_at`). Append-only vintages must keep the time of the fetch, not the time of the store.
- **S6** A scheduled cleanup deletes payload files older than the retention period (see `decisions.md`). It never deletes a file that a not-yet-closed run still references.

### Commands

- **S7** Each command in scope has three modes and one code path for parsing and writing:
  - default (no option): fetch, save the payload file, then store from that file, in one process. `console.php` and manual runs use this mode.
  - `--fetch-only`: fetch and save, then print one JSON line with the payload reference (path, SHA-256, size, `fetched_at`, completeness facts). Writes no import rows.
  - `--from-payload=<path>`: store from a saved file. Makes no API call.
- **S8** Results and failure reports stay as they are now: the same rows, checkpoints, Sentry Issues and safe aggregate logs. The failure report names the step (`fetch` or `store`). A failed store reports the SQLSTATE and the driver error code (for example `22001`/`1406`) and the table, never row values or bindings.
- **S9** Date-bound imports refuse a payload from another Helsinki date: `contracts:fetch` (its rows carry the import date) and the EEX imports whose checkpoints are per date. Other imports must state in their tests whether an older same-day payload is accepted.
- **S10** A partial fetch (some requests failed) still saves the payload with its failed requests listed, and the store step keeps today's partial-acquisition rules (for example `contracts:fetch` with `complete=false`).
- **S11** The checkpoint rules of the morning imports stay: `running` at the start of the fetch step, the final status from the store step. A failed fetch writes the same failed checkpoint as today. A failed store leaves a `failed` checkpoint whose metadata holds the payload reference.

### Temporal

- **S12** Every job step in scope becomes two activities: `run_command` with `--fetch-only`, then `run_command` with `--from-payload=<path>` from the first activity's result. Only the reference passes through Temporal, never the payload.
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
- **D2** A test per command proves that a store failure leaves the payload file in place and that a later `--from-payload` run of the same file succeeds without an HTTP call (`Http::preventStrayRequests()`).
- **D3** The secret-removal test of S2 passes for every keyed source.
- **D4** Orchestration tests (pytest) prove the two activities per step, the reference passing, no new fetch after a failed store, the `ContractImport` order and the `ImportReplay` date refusal.
- **D5** A test proves the completion defers on a busy producer lock (S17) and reaches `ready` on the next check.
- **D6** The failure report of a store `QueryException` contains SQLSTATE, driver code and table, and no binding values (test).
- **D7** On production, after the deploy, every scheduled job in scope has written at least one payload file and its rows match the expected run (checkpoints `ready`, Temporal workflows completed). One `ImportReplay` trial on a same-day payload runs with the user's approval and changes no row that the first store wrote.
- **D8** The documentation of S18 is updated, and `CLAUDE.md` mirrors stay correct.
