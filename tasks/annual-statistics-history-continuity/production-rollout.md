# V3 production rollout — 2026-09-21–22

## Final catch-up checkpoint — 2026-09-22: production release complete

The user approved the two-date catch-up and then confirmed continuation after the
manager specified today's normal command and its snapshot/unit-statistics effects.
A fresh full backup passed independent encryption, ZIP/gzip and all-39-table checks;
see `production-catchup-backup.json` (object timestamp September 22, 10:08:48 UTC).

The September 21 read-only preview produced 748 available annual rows and 30 groups.
All 11 losses versus stored v2 are the same exact-date-component exclusions already
reviewed for September 20. All 30 groups remain. Median changes range from
−EUR 1.2492 to +EUR 20.1266. The guarded application reused the existing historical
command, checked every result before writes, and checked retained September 21
non-v3 rows/components/snapshots and all earlier v3 rows before and after commit.
It completed at 10:43:48 UTC; see `production-sep21-apply.json`.
An SSH disconnect did not cause a restart: the original worker completed once.

September 22 is still today in Helsinki. Its historical guard was not bypassed.
The confirmed normal command is
`contracts:calculate-price-statistics --date=2026-09-22 --overwrite`.
The manager launched it once with `nohup`, nice 15 and PHP 512M under a private
wrapper. The wrapper hashes all v1/v2 annual financial/aggregate rows, earlier
v3 rows and earlier snapshots, then compares them after the normal command.
Current-day snapshots and unit statistics are deliberately refreshed; preserving
v2 financial rows does not preserve their old current-day snapshot IDs.
The remote proof directory is `/tmp/annual-v3-sep22-refresh`, initial PID 5081.
Normal cache-warm dispatch remained enabled. The single 512M, nice-15 worker completed
at 10:59:07 UTC: 756 annual rows, 30 annual aggregates, 261 snapshots and 57 daily
statistics rows. See `production-sep22-refresh.json`. The user's “Continue” explicitly
confirmed this standard current command with `--date=2026-09-22 --overwrite`.

Full-column before/after hashes exactly match for ALL retained v1/v2 annual financial
and annual aggregate rows, earlier v3 rows/aggregates and earlier snapshots. Today's
snapshots and unit statistics were deliberately replaced. V2 financial preservation
does not prove that old current-day provenance snapshot IDs still resolve or that company
date/contract joins retain excluded contracts. The original 242-date history is unchanged.

V3 now has 222,132 annual rows (220,628 + 748 + 756), 7,335 aggregates and 244 evidence
dates through September 22; February 12 remains absent. `production-catchup-public-checks.json`
records HTTP 200 for CSV, daily/weekly statistics, canonical Oomi and editorial output.
CSV has 7,335 active-v3 rows through September 22; the checked HTML shows September 22.
The manually invoked standard producer proves normal current-v3 writes, not scheduler
execution. HTTP/HTML/CSV checks do not prove browser interaction or full economic parity.
Both remaining tasks are complete. No automatic rebuild is added. This documentation
update made no production calls, application changes, tests, commit or push; it is uncommitted.

## Earlier manager checkpoint — 2026-09-22: approved range and activation complete; catch-up pending

The manager performed the approved production operations. This final documentation unit made no
production calls or changes. The earlier checkpoints below are dated records, not current status.

- Code `61b0be0115311a4359321fdba3a55316eb19cb6c` reached SUCCESS in deployment
  `44dd307a-9db5-4fa9-855c-9cb745269984`. The approved full Spatie backup and independent
  streamed encrypted ZIP/gzip verification covered all 39 tables; details remain below.
- All 242 approved evidence dates, 2026-01-21 through 2026-09-20, were applied sequentially
  with one nice-15 worker and the guarded existing command. Each result matched the reviewed
  values before writes. Final stored amount/method and per-date hash checks passed for
  220,628 annual rows and 7,275 aggregates. See `production-apply-dates.csv`.
- Contract evidence and scoped retained v1/v2 financial hashes are unchanged. The two changed
  market whole-row hashes reflect scheduled-import metadata only (`updated_at` and provider
  `last_update`); scoped economic values match the reviewed snapshot. See
  `production-protected-before.json`, `production-protected-after.json`, and
  `production-market-metadata-comparison.json`. Do not claim whole-row equality for these tables.
