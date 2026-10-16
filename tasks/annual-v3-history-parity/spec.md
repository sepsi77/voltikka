# Annual v3 history parity (historical path vs current writer)

## Problem

Production public annual method is `annual_cost_as_of_v3`. Dates up to 2026-09-21 were written by the
historical v3 reconstruction (`contracts:rebuild-annual-cost-statistics`). From 2026-09-22 the current
canonical daily writer writes v3. A historical preview of 2026-10-06/07/08 differs from the
current-writer rows by up to ±295 EUR in segment medians. Known causes:

- (a) open-ended supplier-adjusted contracts get no premium rung in the historical path;
- (b) 11 quarterly contracts fail exact-target validation and fall to relational hold-flat.

A future rebuild (required at the next method change) would put these jumps into the public series.
2026-10-01 (no data) and 2026-10-07 (spot/open_ended/market_reset rows removed) cannot be filled safely.

## Steps

1. Read-only comparison tool: for every current-writer date 2026-09-22..2026-10-15, compute the
   historical v3 result in memory and compare per contract and consumption with stored v3 rows
   (availability, segment, estimate method, annual cost). Classified report. Exclude 2026-10-07.
2. Fix the historical v3 path to match the current writer, except for documented date-safety
   differences. No change to the current writer, v1/v2 or code defaults.
3. Impact on older history (all stored v3 dates before 2026-09-22, three consumptions).
4. Plan (not run) for filling 2026-10-01 and 2026-10-07; preview both on a scratch copy.
5. Tests and docs.

## Constraints

- Local production copy is read-only; writes only on a scratch copy.
- No production access, commit or push. Root AGENTS.md is not edited (suggested text in report).
- Do not edit EEX fetcher or `app/Services/RetailPremium/` (other agents).
- No later seller facts, no current pointers, no future curves in historical reconstruction.

## Acceptance

Parity report before/after with remaining documented differences; focused tests pass; Pint and
`git diff --check` pass.

## Status (2026-10-16) — local work complete, not committed by this executor, not deployed

Fixes, numbers and remaining differences: `decisions.md`. Raw outputs: `results/`. Tools: `tools/`.

## Production plan with dated replacement lineage (prepared, NOT run; needs approval)

Prerequisites: explicit Git push approval (a push to main deploys; `docker-entrypoint.sh` runs
`php artisan migrate --force` at container start, so migration `2026_10_16_000001` runs during the
deploy outage: about 1.5 s for 4,083 links on the local copy), then a fresh verified full backup.

Commands (one `nice -n 15` process per range, memory_limit 512M; locally 129 MB max RSS for 205 dates
in one process; production about 16 s/date, so about 59 min for 221 dates):
```
php -d memory_limit=512M artisan contracts:rebuild-annual-cost-statistics --from=2026-02-13 --to=2026-02-27 \
  --method=annual_cost_as_of_v3 --baseline=annual_cost_as_of_v3            # preview first
php -d memory_limit=512M artisan contracts:rebuild-annual-cost-statistics --from=2026-02-13 --to=2026-02-27 \
  --method=annual_cost_as_of_v3 --baseline=annual_cost_as_of_v3 --apply --stop-on-error
# same two steps for --from=2026-03-01 --to=2026-09-21, then for --date=2026-10-07
```
Verify each date against `results/reapply-expected-totals.csv` (evidence, available, unavailable,
persisted, aggregates). Production data is newer than the local copy; stop and inspect any difference.
Do NOT touch: 2026-01-21..2026-02-11, 2026-02-28 (unchanged), 2026-09-22..2026-10-16 except 2026-10-07
(current-writer rows), 2026-10-01 (no snapshot identity; stays empty).
Before/after: full-row hashes of non-v3 rows, v3 rows on untouched dates, snapshots. Then check
`/sahkosopimus/tilastot` daily and weekly at 2,000/5,000/18,000 kWh and the CSV.
