# Local pricing preflight: comparison safety

## Status

The local runner and report helper are ready for manager review. Only a synthetic, isolated fixture was evaluated. No export, baseline archive, real-data comparison, network request, LLM call, migration, application command, commit, or push was run in this unit. The active local SQLite file was not opened.

Files:

- `preflight/compare.php`: one selected Laravel tree per fresh PHP process.
- `preflight/report.php`: compares two complete private result files without Laravel.
- `preflight/smoke.php`: builds a small synthetic SQLite fixture with direct SQL, not migrations.

The required baseline is **917de212fdc4ef4862903268c3d33ba5cd886e73**, not HEAD 9770811. The runner records this required SHA, but does not assert that an arbitrary `--app` path is that commit. The manager must retain the exact archive command and check the recorded reflection file hashes against that commit. Do not treat the required-SHA metadata as origin proof.

## Isolation

The runner does not load `bootstrap/app.php`, an HTTP/console kernel, application routes, package discovery, `.env`, or cached configuration. It creates a private temporary directory, selects nonexistent environment/config-cache files, removes inherited environment variables before Composer/Laravel loads, and sets safe local overrides. It reads only four selected-tree pricing configuration files. Logs go to a null handler. There is no Sentry provider or DSN. Cache uses the array store. Queue and both bus dispatcher bindings throw; sync dispatch cannot run a job. The Laravel HTTP factory rejects stray requests. The manager must also use the macOS network-denial sandbox: Laravel HTTP protection alone cannot block arbitrary cURL or sockets in changed code.

Only framework base/database/cache providers are registered. The selected tree's `AppServiceProvider::register()` supplies its actual pricing bindings. Its `boot()` is never called. The deployed and candidate register methods were inspected locally and contain only container bindings. No import, warmer, or service command is called.

### Shared vendor and optimized maps

A vendor symlink alone is NOT safe. This runner does not execute `vendor/autoload.php`. It builds a vendor-only Composer loader from the vendor metadata and removes all App, Database and Tests maps. Non-vendor class-map paths and eager helper files fail closed. Missing optional vendor namespace directories are omitted.

A first-position namespace loader resolves all `App\\` and `Database\\` types to the selected tree. A missing class or a path that escapes the selected tree throws; it cannot fall through to the working tree's optimized map. Vendor helpers load only after this guard is installed. No current application helper file is used in baseline evaluation. Reflection checks the calculator, service, payload schema and contract model before pricing, and records paths and SHA-256 hashes. A final check covers every loaded App/Database class, interface and trait.

Baseline and candidate MUST run as separate fresh PHP processes. Do not include this script in an already-booted application. A shared vendor tree is acceptable only through this loader, with the manager's reviewed locked dependency set; a standalone cloned vendor tree is also acceptable. Do not run Composer installation or discovery for this preflight.

### Database proof

The database must be a private `snapshot.sqlite` in a private directory. The runner rejects the selected tree's active local database and the repository's active local database by device/inode, including hardlink aliases. It rejects SQLite sidecars. No database URL or alternative connection is configured.

PDO opens SQLite with `SQLITE_OPEN_READONLY`. Before pricing, the runner verifies the PDO driver and exact sole `main` file, and proves read-only failure with `UPDATE electricity_contracts SET id=id WHERE 0`. That statement affects no rows even if a defective setup permits it; a permitted statement aborts the run. The expected SQLite error is SQLITE_READONLY (8). It then enables and checks `PRAGMA query_only=ON`. The snapshot SHA-256 must remain unchanged after all work. Output uses exclusive creation, private permissions, and no overwrite.

### Mandatory completed-export manifest

Before Laravel loading or pricing, the runner requires the sibling `manifest.json` from the
exporter. It accepts only `status=complete`, `integrity_check=ok`, and strict boolean
`scope_contract_count_verified=true`. The recorded `files['snapshot.sqlite'].sha256` must match
the actual file. A separate PDO READONLY/query_only connection checks that
`queries.active_contracts.rows` is an integer and equals the snapshot's raw active row count.
A failed or partial export cannot be accepted merely because its SQLite file exists.

The same verified snapshot supplies the expected count of contract records with an active row.
After Eloquent loading, that joined count must equal the loaded collection size before pricing.
The private result records the manifest hash, snapshot hash, raw active count, expected joined
active count and orphan active count separately. Orphans are reported, not silently repaired.
No value or date from `summary.json` is used, so this guard does not depend on its hash.

