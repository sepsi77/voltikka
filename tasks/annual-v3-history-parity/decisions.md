# Decisions

## 2026-10-16 — Method and evidence

- Data: local production copy `laravel/database/database.sqlite` (synced 2026-10-16 11:27, data
  through 2026-10-15), read only. All runs used a scratch copy with `PRAGMA query_only = ON` and a
  guard that refuses the repository database (`tools/compare.php` prints the effective file).
  Writes (step 4) used a separate scratch copy only.
- Tool: `tools/compare.php` runs `AsOfAnnualCostCalculator::calculate(date, v3)` in memory (the
  same calculator the rebuild command uses) and writes one JSON per date with the historical and
  the stored v3 row for every contract and consumption. `tools/causes.py` classifies differences,
  `tools/analyze.py` gives segment median deltas, `tools/impact.py` the older-history impact,
  `tools/regime.php` medians, `aggregateDisplayKey()` regimes and stored compatibility keys.
  `tools/run.sh` holds the scratch paths; change them before reuse. Tolerance: EUR 0.01.
- 2026-10-07 is excluded from parity statistics (the local copy still has the incomplete-futures
  rows that production deleted). 2026-10-01 has no stored rows (no snapshots).
- The scratch tools are kept as task artifacts, not application code: the rebuild command already
  compares aggregates, and a per-contract classification is an analysis, not an operation.

## Baseline (before the fix), 22 dates 2026-09-22..2026-10-15

16,452 contract-consumption pairs: 13,453 equal, 2,886 changed, 113 lost
(`results/parity-before-causes.txt`). Causes:
- Anchors (cause a): 1,613 pairs, 49 open-ended contracts. Two defects in the v3 anchor resolver:
  1. every snapshot/component date was an event and needed snapshot identity, so 2026-10-01 (no
     snapshots, but complete source chronology) broke every episode;
  2. before the first immutable observation, dedicated backcast interpretations supplied the
     signature. Several backcasts read a different mechanism (for example a recurring schedule for
     Imatra Huoleton), so the run broke at the source start. The current resolver uses dated raw
     snapshots/component tariffs there.
  Result: own-reference forward shift instead of the comparable premium (Oomi Vakaa 749 → 678,
  Nurmijärvi aikasähkö 735 → 1261 at 5,000 kWh) and a premium donor pool that differed.
- Premium amounts with equal anchors: 432 pairs, 16 contracts (donor pool, consequence of above).
- Target validation (cause b): 740 pairs, 22 contracts. 15 by the date guard (quarterly calendar
  windows without a year, same-year ranges `1.10.-31.12.2026`, relative `after_months` promotions,
  Ranta 18 kk), 7 by the full validator because their citation `analysis_date=2026-10-01` no longer
  equalled the target date.
- 2026-10-02 only: 153 spot + 34 reset pairs move EUR 5–7 (late EEX import, see below).
Median deltas (historical − stored) at 5,000 kWh: open_ended −70 (−73…+5), quarterly +62 (max +82),
market_reset max +14; at 18,000 kWh open_ended −257 (min −271), quarterly max +295.

## Fixes (v3 only)

