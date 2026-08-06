# Versioned historical annual-cost statistics

## Goal

Rebuild historical annual-cost statistics with the current annual-pricing algorithm while preserving the evidence that was available on each statistics date. The public statistics page must never connect or compare annual-cost points that use incompatible calculation methods.

## Requirements

- Version derived annual-cost snapshot and aggregate rows independently from observed unit-price facts.
- Preserve historical `energy_price`, `spot_margin`, `spot_total_energy_price`, and `monthly_fee` evidence.
- Recalculate historical annual costs from the contract universe and prices observed on each date, not today's active-contract set.
- Use only canonical interpretations, source episodes, Spot history, and futures evidence that were available on or before the target date.
- Never apply today's current canonical pricing JSON to an old date.
- Spot annual costs must use the current forward estimator when the required as-of curve exists and its typed rolling-365 fallback otherwise.
- Supplier-adjusted open-ended annual costs must use an as-of price episode and as-of market evidence. Unsupported historical reconstruction must fail closed or use the estimator's explicit typed fallback.
- Store enough method and estimate-basis provenance to prevent mixed-method 30-day changes, since-start changes, weekly/monthly averages, and sparklines.
- Current daily collection and historical rebuilding must produce the same method-version contract for the same date and evidence.
- Do not rewrite immutable source evidence. Derived statistics can be replaced only after the new version passes validation.
- Keep historical rows from the previous method available for audit unless a documented migration safely replaces only derived data.
- The public page and CSV must explain and expose the active annual-cost method version and fallback basis.
- Add regression tests for look-ahead bias, survivor bias, missing old curve vintages, method cutovers, cache invalidation, and current-page values.

## Current implementation boundary

The typed exact-date evidence resolver, calculator, annual-only writer, and dry-run-by-default historical rebuild command are implemented for shadow `annual_cost_as_of_v1` data. Historical dates and contracts use the union of exact-date snapshots and components; component-only identities become typed exclusions, while snapshot-only canonical evidence remains eligible. Apply requires one complete three-consumption result set and exact-target Spot evidence. Canonical current daily calculation writes the same AsOf method inside its required date transaction, but it adapts the exact canonical outcomes already calculated for the public ranking instead of invoking the strict historical calculator. Current provenance uses batched current pointers and does not query `price_components`. Feature-off and observed historical runs remain legacy-only. Public readers and caches are method-isolated and the CSV exports every method for audit. Company comparison now supports either active annual method and reads AsOf seller totals only from the versioned annual table. Every public annual trend uses one shared compatibility guard: all-null legacy keys form one regime, mixed periods and the first point after a transition are chart gaps, and deltas require the same key. Spot win rates use only each segment's newest regime. The main endpoint rejects a stale canonical AsOf row after a feature-off recalculation. Aggregate market insights compare a deterministic same-key segment intersection rather than requiring one global key. Production activation does not exist yet. The configured and public method remains `annual_cost_legacy_v1`.

## Production note

Any production recalculation writes database rows and requires explicit user confirmation after the code is deployed and validated.