Synthetic smoke checks reject six invalid cases: missing manifest, failed status, failed
integrity, unverified scope, wrong snapshot hash and wrong raw row count. The valid fixture has
three raw active rows, two joined contracts and one orphan; all three counts are asserted.
This verifies the guard only. It does not establish real export completeness or pricing impact.

## Input contract for exporter and manager

Do not create empty input tables to make production evaluation pass. Export real declared types and JSON bytes/values. Keep active contracts complete, with trusted ancestors, all relevant dated public evidence, and their source/publication pointers.

The runner requires these real tables:

1. `electricity_contracts`
2. `active_contracts`
3. `contract_source_observations`
4. `contract_source_snapshots`
5. `contract_interpretations`
6. `contract_price_snapshots`
7. `price_components`
8. `electricity_futures_eod_prices`
9. `spot_price_averages`
10. **`contract_price_daily_statistics`** — additional input for the market provider's fixed-term median context. Do not omit it even though this context does not gate estimates.

In particular, dated price snapshots and relational component history are needed by the current price-episode resolver. Current canonical JSON alone is not sufficient. Retain futures reference vintages, rolling-365 Spot evidence and the four-year monthly Spot seasonal history. Retain relevant statistics method/basis columns. There is no need to export auth, queue, cache, runtime, hourly Spot prices or retail-premium observation tables for these annual calls. Missing required tables fail; missing referenced columns fail naturally. The exporter owns full schema/type preservation and export completeness checks.

### Verified production flags

The existing `/tmp/voltikka-energy-production-config.json` shape is supported. Its boolean values must be JSON booleans. The runner requires the observed default V4/v19/v17 tuple and enabled canonical pricing. It preserves that default; it never creates V5 interpretations.

**Before a real run, extend the verified flags file with these effective production values:**

- `max_curve_age_days`
- `absurdity_band` with `floor_cents_per_kwh` and `ceiling_cents_per_kwh`
- `vat_multiplier`
- `area`

These were absent from the supplied file. Do not silently substitute local defaults for them. Expected config locations are `canonical_pricing.reset_forward_shift.max_curve_age_days`, `canonical_pricing.reset_forward_shift.absurdity_band`, `price_forecasting.fixed_term.vat_multiplier` and `price_forecasting.fixed_term.area`. The manager must verify them through the separately approved read-only inspection. The runner stops until they are supplied. Existing fields include checked time, application timezone, canonical/reset flags, beta, seasonal settings, annual method and profile tuple. Provider model/API settings are not used or copied into results.

## Manager execution after review

These are instructions, not operations performed by this unit. Use a new private directory and the same immutable exported snapshot/flags/date for both processes. Under the manager's network-denial sandbox:

```sh
# Manager prepares an archive from this exact commit, after script review:
# git archive 917de212fdc4ef4862903268c3d33ba5cd886e73 laravel | tar -x -C "$BASE"
# Add reviewed vendor access; do not install/build/bootstrap the archive.

php tasks/source-validated-energy-rules/preflight/compare.php \
  --app="$BASE/laravel" --database="$PRIVATE/snapshot.sqlite" \
  --output="$PRIVATE/baseline.json" --as-of=2026-09-16 \
  --flags=/tmp/voltikka-energy-production-config.json

php tasks/source-validated-energy-rules/preflight/compare.php \
  --app="$PWD/laravel" --database="$PRIVATE/snapshot.sqlite" \
  --output="$PRIVATE/candidate.json" --as-of=2026-09-16 \
  --flags=/tmp/voltikka-energy-production-config.json

php tasks/source-validated-energy-rules/preflight/report.php \
  --baseline="$PRIVATE/baseline.json" --candidate="$PRIVATE/candidate.json" \
  --output="$PRIVATE/comparison.json"
```

Use the reviewed export date instead of the example date if it changes. Keep the exact baseline origin proof beside the artifacts. Stop on any nonzero exit. A partial output from a failed run is invalid; use a new output path for a retry. The scripts never delete prior files.

## Measurements and interpretation limits

`CanonicalContractPricingService::outcomesForContractsAtConsumptions()` evaluates every active contract, not a top-N subset. Runs are 5,000 cold, the same 5,000 warm, then 2,000 and 18,000 kWh. They use the same service and data collection, basic-living flat usage, a fixed Helsinki midnight start date and a clock frozen at Helsinki noon on that date. Noon prevents the production UTC clock from falling on the previous calendar date. Thus 2,000/18,000 are warm-input runs, not separate cold requests. Cold is process/service cold, not an OS disk-cache claim.

