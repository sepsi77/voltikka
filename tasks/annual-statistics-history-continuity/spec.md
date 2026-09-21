# Annual statistics history continuity

## Problem

The annual-cost chart on `/sahkosopimus/tilastot` shows gaps, mostly in the
`Toistaiseksi voimassa oleva` (`open_ended`) series. The page hides a point when the dominant
estimate method changes (`AnnualSeriesCompatibility`). That rule is correct. The problem is that
the stored history does not use the same method as the current calculation, so the dominant
method changes more often than the data requires.

The history was recalculated once: `annual_cost_as_of_v2` was applied on 2026-09-12 to all 233
evidence dates (2026-01-21 through 2026-09-11). See
`../annual-statistics-v2-rollout/apply-results.md`. But the historical calculator uses
`ComparisonPolicy::Historical`, which is stricter than the current policy, and the current method
changed again on 2026-09-17 with no history rebuild.

## Evidence (2026-09-21, public page and public CSV, 5,000 kWh)

This review read `https://voltikka.fi/sahkosopimus/tilastot?jakso=daily` and the
`basis_counts.estimate_method` values in `/sahkosopimus/tilastot.csv`. It did not query production.

| Date | Series | Median (EUR) | Cause | Real data gap |
|---|---|---|---|---|
| May 1 | `spot` | 435 → 464 | `rolling_365_spot` → `forward_curve_spot`. FI futures start on 2026-04-08; the in-delivery month needs a curve from before the month start, so the forward method starts on May 1 | Yes |
| Jul 23–24 | `open_ended`, `hybrid` | 638 → 653 | One-day defect. On Jul 23, 54 of 62 `open_ended` contracts used `relational_open_ended_conservative_hold_flat`. On Jul 22 and Jul 24, 58 used `canonical_outcome` | No |
| Sep 12 | `open_ended` | 721 → 648 | Change from the historical calculator to the current canonical adapter | No |
| Sep 17 | `open_ended` | 647 → 774 | Release `111a101` made `supplier_adjusted_forward_premium` the dominant method. No history rebuild followed | No |

`quarterly` also changes its dominant method on Apr 9, May 1, Jul 1, and Jul 23–24. This series is
not on the main annual chart, but the same causes apply. Examine it in the same work.

Dominant `open_ended` methods by period:

- Jan 21 – Sep 11 (historical v2): `hold_current_supplier_price`, approximately 40 of 60 contracts.
- Sep 12 – Sep 16 (current): `supplier_adjusted_spot_seasonal_index` 20, `supplier_adjusted_forward_curve_shift` 13.
- Sep 17 onwards (current): `supplier_adjusted_forward_premium` 19, `supplier_adjusted_forward_curve_shift` 15.

The weekly view (the default) makes each gap larger: a week that contains two display regimes is
null, and the first point after a transition is also null. The one-day Jul 23 defect removes a
full week. Sep 12 and Sep 17 together remove two weeks, then the line jumps from 701 to 789.

Fresh snapshot clarification (2026-09-21): the table above is the earlier public review.
Stored OpenEnded medians for July 22/23/24 are 638.3735 / 625.3125 / 653.3694 EUR.
`canonical_outcome` is a calculation basis and the relational conservative hold is an
estimate basis. The actual dominant estimate method changes from
`hold_current_supplier_price` to `none` and back. See
[july-22-24-aggregates.csv](july-22-24-aggregates.csv) for all three dimensions, dates,
segments, and consumptions. The chronology decision is now approved below; full correction remains pending v3 integration.

## Root causes

1. **Price-episode anchor.** `HistoricalPriceEpisodeResolver` gives a proven episode start only
   when the preceding calendar date exists with a different rate or fee. A price that has not
   changed since the dataset start (Jan 21) has no start. With `startedAt = null`,
   `SupplierAdjustedPriceEstimator::forwardShift()` and `seasonalIndexShift()` both return null,
   and the result is hold-flat. The current policy accepts the left-censored first observed date.
2. **Premium fallback.** `ComparisonPolicy::Historical` ignores the comparable-premium fallback
   (`forwardPremium()`), because the current premium loader reads current peers. No as-of version
   of the premium evidence loader exists.
3. **Jul 23 seam (confirmed by fresh snapshot, 2026-09-21).** Dedicated historical
   episodes end July 22 and accept later retrospective output. Immutable observations begin
   July 23 at 19:15:12 UTC and close that path. Source interpretations must complete by
   20:59:59 UTC (July 23 Helsinki end). Among the 97 stored relational contributors across
   `open_ended`, `hybrid`, and `quarterly` at 5,000 kWh, 88 have valid same-source output only
   after that cutoff, and nine still have no valid same-source output. Ten of the 97 have
   timely failed output. This is not solely delayed interpretation. See
   [investigation.md](investigation.md) and its exact episode/interpretation evidence.
   The user has now approved exact-source retrospective reconstruction under v3. It cannot
   repair the nine sources without valid output and does not authorize later seller facts.
