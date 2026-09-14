# Futures increment: offline result

**Recommendation: do not adopt.** The primary hybrid reduces total error, mainly for six-month contracts. It does not establish falling-price accuracy. All fits are separate by duration; pooled tables only summarize predictions.

## Primary: median, 30 days, fixed delivery basket

MAE in c/kWh. All five models use the same 123 term targets on 41 issue dates. Observed: June 5–26 (66 rows, 22 dates). Canonical: July 27–August 14 (57 rows, 19 dates). Targets end September 13, 2026.

| Model | All | Observed | Canonical |
|---|---:|---:|---:|
| Unchanged | 0.554011 | 0.425676 | 0.702609 |
| Unrestricted production-rule mean | 0.303476 | 0.365170 | 0.232042 |
| Feature-cohort mean | 0.337308 | 0.439536 | 0.218939 |
| Feature mean + futures | 0.281672 | 0.345365 | 0.207923 |
| Production mean + futures (hybrid) | **0.268038** | **0.305492** | **0.224670** |

Hybrid paired error improvements: **0.285973** versus unchanged, **0.035438** versus production mean, **0.069270** versus feature mean. The direct feature-mean futures increment is **0.055636**. On canonical tests, hybrid is **0.005732 worse** than feature mean.

Hybrid/production-mean MAE by term: 6 months **0.315612/0.431346**; 12 **0.295866/0.292563**; 24 **0.192637/0.186520**. Thus, total improvement is not consistent across terms.

Actual up/flat/down counts: **83/35/5**. Hybrid MAE: **0.246947/0.262483/0.657040**; bias: **-0.171458/+0.262483/+0.657040**. Every fitted model predicts UP: 83 correct, 35 false moves, five wrong-way calls. There is no direction gain.

All five down targets are observed 12-month contracts, June 5–9. Canonical down N=0; 6/24-month down N=0. Down MAE is **0.279000** unchanged, **0.644860** production mean, **0.692200** feature mean, **0.704340** feature+futures, **0.657040** hybrid. These five overlapping cases are insufficient evidence of falling accuracy.

## Secondary checks

Rolling-delivery 30-day hybrid MAE is **0.272518**, not the primary fixed-basket result. At 14 days, fixed-basket down N=24 (23 observed on 15 dates; one canonical). Hybrid gets zero down directions correct: seven missed, 17 wrong-way. Down MAE **0.567579**, versus unchanged **0.260058**.

Synthetic x=-1/0/+1, using the first primary fit per term, gives hybrid deltas: 6 **0.7884/0.8561/0.9239**; 12 **0.0133/0.3349/0.6566**; 24 **-0.0246/0.2112/0.4471**. A small negative prediction is possible, but none reaches DOWN. This is behavior, NOT accuracy.

## Limits and evidence

Overlapping windows, revised export vintages, previously inspected data, no untouched holdout, and assumed observed/canonical continuity limit inference. April 8 export bounds omit January–April history: this compares the production RULE, not exact production 174-pair vintages.

`results.json` contains all term/regime/direction metrics and paired comparisons; `evidence.json` contains IDs, training sets and baskets; `predictions.json` contains saved outputs. See `verification.md`. Reproduce: `python3 -B tasks/forecast-futures-increment-test/reproduce.py`.
