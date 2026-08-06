# Decisions

- Production investigation is read-only. Do not run `spot:fetch`, change data, restart a service, or deploy without explicit user confirmation.
- The interrupted 2026-08-05 10:00 UTC `spot:fetch` run left Laravel's default 24-hour overlap mutex. Later hourly runs were skipped. Use `withoutOverlapping(60)` only for the hourly Spot import so an orphan lock expires after one hour.
- Apply explicit ENTSO-E request limits before retries: a 5-second connection timeout and a 30-second total timeout, with environment-configurable service values.
- Change both header render paths so server-rendered and Livewire-rendered unavailable states use `Spot-hintaa ei ole saatavilla`.
- Focused verification passed: 46 tests and 106 assertions across `HeaderSpotPriceServiceTest`, `EntsoeServiceTest`, and `FetchSpotCommandTest`.