1. `HistoricalPriceEpisodeResolver` v3 follows the `CurrentPriceEpisodeResolver` event model with
   dated exact-contract inputs: pre-source raw evidence through the current resolver's own helpers
   (made public, no behaviour change), only observation boundaries after the first observation,
   per-observation exact-source v3 signatures from the new batched
   `AsOfAnnualCostEvidenceResolver::sourceObservationEvidence()` (classification from the dated
   payload `Details`, validation on the observation's first date). Overlapping observations must agree.
   Date safety: observations, payloads and snapshots are dated; the interpretation selection is the
   approved v3 latest target-validated exact-source rule. No current pointer is read.
2. `AsOfAnnualCostEvidenceResolver::hasSupportedTargetDates()`: a calendar year can also come from a
   cited same-year range, or from a cited monthly/quarterly window that contains the observed target
   date (phases may also use the following window). Relative `after_months` boundaries are accepted:
   they count from the customer's contract start and make no calendar claim. Before full validation,
   an `analysis_date=X` citation is moved to the target only when X lies between the first day of the
   covering observation and the target. Not relaxed: a calendar date without a year proof, a window
   that does not contain the target, an analysis date before the covering observation, any validator
   error at the target (for example a phase that ended before the target).
3. Test fixtures now carry realistic source payload `Details` (every stored payload has them:
   3,642/3,642 in the local copy). Old v3 tests that encoded the per-day event model were updated to
   the current semantics (day without snapshot = gap, equal overlapping observations agree).

## After the fix, same 22 dates

15,346 equal, 1,106 different (`results/parity-after-causes.txt`):
- Replacement lineage, 826 pairs: anchors of Iin Yleissähkö, Imatra Huoleton, Oomi Vakaa, Parikkala
  Kesto Valo, Vihreä Vakaa valinta (ckriqr) and three Seinäjoki SE Oikea contracts, plus the premium
  donor pool of 16 open-ended contracts. The current resolver reads `replaced_by_contract_id`; there
  is no dated record of when a link was made, so history keeps exact-contract identity (existing
  documented limit). Scratch experiment `tools/LineageResolver.php` with current links:
  0 differences on 2026-09-22/30, 10-06, 10-12 (`results/lineage-experiment-causes.txt`).
- 2026-10-02 EEX, 277 pairs: trade dates 1.10/2.10 arrived on 3.10 (stuck 2.10 run). History uses
  the market vintage by trade date (strictly before the target), as documented for v3.
- 2026-10-02, 3 pairs: Kokkola Vuodenaika Uusiutuva Q3 interpretation ends 2026-09-30; full target
  validation rejects it (date-safety rule; the writer still used it).
Medians: open_ended +3 / +9 / +39 (2,000/5,000/18,000 kWh; max +16 at 5,000, +117 at 18,000);
quarterly, spot, market_reset 0 except 2026-10-02 (≤ +7 at 5,000). Fixed-term and hybrid 0.

Decision for the manager: dated replacement trust (for example "use A→B only when both endpoints
were observed by the target date") would remove the remaining open-ended bias, but it reads a
current pointer without a creation date. Not implemented; needs a policy decision.

## Older history impact (243 stored v3 dates 2026-01-21..2026-09-21, all three consumptions)

`results/older-history-impact.txt`. 76 dates change: 2026-02-13..02-27 (Oomi Vakaa nn2zdu anchor
moves to the left-censored 2026-01-21) and every date 2026-07-23..2026-09-21. 216,304 pairs equal,
5,063 changed, 714 new, 9 lost, 52 contracts.
- New: 672 Spot pairs (5 promotion contracts with relative months: Sähkötytöt, Aalto Luotsi,
  Vihreä Vire Tuuli ×2, Vihreä Opiskelijan Verraton), 30 Cheap Kvartaalisähkö, 12 Kokkola Tyyni
  6e4ly6. Lost: 9 (Kokkola Tyyni e9n4yq: canonical now selected, outcome not listed; was relational).
- Moves: 768 pairs none → recurring_forward_curve_shift, 48 none → hybrid_base_only (equal cost).
- Medians at 5,000 kWh: open_ended −28…−56 on 2026-07-24..2026-09-10 (p10 −46.5), +5…+7 on
  2026-09-15..21; quarterly ≤ +6.6 (10 days); spot ≥ −1.7; market_reset ≤ +5; fixed-term and hybrid 0.
- Dominant-family transitions after 2026-05-01: none created or removed (quarterly 2026-07-01 stays).
- Seam: open_ended 5,000 kWh 2026-09-21 new 801.33 → stored current 2026-09-22 774.41 (old 794.26).
  The 2026-09-22 historical value is 786.45; the gap is the lineage difference.
So the fix also moves older stored values. Under "History follows the current method" the 76
changed dates need a production v3 re-apply (unchanged dates would be rewritten identically).

## 2026-10-07 and 2026-10-01 (scratch previews, not production)

2026-10-07: on a scratch copy with production's deletion mirrored (447 rows / 21 aggregates left),
the fixed rebuild preview gives evidence 1,113, available 748, contracts matched 447 + new 301 (the
exact removed set), aggregates matched 21 + new 9. Apply: persisted 748, aggregates 30. All annual
rows/aggregates except v3 2026-10-07 and all snapshots keep identical full-row hashes. Fixed-term
and hybrid rows are unchanged; quarterly rows change (stored 599 → 611 at 5,000 kWh; neighbours
610/614) because the stored quarterly rows also came from the incomplete-futures run. Medians at
5,000 kWh (10-06 / 10-07 / 10-08 stored): spot 529 / 527 / 534, open_ended 748 / 755 / 753,
market_reset 714 / 733 / 740, quarterly 610 / 611 / 614. Display regime and stored compatibility key
equal both neighbours in every segment and consumption (`results/scratch-2026-10-07-regimes.txt`).
Decision: a full-date apply is better than restoring three segments: it is the existing tool, keeps
fixed-term/hybrid identical and also repairs quarterly.

2026-10-01: the fixed path cannot fill it. All 369 identities on that date are component-only
(`missing_historical_snapshot_identity`); the rebuild finds 0 available results. Tested option A on
scratch: `contracts:backfill-price-statistics` for 2026-10-01 creates observed-basis snapshots
(263; text-first segments, no `market_reset`, 7 more members than 2026-09-30), and the v3 rebuild then
writes 744 rows / 27 aggregates. market_reset has no point, hybrid/open_ended/quarterly get a
different stored compatibility key, observed unit rows appear inside the canonical era.
Recommendation: leave 2026-10-01 empty (one missing day).

## 2026-10-16 (later) — Dated replacement lineage (user decisions)

State before this unit: c12cc7a (the fixes above) is deployed; production 2026-10-07 was refilled
with a full-date apply (748 rows / 30 aggregates). This unit is local and uncommitted.

1. User decision: historical v3 trusts replacement links (`replaced_by_contract_id`) like the current
   writer. Explicit, documented exception to "no current pointers".
2. User scope addition: a link gets a date, and history trusts it only when that date is on or before
   the target ("The link is usually created on the date when old contract disappears from the source
   API and a new one becomes available").

Implementation:
- Column `electricity_contracts.replaced_by_linked_on` (nullable DATE, Europe/Helsinki). A date, not
  a timestamp: history trusts a link per Helsinki target date, and a backfill can only derive dates.
- `ContractReplacementLinker` writes the Helsinki date of a new link; it never changes existing links.
- Migration `2026_10_16_000001` adds the column and fills null dates: max(successor first seen,
  predecessor last seen) + 1 day, from source observations and price components (sources start on
  2026-07-23 for every contract, so they alone would hide earlier evidence). Why "+ 1 day" also for the
  successor: the matcher links only to ACTIVE candidates, and a new contract is active only after its
  first validation, which runs after its first import. Evidence: with "successor first seen" alone,
  history used lineage on 2026-10-10 (Vihreä Vakaa valinta) and 2026-10-13 (three Seinäjoki SE Oikea),
  but the stored current rows show no lineage on those first days; with "+ 1 day" both dates match.
  Production copy: 4,083 links, 0 null; successor date from price components 2,861, from source
  observations 1,222 (ties count as source); 49 bounded by the predecessor's later last observation
  (median 11 days, max 231). Migration time about 1.5 s, 87 MB RSS.
- Shared model API: `getReplacementLineageIdsByContractIds($ids, ?$asOf)` and
  `getLineageIdentitiesByContractIds($ids, ?$asOf)`. With an as-of date a link counts only when its
  date is non-null and ≤ as-of. Without it (current writer, presenters, RetailPremium) nothing changes.
- V3 anchors (`HistoricalPriceEpisodeResolver`) use lineage carriers like the current resolver, and the
  premium adapter uses lineage identity keys; both pass the target date. Anchor flag
  `historical_trusted_replacement_lineage` replaces `historical_exact_contract_only`.
- The rebuild command's stale warning "V3 is not ready for release…" is now "V3 rows are public
  history: preview first; --apply needs a verified full backup and explicit approval. Do not apply
  dates written by the current daily writer."

Parity, 22 dates 2026-09-22..2026-10-15 without 10-01/10-07 (`results/parity-dated-lineage-*.txt`):
16,115 pairs equal; the 337 different pairs are all on 2026-10-02 (late EEX import 334, Kokkola stale
interpretation 3). All other 21 dates are exact. Undated lineage (first unit of this task) left
2+21 extra pairs on 2026-10-10/13 (`results/parity-undated-lineage-causes.txt`).

2026-10-07 (production holds the c12cc7a full-date apply): with dated lineage 33 open_ended pairs
(11 contracts) change; open_ended median 755.26 → 746.98 at 5,000 kWh (335.28 / 2,538.42 at
2,000 / 18,000). Neighbours stored: 748.42 (10-06), 753.00 (10-08). It must be re-applied.

Older history (`results/older-history-impact-dated-lineage.txt`): 220 of 243 dates change:
2026-02-13..2026-02-27 and 2026-03-01..2026-09-21 (`results/reapply-dates-dated-lineage.txt`).
Unchanged: 2026-01-21..2026-02-11, 2026-02-28 (2026-02-12 has no data). Pairs: 213,217 equal,
8,150 changed, 714 new, 9 lost, 60 contracts. Method moves: none → recurring_forward_curve_shift
768, premium → own-reference shift 432, shift → premium 315, none → hybrid_base_only 48 (equal cost).
Medians at 5,000 kWh: fixed-term and hybrid 0; open_ended median −0.5 (p10 −72, p90 +16, min −109,
max +25; 156 days ≥ 1 EUR); quarterly ≤ +6.6; spot ≥ −1.7; market_reset ≤ +5. At 18,000 kWh open_ended
min −427, max +92. Main effect: 2026-07-24..2026-09-14 open_ended −60…−109 (old series jumped
661 → 750 on 07-24; the new one goes 668 → 682). New one-day step 2026-08-08 → 08-09 (733 → 693):
the Iin Yleissähkö link becomes effective on 08-09, Iin stops being a high-premium donor and becomes
a premium target; same display family, so no chart gap. Seam: 2026-09-21 new 788.87 → stored current
2026-09-22 774.41 (old 794.26).
Display regimes after a full scratch apply (aggregateDisplayKey, from 2026-05-01): only the real
quarterly 2026-07-01 transition remains in open_ended/hybrid/spot/market_reset/quarterly; none created.

Scratch apply proof (copy of the migrated local copy): the real command, `--apply --stop-on-error`,
three invocations: 02-13..02-27 (15 dates, 39 s, 124.6 MB max RSS), 03-01..09-21 (205 dates, 539 s,
129.2 MB max RSS), 10-07 (3.5 s). 0 failed. Totals 221 dates, 200,429 rows, 6,645 aggregates.
Per-date expected totals: `results/reapply-expected-totals.csv`. Protected hashes equal before/after:
non-v3 annual rows, v3 rows on untouched dates, non-v3 daily rows, v3 aggregates on untouched dates,
snapshots. A recompare after apply shows the stored rows equal the in-memory result.