4. **No rebuild after a method release.** Release `111a101` changed the current method, and the
   history kept the old method.

## First v3 code unit — partial implementation

Implemented exact-source retrospective selection, typed provenance, inherited v2 safety checks,
and explicit v3 command/writer support. Defaults and active/public/current producer remain v2.
The next bounded unit adds v3 dated exact-contract left-censored anchors and passes the selected
method from the annual calculator. Fees do not reset full energy signatures. Source-covered dates
use exact-source v3 proof; dedicated historical proof is used before coverage where available.
Unknown/conflicting evidence breaks runs. No current lineage links or metadata are read.
The next integration unit now supplies exact-date supplier/reset premiums to the shared Current
candidate and calculation policy for v3 only. V1/v2 keep Historical. Dedicated validated episodes
and approved later exact-source interpretations can be donors; later seller prices cannot.
Campaign proof uses only the exact historical payload through a shared pure extractor. V5 rules
fail closed until full dated source validation exists. The full-history preview is now complete,
but acceptance failed; see [v3-preview.md](v3-preview.md).
Older absent donors and dated replacement lineage are not implemented. Dated identity gaps and pre-source Time/Season without validated full canonical
buckets remain unresolved; no weighted-average proof is added. V3 is not ready for
release. No automatic rebuild or schedule is added. See `decisions.md` for approval and checks.

## Latest exact-source selection repair (2026-09-21)

The timely/first-later preference is superseded as a processing seam defect. V3 now selects the
latest exact-source reconstruction that passes full stored-profile validation at the target date.
Rejected candidates cannot remove active historical discounts or inject unproved calendar dates.
Unknown legacy date proof stays explicit; neither another source nor a recurrent episode repairs
it. See `v3-continuity-repair.md` for the separate July 22–24 rerun and remaining release checks.
The original full-history preview and all failed-acceptance artifacts below remain unchanged.

## Full-history preview result (2026-09-21)

All 242 evidence dates through September 20 completed on an isolated read-only SQLite backup.
V3 has 221,303 available contract-consumption-date pairs versus 220,706 stored v2 pairs:
876 lost, 1,473 new, and 35,209 changed matched pairs. Protected evidence hashes stayed equal.
See [v3-preview.md](v3-preview.md) and its bounded CSV artifacts for all dates and groups.

Release acceptance remains blocked. July 23 no longer changes the dominant method at 5,000 or
18,000 kWh, but July 24 changes OpenEnded 2,000 kWh from premium to a premium/shift tie.
Four unchanged source identities receive different canonical candidate/mechanism proof from
successive interpretations; these are not proved seller-price changes. Other remaining transitions
come from the July 1 reference boundary, August 7 membership loss, and September 14/15 source
ambiguity. Current collection still writes v2. Same-evidence dated current parity was not verified.
No apply, active-method change, production operation, commit, push, or deployment ran.

## Goal

One continuous annual-cost series for each segment from the first date on which the current
method is possible, with at most one method transition in spring 2026 that comes from the real
futures data gap.

## Scope

1. Find the cause of the Jul 23 seam and correct it. If the hypothesis is correct, decide the
   evidence rule (now latest target-validated reconstruction of the exact covering source,
   independent of completion relative to target) and rebuild that date.
2. Add an as-of mode of the current annualized policy as a new method version
   (`annual_cost_as_of_v3`), with no look-ahead:
   - accept left-censored episode anchors in the same way as the current policy, with their
     uncertainty flags;
   - add as-of premium evidence: peers, observations, and publication facts limited to data known
     on the target date, and futures vintages strictly before the target date;
   - for Apr 8 – Jul 22, premium evidence must come from the dedicated historical
     interpretations, because immutable source observations do not exist for those dates;
   - before Apr 8, forward-curve methods stay impossible. The seasonal index can replace the flat
     hold when an anchor exists.
3. Preview v3 against stored v2 with `contracts:rebuild-annual-cost-statistics`, review coverage
   and median changes, then apply with the same backup and approval steps as v2.
4. Release rule (done 2026-09-21): a change to the current annual method requires a history
   rebuild plan. The policy "History follows the current method" is in
   `laravel/app/Services/ContractStatistics/AGENTS.md`, with pointers in the root, `laravel/`, and
   `CanonicalPricing` context files. When the v3 code is complete, replace the "known gap" notes
   in those files with the as-of rules that exist then.
5. Optional display decision: show the first point after a regime transition with a marker, not
   as null. A one-day change then removes one point, not two weeks. Stored data is unchanged.

## Constraints

- Historical reconstruction uses latest methods, never later seller facts/prices, current peers,
  or future curve evidence. Approved v3 selects the latest target-validated interpretation of the
  EXACT covering source, independent of completion relative to target. Full stored-profile source
  validation uses the exact payload and target date. Unsupported date proof stays unresolved;
  completion and retrospective use remain explicit provenance.
  Missing exact-source interpretations stay unresolved; legacy NULL binding proves source identity,
  not episode completion. V1/v2 strict behavior remains unchanged.
