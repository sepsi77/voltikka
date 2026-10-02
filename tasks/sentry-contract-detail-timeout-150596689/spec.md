# Contract detail timeout — Sentry 150596689

Investigate the reported 30-second PHP timeout on a contract detail GET. The supplied breadcrumbs show a cold 2,000 kWh shared market-metrics build. PHP stopped in PremiumObservation::evidenceKey; this does not prove that one JSON call caused the timeout.

Measure local cold calculation costs. Use the smallest performance correction that keeps exact pricing, evidence selection, provenance, conflict rules and cache safety unchanged. Add regression tests and record timing limits. No production mutation, commit or push is authorized. Preserve unrelated existing worktree changes.

## Bounded implementation status

Implemented build-local anniversary and billing-duration reuse in PhaseTimelineBuilder and immutable fraction reuse in WindowSegment. All canonical pricing unit tests and the relevant bill-comparison feature tests pass (316 tests / 6,574 assertions); focused Pint passes. See implementation.md. Manager full-market exact parity and three paired timing runs are complete at both consumptions; see verification.md. Production timeout cause and invalidation writer remain unproved.

## Approved retained-cache policy

The later approved policy in `cache-policy-spec.md` supersedes the earlier immediate-invalidation requirement. The producer/lifecycle stage is complete locally; see `cache-policy-producer.md`. Invalidation retains the exact active pointer and records durable demand. Background producers build and verify complete replacements. Pure GETs, including custom query strings, must become cache-only in the separate consumer stage; explicit user actions may still calculate exact custom consumption. The shared cache read boundary is now implemented; see `cache-policy-reads.md`. Page/action integration and the combined regression gate remain separate. The overall policy is incomplete until that consumer stage is complete.

## City-page scope extension

The user supplied Sentry 150173902 and 143155060: Lapinjarvi and Mikkeli city-page timeouts at 2026-09-30 23:09:07/23:09:10 UTC, on the same server and crawler. This is 02:09 Helsinki, before the morning contract import. Breadcrumbs show curve-reference queries and premium/episode preparation, not merely a single-contract detail calculation. The user approved local full-request measurement, including local-contract sections, empty caches and concurrent requests. Use isolated temporary caches and read-only local application data; do not change production traffic or data. Record wall/SQL/stage measurements and response outcomes without treating inclusive stage times as additive.
