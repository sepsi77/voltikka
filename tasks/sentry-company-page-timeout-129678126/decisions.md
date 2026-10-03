# Investigation results — 2026-10-03

## Request path and release

- Read-only Railway MCP confirmed successful active deployment `e8475044-b6d3-4665-affe-c25509b4e2d6`, commit `6d433571191ae9a9c869aa7d23b2b5c7ce93fe0a`, created 2026-10-02 01:18 UTC. Thus the recent GET annual-price cache fix preceded this incident. Local PHP matches that release; the later local commit changes documentation only.
- CompanyDetail render asks for market comparison before contract prices. CompanyMarketComparisonService reads stored statistics, not live annual-price calculations.
- The four completed Sentry fingerprint queries match buildFingerprint(). Its next query is `MAX(snapshot_date)` on contract_price_annual_costs for exact v3/canonical basis, followed by `MAX(updated_at)`. Canonical mode repeats six queries for observed basis, for twelve aggregate queries total.
- This is related to the older fingerprint-scan problem documented in `../sentry-company-page-timeout-144047512/decisions.md`, not evidence that the GET annual-price fix failed.

## Safe production access

Used Railway CLI 5.62.1 and `railway run --project 6d8cae01-1006-409f-8108-1d51f1abc676 --environment 9245cef8-41d0-486e-862f-193726511dba --service beb2ba12-4a7b-416b-b4b1-596434dc3215 --no-local` to inject credentials into a standalone local PDO diagnostic. No credentials were printed. No Laravel bootstrap or application commands ran. Each connection used a READ ONLY transaction, session MAX_EXECUTION_TIME=5000 and metadata lock timeout=3, and ended with rollback. Only SELECT, EXPLAIN, SHOW and session/read-only transaction controls ran. No production schema, data, cache or configuration was changed.

Diagnostic script: `/tmp/voltikka-company-db-readonly.php`. Full transient outputs: `/tmp/pi-bg/job-15810-8.log` and `/tmp/pi-bg/job-15810-10.log`. All collection queries succeeded. These paths are local temporary evidence, not durable backups.

## Production evidence

- MySQL 9.4.0; performance_schema=0. Historical statement metrics are unavailable from that facility.
- Annual table estimated 685,109 rows; data length 1,127,743,488 bytes and index length 370,327,552 bytes. TABLE_ROWS is an estimate, not an exact count.
- Annual index `(method_version, snapshot_date, segment_key, consumption_kwh)` exists. No index includes pricing_basis; no index includes updated_at.
- Both suspected MAX queries use only the method prefix of that index. EXPLAIN estimates 342,554 rows and reports `Using where`, not an index-only MAX shortcut.
- First measured MAX(snapshot_date): 1,720.94 ms; MAX(updated_at): 1,797.75 ms. On a second connection these fell to 1,124.67 and 1,150.87 ms. These client timings include public-proxy network latency; baseline simple reads were about 250–320 ms.
- Full twelve-query fingerprint: 7,428.88 ms over the public proxy after earlier reads. The four annual-table scans in that run took 952.05, 1,095.35, 1,159.57 and 1,042.33 ms. Do not equate 7.43 seconds over a remote connection with the internal app request time.
- Company identity is Turku Energia Oy. The exact canonical/v3/5,000 kWh latest-usable-date join completed in 351.06 ms including network latency. Plan starts with 2,345 estimated company snapshot rows, then one-row unique lookups into annual costs and statistics. It is not the main suspect in this measurement.
- Both latest annual date and company joined date are 2026-10-03. Latest canonical annual updated_at is 2026-10-03 03:10:28.
- At measurement: two connections, no waiting InnoDB row locks and no long-running foreground queries. Buffer pool is 1 GiB. First read counters imply about 99.95% lifetime hit ratio. Counters and current quiet state do not establish conditions at 04:02 UTC.

## Conclusion and next action

Confirmed inefficient stored-statistics fingerprint queries remain on a cold GET. The exact 30-second incident was not reproduced, so neither the unfinished query nor incident-time contention is proved. The first annual aggregate remains the strongest suspect from breadcrumb order and plans.

