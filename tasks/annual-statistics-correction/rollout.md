# Annual statistics correction rollout

## Approval boundary
This is a plan, not permission to run commands. No production CLI command was run for this work. Exact Railway project, environment, service, execution path, dates, and backup procedure must be stated and approved later. Do not use an implicit linked Railway context.

All dates below are operator-reviewed real historical dates before the Helsinki execution date. Replace placeholders with exact dates. Do not use a future date or change the application clock to make a date eligible.

## 1. Before release
- Review the integrated tests and diff. Preserve the pricing and About fixes already in the working tree.
- Obtain explicit approval for a full database backup. Confirm that the backup completed and that the restore procedure and credentials are available without printing secrets.
- Record the active public method, the last v1 annual date, row counts, aggregate medians, contract-consumption coverage, and source-table counts.
- Plan code release after the last v1 date has ended in Helsinki. Avoid a same-day overwrite of that date. Current snapshots are unversioned: replacement can leave old v1 provenance snapshot IDs unresolved, and company date/contract joins can lose identities excluded by the new collection. Old annual rows alone do not replace a database backup.

## 2. Release code without activating v2
- Keep `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_as_of_v1` unchanged.
- Get separate release approval. Deployment must not invoke a history rebuild or change the active method.
- New current canonical collection writes v2 only. Unit statistics continue to advance. Public annual readers still use retained v1 and show its older date during this brief staging period.
- Check current v2 coverage before historical application or activation. Check available and unavailable identities at 2,000, 5,000, and 18,000 kWh, all relevant segments, method/basis flags, source provenance, and same-date aggregate totals.

## 3. Preview exact historical dates
Planned Artisan commands only; the approved execution context is still required:

```sh
php artisan contracts:rebuild-annual-cost-statistics --date=<YYYY-MM-DD> --method=annual_cost_as_of_v2 --baseline=annual_cost_as_of_v1
php artisan contracts:rebuild-annual-cost-statistics --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> --method=annual_cost_as_of_v2 --baseline=annual_cost_as_of_v1 --stop-on-error
```

For diagnostic subsets only:

```sh
php artisan contracts:rebuild-annual-cost-statistics --date=<YYYY-MM-DD> --method=annual_cost_as_of_v2 --baseline=annual_cost_as_of_v1 --contract=<ID> --limit=10
```

- Save the bounded per-date output and totals for review. Check matched aggregate median changes and new/lost aggregates. Check matched/new/lost contract-consumption coverage, unavailable reasons, and method/basis flags.
- Investigate missing baselines explicitly. A missing baseline is not a zero-cost baseline. Historical AsOf unavailable rows were not stored, so unavailable baseline counts can be unknown.
- A partial preview uses one sorted contract universe for both sides. It does not compare a partial median with a full-market median.
- Review large movements and evidence changes for Spot, reset, supplier-adjusted, short-term, Hybrid, package, and VAT cases. Do not treat recalculated v1 as the original stored v1 mathematics.

## 4. Apply only reviewed full dates
Obtain explicit operator approval for the exact date range and expected row changes. Take and verify the approved full backup before writes.

```sh
php artisan contracts:rebuild-annual-cost-statistics --date=<YYYY-MM-DD> --method=annual_cost_as_of_v2 --baseline=annual_cost_as_of_v1 --apply --stop-on-error
```

- No `--contract` or `--limit` on apply. Each complete date is transactional. Empty or incomplete current evidence must fail rather than clear retained results.
- The command compares stored baseline values before writing. An interrupted range can contain completed dates; inspect the output before a reviewed rerun.
- Verify v1 annual rows and v1 aggregates are unchanged. Verify raw seller components, snapshots, source observations, interpretations, actual Spot inputs, and market inputs are unchanged by the rebuild.
- Verify v2 row counts, complete per-contract reference levels, aggregate counts, and numerical results against the preview. Investigate every unavailable or lost identity before activation.

## 5. Activate only after a separate approval
- Request explicit approval to change `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION` to `annual_cost_as_of_v2`, with exact Railway context and expected public effect.
- Check the statistics page, annual charts and gaps, dated labels, CSV method/basis fields, homepage trend, editorial charts and insight summaries, company comparisons, and consumption calculator.
- Confirm unit panels and the seller-set index retain their original metric rules. Confirm annual readers do not mix v1 and v2. Check same-day and older endpoint cases, sample floors, and wrong-basis exclusions.

## 6. Rollback
- A separately approved active-method switch back to `annual_cost_as_of_v1` restores retained stored v1 selection. It does not resume old current mathematics.
- New current collection still writes v2. Public v1 annual figures therefore remain dated old history, while unit figures can be newer. Do not call retained v1 today's price.
- Verify dated annual copy, charts, CSV, company comparisons, and calculator after switchback. Do not overwrite the last v1 snapshots to make the date look current.
- A source/snapshot recovery requires the approved full database backup and a separate restore decision. Never assume that an annual-method switch repairs unversioned snapshot evidence.
