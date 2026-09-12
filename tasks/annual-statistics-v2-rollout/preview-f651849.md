# Corrected production preview — f651849

## Complete historical coverage
- All nine sequential read-only batches succeeded: 233 available evidence dates, 2026-01-21 through 2026-09-11. February 12 has no evidence date. No duplicate date/aggregate/lost-identity records were found.
- 315,237 candidate contract-consumption-date results: 213,755 available and 101,482 unavailable.
- Against stored v1: 194,603 matched available identities, 19,152 newly available identities, 1,653 lost identities.
- Aggregates: 6,639 matched, 979 new, zero lost; 7,618 candidate aggregates in total. No group with at least 10 v1 contributors falls below 10 in v2.
- Largest batch peak: 80.5 MiB. No historical apply or public activation occurred.
- Full summary: `/tmp/annual-v2-f651849-preview-summary.json`. Logs: `/tmp/annual-v2-f651849-preview-<FROM>-<TO>.log`. The completed job was `job-91747-119` (54m50s).

## Numerical review
These are dated estimates, not actual electricity bills. Ranges span different consumption levels and segments.

| Date and segment | Consumption | Stored v1 median | Corrected v2 median | Contributors |
|---|---:|---:|---:|---:|
| January 21 quarterly | 5,000 kWh | €704.34 | €594.60 | 12 → 12 |
| April 8 quarterly | 5,000 kWh | €476.30 | €719.79 | 15 → 15 |
| June 1 quarterly | 5,000 kWh | €469.70 | €724.74 | 13 → 13 |
| September 11 quarterly | 5,000 kWh | €661.5916 | €661.591639 | 18 → 18 |

The largest matched median increase is May 1 quarterly at 18,000 kWh: €1,561.80 → €2,525.03 (+€963.23), with the same 15 contributors. The largest decrease is March 23 quarterly at 18,000 kWh: €2,356.44 → €1,829.37 (−€527.07), with the same 14 contributors. These use the lower-confidence seasonal estimate when the historical reference curve is unavailable. The quarter-reference defect is corrected; the remaining seasonal changes do not establish another defect or justify a coefficient retune. September 11 uses forward-curve estimates and matches stored v1 to rounding in this segment.

## Every lost identity classified
All 1,653 lost identity records were collected, not just a date sample:
- 1,170 have incomplete Louna Helppo package evidence, including missing numeric monthly allowances.
- 474 have conflicting source interpretations: Paneliankosken Kulutusjousto variants, Cheap variants, and a Vihreä Älyenergia offer. These retain the existing conflicting-source guard; this rollout does not redesign interpretation validation.
- Nine are Tyyni on July 29–31 at the three reference consumption levels. The selected source is complete for August, but its only priced phase and recurring period start on August 1. It gives no applicable July price in the selected episode. This is an unpriced initial interval, not rejection merely because the later monthly prices are unknown. A local read-only inspection matched production observation 212, source snapshot 627, and interpretation 1990 exactly. Do not silently apply the August price backward.

The detailed source IDs, issue codes, and bounded missing-fact excerpts remain in `REVIEW_LOST` log records. The old stored v1 rows remain available for audit.

## Current-date checks and completed refresh
- At September 12 07:04:43 Helsinki, exact deployed SHA f651849 and schema17 were present, public v1 was still active, and v1 counts were unchanged (196,256 annual rows / 6,639 aggregates).
- The scheduled current producer independently created 705 v2 rows and 30 aggregates for September 12. Historical preview scripts did not create these rows.
- Read-only current validation matched all 705 annual values to all 705 non-null snapshot reference-level totals. The 244 snapshot contracts have 27 null reference-level masks. Row counts are 240 at 2,000 kWh, 234 at 5,000 kWh, and 231 at 18,000 kWh. All 30 aggregate counts and medians match their stored members. Every row uses `canonical_calculation` / `canonical_outcome`.
- The strict provenance check failed: 63 rows have no stored interpretation ID. Observation and source snapshot IDs are present; there are no mismatched non-null references. Do not describe this as a fully passed current preflight.
- A follow-up at 07:17:42 showed all 21 affected contracts now have published interpretations matching their pointed snapshots. Their statistics were written at 03:01:19 database time, before these publications (03:02–03:17 database time). Source record publication alone does not repair the stored annual provenance or totals; a current-statistics refresh and recheck remain necessary before activation.
- Logs: `/tmp/annual-v2-post-preview-inspect.log`, `/tmp/annual-v2-current-validation.log`, `/tmp/annual-v2-current-provenance-diagnostic.log`.
- Code review confirmed that the 07:30 forecast recovery is conditional, not a general guaranteed repair. At 07:29:34 its sole failure was `statistics_publication_order`. At 07:32:45, after the scheduled refresh time, stored current v2 passed: 789 rows across 272 snapshot contracts, 30 matching aggregates, complete matching source provenance, and zero issues. The 27 null snapshot reference masks remain excluded; available counts are 268 / 262 / 259 at 2,000 / 5,000 / 18,000 kWh. Public v1 remained active. See `/tmp/annual-v2-current-after-schedule.log`. No agent-issued current refresh was run.

## Apply boundary
Historical apply still requires exact approval and confirmation that the verified full backup and restore access remain available. Use reviewed full dates only, in the same bounded monthly batches. Preserve stored v1 and all observed source tables. Compare each recalculated date with its reviewed counts, medians, methods, and loss set before writing; stop on a mismatch. Public activation is a separate approval after current coverage and provenance pass.
