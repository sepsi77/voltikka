# Decisions

- Local work only. Preserve the untracked forecast-futures-increment-test research folder and all pinned exports.
- Use the fixed-basket production_futures model, not rolling baskets or a futures-led hypothesis.
- Production has January 21 onward retail history; the pinned export starts April 8. Exact production values will differ.
- Median research does not establish measured p20/p80 accuracy.
- New identity hides previous-model forecasts until initial generation; release has a data gap.
- Reuse FixedTermHedgeCostService with optional delivery start and an optional build-local curve snapshot. Existing callers keep rolling next-month baskets and configured area/VAT. The forecast batch fixes FI and VAT 1.255, and loads curves once per build. No dependency or process-wide cache is added.
- Keep the unrestricted historical mean separate from the feature cohort mean. Only the ridge contribution uses the feature cohort. Both cohorts require the existing configured minimum with an absolute 20-start floor.
- Legacy financial/futures database diagnostics remain NULL. Real current/lag basket facts and fitted parameters are named feature metadata. Existing nullable columns suffice; no new migration or backfill is needed.
- Restore the shared EEX checks for forecasts by removing only the forecast exclusion around the existing block. Retail-premium requirements, publication recovery, source episodes and schedules retain their prior behavior.
- Keep evaluation method/provenance rules unchanged. Tests cover old historical-change and gap models beside new rows, plus completed-row preservation.
- Copy is a narrow clarification, not a redesign. Keep layout, controls, Preferred Sources, saved price comparisons and direction/horizon. Explain futures once. Use one visible uncertainty notice; keep sparse and crossed-quantile states honest.
- Isolated PHP replay matches all 123 eligible primary fixed-cohort median predictions (41 per term; 41 issue days), evaluates all 123 new rows and preserves all 1,314 exported old forecasts. Two independent memory-database runs agree. Independent raw-array Python arithmetic also matches all nine September 13 lanes, including means, population standard deviations, slopes, contributions and four-decimal prices.
- Research/export artifact hashes remain unchanged. Existing research source pins correctly refer to the previous implementation; do not rewrite those pins to match this release.
- Frozen median research has no untouched future holdout and limited falling-market evidence. Approval selects the tested hybrid, but it does not authorize new accuracy or quality claims.
- No production calls, mutation, commit, push, migration or manual generation were performed. The release plan records the exact proposed push and explicit Railway context. A literal generation date must come from a new freshness dry run before separate approval.
