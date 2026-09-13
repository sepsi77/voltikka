# Decisions

- Freeze L=0/7/14/21/30 and the 30-day horizon before calculation. Omit optional horizon 14 to keep this unit bounded. Do not select a lag from these results.
- Import the prior verified export/ownership helpers read-only. Extend only the hedge vintage cutoff; delivery months remain selected at the retail date.
- Use current-generation unrounded expected change for new labels, then four-decimal storage. Use saved original directions for v2 references. Decimal four-decimal comparisons are diagnostics, not replacements.
- Hash the raw export, prior research folders, and current forecast service sources before any analysis. Do not rerun prior scripts that write into prior folders. Retained actual-PHP proof is sufficient if these hashes and the prior reproducibility chain pass.
- Preserve native history and common historical support as separate contexts. Do not relax minimum warmup. Keep observed and canonical results separate.

## Completed evidence

- Full canonical primary intersection: 57 rows, July 27–August 14, 19 dates and 19 rows per term. All five lags have full current hedge coverage and warmup. The matched original-v2 reference has 48 rows; August 2–4 have no stored v2 rows.
- Canonical MAE at lags 0/7/14/21/30: .7320/.7028/.6799/.6747/.6975 c/kWh. Correct counts: 16/16/17/16/17. Larger numerical improvements do not give comparable direction improvement. No lag is selected.
- Common history has 79–97 prior days and starts May 9 for all canonical forecasts. Native histories differ by 30 earliest dates between L0 and L30. Common support leaves canonical stored forecast prices and directions unchanged; raw EWMA values differ slightly. `history-sensitivity.*` retains each difference.
- Observed intersection: 117 rows on 39 issue dates, May 19–June 26. It has 67 rises, 40 flat outcomes, and 10 falls. Keep its distinct class balance and outcomes separate from canonical's 44 rises/13 flat/no falls.
- August 8/9 six-month L0 wrong falls become missed rises under all four older-vintage variants. The N shift can more than offset a lower H. `diagnostics.*` retains the predeclared higher-H, gap-sign-change, and strong-fall flags, without selecting examples as a tuning rule.
- Retained actual-PHP evidence passes its original hash chain. L0 matches 147 generated canonical median rows numerically and by saved class/history count. A further 297 exact 30-day pair rows across both bases match the prior PHP-verified helper evidence, including unavailable forecasts. No database is opened.
- Independent verification passes 2,385 daily-expanded hedges, 9,540 closed-form EWMA candidates, 4,194 cohort rows, 328 metric groups, and 15 synthetic checks. There are zero raw-versus-stored-four-decimal direction disagreements among 3,774 eligible forecasts across both support contexts.
- `python3 tasks/forecast-lagged-levels/reproduce.py` passes twice with 21 byte-identical artifacts and 138 pinned source files unchanged. It also checks the earlier direction-sensitivity input manifest and the earlier horizon replay artifact hashes. No prior writer runs.
- Keep the current model unchanged. A forward-only shadow comparison of the frozen grid needs separate approval. No statistical confidence, causal effect, independent-row p-value, automatic release, production action, commit or push is justified.
