# Findings and decisions

- Negative and zero numeric prices are preserved by `HeaderSpotPriceService`; the Blade condition tests the returned array, not the numeric value. A negative price should render normally if a current row is found.
- The header shell is loaded only once through JavaScript. Missing current interval data or a transient failed request can leave it gray for the page lifetime. Injected `wire:poll.60s` markup is not a mounted Livewire component and should not be treated as working polling.
- The hourly chart uses a positive-only scale with zero fixed at the bottom. Negative, zero, and sufficiently small positive prices are all clamped to a 4% upward bar.
- The expanded quarter-hour chart repeats the same signed-value bug by clamping every non-positive width to 4% to the right.
- Recommended follow-up: implement a signed scale with an explicit zero baseline, signed bar direction, header retry/refresh behavior, and negative/zero regression tests.
- Follow-up implementation tasks were created in `tasks/fix-header-spot-price-refresh/` and `tasks/fix-negative-spot-price-charts/`.
- A bounded production query was attempted read-only. Local `railway run` could not resolve the private MySQL hostname, and Railway SSH required linking the configured SSH key, so production data was not changed or confirmed.