A focused candidate fix is to give the annual aggregates matching indexes `(method_version, pricing_basis, snapshot_date)` and `(method_version, pricing_basis, updated_at)`. Validate this locally before proposing a release; preserve same-day rewrite detection, exact method/basis selection and observed fallback. Caching already reduces frequency, but does not make the first cold scan cheap. The original investigation did not authorise implementation, migrations, deployment or manual cache warming. The later user instructions below supersede that limit for this tracked migration and manager release only.

## Local migration and verification — 2026-10-03

- Added `2026_10_03_000001_add_fingerprint_indexes_to_contract_price_annual_costs.php`. It adds only `contract_annual_costs_method_basis_date_idx` and `contract_annual_costs_method_basis_updated_idx`, both nonunique with the specified exact column order. Named guards tolerate repeat/partial up and down. Both directions fail if the required table is absent; down removes only these two names.
- MySQL uses one ALTER for all missing indexes, explicit `ALGORITHM=INPLACE, LOCK=NONE`, and no table-copy fallback. Both directions save session `lock_wait_timeout`, set five seconds, and restore the original in `finally`. No application query, row, existing key, cache or feature flag changed.
- `cd laravel && php artisan test --filter='ContractAnnualCostFingerprint.*MigrationTest'`: 14 tests passed, 112 assertions. SQLite tests use isolated `:memory:` and compare full rows (all financial, source/historical provenance and timestamp columns), existing unique/lookup indexes and foreign keys across repeat up/down. Each partial index state is covered. Mocked MySQL tests verify exact one-ALTER SQL and timeout restoration on successful and failed up/down.
- `vendor/bin/pint` on only the migration and the two new PHP test files: passed after formatting. No CSS/JS changed; the manager runs the build and company-page regression gate separately.
- Live disposable local MySQL 9.4.0 gate passed in Docker `mysql:9.4`, localhost port 13364, a fresh `fingerprint_test` database, no production credentials. Final executor command: `php /tmp/voltikka-fingerprint-mysql-test.php` (the script explicitly selects only the localhost `fingerprint_local` connection). Output: `/tmp/voltikka-fingerprint-mysql-gate-result.log`. The transient script runs the actual tracked migration against 120 fixture rows. It proved one explicit online ALTER for both indexes, exact nonunique definitions, repeat up, both partial up/down states, repeat down, existing keys and full-row preservation. Both exact v3/canonical MAX EXPLAINs report `Select tables optimized away`.
- A second local PDO connection held a metadata lock. Up failed with MySQL 1205 after 5.01 seconds; the original session timeout of 37 was restored. Retrying after release of that lock passed. The timeout was also 37 after final down and repeat down. An initial rerun failed with connection refused because the prior container had disappeared; the executor recreated only that disposable local container and the final gate passed. Framework PDO constant deprecation notices occurred during the standalone bootstrap; they did not fail the gate. The disposable container was removed after verification.
- Limits: fixture plans and online DDL prove local MySQL behavior, not production latency, build duration, disk capacity or incident-time contention. No production command, local database.sqlite mutation, commit, push, deployment, history rebuild or cache flush ran in the executor session.

## Release status

Manager-reported gates passed: `npm run build` completed (Vite 856 ms; nonblocking stale Browserslist warning), and `CompanyDetailSectionsTest` passed with 40 tests / 166 assertions. Read-only production Railway SSH `df` reported MySQL `/var/lib/mysql`: 46G total, 2.7G used, 44G available (6% used). This is free-space evidence, not a proof of build duration or absence of contention. No production DDL ran.

The user has explicitly authorised commit, push and deployment. The manager also reran the combined migration/company gates: 54 tests passed with 278 assertions, and Pint `--test` passed. Build and regression gates are complete. Release now awaits the manager's final review and release actions, not user approval. The manager owns final diff/regression/build review, release actions, exact deployment verification and read-only production checks of the two index definitions and MAX plans. A five-second metadata lock timeout deliberately fails a blocked deployment; it does not bound total online index-build duration. A partial state is retry-safe.
