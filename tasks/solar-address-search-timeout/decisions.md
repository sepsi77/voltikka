# Decisions

- Use the approved interactive budget: one attempt, 2-second connection timeout, 5-second total timeout. Retries can exceed the Livewire request budget.
- Keep exceptions outside successful cache writes so a later search can recover.
- Preserve all existing dirty work. Only add the timeout policy to the already modified Services context. Its CLAUDE.md is a symlink to AGENTS.md.
- Removed the retry chain and its unused constants. Request headers, query, DTO conversion, cache lifetime, and component code stay unchanged.
- Regression tests inspect the actual HTTP options through the fake handler. Server-error and connection-timeout tests count handler calls across two searches and check that the failed query has no cache entry. The existing success-cache test proves reuse. The component test proves stale suggestions clear and the exact existing notice appears after ConnectionException.
- Verification: `cd laravel && php artisan test --filter='DigitransitGeocodingServiceTest|SolarCalculatorLivewireTest|SolarGeocodeApiTest'` passed: 35 tests, 171 assertions. Timeout checks use HTTP fakes, not a public API. No full suite or asset build is required for this PHP-only scope.
- `git diff --check` passed. No commit, push, deployment, or production change was made.
