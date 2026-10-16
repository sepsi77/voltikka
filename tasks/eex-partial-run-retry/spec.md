# EEX partial-run retry and complete FI proof

## Problem
`futures:fetch-eex` runs once at 04:00 Europe/Helsinki and takes about 3.5 hours (about 15 s
polite throttle per EEX call). It has no in-run retry. One transient connection failure makes the
same-day `eex_futures` checkpoint `failed`, and the morning forecast and retail-premium consumers
then wait until their 12:00 deadline and write nothing.

Production evidence (`/app/storage/logs/eex-futures-fetch.log`, read-only):
- 2026-10-07: `Failed to discover month maturities using NP FBBM: ConnectionException`, so the run
  made 468 instead of 793 requests (no month rows for any area), plus
  `Failed to fetch SE4 quarter 4SBQ 202701: ConnectionException`. Failures 2, 5294 points.
- 2026-10-09: `Failed to fetch NO3 year 3NBY 202801: ConnectionException`. Failures 1, 8180 points.
- Every failure is a connection failure that survived the HTTP client's own 3 attempts with 1 s
  sleep. Failures are counted per unit: one per failed tenor discovery and one per failed
  market/maturity EOD request.

## Requirements
1. After the main pass, retry only the failed units (a tenor discovery, or a market/maturity EOD
   request) with the same throttle, after a short pause, for a bounded number of passes, before
   the final checkpoint write.
2. A recovered run is `ready` and reports no import Issue. A persistent failure keeps the existing
   behaviour: `failed` checkpoint and one aggregate Issue.
3. Keep UUID checkpoint ownership, upsert idempotency, and "empty out-of-range maturities are
   normal".
4. The current-run prior-date FI Base proof must cover the month, quarter and year tenors, not
   just any row. A run with no month rows and no counted failure must not become `ready`.
5. Tests with HTTP fakes; update ElectricityFutures and Laravel context files.

## Out of scope
New scheduler entries, root AGENTS.md (suggested text goes to the manager), RetailPremium and
ContractStatistics code, production mutations.
