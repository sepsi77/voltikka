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

## Root causes

1. **Price-episode anchor.** `HistoricalPriceEpisodeResolver` gives a proven episode start only
   when the preceding calendar date exists with a different rate or fee. A price that has not
   changed since the dataset start (Jan 21) has no start. With `startedAt = null`,
   `SupplierAdjustedPriceEstimator::forwardShift()` and `seasonalIndexShift()` both return null,
   and the result is hold-flat. The current policy accepts the left-censored first observed date.
2. **Premium fallback.** `ComparisonPolicy::Historical` ignores the comparable-premium fallback
   (`forwardPremium()`), because the current premium loader reads current peers. No as-of version
   of the premium evidence loader exists.
3. **Jul 23 seam.** Jul 22 is the cutoff for dedicated historical interpretations. From Jul 23
   the resolver uses immutable source observations and requires an interpretation completed by
   the target day. Hypothesis, not confirmed: the Jul 23 interpretations were completed after
   Jul 23, so the covering observation closes the dedicated path and no canonical data is
   accepted. The rollout notes do not mention this date.
4. **No rebuild after a method release.** Release `111a101` changed the current method, and the
   history kept the old method.

## Goal

One continuous annual-cost series for each segment from the first date on which the current
method is possible, with at most one method transition in spring 2026 that comes from the real
futures data gap.

## Scope

1. Find the cause of the Jul 23 seam and correct it. If the hypothesis is correct, decide the
   evidence rule (for example, accept the first valid interpretation of the same covering
   observation episode) and rebuild that date.
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

- No look-ahead. A historical date can use only evidence that existed on that date. Do not apply
  today's interpretation or today's peers to a past date.
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
