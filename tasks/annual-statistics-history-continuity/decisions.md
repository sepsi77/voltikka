# Decisions

## 2026-09-21 — Review result

- The gaps are not caused by a missing history recalculation. Historical v2 exists for all 233
  evidence dates. The gaps come from a method difference between the historical and the current
  calculation, one one-day defect (Jul 23), and one current-method release without a rebuild
  (Sep 17). Only the Spot transition on May 1 comes from the real FI futures data gap.
- Evidence source: the public statistics page (daily and weekly views) and the public CSV
  `basis_counts`. Production was not queried. The local SQLite snapshot is from 2026-08-25, before
  the v2 application, so it cannot reproduce stored v2 rows. Run
  `scripts/sync-production-database.sh` before local investigation.
- The Jul 23 cause in `spec.md` is a hypothesis. Confirm it before a correction.

## Open decisions

- Evidence rule for Jul 23, if interpretations were completed after the target day.
- Whether pre-immutable dedicated historical interpretations are acceptable donors for as-of
  premium evidence (Apr 8 – Jul 22).
- Whether left-censored anchors are acceptable for historical dates. The current policy accepts
  them with uncertainty flags; the Historical policy rejected them deliberately.
- New method version `annual_cost_as_of_v3` against an in-place v2 rebuild. The default is a new
  version, because v2 rows are stored audit evidence and the writer replaces only one method.
- Display of the first point after a regime transition.

## 2026-09-21 — Policy reversed: history follows the current method

The user decided that the context files must not prevent a history recalculation. The rule is now
the opposite: when the calculation method changes, recalculate the history to get as complete a
series as possible.

- The canonical policy text is "History follows the current method" in
  `laravel/app/Services/ContractStatistics/AGENTS.md`. The root `AGENTS.md` has it as a mandatory
  principle.
- Replaced: "this policy does not authorize historical rewrites" in
  `laravel/app/Services/CanonicalPricing/AGENTS.md`.
- Reframed as implementation state and known gaps, not rules: the statements that Historical is
  "unchanged", "strict", "retained", or "never reads current peers" in the `CanonicalPricing`,
  `SupplierAdjusted`, `MarketReset`, and `ForwardPremium` context files. The individual sentences
  stay, because they describe the code correctly until v3 exists.
- Kept, because they protect evidence and do not prevent a rebuild: no look-ahead, no rewrite of
  observed evidence, retained earlier method rows, no automatic rebuild on deployment, and explicit
  approval plus a verified backup for a production apply.
- Effect on the open decisions above: left-censored anchors and as-of premium evidence are now the
  expected direction. The remaining question for each is how to make it date-safe, not whether to
  do it.