- The only activation variable set was
  `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_as_of_v3`. Deployment
  `c1a933ac-8e20-415f-a48f-d18690953d56`, at the same commit, reached SUCCESS on September 22.
  `production-activation-runtime.json` confirms active v3.
- `production-public-checks.json` records HTTP 200 for daily/weekly statistics, CSV, home,
  calculator, editorial, and canonical Oomi `/sahkosopimus/sahkoyhtiot/oomi-oy`.
  The CSV has 7,275 active-v3 aggregates through September 20. September 20 dated copy is
  visible in statistics, company and editorial output. The initial `/oomi` 404 was an invalid
  probe URL, not a site defect. No browser interaction or full current economic parity is proved.

### Pending at that earlier checkpoint: September 21–22 catch-up and normal current-producer proof

Runtime contains v2 through September 22 because normal collection ran before activation.
The approved rebuild stops at September 20. September 21 and 22 were NOT reconstructed into v3.
The manager told the user that separate catch-up approval is required. This task is not wholly
complete; activation alone does not make all calendar history current.

Next, prepare fresh dated previews for September 21 and 22, review coverage, methods, amounts and
protected evidence, and obtain separate explicit apply approval with the required verified backup.
Only then may an authorized operator apply those dates sequentially and verify stored results and
public endpoints. Separately obtain proof from a normal current collection that it writes v3;
configuration alone is not producer proof. Do not trigger a current overwrite or assume automatic
catch-up. Deployment and cache changes must not run a rebuild automatically.

## Earlier manager checkpoint: history applied; activation deployment pending

All 242 approved dates completed. A separate final committed-state verification
checked every result against the reviewed values and every date's recorded hash:
**220,628 annual rows, 7,275 aggregates**. All checks passed. No automatic retry ran.
`production-apply-dates.csv` records the date counts, hashes, and completion times.
The downloaded marker/log archive is retained locally at
`/tmp/annual-v3-production-apply-evidence.tgz`, SHA-256
`51c85a22a539946b4d1e7c03c14a0c0d6c3f38a8070bec025adf396dab15a0bf`.

`production-protected-before.json` and `production-protected-after.json` show
identical hashes for v1 annual/aggregate rows, scoped historical v2 rows, contract
snapshots/components, immutable sources/observations/interpretations, dedicated
historical evidence, and historical hourly Spot rows. Target-date non-v3 hashes
were also checked before and after every date and at final verification.

Two market-table full-row hashes changed during scheduled imports. A row-by-row
comparison to the reviewed local snapshot found only `updated_at` changes in
1,272 Spot-average and 8,040 futures rows, plus provider `last_update` metadata in
7,832 futures rows. All 1,753 scoped average rows and 31,343 futures rows retain
the same IDs and economic values. See `production-market-metadata-comparison.json`
for the normalization and scope; this is not a claim that those full rows are byte-identical.

At `2026-09-22T05:12:40Z`, the manager applied the separately approved setting
`CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_as_of_v3` to the explicit
Voltikka production app context. It triggered deployment
`c1a933ac-8e20-415f-a48f-d18690953d56` of the same commit `61b0be0`.
The exact-deployment poll is running. Runtime/public activation verification is pending.
The approved rebuild ends September 20; no unreviewed September 21 apply or current
statistics overwrite ran. New current v3 dates rely on the normal scheduled producer.

## Earlier manager checkpoint: deployed; full backup verified

Commit `61b0be0115311a4359321fdba3a55316eb19cb6c` deployed successfully as
`44dd307a-9db5-4fa9-855c-9cb745269984`. The exact-deployment poll reached `SUCCESS`.
Runtime SSH confirms that commit, MySQL, and active `annual_cost_as_of_v2`.
Statistics HTML and CSV returned HTTP 200. The dated production manifest has the
same 242 evidence dates from January 21 through September 20. All 39 database
tables use InnoDB. There are no configured dump table exclusions.

The user explicitly approved code release, a full production backup, historical
v3 rebuild, and subsequent v3 activation. The manager ran the existing full-database
backup command with explicit Railway context and one nice-15 process. It succeeded.
Independent streamed verification then established:

- Object: `Voltikka/voltikka-2026-09-21-15-34-48.zip`.
- Object timestamp: `2026-09-21T15:37:06+00:00`; size: 54,533,298 bytes.
- SHA-256: `4f6aa40fdef90f03bd3c1ee990486853641c97b98ea8b56ae637bd9b41b86fbc`.
- One encrypted SQL-gzip entry; ZIP CRC and complete gzip integrity passed.
- Decompressed SQL: 934,674,444 bytes; all 39 production tables covered.
- Existing S3 access and archive password were verified without exposing values.
- No restore was run. The temporary encrypted verification file was removed.

