# Decisions

- Production investigation is read-only. Do not run `spot:fetch`, change data, restart a service, or deploy without explicit user confirmation.
- The interrupted 2026-08-05 10:00 UTC `spot:fetch` run left Laravel's default 24-hour overlap mutex. Later hourly runs were skipped. Use `withoutOverlapping(60)` only for the hourly Spot import so an orphan lock expires after one hour.
- Apply explicit ENTSO-E request limits before retries: a 5-second connection timeout and a 30-second total timeout, with environment-configurable service values.
- Change both header render paths so server-rendered and Livewire-rendered unavailable states use `Spot-hintaa ei ole saatavilla`.
- Focused verification passed: 46 tests and 106 assertions across `HeaderSpotPriceServiceTest`, `EntsoeServiceTest`, and `FetchSpotCommandTest`.
- The follow-up uses an independent read-only `spot:check-freshness` command. It accepts a latest FI hour equal to or later than the current Helsinki hour start. Missing or older data writes one error with UTC hour values and lag minutes only.
- The freshness check runs at minute 10 on one server and appends output to `storage/logs/spot-freshness-check.log`. It has no overlap mutex, so an orphan lock cannot silence it.
- Follow-up verification passed: 23 tests and 55 assertions across `CheckSpotPriceFreshnessCommandTest` and `FetchSpotCommandTest`.
- Global scheduler reporting uses central lifecycle listeners in `AppServiceProvider`, not callbacks on each schedule. Non-zero finished tasks, thrown task exceptions, and overlap-protected skips use the normal Laravel error log. Deliberate filter skips stay silent.
- Scheduler failure context is restricted to the public task display summary, cron expression, timezone, and applicable exit code/runtime or exception class. It excludes exception messages and task output.
- Global reporting verification passed: 28 tests and 60 assertions across `ScheduledTaskFailureLoggingTest`, `CheckSpotPriceFreshnessCommandTest`, and `FetchSpotCommandTest`. PHP lint passed for all changed PHP files, and `git diff --check` passed.
