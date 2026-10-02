# Private v3 rollout tools — 2026-09-21

## Status and scope

Local file authoring is complete. The manager must inspect these files before upload or use.
This unit did not connect to Railway, execute a preview/apply, start the controller, run tests,
or change application code. The manager owns backup verification, production execution,
activation, task status, and `production-rollout.md`.

Tools are in `/tmp/annual-v3-production-tools/`:

- `export_expected.py`: pure Python extraction from the repaired preview files. No financial
  calculation. It creates `expected/YYYY-MM-DD.json`, `manifest.json`, and `dates.txt`.
- `run-date.php`: one-date `/app` bootstrap and decorated writer.
- `controller.sh`: serial shell controller. Requires Bash, `flock`, `nice`, and PHP.

The export contains exactly 242 dates, January 21 through September 20, with February 12 absent.
The per-date JSON files total **155,870,969 bytes** (about 149 MiB). The three scripts and manifest
add less than 60 KiB. This is the uncompressed upload size; compression was not measured.
Source-file and expected-file SHA-256 values are retained. Do not upload the original SQLite
snapshot or the full preview artifacts. Export fails rather than replace an existing export.

## Gates

The runner requires deployment metadata `RAILWAY_GIT_COMMIT_SHA` to equal
`61b0be0115311a4359321fdba3a55316eb19cb6c`, MySQL, active v2, canonical pricing on,
reset shift on, beta 1.0, an exact manifest date before Helsinki today, and 512 MiB memory.
It checks effective configuration; it does not change financial flags. Commit proof uses the
platform deployment metadata, not a `.git` directory inside the image.

The writer subclass uses the existing method signatures and return types. Preview and write
check every typed result against the reviewed expected set, including unavailable identities,
all three consumption slots, classifications, method, bases, reasons, compatibility, and totals.
Totals and medians allow an absolute difference no greater than 0.00011. All other retained
fields must agree exactly. Comparisons sort identities, not provenance-sensitive result hashes.
No source/storage identity hash is used to compare local and production outcomes.

Aggregate medians, compatibility, summary counts and basis counts must agree before write.
The complete result comparison runs again before `parent::write()`. An outer transaction spans
command execution and stored verification. A failed result gate cannot reach the parent write.
A later failure before the outer commit rolls back that date. Stored annual identities, totals,
classifications and bases, aggregate identities, medians, compatibility and contributor counts
must agree. All-column, primary-key-ordered hashes on the same connection protect all non-v3
annual rows, non-v3/non-annual daily rows, and target-date snapshots. Stored and protected hashes
are checked again after commit before the success marker is published.

**Three consumption slots does not mean three available rows for every contract.** The reviewed
results contain real per-consumption exclusions. For example, January 21 has 971 available rows,
which is not divisible by three. Every contract must retain all three typed result identities;
only the exactly reviewed available identities may be stored. Unavailable results must stay absent.

## Operation and failure behavior

After inspection/upload, the manager can use `run-date.php DATE preview|apply PRIVATE_RUN_DIR`
for one date. The directory must already exist with private permissions. The controller accepts
`controller.sh preview|apply /absolute/new-private-run-directory`. These are interfaces, not
instructions to start an unapproved operation.

The controller uses `umask 077`, a shared `/tmp/annual-v3-production-controller.lock`, one
foreground `nice -n 15 php -d memory_limit=512M` child per date, separate logs, PID/status files,
and atomic success/completed markers. It is compatible with `nohup`. It has no parallel loop,
no sleep, no automatic skip and no automatic resume. An existing run directory is rejected.
The runner also rejects an existing started or success marker for that date/mode. On interruption,
the manager must inspect stored state and define a separate reviewed recovery operation; deleting
markers is not proof of safety.

Raw Artisan exception output is not copied to logs because it can contain SQL values. Private logs
contain bounded identifiers/field names for differences and safe exception class/reason codes.
Success requires command exit zero, `dates=1 failed=0`, exact counts, decorated-writer call counts,
and stored verification for apply. The controller validates marker code/manifest/expected hashes.
A post-commit verification or marker-write failure can leave a committed date without a success
marker; stop and inspect it. Do not infer rollback from a missing marker.

The tools do not call snapshot/current recalculation, change the active method, or warm caches.
The parent owns full protected-evidence baseline checks and control of other writers. The local
controller lock is not a distributed lock and cannot exclude a separate application worker.
No MySQL runtime or end-to-end execution is claimed by this authoring unit.

## Local verification

- `python3 /tmp/annual-v3-production-tools/export_expected.py`: passed; 242 exported dates,
  155,870,969 expected JSON bytes. Extraction checks duplicate identities, the exact date set,
  all three typed consumption slots, availability masks, summary counts and aggregate membership.
- `bash -n /tmp/annual-v3-production-tools/controller.sh`: passed.
- `php -l /tmp/annual-v3-production-tools/run-date.php`: passed.
- No application test suite or pricing calculation was run.
