# Solar address search timeout

Fix Sentry 146562268: interactive Digitransit autocomplete must use one HTTP attempt, with a 2-second connection timeout and a 5-second total timeout. Remove retries. Preserve request headers and query, DTOs, seven-day successful cache, and existing Finnish unavailable notice. Failed requests must not enter the cache.

Add isolated service and component regression tests. Do not call a public API. Do not change PVGIS, UI copy, production settings, or existing cache-refresh work. Run focused tests and `git diff --check`. No commit, push, or deployment.
