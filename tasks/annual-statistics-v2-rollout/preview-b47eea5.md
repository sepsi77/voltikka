# Completed production preview for b47eea5

This is the first candidate preview, not applied data. Its quarterly seasonal results need correction before rollout. Do not use these counts or medians as the final apply manifest after the next code change.

## Coverage
All 232 available evidence dates from 2026-01-21 through 2026-09-10 completed. There are no duplicates. Calendar date 2026-02-12 has no evidence and was not fabricated. The first process timed out after 79 complete dates; six subsequent bounded monthly batches completed the remaining 153 with exit 0 and no failed calculation dates. All processes used asserted MySQL read-only transactions; no apply or activation ran.

| Measure | Count |
|---|---:|
| Candidate available annual rows | 212,960 |
| Candidate unavailable results | 101,122 |
| Matched old/new date-contract-consumption identities | 193,808 |
| Newly available identities | 19,152 |
| Previously available identities now unavailable | 1,653 |
| Matched aggregate identities | 6,609 |
| New aggregate identities | 979 |
| Lost aggregate identities | 0 |

These are dated consumption identities and aggregate rows, not distinct contracts or a guarantee that public sample-floor rules keep every chart line. Candidate evidence totals 314,082. Unavailable results include identities that never had a stored v1 annual value; they are not all losses.

Combined summary: `/tmp/annual-v2-production-preview-summary.json`. Original and monthly logs are `/tmp/annual-v2-production-preview*.log`. Largest delta diagnostics: `/tmp/annual-v2-production-delta-review.php` and `.log`.

## Outlier review and rollout hold
Four production dates were inspected read-only: January 21, April 8, June 1, and September 10. The three largest absolute aggregate changes and three largest matched-member changes were inspected per date; this is a bounded numerical review, not examination of every contract-day result.

- January 21 quarterly/18,000 kWh: median €2,396.94 to €2,123.65; same 12 members, all seasonal-index estimates.
- April 8 quarterly/18,000 kWh: €1,561.80 to €3,360.91; same 15 members, all seasonal-index estimates.
- June 1 quarterly/18,000 kWh: €1,538.04 to €3,572.58; same 13 members, all seasonal-index estimates. The 5,000-kWh median is €469.70 to €1,034.85. This is not a membership-change effect.
- September 10 open-ended/18,000 kWh: €2,262.75 to €2,484.72; same 39 members. Most revised results explicitly hold the current supplier price because the dated price-episode anchor is unavailable. No available identity was lost on that date.

A code review then confirmed a reference-period mismatch. The request's `anchorPeriodMonth` is a month inside the known period. The seasonal fallback uses that month's index even for quarterly/seasonal/other cadence. Thus June represents an entire Q2 price, unlike the documented quarter reference used by the forward path. An independent read-only review checked the code, full market-reset context, and historical decisions and found no deliberate exception supporting that choice.

Synthetic proof: Q2 indices April 0.9, May 0.6, June 0.3 have day-weighted quarter mean 0.6. At a published 10 c/kWh, July index 0.6 gives 20 c/kWh with the June-only denominator but 10 c/kWh with the quarter denominator. June remains exact. This proves the period mismatch; it does not establish how much of the production median increase the fix removes.

Prepare a narrow cadence-aware seasonal reference correction, retain beta and all other model assumptions, increment deployed cache schema 16 to 17, and obtain a new code-release approval. A fresh complete preview is required afterwards. Do not merely rerun dates labelled seasonal in old output: a changed reference can also make a previously guarded seasonal attempt usable instead of held flat.

The remaining multiplicative seasonal model is still lower-confidence and scales the complete retail energy rate. Correcting the denominator does not validate every resulting estimate or justify tuning beta to force desired prices.

## Loss review scope
All lost identities on the four diagnostic dates were recorded. They were canonical non-listed outcomes: Paneliankosken Kulutusjousto variants and Turku Energia Louna Helppo package variants on the earlier dates; none on September 10. The prior local review found conflicting-source interpretations and absent package allowances in those families. This does not constitute a full individual review of all 1,653 lost dated identities.

At the final diagnostic (`2026-09-11T22:49:35+03:00`), public method was still v1 and v2 annual row count was zero.
