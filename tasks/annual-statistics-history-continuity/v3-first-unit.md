# First v3 unit — local verification, 2026-09-21

Status: partial implementation, not ready for release. User approval permits first-valid later
interpretation of the EXACT dated source, with explicit retrospective provenance. It does not
permit later seller facts/prices, current peers, or future curves. Missing interpretations remain
unresolved. The chronology decision is closed; as-of premium and anchor integration remains open.

## Implemented

- Explicit v3 enum and method-aware batch/single-date resolver; v1 defaults and v2 stay strict.
- Latest timely valid output wins. Otherwise first valid later exact-source output wins.
  Selected-second ties, invalid output, wrong episode, source ambiguity, and ownership failure
  stay closed. No later different source or current canonical pointer is substituted.
- Typed `SourceInterpretationProvenance` carries covering/source/analysis IDs, target date,
  UTC completion, retrospective status, and legacy-null-binding status into result/writer JSON.
  NULL analysis observation binding does not prove episode completion.
- All v2 audience, consumption-proof, energy and uniform-monthly fallback checks protect v3.
- Existing command permits explicit v3 preview/apply and warns that integration is partial.
  Defaults/current producer/public method stay v2. Writer replacement stays method-scoped.
- No estimator, anchor or premium loader changes. No automatic rebuild or schedule.

## Isolated July 23 preview

Created `/tmp/annual-v3-july23-preview.sqlite` with SQLite backup from the fresh local database,
opened with `mode=ro`. No production access. Only the isolated copy was used for the command:

```sh
cd laravel
APP_ENV=local APP_CONFIG_CACHE=/tmp/annual-v3-no-config.php \
DB_CONNECTION=sqlite DB_DATABASE=/tmp/annual-v3-july23-preview.sqlite DB_URL= \
CACHE_STORE=array LOG_CHANNEL=single php -d error_reporting=22527 artisan \
contracts:rebuild-annual-cost-statistics --date=2026-07-23 \
--method=annual_cost_as_of_v3 --baseline=annual_cost_as_of_v2
```

Exit 0. One date, zero failed dates; 1,317 evidence identities, 864 available, 453 unavailable.
All 30 aggregates matched; none new or lost. Contract-consumption pairs: 864 matched,
29 lost, zero new. Matched median deltas: approximately 0 through +238.99 EUR.
Unavailable reasons: 36 canonical not listed, 30 consumption out of range, six legacy mask,
381 missing historical snapshot identity. Output counts for estimate/calculation basis include
unavailable results; they are not contributor counts. No full-history or gap-free claim follows.

Full isolated-copy SHA-256 before/after matched (`shasum -a 256 -c` returned OK).
Raw command output: `/tmp/annual-v3-preview.log`. No apply was run on this copy or the active file.
Tests use forced SQLite `:memory:`; only test fixtures exercise v3 writes.

## Verification

Focused suite covers calculator, dedicated historical integration, writer, rebuild command,
annual persistence, current statistics integration and current AsOf parity. It tests delayed
exact-source selection, timely preference, first-valid ordering, legacy/null and explicit binding,
missing/invalid/wrong-episode/tied output, source ownership/ambiguity, batched dates/query count,
v2 invariance, typed/stored provenance, v2 safety parity, and v1/v2 stored-row preservation.
Final command and result are recorded in `decisions.md`.

Full v3 still needs as-of premium/anchor integration, future-leakage tests for those changes,
full-history preview and review of lost contributors/median changes. No release, public switch,
production mutation, commit, push, or deployment is part of this unit.
