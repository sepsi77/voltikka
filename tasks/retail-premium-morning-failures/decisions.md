# Decisions

## Evidence (read-only production, 2026-10-16)

- `morning_retail` checkpoints since scheduled mode started: `failed` / `execution_failed` /
  `RuntimeException` on 2026-09-21..27, 09-29, 09-30, 10-03..06, 10-10..12, 10-14..16 (19 days);
  `waiting` / `dependencies_not_ready` until noon on 09-28, 10-01, 10-02, 10-07..09, 10-13. The
  scheduled collector never completed. The manager's list of three days was a subset.
- `laravel.log` has only `Morning consumer execution failed; inspect before further writes.` with the
  exception class (by design). The message is not logged anywhere.
- `retail-premiums.log` (no timestamps) ends with 19 partial runs and no `Done.` line. Each run prints
  an observation line before it persists it, so the last line names the failing row: runs 1–9 stop at
  `1eh6re-vaasan-sahko-myynti-oy-perussahko-yleissahko` (month), runs 10–19 at
  `1xpsyz-paneliankosken-voima-oy-kosken-markkinawoima` (spot_disclosed).

## Exception and root cause

- `RuntimeException('Existing retail observation requires inspection.')`, thrown by
  `CollectRetailPremiumObservations::assertCompatibleObservation()` (called from `persistOwned()` in
  the first `persist()` transaction of that row), caught by `MorningConsumerExecution::run()`.
- Reproduced on a scratch copy of the 2026-10-16 11:27 local database (DB file printed by each
  script). Stored v2 row 3183 (1xpsyz) has interpretation 1027 (schema-v3/prompt-v17); the contract
  now publishes 1816 (schema-v4/prompt-v19, created 2026-07-28) for the same snapshot 358 and episode
  18. `reuseOpenPricePeriodIdentity()` adopts the stored key (same episode and price signature), so the
  scheduled check compares the rows. The existing exemption covers `published_interpretation_id` only.
  The reinterpretation also rewrote `phase_label` (for example "Spot-sopimuksen nykyinen hinnoittelu"
  -> "Nykyinen hinnoittelu") and the `evidence` quotes / `source_kind` of the components. Prices are
  identical. Same for 1eh6re (1024 -> 1626, snapshot 88, episode 11) at 2026-09-30.
- All 34 incompatible active rows today have the same episode and snapshot; the only differences are
  the interpretation ID, `phase_label` (28) and `evidence`/`source_kind` (29). No economic key differs.
- Manual runs before 2026-09-21 never called the check, so they extended these rows silently.

## Fix

- Under the existing republication condition only (same episode, snapshot, observation key and price
  signature), also ignore `phase_label` and `evidence`/`source_kind` inside each `energy_components`
  entry. All other keys, all other fields, and all non-republication cases still fail closed. Stored
  rows keep their original text (immutable). Not chosen: projecting components onto the price
  signature keys, because components outside the signature (other energy rates, other fees) must
  still be compared.
- Print `Existing retail observation {id} differs in {field}` before the exception so the next
  inspection does not need a reproduction. Values are not printed.
- Not changed: `mergeObservationMetadata()` replaces `source_metadata` schema/prompt/validator
  versions with the newer interpretation's while the row keeps the original interpretation ID. This
  predates the incident (rows already show prompt-v19 with interpretation 1027/1024).
- Not changed: `quality_flags` stays compared. `low_pricing_confidence` comes from LLM confidence, so a
  future reinterpretation can still stop the run there; today no row differs.

## Verification on the database copy

- Diagnostic over 367 active contracts: 34 incompatible rows before, 0 after; 1eh6re at 2026-09-30:
  2 before, 0 after.
- Real `retail-premiums:collect --scheduled` at 2026-10-16 08:00 (freshness stubbed ready, outer
  transaction rolled back): HEAD code prints 7 lines, stops at 1xpsyz, checkpoint `failed`
  `RuntimeException`; fixed code completes with 518 saved/extended, 1 skipped, 368 new rows,
  checkpoint `ready`, `scheduled_complete=true`.

## Backfill

- Still-current episodes need no backfill: the first successful run records each full open period
  from the pointed episode (368 new rows in the check). This includes the 2026-10-01 quarterly resets
  and the October monthly period, if the fix is in production before the next monthly reset
  (2026-11-01). The monthly calibration on the 2nd then sees them.
- Lost from forward collection: episodes that started and ended inside 2026-09-16..10-15 for active
  tips: 73 FixedPrice (71 one-day, plus Herrfors `khvntz…yleissahko` and `wlovru…vakaa`, open-ended,
  2026-10-01..10-14) and 115 Hybrid (not comparable, no premium). No Spot and no reset cadence, so
  beta calibration is not affected. Rows whose episode ended in the window keep an understated
  `last_observed_date` (values unchanged).
- Do not run `--include-inactive` over the window: history periods keep their original first date
  and overlap the forward rows (line 186 filter), which duplicates periods in calibration series. The
  only defensible optional step is a per-contract dry run for the two Herrfors episodes
  (`--include-inactive --contract=<id> --from=2026-10-01 --to=2026-10-14 --dry-run`), inspected before
  any approved write. Low value: these rows feed no calibration.
