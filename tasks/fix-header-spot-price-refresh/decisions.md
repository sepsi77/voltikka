# Decisions and guardrails

- Treat zero and negative prices as valid data. Availability must be based on whether a current database row exists, never on numeric truthiness.
- Keep the current architecture lightweight: prefer one plain-JavaScript fetch coordinator that updates every header container unless implementation evidence strongly favors mounting a real Livewire component.
- Do not rely on Livewire directives inside HTML injected through `innerHTML`.
- Share one in-flight request and response across desktop/mobile instances.
- Retry failures conservatively and refresh periodically; avoid request loops or multiplying timers.
- Preserve `SpotPriceQuarter` preference and `SpotPriceHour` fallback.

## Implemented

- The layout now owns one in-flight request, one timer, and bounded retry delays (5/15/30/60 seconds), then refreshes successful responses every 60 seconds.
- One fetched fragment is applied to both desktop and mobile containers. Repeated initialization reuses the same coordinator and reapplies the latest markup.
- Initial failures switch from the loading shell to an explicit unavailable state; later refresh failures retain the last useful result while retrying.
- The injected fragment no longer includes `wire:poll` and exposes an explicit available/unavailable data state.
- Feature tests cover quarter preference, hourly fallback, negative and zero rendering, unavailable rendering, cache headers, and removal of fake polling.
