# Historical v2 apply plan — user approved

The user approved the reviewed historical application after the Voltikka status update. Execution remains gated by the checks below. Public activation is excluded.

## Exact production target
- Project: Voltikka — `6d8cae01-1006-409f-8108-1d51f1abc676`
- Environment: production — `9245cef8-41d0-486e-862f-193726511dba`
- App service: voltikka — `700d0624-fa96-4266-876c-e37640d220ea`
- Required code: `f6518498c2b7598c87bd79e62855c18afba63c16`, calculated-cost schema 17, pricing mode c1r1, public annual method v1.
- Execution: Railway SSH with the explicit IDs above, working directory `/app`. A guarded PHP process will invoke the existing Artisan command with the arguments below. No production code file is changed.

## Preconditions
- Obtain affirmative approval for this plan and confirm that the previously verified full database backup and restore access remain available. The agent has not independently verified a backup archive.
- Verify current v2 reference-level totals, aggregate medians/counts, and complete matching source provenance after current-statistics refresh. No manual current refresh is included in this approval.
- Capture retained v1 annual/aggregate counts and stable content fingerprints before writes. Check that no unexpected historical v2 rows already exist.
- Preserve the September 11 v1 endpoint and its snapshots. Do not invoke the current-state calculator for any past date.

## Exact command and date batches
For each row below, sequentially, invoke:

```sh
php artisan contracts:rebuild-annual-cost-statistics \
  --from="$FROM" --to="$TO" \
  --method=annual_cost_as_of_v2 \
  --baseline=annual_cost_as_of_v1 \
  --apply --stop-on-error
```

| FROM | TO | Evidence dates | Expected available annual rows | Expected aggregates |
|---|---|---:|---:|---:|
| 2026-01-21 | 2026-01-31 | 11 | 10,723 | 396 |
| 2026-02-01 | 2026-02-28 | 27 | 26,281 | 945 |
| 2026-03-01 | 2026-03-31 | 31 | 31,042 | 1,116 |
| 2026-04-01 | 2026-04-30 | 30 | 28,972 | 972 |
| 2026-05-01 | 2026-05-31 | 31 | 28,538 | 1,015 |
| 2026-06-01 | 2026-06-30 | 30 | 27,093 | 990 |
| 2026-07-01 | 2026-07-31 | 31 | 27,188 | 951 |
| 2026-08-01 | 2026-08-31 | 31 | 25,212 | 903 |
| 2026-09-01 | 2026-09-11 | 11 | 8,706 | 330 |
| Total | | 233 | 213,755 | 7,618 |

February 12 has no selected evidence date. There is no contract filter or limit.

## Write limits and verification
- Each batch is bounded to 1,200 seconds and 256 MiB, as in the preview. A failed batch stops later batches. Inspect completed dates before any resumption; complete dates can already be committed.
- Use the existing calculator and writer. Check each recalculated date against reviewed identity counts/loss sets, aggregate counts/medians, and estimate-method counts before allowing its write. A mismatch stops the run for review; do not silently accept changed inputs.
- The historical command may write only the selected v2 annual rows in `contract_price_annual_costs` and selected v2 annual aggregates in `contract_price_daily_statistics`. A connection-level SQL write guard should reject writes to other tables. Do not change stored v1, raw observations, price components, historical snapshots, interpretations, actual Spot inputs, or market inputs.
- The writer owns its existing full-date transaction; do not wrap a whole month in an outer write transaction.
- Verify stored counts, consumption identity coverage, medians, method/basis fields, and provenance after each batch. Compare retained v1 content fingerprints after the run. Concurrent scheduled current collection is separate and must not be mistaken for a historical write.
- Public method remains `annual_cost_as_of_v1`. The completed historical write will create candidate data, not activate public v2 readers. Activation needs a separate exact approval and final current-date check.