Protected baseline hashes are recorded in `production-protected-before.json`.
Guarded production previews for July 22, 23, 24 and September 20 all passed against
reviewed local full-date results. The manager inspected the private runner and controller.

The approved 242-date apply is now running under controller PID 400, with one
nice-15 PHP process at a time. Private remote paths:
`/tmp/annual-v3-release-61b0be0-tools/` and `/tmp/annual-v3-production-apply/`.
Runner SHA-256: `fac9514576b6987a035a7e6c934047681656b9d765d600f9fc6a5f3bf6bf8612`.
Expected-result manifest SHA-256:
`faf702e3d8207fd45e043e7847d0d0cd42d7971e1f389f23f2aad737b3fdaaad`.

The wrapper checks every typed result and aggregate against reviewed values before
writing (EUR 0.00011 tolerance), uses the existing application command/writer, and
verifies stored results and non-v3 date rows inside an outer date transaction.
Success markers require a successful command summary and committed-state verification.
A shared controller lock prevents parallel date controllers. There is no automatic retry.
A read-only 60-second monitor reports progress and terminal failure/success. SSH loss
does not authorize a restart. A missing marker alone does not prove rollback after commit.
V2 remains active. Full-run verification and activation have not occurred.

The following section is the earlier preparation record, not current deployment status.

## Earlier checkpoint: waiting for deployment

One explicit Railway MCP `railway_list_deployments` read returned:

- Project `6d8cae01-1006-409f-8108-1d51f1abc676`.
- Production environment `9245cef8-41d0-486e-862f-193726511dba`.
- App `700d0624-fa96-4266-876c-e37640d220ea`.
- Deployment `44dd307a-9db5-4fa9-855c-9cb745269984`: **BUILDING**, created
  2026-09-21 15:22:14.777 UTC, commit `61b0be0115311a4359321fdba3a55316eb19cb6c`.
- Previous deployment `89278e6e-12a9-4b04-a8b9-e894ea110bef`: SUCCESS,
  commit `3d1d6a99ae448f35059d4ba0b42bd999e3f1380c`.

The manager owns the deployment poll. This unit did not start another poll or SSH process.
No post-release runtime, page, CSV, database, preview, backup, apply, or activation check ran.
No production mutation ran. There is no new backup object or hash proof.

The manager reports user approval for release, activation, full backup and historical rebuild.
This executor's higher-priority safety instructions prohibit production-mutating Railway/SSH
commands. Thus this executor cannot run the manual backup, even after deployment succeeds.
An authorized operator must perform that step. User approval is not reported as missing.

## Ready checks after exact deployment SUCCESS

Use the same explicit app context above and database service
`beb2ba12-4a7b-416b-b4b1-596434dc3215`. Every CLI call must use
`RAILWAY_CALLER=skill:use-railway@1.2.2` and
`RAILWAY_AGENT_SESSION=annual-v3-release-61b0be0`.

1. Confirm runtime commit, effective active method (must still be v2), PHP memory limit,
   disk space, available memory, and conflicting import/interpretation/rebuild/backup jobs.
   Do not print environment variables, connection strings, or raw process arguments with secrets.
2. Fetch `/sahkosopimus/tilastot` and `/sahkosopimus/tilastot.csv`. Require HTTP 200,
   a complete CSV with consistent field counts, dated endpoints, and active-v2 annual rows.
3. Read the exact union of snapshot/component/baseline dates. Require the reviewed 242 dates,
   January 21–September 20, excluding February 12. Do not infer dates from a calendar alone.
4. Record financial counts and ordered logical SHA-256 hashes for retained v1/v2 annual and
   aggregate rows. Record protected source/component/snapshot/interpretation, dedicated historical,
   Spot and futures evidence counts/hashes. Use bounded streaming, not whole-table PHP arrays.
   Separate concurrent current-day changes from protected historical data; stop on unexplained drift.
5. Preview July 22/23/24 and September 20 sequentially, only after resource checks pass.
   Use one nice-15 PHP child with explicit 512 MiB per date. Require full-date results,
   `Totals: dates=1 failed=0`, reviewed coverage/method/median comparisons and complete output.
   Shell exit alone is not proof. The command has no JSON option; a reviewed read-only diagnostic
   must separately produce structured result proof if needed. Do not invent `--json`.

