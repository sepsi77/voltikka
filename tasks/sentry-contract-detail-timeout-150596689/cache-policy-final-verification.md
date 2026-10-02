# Final local retained-cache verification — 2026-10-01

## Result

The settled local implementation and regression gate pass. No commit, push, deployment, production producer invocation, production database write, statistics method switch or history rebuild occurred in this task. Production timeout elimination is not proved.

## Final gates

- `cd laravel && php artisan test`: **2,953 passed, 24,174 assertions**, 141.82 seconds. Log: `/tmp/pi-bg/job-57171-58.log`. This ran after all implementation agents finished. It supersedes the failed integration checkpoints, which remain recorded as historical evidence.
- `npm run test:js`: **12 passed**, no failures or skips; `npm run build`: passed. Log: `/tmp/pi-bg/job-57171-56.log`. The old Browserslist dataset warning remains; no dependency update was made.
- Changed-file `vendor/bin/pint --test`: **70 PHP files passed**. Log: `/tmp/pi-bg/job-57171-59.log`. `git diff --check` passed separately afterwards.
- Context mirror audit: **50 pairs match**. The audit found a pre-existing missing `laravel/app/Services/SpotForecasts/CLAUDE.md`; added only its `AGENTS.md` symlink. No SpotForecasts content or behavior changed.
- Final worktree status retains the unrelated `tasks/annual-statistics-history-continuity/` files. Nothing was staged or committed.

## Accepted review repairs

Manager inspected the actual core/caller/page diffs, not only agent reports:

- Demand revision is floored at the active satisfied revision through a direct descriptor read. Losing demand metadata cannot hide the next invalidation. An unmet candidate with lost proof cannot promote.
- New cache metadata includes effective reset/cadence/base-effect facts through four bounded SQL scalar projections. Changed mechanisms cannot relabel retained financial facts. Dates/prices alone keep old verified prices. Older absent/partial metadata has an explicit compatibility limit.
- Exact custom preparation accepts 1–150,000 integer kWh. New custom payloads have 30-minute TTL and a 64-live-profile limit per active generation. Admission/write use the transition lock; expired owned keys are physically removed with failure-safe ownership. Preset/company prices remain retained forever until replacement. Custom company results use request memos, not persistent wrappers.
- Calculator results are locked server state. Raw bounds are checked before permission, pricing, analytics or redirect. Expected unavailability shows a Finnish alert; unknown/storage/conflict failures are not swallowed. Valid **7,312 kWh** remains exact in both pricing modes.
- Detail guard placeholders stay unavailable on GET/HEAD but can reach the explicit-action evaluator. Genuine cached financial exclusions remain excluded without recalculation.

Core focused gate: 85 tests / 693 assertions. Protected-action gate: 45 / 286. Detail/policy follow-up: 156 / 959. These overlap the full suite and must not be added to its count. SQLite execution and MySQL SQL compilation passed; live MySQL execution was not tested.

## Final full-kernel city checks

Fresh isolated cache, explicit private producer before request timing, SQLite `mode=ro` plus `query_only=1`, denied Laravel stray HTTP and PHP URL access, disabled Sentry/network logs. September 22 frozen comparison date and the older 378-contract local snapshot; PHP 8.5.11. This is not production PHP 8.4/MySQL or a browser test.

| Page | HTTP | Handle + terminate | SQL queries | Local cards |
|---|---|---|---|---|
| Lapinjarvi | 200 | 0.210 s | 33 | 58 |
| Mikkeli | 200 | 0.082 s | 16 | 8 |

Both have zero tracked annual calculation, premium, episode, timeline or anchor calls, zero futures/premium/interpretation/immutable source-snapshot SQL, and no recorded request errors. All local cards remain rendered.

Final visible-text euro/c/kWh extraction, excluding script/style and accepting both integer and decimal prices, matches earlier captured values in exact order: **509 Lapinjarvi strings and 217 Mikkeli strings**. This broader extraction differs from the earlier 266/112-string extraction; it is not a full-HTML or full-column provenance comparison.

Artifacts: `/tmp/voltikka-retained-final-network-denied/{lapinjarvi,mikkeli}.{json,html}`. Log: `/tmp/pi-bg/job-57171-64.log`. Original captures are listed in `cache-policy-city-get-check.md`. Framework PHP 8.5 PDO-constant deprecation warnings appeared at bootstrap; requests still passed.

## Final detail/API and unusual-profile snapshot checks

The same isolated read-only, network-denied harness also served the real local `next4d-aalto-energia-oyj-aalto-kuukausihinta` detail and API routes from prepared prices:

- Detail GET: HTTP 200, 0.610 s, 49 queries; zero tracked annual/donor preparation. Eleven queries are bounded source/publication identity joins used by prepared-page/history cache keys, not interpretation output, immutable snapshot payloads or financial donor preparation. No futures, premium or immutable source-snapshot reads occurred. Do not claim that detail has zero interpretation-table references.
- API GET at 5,000 kWh: HTTP 200, 0.032 s, four queries; available canonical pricing and a finite cached annual total, zero annual/donor work.
- A temporary local simulated explicit POST permission prepared **7,312 kWh** on the real snapshot before instrumentation reset. Destination `/sahkosopimus?consumption=7312` GET: HTTP 200, 0.093 s, seven queries, exact selected consumption visible, zero annual/donor work. A fresh-process API GET at 7,312 kWh: HTTP 200, 0.036 s, four queries, available cached canonical total, zero annual/donor work.

These are programmatic HTTP-kernel checks, not browser clicks. Actual locked-result/Livewire compare-action behavior and both pricing modes are covered by the passing regression tests. Artifacts: `/tmp/voltikka-retained-final-network-denied/{detail,api,custom,custom-api}.{json,html}` and its separate `request.php`. Logs: `/tmp/pi-bg/job-57171-69.log` and `job-57171-72.log`.

## Remaining production limits

Production request-level timeout correlation and the first earlier unexplained concurrent local HTTP 500s remain unresolved. Confirm replica/store/scheduler topology before release. A new per-instance scheduled producer needs explicit deployment approval. Any first manual production producer needs separate exact-context approval. Verify actual retained serving, complete eventual promotion, custom handoff and crawler CPU/timeouts after approved deployment. Local success is not a production fix claim.