- Do not rewrite `contract_price_snapshots`, `price_components`, stored v1 rows, or stored v2 rows.
  A new method version is written beside them (`AnnualCostStatisticsWriter` replaces only the
  selected method).
- Keep `AnnualSeriesCompatibility` strict for stored audit evidence. V2 and v3 cannot share a
  display regime.
- Historical pre-Apr-8 rows stay a separate regime. Do not fabricate futures evidence.
- The policy direction is decided: Historical differs from Current only for date safety or missing
  evidence. Each remaining difference needs a documented reason.
- The `RetailPremium` method-version rule applies: filter analysis to the current
  `method_version` pair.
- Production apply, the active-method switch, and each Git push need separate explicit user
  approval and a verified full database backup. Follow
  `../annual-statistics-correction/rollout.md`.
- Use the simplest solution. Reuse the current estimators, the premium selector, and the rebuild
  command. Do not add a parallel calculator.

## Acceptance

- The cause of Jul 23 is documented with evidence, and the date no longer breaks the series.
- In a local preview on a fresh production snapshot, `open_ended`, `hybrid`, and `quarterly` have
  no dominant-method transition after 2026-05-01, other than transitions with a documented real
  evidence cause.
- The historical-to-current seam date has the same dominant method on both sides.
- Tests prove no future leakage for anchors, premium evidence, and curve vintages.
- V1 and v2 stored rows stay byte-identical after a v3 apply.
- Context files are updated: root `AGENTS.md`, `laravel/AGENTS.md`,
  `laravel/app/Services/ContractStatistics/AGENTS.md`, and the `CanonicalPricing` subtree files
  that state the Historical policy.

## Read first

- `laravel/app/Services/ContractStatistics/AGENTS.md`
- `laravel/app/Services/CanonicalPricing/AGENTS.md`, `SupplierAdjusted/AGENTS.md`,
  `MarketReset/AGENTS.md`, `ForwardPremium/AGENTS.md`
- `laravel/app/Services/ContractStatistics/AsOfAnnualCostCalculator.php`,
  `HistoricalPriceEpisodeResolver.php`, `AsOfAnnualCostEvidenceResolver.php`,
  `AnnualSeriesCompatibility.php`
- `laravel/app/Services/CanonicalPricing/SupplierAdjusted/SupplierAdjustedPriceEstimator.php`
- `../annual-statistics-v2-rollout/` and `../annual-statistics-correction/rollout.md`

## Validation resource pause — 2026-09-21

The first attempt paused after excessive CPU use from eight workers. The manager killed the
owned stopped process trees. The user then approved one worker with `nice -n 15`. The full test
suite, remaining 186 dates, and fresh disposable apply checks completed in sequence. The 56
complete earlier dates and all original failed-preview artifacts remain. Never restart the old
parallel scheduler. See `v3-repaired-preview.md` for final checks and the resource incident.

## Final local validation result — 2026-09-21

All 242 evidence dates through September 20 pass at all three consumptions. July's processing
seam is repaired globally; 14,978 continuing exact-source identities have no selection change.
All 11 remaining post-May dominant transitions have dated reference or membership causes.
September 12/17 are continuous; all nine requested endpoint dominant methods agree with stored
current-produced v2. This is not full-store amount equality. Same-input current/v3 fixtures pass.

The 1,569 coverage losses and 420 median changes of at least EUR 100 are classified with actual
contributor evidence. Preserve the explicit 703 legacy temporal-proof exclusions across seven
contracts; no unsafe reconstruction is accepted. Median deltas do not prove forecast accuracy.
A full 40-table disposable-copy proof and six v3 applies preserve every v1/v2 financial row and
protected evidence hash. The full suite passes 2,878 tests / 21,930 assertions. Local historical
continuity is accepted within these evidence limits. Strict real-evidence display gaps stay.
Production backup, release/apply and active-method approvals remain separate and incomplete.

## Final local context cleanup — 2026-09-21

Root, Laravel, ContractStatistics and CanonicalPricing (including SupplierAdjusted, MarketReset
and ForwardPremium) contexts now distinguish retained v1/v2 Historical behavior from explicit v3
Current semantics on dated evidence. Earlier release records remain intact. The current producer
selects v3 only for exact active-v3 configuration; defaults and public v2 remain unchanged.
Preview requires an explicit 512 MiB limit and one nice-15 worker per date, sequentially; the
144.5 MiB peak does not prove 128 MiB or unbounded-range safety. No compute was repeated for this
documentation-only unit. Release still requires explicit Git push approval, a current verified
full production backup, production apply approval, and separate active-method switch approval.
No automatic deployment-time rebuild or production authorization is implied.
