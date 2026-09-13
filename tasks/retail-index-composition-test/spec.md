# Retail index composition test

Offline research only. Read the verified export `../supplier-premium-coverage/export-20260913T094138Z`. Rebuild exactly 11,608 eligible General snapshot-days and match the prior supplier and cadence results.

Compare contract-weighted General median, daily equal-supplier mean of supplier medians, and issue-fixed equal-supplier cohort mean. Select every supplier available at issue, by term and basis. Never select on a later outcome. Require all fixed members on each exact required date; no survivor selection, missing-member renormalization, gap fill or basis crossing.

Primary horizons: 14 and 30 days. Secondary: 45 and 60. Keep current/target diagnostics separate from lag-7/current/target learning rows. Report all, one, two and at-least-three supplier counts. Preserve unavailable pairs and member evidence. Also test the fixed July 27 cohort as a true daily series with no fill.

Report target changes, persistence MAE, sign differences, membership/reweighting differences, offer turnover and coverage by term/basis. No learned models, forecast-skill claims, causal product-mix decomposition, public-index replacement, application change, production access, dependency install, database operation, commit or push. All new work stays in this folder.

Independently verify hashes, eligibility, arithmetic, exact dates, membership and weights, prior cadence results and reproducibility. Supply reusable pure functions and feature/target CSV rows.