Local review reference: 325,491 candidates; 220,628 available; 104,863 unavailable;
1,569 lost and 1,491 new pairs; 420 large median changes. The seven loss classes and
703 temporal-proof exclusions remain as documented in `v3-repaired-preview.md`.
These are local reference values, not fresh production results.

## Trusted full database backup procedure

Existing procedure: Spatie `php artisan backup:run --only-db`, not the public-data sync export.
`config/backup.php` backs up the configured database connection, compresses its SQL with gzip,
and writes an encrypted ZIP. `config/database.php` has no MySQL table include/exclude filter;
it uses `useSingleTransaction` and the tested `skip-ssl` option. Verify effective production
settings and that all tables use an engine compatible with a consistent single transaction.
Production destination is the existing `voltikka-backups` S3 bucket,
`460e1b25-73fc-45e3-a43a-0473d2d2b86d`. Do not create a new backup system.

After an authorized operator runs the manual backup, require all of these proofs:

- Successful dump and destination-copy messages, successful command result, exact object key,
  timestamp, byte size and object metadata. An ETag alone is not a SHA-256 or restore proof.
- Stream/download the encrypted object to a mode-0600 temporary file without exposing credentials.
  Record its SHA-256. Use existing runtime credentials in memory, never command arguments or logs.
- Check encrypted ZIP entries and password access; fully read the SQL gzip stream to validate ZIP
  and gzip integrity. Compare dump table coverage with the full schema, including private/runtime
  tables. Record safe counts and checks, not SQL data or secrets.
- Confirm object-storage credentials and `BACKUP_ARCHIVE_PASSWORD` are available to the restore
  operator. Boolean availability is sufficient for the record; never record their values.

Restore: retrieve that exact object, verify SHA-256, decrypt with the protected archive password,
decompress the SQL, and restore into an independently approved temporary MySQL database.
Check schema, table counts and protected financial/evidence hashes there. A restore is not part
of this unit. Never restore over production without separate explicit approval. Previous v2
rollout evidence was only operator-confirmed restore access plus object metadata; it does not
meet the present independent archive-integrity requirement.

## Earlier exact apply plan (subsequently executed for the approved range)

After all gates pass, run one full date at a time inside the deployed app directory:

```sh
nice -n 15 php -d memory_limit=512M artisan contracts:rebuild-annual-cost-statistics \
  --date="$date" --method=annual_cost_as_of_v3 --baseline=annual_cost_as_of_v2 \
  --apply --stop-on-error
```

`$date` must come from the verified 242-date manifest in the approved range, not user input.
No contract filter, limit, whole-range PHP process, parallel pool, current overwrite, or activation.

Proposed transport: stage a small reviewed shell controller and date manifest in a private remote
run directory through explicitly scoped Railway SSH. Start it with `nohup`, stdin `/dev/null`,
and private per-date logs. Use one exclusive run lock, one PHP child, a PID/start-time record,
and atomic completion/failure markers. Validate each date summary before starting the next.
Keep a structured per-date verification file and hashes. On SSH loss, reconnect and inspect the
exact remote process and stored rows; never start a duplicate. The previous v2 run proves that
SSH loss can leave PHP running. Container replacement can still stop this controller; do not
claim `nohup` survives a deployment. Staging/launch require an authorized operator; no controller
was installed or tested in production by this unit.

Verify stored rows, complete reference levels, aggregate medians/methods and lost identities
against reviewed results. Verify retained v1/v2 and protected evidence hashes after each bounded
batch and at completion. Require all 242 successful date records before activation.

Activation is a separate operation: set only the approved active annual method to v3, verify the
resulting exact deployment, then check page/CSV, chart gaps, endpoints, homepage/editorial annual
readers, company comparison and calculator. Unit panels and seller-set index stay unchanged.
Check current-v3 production at the next normal collection; do not trigger a current overwrite.
A switch back to retained v2 requires its own authorization and does not restore source data.

## Verification in the earlier preparation unit

- One MCP deployment-list read: BUILDING for the exact approved commit; no duplicate polling.
- Read local rollout, backup configuration, prior v2 results and command implementation.
- No PHP tests or build: documentation-only preparation, no application change.
- Production gates above remain pending. No backup proof, apply or activation is claimed.
