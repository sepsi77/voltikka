# ENTSO-E spot fetch failures and Sentry noise

## Problem

Sentry issue 154972944 (2026-10-20 18:00 UTC): "Scheduled command [... spot:fetch] failed with exit code [1]."
The user considers it noise and wants it eliminated, and wants to know why ENTSO-E fails periodically.

## Findings

- Not caused by the Laravel 11 -> 13 upgrade. `laravel.log` has 30 dated `Data fetch failed: spot`
  entries from 2026-10-02 to 2026-10-20 (1 to 3 per day); `spot-fetch.log` has 745 failure lines in total.
  The next hourly run always succeeded.
- Exception class: almost always `RequestException` (an HTTP error response), sometimes `ConnectionException`.
- Railway network-flow logs for 2026-10-20 01:00 UTC (before the upgrade) and 18:00 UTC (after it):
  one HTTPS connection to ENTSO-E (20.79.236.108); the request was sent, the server was silent for
  18 s / 30 s, then sent about 1.6 KB and the connection closed. No second attempt.
- A normal request takes 2 to 4 s (local check on 2026-10-21, HTTP 200, 28 KB).
- `EntsoeService` retried only 5xx responses and connection failures. A non-5xx error response was
  therefore not retried. The exact status code was not logged anywhere.
- One failed run made two Sentry Issues: the command's own `Data fetch failed: spot` Issue and the
  scheduler's generic non-zero-exit exception (the pasted issue). The same applies to
  `futures:fetch-eex` and `contracts:fetch`.

## Change

1. `EntsoeService` retries every failed attempt (any error response or connection failure), 3 attempts.
2. `DataFetchFailureReporter` records `http_<status>` as the reason for an HTTP `RequestException`,
   so a remaining failure shows the status code.
3. `SelfReportedScheduledImportFailure` + `bootstrap/app.php`: the scheduler's exit-code-1 exception for
   the three self-reporting imports is not reported. Other exit codes stay reportable.
4. `contracts:fetch` reports `checkpoint` when the checkpoint record is refused without an exception,
   because that exit-1 path had no own Issue before.

## Acceptance

- Tests for retry on a 400, exhausted retries, the status reason and the filter pass.
- After deploy: watch `laravel.log` for `Data fetch failed: spot`; the reason shows the status code.
