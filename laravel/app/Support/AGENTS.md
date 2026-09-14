# Application support

## Import failure reporting

`DataFetchFailureReporter` belongs to one command invocation. Contracts, EEX, and Spot collect failures and send one aggregate message Issue and one Laravel log at the outcome boundary. Optional stages and recovered retries do not alert. The fingerprint stays `data-fetch-failure` plus the import name. The Sentry scope is cleared before capture, then restored.

Only controlled stage/count names, numeric counts, exception classes, and closed reason codes may enter context. Never send exception messages, traces, URLs, query strings, SQL, response bodies, headers, seller text, tokens, or raw Throwable objects to Log or Sentry. Never call captureException for an aggregate import failure.

`fail()` accepts the caught Throwable only to read its class and, for the final cache conflict/storage exception classes, its closed reason. Their private constructors and named factories limit reasons to `evidence_changed`, `generation_changed`, `cache_write_failed`, and `cache_readback_failed`. `ContractImportCompletionStopped` also exposes a closed reason: its constructor maps unknown input to `unexpected`. Allowed reasons are `ownership_changed`, `date_expired`, `superseded`, `publication_missing`, `active_set_empty`, `waiting`, `deadline_exhausted`, `checks_exhausted`, and `interrupted_execution`. This keeps required completion failures diagnostic without exposing raw constructor input. All other Throwable classes get `unexpected`; no exception-message matching is permitted. Reasons are counted per required stage. `ContractPostImportResult::requiredExceptions` preserves the original required-stage Throwable until the command passes it through this safe boundary. The compatibility message map is not diagnostic context.

Tests: `DataFetchFailureReporterTest`, `FetchContractsCommandTest`, `FetchEexFuturesCommandTest`, and `FetchSpotCommandTest`. Secret-bearing exception messages must not enter the aggregate log or Issue.
