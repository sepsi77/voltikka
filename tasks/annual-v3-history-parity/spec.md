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

## Production plan (prepared, NOT run; each step needs the manager's and the user's approval)

Prerequisites: the code release (Git push to main deploys), and a fresh verified full production
backup as in `../annual-statistics-correction/rollout.md`. Production data is newer than the local
copy, so every step previews first and compares with the expected numbers below; stop on a difference
that is not explained.

1. 2026-10-07, full-date v3 apply (restores spot/open_ended/market_reset, repairs quarterly):
   ```
   php -d memory_limit=512M artisan contracts:rebuild-annual-cost-statistics --date=2026-10-07 \
     --method=annual_cost_as_of_v3 --baseline=annual_cost_as_of_v3
   ```
   Expected (scratch): evidence=1113 available=748 unavailable=365; contracts_matched=447,
   contracts_new=301, aggregates_matched=21, aggregates_new=9. Then the same command with `--apply`:
   persisted=748 aggregates=30. Verify: medians as in `decisions.md`, display regime equal to both
   neighbours, non-target rows unchanged (full-row hashes before/after).
2. 2026-10-01: no apply. The fixed path has no dated snapshot identity for that date.
3. Older history (required by "History follows the current method"): the same command, one date
   per process, sequential, `nice -n 15`, 512 MiB, preview then `--apply`, for 2026-02-13..2026-02-27
   and 2026-07-23..2026-09-21 (76 dates). Expected changes per date: `results/older-history-impact.txt`
   and the per-date JSON method in `tools/`. Retained v1/v2 rows and other dates stay unchanged.
4. Public checks: `/sahkosopimus/tilastot` daily and weekly at 5,000 kWh, CSV for 2026-10-07 and the
   re-applied range.
