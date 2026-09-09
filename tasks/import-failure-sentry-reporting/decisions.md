# Decisions

- Explicit Sentry message events are required: the Laravel Sentry log driver sends Logs, not Issues.
- Use temporary scopes and one stable fingerprint per import. No persistent suppression.
- Keep all work local. No commit, push, deployment, or production operation was made.
- Each command invocation creates a fresh aggregate reporter and calls `report` once in its final outcome boundary. The reporter creates an explicit Sentry message event and one Laravel log. A failed exit uses error severity; a reported condition with the existing successful exit uses warning severity. A successful acquisition after retries has no failure facts and is silent.
- Clear the temporary Sentry scope before capture to remove inherited HTTP breadcrumbs and private context. Restore the caller scope afterward. Send controlled import/stage codes, numeric totals, and exception classes only. No date-based fingerprint or persistent suppression.
- Keep Spot's successful empty-response exit, scoped contracts' successful empty-response exit, and partial contracts' successful exit. They now report warning Issues. Full empty contracts and missing full-run EEX FI proof still fail.
- Catch unexpected thrown command failures at the outer boundary. They now return exit failure with a safe `unexpected` reason instead of escaping to automatic exception capture. This prevents duplicate Issues and raw exception output. Spot save/derived-data failures use `save_or_post_import`.
- EEX request/discovery diagnostics remain console-only and show exception classes. The aggregate includes all exhausted request failures and missing current-run prior-date FI proof. Normal empty maturities do not add a failure.
- Azure now retries ConnectionException as a transient error and the contract command counts exhausted connections per postcode. Partial imports still preserve absent active rows and skip replacement linking.
- Removed redundant HTTP response logs from Azure, ENTSO-E, and EEX acquisition services. Their return and exception contracts stay unchanged. ENTSO-E parser errors still return an empty array but no longer log raw XML/parser text; Spot reports the empty result. Backfill semantics remain unchanged and its tests pass.
- Required contract stage messages are not sent to the reporter; only a controlled stage code is used. Optional interpretation, logo, and cache-warm failures remain outside import Issue reporting. Scheduler listeners are unchanged.

## Verification

- Real Sentry Hub and Client tests capture events in `before_send` and drop them before network transport. Tests prove message Issue creation, warning/error level, fixed fingerprint, safe aggregate context, class-only exceptions, scope restoration, silent success, and repeated-run grouping.
- Command tests cover exhausted Spot timeout, save failure, wholly empty Spot response, successful connection retry with nonempty today-only data, partial contracts, required statistics failure, contract HTTP/connection exhaustion, successful HTTP/connection retries, scoped empty contracts, multiple EEX request failures, multiple discovery failures, missing FI proof, and normal empty maturities.
- Initial targeted test run: 11 failed because the new test helper used the wrong SDK hub accessor; fixed to `SentrySdk::getCurrentHub` / `setCurrentHub`. The next run passed all 85 tests.
- Final command: `cd laravel && php artisan test --filter='DataFetchFailureReporterTest|FetchSpotCommandTest|EntsoeServiceTest|FetchEexFuturesCommandTest|FetchContractsCommandTest|EexFuturesServiceTest|BackfillSpotCommandTest|AzureConsumerApiClientTest'` — 111 passed, 403 assertions, 27.91 seconds. Six suites matched; standalone EexFuturesServiceTest and AzureConsumerApiClientTest do not exist. Their acquisition paths are tested through the commands.
- Pint passed on the three commands and the three new PHP files. `git diff --check` passed. Final diff and status were reviewed. CLAUDE mirrors remain symlinks.
- No full application suite or frontend build was run; the changes are limited to import reporting and acquisition retry handling. Live Sentry delivery was not tested because all work is local.
