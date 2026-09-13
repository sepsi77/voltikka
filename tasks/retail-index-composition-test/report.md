# Retail index composition test

## Result and recommendation

**Supplier membership affects some measured changes, but it is not the main difference on the complete pairs. A strict fixed-supplier target has too little current coverage for adoption as a 30-day learning target.** It is a useful audit target, not a validated forecast target or a replacement for the public median.

On 48 complete canonical 14-day term/issue pairs, the mean absolute membership/reweighting difference is **0.0201 c/kWh**. The mean absolute fixed-cohort change is **0.4095**. Membership control changes no three-state directions relative to the dynamic equal-supplier mean. Individual membership effects can still be substantial: the range is **−0.2283 to +0.1700**. These selected pairs cannot show the effect of all supplier departures: a departure of any issue member rejects the pair.

At 30 days, only **4/57** canonical current/target pairs remain, and **none** has complete same-basis lag-7 evidence. This coverage failure is the decisive result. Do not weaken the cohort rule after viewing these outcomes.

## Evidence and fixed method

Only `../supplier-premium-coverage/export-20260913T094138Z/` is read. Manifest SHA256: `6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6`. All files, byte counts, row counts, SQL hashes, method pair and export bounds pass checks.

The rebuild matches **11,608 General offer-days**, **9,094 supplier/term/basis dates**, **477 market dates**, **27 suppliers** and **780 contract IDs**. Counts by 6/12/24-month term are **1,989 / 4,858 / 4,761**. Exclusions are 6,801 wrong FixedPrice/term-segment rows, 7,205 non-General rows and six invalid/Spot rows. Rates must be finite and in 0.005–50 c/kWh. Fees are excluded.

Household/Both/legacy-null audience and household VAT scope come from the snapshot producer. The export does not independently prove all historical audience, VAT, national availability or variant classifications. Supplier identity is exact `company_name`, because this export has no company ID. No fuzzy merge is used.

Definitions, for each exact term/date/basis:

- **C:** median over every eligible General contract offer, with one weight per offer.
- **D:** equal-supplier arithmetic mean of each supplier's General offer median, using that day's eligible suppliers.
- **F:** select every supplier available at the issue date. Freeze equal weights 1/N. At current, lag-7 and target, use those same suppliers. Any missing member makes the required value unavailable. Never replace a member or renormalize.

The public unit median includes eligible Time/Season offers. **C is narrower and is not that public target.** C and D also use different aggregation functions; C−D differences are not pure provider effects.

Observed evidence ends July 26; canonical begins July 27. All comparisons stay in one basis and use exact calendar dates. No gap fill, survivor-only selection, cross-basis fallback or future cohort selection occurs. Lag-7 means the exact earlier date, not a requirement that all intervening dates exist. Current/target diagnostics need no lag evidence. Feature-ready rows also require every fixed member at lag-7.

F is specific to each issue cohort. Its values across different issue dates **do not form one coherent daily series**. Do not difference that construction across issue dates. For each retained pair, the three target changes use identical dates and eligibility.

## Canonical results

All values are c/kWh. `|Δ|` means mean absolute current-to-target change: the unchanged-price baseline MAE **for that particular target**, not a test of forecast accuracy. A smaller value for a different target does not show improved forecast skill.

| Term | Horizon | Same-basis candidates | Complete current/target | With lag-7 | Mean abs ΔC | Mean abs ΔD | Mean abs ΔF | Mean abs (ΔD−ΔF) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 6 | 14 | 35 | 16 | 8 | .7944 | .6234 | .6326 | .0333 |
| 12 | 14 | 35 | 13 | 6 | .5473 | .4483 | .4591 | .0146 |
| 24 | 14 | 35 | 19 | 12 | .1689 | .1748 | .1876 | .0129 |
| 6 | 30 | 19 | 3 | 0 | 1.4317 | 1.2202 | 1.2137 | .0598 |
| 12 | 30 | 19 | 0 | 0 | — | — | — | — |
| 24 | 30 | 19 | 1 | 0 | .2600 | .2575 | .2674 | .0099 |

The 14-day diagnostic loses **57/105** candidates to missing cohort members. The 30-day diagnostic loses **53/57**. Adding lag-7 loses another **22** and **4**, respectively. These are whole-pair failures, not excluded suppliers with redistributed weights.

Pooled 14-day diagnostics have 48 term rows across **31 issue days**. Mean absolute changes C/D/F are **.4799 / .3984 / .4095**. The 26 feature-ready rows cover **20 issue days**; on that smaller identical cohort they are **.3285 / .2409 / .2477**, with mean absolute membership difference **.0088**. Do not compare these two rows as if they had the same sample.

At 45 days, canonical has only four issue dates per term: **0/12** complete fixed-cohort pairs. At 60 days there are **no same-basis canonical candidates**. The canonical span is 49 calendar dates. All 477 issue cohorts have **6–25 suppliers**. There are no one- or two-supplier cohorts; all retained results are in the at-least-three group. `metrics.csv` retains the zero one/two groups explicitly.

### Membership differences and signs

Define `E = ΔD − ΔF`. This isolates membership/reweighting **under this construction**, not pure product mix or a causal decomposition. Since every old member must remain, complete pairs can measure the effect of new suppliers and the resulting dynamic weights, but cannot measure missing old members. This is outcome-availability selection, despite issue-only cohort selection.

Canonical 14-day E is nonzero above .0001 in **38/48** pairs. Signed E p10/median/p90 is **−.0328 / −.0039 / +.0078**; mean absolute E is .0201. At 30 days all **4/4** have nonzero E; p10/median/p90 is **−.0590 / +.0026 / +.0636** and mean absolute E is .0473. Per-term distributions, extrema and aggregation differences `ΔC−ΔD` are in `metrics.csv`. No explained fraction is reported: components can cancel and there is no identified causal partition.

