# Decisions

- Work is local only. Existing release task changes are outside scope.
- Prefer conservative inspection over retry after an uncertain writer interruption.
- Reuse the existing checkpoint table with `morning_retail` and `morning_forecast` effective-date keys. One small domain helper owns row-locked UUID claims and outcome/deadline facts. No migration, queue, generic engine, nested Artisan call, configuration graph or producer retry was added.
- Non-dry manual commands share the same effective-date exclusion, including partial selections and historical retail mode. A successful manual run does not certify scheduled scope. Scheduled completion blocks later same-date writes. A missing manual freshness prerequisite remains waiting and retains its failure exit/output; it does not permanently consume the day.
- Running claims do not expire. Unknown interrupted output requires inspection, not automatic takeover. Per-row output transactions and forecast statistics recovery check the owner. Scheduled write fences reject an old writer after Helsinki midnight.
- Scheduled mode validates all scope options before claiming. Background five-minute ticks are independent and have no overlap mutex, so the noon tick can alert even during a live writer. Their time filter includes the full noon minute; process startup seconds cannot suppress that final tick. Preflight that crosses noon cannot start output or send a false execution-error alert. Waiting returns success without warning/error logs. Recorded execution failure returns success to the scheduler because its one safe aggregate log owns the alert. A checkpoint outage returns failure and remains visible.
- Forecast generation rejects any pre-existing issue-date rows before recovery or building, rather than certify an old model or partial write. Retail observations span days and can be reused only when their current financial/basis/method fields match at storage precision and actual persistence/date extension is verified. No automatic overwrite is added. A sole forecast statistics-publication-order failure still permits recovery, with model rejection before its writes.
- Full producer start facts now say `running`. EEX start/final writes share UUID ownership under a row lock; confirmed unexpected exceptions record owned failure in both producers. Scoped/dry EEX runs remain outside global checkpoint writes. Contract pending-completion proof is unchanged.
- Alert facts commit before Laravel log emission. This gives one emission attempt under normal repeated ticks, not transactional log delivery. A crash between commit and logging can lose the message; an unavailable checkpoint store cannot deduplicate failures. No outbox was introduced.
- Exclusion covers each command's effective date, not arbitrary cross-date repairs or unrelated direct statistics writers. Source data is not frozen. Tests use local SQLite; live InnoDB blocking was not tested. These are explicit implementation limits, not deployment approval.

## Verification history

- Initial four-suite regression: 98 passed, 826 assertions.
- Expanded first pass: 3 failed, 174 passed. Fixed a repeated manual model-rejection path, SQLite date-cast duplicate insertion, and the schedule test's registration-time clock setup.
- Expanded second pass: 177 passed, 1,624 assertions.
- After deadline/failure/midnight coverage and Pint: 180 passed, 1,630 assertions.
- Full PHP suite at that point: 2,836 passed, 21,371 assertions, 141.99 seconds.
- Further preflight/start-transaction refinements: 182 focused tests passed and 2,838 full-suite tests passed.
- Added noon-second and preflight-boundary checks. One new test first failed because its Mockery class reference lacked a namespace; corrected it before the final runs below.

## Verification before manager review

All commands ran locally. No production command, commit, push, deployment, migration or dependency change was performed. The four pre-existing `source-validated-energy-rules` task changes were not edited.

From `laravel/`:

```sh
php artisan test --filter='MorningConsumerExecutionTest|MorningJobFreshnessGateTest|FetchEexFuturesCommandTest|FetchContractsCommandTest|DeferredContractImportCompletionTest|RetailPremiumCollectionTest|InferredRetailPremiumCollectionTest|RetailPremiumHistoryBackfillTest|HistoricalChangeForecastTest|FixedContractPriceForecastingTest'
```

Result: **183 passed, 1,644 assertions, 18.22 seconds**. This covers late readiness and once-per-day completion, independent/manual exclusion, interrupted and stale claims, quiet waiting, zero-output and partial-write failure, noon alerts during running work, deadline deduplication, current-date/DST/midnight bounds, scope rejection, dry-run immutability, existing output compatibility, statistics recovery/model rejection, and full/scoped producer behavior.

```sh
php artisan test
```

