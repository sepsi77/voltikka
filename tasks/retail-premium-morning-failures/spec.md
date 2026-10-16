# Retail premium morning collection failures

## Problem

Production `data_freshness_checkpoints` key `morning_retail` shows status `failed`, reason
`execution_failed`, exception class `RuntimeException` for the scheduled
`retail-premiums:collect --scheduled` run. The manager reported 2026-09-30, 2026-10-06 and
2026-10-10. A read-only production check shows the failure on **every day whose dependencies were
ready since scheduled mode started on 2026-09-21** (19 days through 2026-10-16). The other days
(09-28, 10-01, 10-02, 10-07, 10-08, 10-09, 10-13) were `waiting` until the noon deadline. The
scheduled collector has never completed.

## Goals

1. Find the exact RuntimeException (message, stack location) from production logs, read-only, and
   reproduce it locally on a copy of `laravel/database/database.sqlite`.
2. Find the root cause and fix it with the smallest correct change. Keep immutable rows,
   `method_version` identity and freshness gates.
3. Add tests that reproduce the failure and pass after the fix.
4. Recommend whether and how the missing days can be backfilled. Do not run it in production.
5. Update `laravel/app/Services/RetailPremium/AGENTS.md`.

## Constraints

- No commit, push or production write. Read-only production inspection only.
- Do not edit `ElectricityFutures/`, `FetchEexFutures`, `MorningJobFreshnessService` proof rules or
  `ContractStatistics/`. Do not edit root `AGENTS.md` (report suggested text).

## Acceptance

Focused tests pass (`RetailPremium*`, `MorningConsumerExecution*`, `MorningJobFreshness*`),
`./vendor/bin/pint --test --dirty` for own files, `git diff --check`.