With zero defined as absolute change at most .0001, D and F have **0/48** three-state sign differences at 14 days and **0/4** at 30 days. C versus F has **6/48 (12.5%)** differences at 14 days, including **5/47 (10.6%)** strict opposite directions among pairs where both are nonzero. By term, C/F sign differences are **2/16, 0/13 and 4/19**. At 30 days they are **0/4**. The C/F differences cannot be attributed solely to supplier membership because the median-to-mean change also matters.

## Older observed evidence, kept separate

| Term | Horizon | Complete / candidates | Mean abs C change | Mean abs D change | Mean abs F change | Mean abs membership difference |
|---|---:|---:|---:|---:|---:|---:|
| 6 | 14 | 19/96 | 1.2253 | 1.0375 | 1.0361 | .0030 |
| 12 | 14 | 67/96 | .3054 | .2271 | .2271 | .0000 |
| 24 | 14 | 60/96 | .1643 | .1708 | .1716 | .0008 |
| 6 | 30 | 0/80 | — | — | — | — |
| 12 | 30 | 34/80 | .3879 | .2888 | .2888 | .0000 |
| 24 | 30 | 27/80 | .1844 | .1591 | .1595 | .0019 |

Observed complete/feature-ready totals at 14/30/45/60 days are **146/132, 61/53, 14/9, 0/0**. D/F has no sign differences on retained observed pairs. C/F differs in **6/146** at 14 days and **2/61** at 30 days. These selected older samples do not extend canonical horizon coverage.

## Turnover and the remaining product problem

Across 48 adjacent canonical dates per term:

| Term | Supplier entries / exits | Contract ID entries / exits |
|---|---:|---:|
| 6 | 10 / 13 | 42 / 46 |
| 12 | 20 / 21 | 91 / 94 |
| 24 | 22 / 22 | 96 / 97 |

Counts are appearances in this eligible date panel, not verified business launches or withdrawals. A reappearance can count again. Observed term counts and exact sets are in `turnover-summary.csv` and `daily-indices.csv`.

The independent raw rebuild reproduces the [cadence report](../fixed-term-repricing-frequency/report.md): **473 supplier median changes**, of which **472** coincide with changed ID sets and **430** have fully disjoint ID sets; only **one same-ID price change**. Thus fixed supplier weights do not control product identity or promotion/variant mix within a supplier. The earlier dated-lineage sensitivity maps only **4,648/11,608 days (40%)**, with **222 ambiguous days** and **112 adjacent carrier-switch price changes**. It is not a complete contemporaneous successor ledger. See that report and its mapping audit; do not reuse later full lineage membership as historical truth.

The remaining blocker is a dated, source-supported mapping of comparable offer variants across ID replacements, including energy-promotion phases, fee-only promotions and eligibility. That evidence must distinguish comparable successor prices from a changed variant population. Neither identical IDs nor supplier medians solve it. These results cannot quantify all pure product-composition effects.

## Truly fixed July 27 index

Weights are selected once from July 27, separately by term, with all members required on every exact later date:

| Term | Fixed suppliers | Available dates / 49 | Missing dates |
|---|---:|---:|---:|
| 6 | 10 | 1 | 48 |
| 12 | 22 | 16 | 33 |
| 24 | 23 | 15 | 34 |

`july27-index.csv` preserves every weight and missing-member list. No date is filled. This truly stable series is not feasible as a continuous current market index with this coverage.

## Reuse, limits and verification

Use `analysis.py` pure functions `select_cohort(issue_supplier_medians)` and `fixed_value(weights, date_supplier_medians)`, or `feature-target-rows.csv` (**220 rows total; 26 canonical, all at 14 days**). That CSV contains lag-7/current/target levels and fixed seven-day changes. A future model must use only issue/past feature columns; target values, target availability and missing-target lists are outcome audit data, not features. Dated rows are revised export-time evidence, not proven original job vintages. Strict date selection alone does not establish archival vintage safety.

Overlapping windows, shared supplier shocks and term dependence prevent independent-row significance claims. All pooled results give one weight per term/issue row. No model was fitted or merged, and no public target or application setting changed. A separate forward-only experiment needs longer canonical coverage and archived comparable variants. Smoother targets alone are not evidence of accuracy.

Commands from repository root:

```sh
python3 tasks/retail-index-composition-test/analysis.py
python3 tasks/retail-index-composition-test/verify.py
python3 -B tasks/retail-index-composition-test/reproduce.py
```

All final commands pass. Independent Decimal checks rebuild raw medians, match **9,571 prior pilot index rows**, validate **19 unchanged cadence artifacts**, and reproduce the original cadence counts including **891 canonical supplier 30-day pairs**. Checks cover **1,908 candidate pairs**, **36,376 member rows**, **256 metric cells**, **147 fixed-launch dates**, exact dates/bases, complete membership, equal weights summing to one, missing-member rejection, aggregation, signs, distributions, turnover and feature-row selection. Synthetic cases cover future entrants, missing old members and a valid single zero-price member in the pure aggregation function. The production eligibility range still excludes zero signup rates.

The first development check correctly found that old task reports/decision files had authorized continuation changes. The preserved-artifact check now follows the earlier reproducibility script: it checks the 19 original science/code artifacts, not the four updated task/report files. No old file was changed here.

Two complete runs produced **10 byte-identical CSV/JSON artifacts**; see `reproducibility.json`, `independent-checks.json`, `analysis.log` and `verify.log`. `pair-audit.csv` retains every unavailable pair, reason, cohort and missing member; `cohort-members.csv` retains exact rational weights and all three member levels. No Laravel test or asset build was needed because only offline research files changed. No production access, database operation, dependency install, application change, commit or push occurred.