Final code result: **2,839 passed, 21,385 assertions, 141.45 seconds**. No failed or skipped tests were reported in the summary. Log: `/tmp/bounded-morning-full-tests-final.log`.

```sh
vendor/bin/pint --test app/Console/Commands/CollectRetailPremiumObservations.php app/Console/Commands/RunFixedContractPriceForecasts.php app/Console/Commands/FetchContracts.php app/Console/Commands/FetchEexFutures.php app/Models/DataFreshnessCheckpoint.php app/Services/ContractImport/ContractImportCompletion.php app/Services/MorningFreshness/MorningConsumerExecution.php routes/console.php tests/Feature/MorningConsumerExecutionTest.php tests/Feature/MorningJobFreshnessGateTest.php tests/Feature/FetchEexFuturesCommandTest.php tests/Feature/FetchContractsCommandTest.php tests/Feature/DeferredContractImportCompletionTest.php tests/Feature/RetailPremiumCollectionTest.php
```

Result: **passed** for all 14 changed PHP files. Pint was also used to format these files before the final tests.

From the repository root:

```sh
git diff --check
for d in . laravel laravel/app/Services/ContractImport laravel/app/Services/ElectricityFutures laravel/app/Services/MorningFreshness laravel/app/Services/PriceForecasting laravel/app/Services/RetailPremium; do cmp "$d/AGENTS.md" "$d/CLAUDE.md" || exit 1; done
git status --short
```

Results: no whitespace errors; all seven changed context mirrors match through their existing symlinks. Final diff and status reviewed. No CSS or JS changed, so no asset build was required.

## Manager review corrections

- Review found that the new compatibility guard rejected same-episode republication solely because the published interpretation ID changed. The guard now permits only that incidental field difference when the exact observation key, source episode, source snapshot and price signature match. All other source/economic comparisons remain. Stored original interpretation provenance is not rewritten. A new test proves scheduled date extension and original-ID retention; economic mismatch coverage remains.
- Review found that invalid manual options could enter a durable claim before validation and permanently block corrected calls. Retail historical flags/groups/date ranges and forecast date/horizon/selections now resolve before claims, recovery and output. Execution receives the resolved dates/range rather than parsing again. Manual model rejection stays before claims. Invalid arguments return `INVALID`, including unparseable dates and dry-run arguments, without checkpoint writes.
- Historical default start still reads the minimum price-component date; default end remains yesterday. Existing historical tests remain unchanged. Scheduled scope restrictions, freshness gates, ownership and terminal execution failure rules remain unchanged.
- First correction regression: 88 tests passed, 828 assertions, 2.52 seconds (`MorningJobFreshnessGateTest|RetailPremiumCollectionTest|RetailPremiumHistoryBackfillTest|HistoricalChangeForecastTest|FixedContractPriceForecastingTest`).

### Verification after review corrections

All commands ran locally after both fixes. No production command, commit, push or deployment occurred. The unrelated release-task changes remain untouched.

```sh
cd laravel
php artisan test --filter='MorningConsumerExecutionTest|MorningJobFreshnessGateTest|FetchEexFuturesCommandTest|FetchContractsCommandTest|DeferredContractImportCompletionTest|RetailPremiumCollectionTest|InferredRetailPremiumCollectionTest|RetailPremiumHistoryBackfillTest|HistoricalChangeForecastTest|FixedContractPriceForecastingTest'
php artisan test
vendor/bin/pint --test app/Console/Commands/CollectRetailPremiumObservations.php app/Console/Commands/RunFixedContractPriceForecasts.php tests/Feature/MorningJobFreshnessGateTest.php tests/Feature/RetailPremiumCollectionTest.php
```

- Focused suites: **185 passed, 1,686 assertions, 18.07 seconds** (`/tmp/morning-review-expanded.log`).
- Full suite: **2,841 passed, 21,427 assertions, 139.14 seconds** (`/tmp/morning-review-full.log`).
- Pint: **passed** for the four PHP files changed in this review correction.
- `git diff --check`: passed. The four context mirrors changed in this correction match their canonical files. Final changes and status were reviewed.
- No new limitations. Existing effective-date exclusion, inspection-only interrupted claims, SQLite concurrency-test scope and best-effort log-delivery limits remain as documented above.
