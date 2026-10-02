# Decisions

## Final local acceptance — 2026-10-01

The manager accepts the implemented retained-cache policy locally after actual diff review and the settled 2,953-test / 24,174-assertion PHP gate. JavaScript tests, production asset build, changed-file Pint, whitespace and 50 context mirrors pass. Cached city values match earlier rendered captures; full-kernel city/detail/API and prepared exact 7,312-kWh GET checks perform no annual/donor calculation. See `cache-policy-final-verification.md` for evidence and limits.

The independent mechanism/category and unbounded custom-handoff findings are repaired. New custom profiles have a 30-minute TTL and 64-live-profile limit; server results are locked and raw integer comparison bounds are validated before preparation. Valid values stay exact, not rounded to a preset. Shared preset retention, strict new-build evidence, historical statistics and annual calculation mathematics remain unchanged by this policy.

No commit, push, deployment or production producer is authorized or performed. Production cause correlation and after-release behavior remain open; release and a first manual producer require separate explicit approval. Earlier stage checkpoints below remain historical evidence.

## Later approved policy — producer stage

The user-approved `cache-policy-spec.md` supersedes earlier immediate-invalidation constraints below. Producer/lifecycle work now retains the exact active descriptor and records durable demand. Promotion satisfies captured demand in the pointer write and rejects changed demand, changed active identity or lost producer ownership. Import completion-write proof also checks demand. The full local PHP suite passes: 2,887 tests / 23,115 assertions. See `cache-policy-producer.md`. Public guards and HTTP miss calculations were not changed in this stage. Pure GETs, including custom query strings, must use cached data only in the next stage; explicit user actions may calculate exact custom consumption.

## Shared cache read boundary

The shared read guard now retains verified price and provenance across publication/pointer/EEX changes. The current build guard remains strict. Current availability and optional build-time scalar classification still guard transport. Missing public preset payloads request one repair demand and return a typed, non-cacheable Finnish 503. Custom misses return null without demand. Company wrapper schema 3 removes live publication evidence from stored keys and uses cheap availability identity for guarded aggregation. The shared calculated-cost schema and all financial math stay unchanged.

`prepareComparisonForConsumption(int): ContractMetricSet` is the exact custom handoff API. It requires the scoped policy permission, uses the existing generation/manifest storage, and does not copy custom profiles into replacement generations. The standalone calculator caller must grant explicit action permission and call this method before its existing redirect. See `cache-policy-reads.md` for verification and integration exceptions. The six manager-assigned cache/service classes now pass together: 71 tests, 474 assertions. Their fixtures model CLI producers or seed verified presets before actual HTTP/Livewire reads; no GET policy or global test setup was weakened. Company availability markers are 64-character fingerprint strings, not copied universes. Empty company wrappers now cheaply reaggregate shared metrics so reactivated cached contracts do not remain hidden.

## Demand metadata loss safety

The manager found that a lost standalone demand key could restart revisions below the active generation's satisfied revision. Lifecycle demand reads now use the stored active satisfied revision as a floor, with a direct `ACTIVE_KEY` read and no bootstrap recursion. Loss alone creates no change. The next invalidation or repair advances above the floor; a candidate with lost higher unmet demand proof still cannot promote. The six owned cache classes and warmer tests pass: 74 tests, 517 assertions. Import completion's raw-demand proof and all consumer services are unchanged in this follow-up.

## Earlier investigation decisions

- The supplied completed SQL timings are small. Local read-only measurement found about 5 seconds per cold full metrics build; the production 30-second timeout was not reproduced. See investigation.md for exact measurement scope and platform evidence.
- A cache miss calculates the active market so the detail page can show its rank. A timeout while visiting a fixed-term contract can therefore occur in a different contract's premium path.
- Do not raise PHP's execution limit or suppress the Sentry Issue as a substitute for fixing repeated work.
- No pricing method or history change is intended. A performance-only correction must retain outputs on the same inputs.
- Measurement refuted the initial premium-serialization suspect on the local snapshot: selector cost was about 0.0025 seconds. Prioritize repeated no-overflow timeline anniversaries, billing-month day counts and immutable segment fractions. Do not add premium result caching without measured need.
- Cache safety regression gate passes: 27 tests, 147 assertions. These tests verify retained-generation, immediate invalidation, conflict recovery and request memoization behavior; they do not prove production latency.
- Investigation uses local data only. Production runtime and cache-loss causes are not yet verified.
- A second supplied event (Issue 129678126, 2026-10-01 03:11:58 UTC) shows a cold 20,000 kWh build at version 774. The first event at 03:06:17 used version 752. The 22-version increase in 341 seconds suggests repeated invalidation or promotion; it does not prove which writer caused it.
- Current interpretation publication immediately invalidates the global generation after every successful publication. EEX completion also invalidates it. These safety boundaries must remain; do not suppress publication invalidations merely to improve cache reuse.
- The old task for Issue 129678126 records an August MySQL communication failure and a later move to file cache. The reused Issue ID does not prove that this October incident has the same cause.
- The bounded correction reuses 13 exact no-overflow anniversaries and 12 billing-month durations within each timeline build. It does not chain month additions or change relative phase resolution, governing ties, calendar boundaries, segment order or fraction summation order.
- Immutable WindowSegment objects compute one signed duration and retain the existing clamped calendar fraction and unclamped billing fraction. Null billing denominators still use the calendar fraction; split objects keep the original scale and denominator.
- No pricing schema, method, estimator, lineage, cache invalidation or process/persistent cache changes are included. Nearest Support and DTO contexts are updated through their existing CLAUDE.md symlinks. Unrelated dirty contexts and annual-history task records remain unchanged.
- Local implementation gate passes: 316 tests / 6,574 assertions across all canonical pricing unit tests and four bill-comparison feature classes; focused Pint passes. Deterministic checks require 13 anniversary calls, 12 billing-month durations and one duration per segment construction, with no fraction-read date work. See implementation.md. Full-market exact parity and repeated cold timing checks are complete in verification.md.
- September 30 city-page incidents occurred at 02:09 Helsinki, before the 06:00 full import. Morning publication churn cannot explain every failure. Concurrent expensive city requests are a separate suspect, not a proved cause.
- Approved local full-kernel GET measurement for Lapinjarvi and Mikkeli is complete; see city-measurement.md. The older local snapshot and local PHP/runtime differences remain explicit.
- Confirmed: each simultaneous cold worker independently builds all 378 shared metrics, and canonical local-card enrichment bypasses the verified shared metrics because an unconditional legacy-card-price argument selects direct calculation. Warm shared-cache requests still repeat local donor preparation.
- Current sequential cold city medians are 4.320/4.126 s versus 6.392/6.015 s with the two HEAD classes. Eight simultaneous requests take 6.324–6.585 s versus 10.586–11.200 s. Initial two/four-worker cases include two unresolved HTTP 500s; a repeat with exception capture succeeded. No production 30-second timeout was reproduced.
- Recommend reviewing shared metric reuse for canonical local cards as the next small change. Keep legacy card loading and all eligibility/evidence guards. The retained shared calculation-date boundary needs explicit review. Cold-build coordination is a separate cache-safety design, not a reason to remove immediate source invalidation. No additional code change was made for this measurement request.
