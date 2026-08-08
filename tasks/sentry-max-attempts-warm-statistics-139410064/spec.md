# Spec

Investigate and fix Sentry issue 139410064 (`VOLTIKKA-1P`), where `App\Jobs\WarmContractPriceStatisticsCache` exhausted three production queue attempts on 2026-08-08.

Goals:
- Prove why the first three job attempts did not complete.
- Keep statistics-page cache warming asynchronous and bounded.
- Reduce the warmer runtime enough to complete safely under its queue timeout.
- Add focused regression coverage for the failure mechanism.
- Do not mutate production data or infrastructure during investigation.
