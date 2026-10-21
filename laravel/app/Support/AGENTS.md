# Application support

## Import failure reporting

`DataFetchFailureReporter` belongs to one command invocation. Contracts, EEX, and Spot collect failures and send one aggregate message Issue and one Laravel log at the outcome boundary. Optional stages and recovered retries do not alert. The fingerprint stays `data-fetch-failure` plus the import name. The Sentry scope is cleared before capture, then restored.

Only controlled stage/count names, numeric counts, exception classes, and closed reason codes may enter context. Never send exception messages, traces, URLs, query strings, SQL, response bodies, headers, seller text, tokens, or raw Throwable objects to Log or Sentry. Never call captureException for an aggregate import failure.

`fail()` accepts the caught Throwable only to read its class and, for the final cache conflict/storage exception classes, its closed reason. Their private constructors and named factories limit reasons to `evidence_changed`, `generation_changed`, `cache_write_failed`, and `cache_readback_failed`. `ContractImportCompletionStopped` also exposes a closed reason: its constructor maps unknown input to `unexpected`. Allowed reasons are `ownership_changed`, `date_expired`, `superseded`, `publication_missing`, `active_set_empty`, `waiting`, `deadline_exhausted`, `checks_exhausted`, and `interrupted_execution`. This keeps required completion failures diagnostic without exposing raw constructor input. An Illuminate HTTP `RequestException` with a response gets `http_<status>` (for example `http_400`): the integer status only, never the body or URL. All other Throwable classes get `unexpected`; no exception-message matching is permitted. Reasons are counted per required stage. `ContractPostImportResult::requiredExceptions` preserves the original required-stage Throwable until the command passes it through this safe boundary. The compatibility message map is not diagnostic context.

Tests: `DataFetchFailureReporterTest`, `FetchContractsCommandTest`, `FetchEexFuturesCommandTest`, and `FetchSpotCommandTest`. Secret-bearing exception messages must not enter the aggregate log or Issue.

## Duplicate scheduler exception for imports

The scheduler throws a plain `Exception` ("Scheduled command [...] failed with exit code [N].") for each non-zero foreground exit and reports it, so one failed import made two Issues. `SelfReportedScheduledImportFailure` matches only that exception for `spot:fetch`, `futures:fetch-eex` and `contracts:fetch` with exit code 1, and `bootstrap/app.php` does not report it. These commands catch every Throwable and return 1 only after a reporter `fail()` call; keep that true for each new `FAILURE` path, or that failure has no Issue. Exit 255 (PHP fatal error) or a killed process stays reportable, because the command did not send its own Issue. The scheduler log listeners still run. Tests: `SelfReportedScheduledImportFailureTest`.

## Database connection loss

`DatabaseConnectionLost` decides whether a Throwable means "database unreachable" (only `QueryException`/`PDOException` in the previous chain, matched with Laravel's lost-connection phrases). `bootstrap/app.php` uses it for a retryable 503 and `config/sentry.php` `before_send` uses it for the one `database-connection-lost` fingerprint. Do not widen it to other exception classes: the phrase list contains generic socket text such as `reset by peer`. Tests: `DatabaseConnectionLostTest`. See `../../AGENTS.md#database-unavailability`.
