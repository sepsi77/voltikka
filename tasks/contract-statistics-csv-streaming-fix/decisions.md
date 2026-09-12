# Decisions

Keep Eloquent hydration and the existing five-column order. Do not change activation guards or public audit semantics. Use the query connection's read PDO, which also resolves Laravel transaction/sticky routing. Release the iterator before restoring buffering and before subsequent queries.

## Verification

- `cd laravel && php artisan test --filter='ContractPriceStatisticsCsvStreamingTest|AnnualMethodReaderStagingTest|ContractPriceStatisticsPageTest'`: 40 passed, 347 assertions (final run: 2.14 seconds).
- `cd laravel && vendor/bin/pint --test app/Http/Controllers/ContractPriceStatisticsCsvController.php tests/Feature/ContractPriceStatisticsCsvStreamingTest.php`: passed. Pint was first run in fix mode on these two files only.
- `git diff --check`: passed. Reviewed the controller diff and final worktree status; other agents' context and rollout files were not changed by this task.
- The first isolated test run used `DatabaseMigrations`. Assertions completed, but teardown failed on existing protected rollback rules and a SQLite index/drop-column error. The final tests use the existing `RefreshDatabase` pattern instead. No migration or guard was changed.
- The 540-row test proves exact full row output, last row, schema, NULL/numeric/date/JSON format, provenance, v1/v2 markers, and one data SELECT without LIMIT/OFFSET. A PDO test double delegates statements to the same SQLite handle and checks the actual read-PDO path, unbuffered execution, iterator statement release with a weak reference, and restoration from both initial settings after success and hydration failure. It skips only when the MySQL PDO constant is unavailable; it ran locally.
- The implementation agent made no production calls, commit, push, CSS/JS changes, or full-suite run. The parent owns the independent real-MySQL performance benchmark and separate release approval.

## Parent verification — 2026-09-12

The parent reviewed the actual controller, test, and context diffs. A separately named candidate controller ran only in one temporary CLI process against the exact active deployment `bcb5a9e7-2b88-4841-a97c-08708d990eb9`, SHA f651849, public v2. MySQL repeatable-read/read-only was verified before its transaction. The process used array cache, a 128 MiB memory limit, and the same 30-second execution limit. No production file, route, configuration, or data was changed.

- **28,819 rows**, including all **7,648 active v2 aggregates**, exported in **14.424 seconds** at **36.5 MiB** peak memory.
- Complete 18-column rows, five-key ordering, September 12 endpoint, exact expected row counts, and v2-only active markers passed.
- All **23,500 complete rows** from the old partial public export match the candidate prefix in every CSV field.
- The previous PDO buffer setting was restored, and a subsequent query succeeded with the same row count.
- Full suite: **2,285 tests / 10,862 assertions**, 96.35 seconds. Production asset build passed. `git diff --check` passed.
- Evidence: `/tmp/annual-v2-csv-candidate-benchmark.php`, `.json`; `/tmp/annual-v2-csv-candidate.csv`, `.stderr.log`; `/tmp/annual-v2-csv-fix-tests.log`, `-build.log`. Jobs `job-55742-188` and `job-55742-189` completed successfully.

The user subsequently said **“Approve push and deploy”**, explicitly authorizing `git push origin main` to automatically deploy Voltikka / production / voltikka. Preflight confirms local and remote main at f651849 and the active bcb5a9e7 deployment at SUCCESS. The reviewed fix and rollout records are included in this release. After success, verify a complete public HTTP CSV download; the CLI benchmark does not substitute for that final check. No historical rebuild, method switch, or timeout change is included.
