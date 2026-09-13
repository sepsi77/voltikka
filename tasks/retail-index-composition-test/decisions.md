# Decisions

- Freeze all available issue-day suppliers with equal weights. Supplier identity is exact exported company_name: the export has no company ID. No fuzzy name merge or retrospective lineage join.
- Use the same General eligibility as the supplier pilot. Trust the producer's household/VAT scope; do not claim independent historical VAT or audience proof.
- Pairwise index values are specific to each issue cohort, not one coherent series. Lag-7 features require all members at exactly issue minus 7 days in the same basis; no full intervening-day requirement is imposed.
- Current/target diagnostics do not require lag history. Keep feature-ready results separate. All candidate issues are retained, including future targets outside the export and basis-seam failures.
- Difference between dynamic equal-supplier change and fixed-cohort change measures membership/reweighting under this construction. It does not identify within-supplier product composition. Contract-median versus supplier-mean differences also change the aggregation functional.
- Use 0.0001 c/kWh for nonzero signs. Report both three-state sign differences and strict opposite nonzero directions, with their own denominators. Do not report an explained fraction: signed components can cancel and there is no causal partition.
- Equal weight per term/issue row in summaries; terms and overlapping windows are not independent observations. No fit, parameter selection or significance test.
- July 27 daily cohort is a predeclared coverage sensitivity, not selected for later survival.

## Completion

- Rebuilt exactly 11,608 offer-days and matched prior medians and cadence counts. Canonical 14/30-day complete pair counts are 48/4; lag-7-ready counts are 26/0. The fixed July 27 series has only 1/16/15 available dates by 6/12/24-month term out of 49.
- Supplier membership effects are nonzero but modest on average in retained 14-day pairs (.0201 c/kWh absolute mean); missing departures prevent an all-market decomposition. No adoption as a validated 30-day learning target is supported.
- Retain all 1,908 candidate pairs and 36,376 member rows. Reusable feature CSV has 220 rows, of which 26 are canonical. Do not use outcome or missing-target fields as features.
- Independent verification passed. Two runs produced ten byte-identical output artifacts. Earlier report/task files have authorized continuation edits, so prior hash checks cover the same 19 science/code files as the prior reproducibility check, not four mutable task/report files.
- No app, other task, production, database, model or dependency change was made. The residual comparable-variant mapping problem remains outside this unit.
