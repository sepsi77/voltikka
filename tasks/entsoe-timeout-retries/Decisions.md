# Decisions

- `EntsoeService` now treats `Illuminate\Http\Client\ConnectionException` as retryable, covering cURL 28 ENTSO-E timeouts in addition to existing 5xx retry behavior.
- `spot:fetch` and `spot:backfill` catch exhausted request/connection failures so scheduled runs fail gracefully (or continue by chunk for backfill) after retries.
- Command logs redact `securityToken` from raw HTTP exception messages because Laravel/Guzzle messages can include the full ENTSO-E URL.
- Added focused tests for service connection-timeout retry and command timeout handling.
