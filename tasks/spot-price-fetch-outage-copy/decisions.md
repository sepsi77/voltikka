# Decisions

## 2026-09-01

- Production `spot:fetch` has failed repeatedly against the ENTSO-E API. The latest attempts return HTTP 503 after the configured retries; some earlier attempts timed out. The 2026-09-01 03:00 UTC run failed in 18.91 seconds.
- The independent hourly freshness check confirms stale official FI data. At 03:44 UTC, the newest stored official hourly row was 2026-08-31 21:00 UTC, six hours behind the current UTC hour. This explains why the header has no current price and why the page has only the first Helsinki hour of 1 September as official data.
- The third-party forecast import still succeeds, but forecasts must stay separate from official ENTSO-E actual prices. The UI must not present a forecast as the current official price.
- No manual production import was run because it can write Spot rows and derived averages if ENTSO-E recovers. It requires explicit production-mutation confirmation.
- Correct the unavailable-state text from `Spot-hintaa ei ole saatavilla` to `Spot-hintoja ei ole saatavilla` in both the server-rendered shell and Livewire component.
- Updated the two active header views and their focused assertions. Display behavior and pricing logic did not change.
- Verification: `cd laravel && php artisan test --filter="HeaderSpotPrice"` passed with 6 tests and 27 assertions in 0.31 seconds.
- Verification: `git diff --check` passed with no output.
- No format command was necessary because the change only replaces string literals and task text.