Each engine phase records SQL count/time, wall time and absolute peak memory. Load and audit phases have separate metrics. Overall wall time includes setup and result preparation; engine phase time does not include serialization/ranking. The private result now retains bounded per-query timing and parameterized SQL shapes (at most
256 queries, 65536 bytes per shape). String/numeric literals and SQL comments are removed; bindings
and source prose are never retained. Queries taking at least 100 ms also receive an EXPLAIN QUERY
PLAN on the same PDO READONLY connection, with bindings used only in memory. Plans have a
512-row limit. Loaded application/config file hashes, runner hash and UTC capture time identify
the exact profile. A trusted local PHP caller can supply a diagnostic callback for read-only SQL
replays; this is not a CLI option. Replay callbacks add wall time, so those instrumented wall times
must not be compared as engine latency. See `query-profile.md`. Model results, phase facts, real-term totals, benefits, method/basis/certainty and assumptions are complete except prose/quote/label fields. Generic `excluded_incomplete` can cover several service guards: it is not a more specific parser diagnosis. The audit adds stored calculation status, issue codes and read-side source-proof state without raw source quotes.

The profile audit counts all interpretation rows in the **export scope**, including retained ancestors. Per-active-contract metadata identifies the pointed published profile, source observation, audience and national flag. It is not a claim about unexported production history. Whole-source grammar admission is NOT evaluated independently: no V5 analysis was fabricated. The deployed tree has no `CurrentSourcePromotionEvidence` helper; the baseline audit records that absence and never imports the staged helper. Existing V5 rows in the candidate, if any, receive the service's ordinary exact source proof. V4 source-proof validity does not prove V5 grammar admission or prospective producer accuracy.

The household/national annual-cost order is **RECOMPUTED engine order**, with cost then ID tie-breaking. It is not observed live cached ranking. Public cache, consumption limits, integrity and listing guards can differ. All active audiences remain in the full rows, including regional and company-only rows.

The helper requires identical snapshot, flags, date, pricing config and contract universe.
Index/statistics diagnostic copies are not full pricing comparison inputs. Their different file
hashes remain rejected by report.php. Do not replace an original manifest hash to bypass that guard.
Diagnostic SQL comparisons must identify each variant and verify all business-table row digests;
query result multisets must match too, because equal-date ORDER BY ties can change row order. It checks cold/warm equality. `changed_raw_total_count` is separate from `changed_displayed_total_count`, which compares totals rounded to two decimals. Rows retain raw and displayed euro deltas. Benefit changes compare only top-level savings, eligibility and nested real-term savings. Other real-term total/metadata changes have their own count and retain the full `changed_fields`; they are not benefit changes. It writes all changed rows, counts/exclusions, changed total/benefit fields, rank movements, comparability/assumption reasons, absolute median/max deltas over all paired numeric totals, and at most 15 largest examples per phase. The original two artifacts retain every unchanged row too. This measures staged-code recalculation of existing stored facts only. **Unknown V5 re-analysis impact remains separate.**

## Verification

- `php tasks/source-validated-energy-rules/preflight/smoke.php`: PASS after fixture corrections. Four known exact totals (560/560/260/1860 EUR), one excluded contract, SQLite read-only proof, no source-label leak, cold/warm equality, identical-report and changed-total checks. Queue/bus bindings also reject access, and the vendor class map contains no App/Database entries.
- `php -l` on compare.php, report.php and smoke.php: all passed.
- `laravel/vendor/bin/pint` on the three scripts: passed after formatting.
- `git diff --check`: passed at the final script gate. Final status was reviewed; unrelated work was preserved.

Initial smoke failures were fixture setup errors (wrong repository depth, unsupported `billed` role/boundary key, strict integer-vs-float assertion). The vendor-only loader also first rejected two absent optional vendor namespace directories; it now omits only nonexistent directories and still rejects existing non-vendor mappings. A separate local SQLite experiment showed that BEGIN IMMEDIATE can succeed on a read-only handle, so it is not used as read-only proof. The zero-row UPDATE probe correctly fails with SQLite error 8.

No application test suite or build was run: application code and CSS/JS were unchanged. This small fixture does not verify real-data projections, baseline compatibility, performance at production scale, or live release safety. Those checks remain with the manager after review/export.
