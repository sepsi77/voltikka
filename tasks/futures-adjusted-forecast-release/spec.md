# Local futures-adjusted forecast release

Implement fixed_term_futures_adjusted_v1: unrestricted completed same-basis historical mean plus the tested fixed-delivery-basket seven-day futures ridge contribution. Match production_futures, fixed cohort, in the frozen research. Do not use rolling baskets, the old gap/fair-price model, a futures-led replacement or tuned windows.

Fit each 6/12/24-month term, median/p20/p80 quantile and horizon independently. Full history is all exact completed pairs with target strictly before issue under the existing observed-prefix/canonical-continuation, latest-ID and finite-current rules. Require max(20, configured minimum) unique starts in both full and feature cohorts. Preserve old records and evaluations.

Feature: latest FI Base wholesale basket value strictly before issue minus the value strictly before issue minus seven calendar days. Both vintages price the same duration basket beginning next month of issue, with month/quarter/year fallback, calendar-day weights and VAT 1.255. Load curves once per build; reuse feature calculations across quantiles without stale process-wide caches.

From feature-complete pairs, use population standard deviation and fixed ridge 1: slope = mean(z*(y-mean(y)))/(mean(z²)+1). Prediction change = unrestricted full mean + slope*((current feature-feature mean)/feature std). Zero variance gives zero contribution. Missing current/lag futures or insufficient feature history omits the forecast. Persist normal four-decimal changes and prices; keep direction thresholds at 0.15. Save cohort/fitting/basket provenance and leave legacy financial diagnostics NULL. No new migration is expected.

Restore forecast EEX same-day checkpoint, current-run prior-FI proof and data freshness checks without changing retail-premium requirements, source-episode coverage, unit-statistic checks, recovery or schedules. Reject old model generation pins before recovery.

Simplify Finnish forecast-page copy, title/meta/schema and contradictory related copy without layout or control changes. Keep Preferred Sources, current-price comparison, saved directions and dates/horizon. Explain futures once, show one concise uncertainty notice, retain honest sparse/crossed-quantile states, and describe p20/p80 as contract-price distribution rather than prediction confidence. Do not publish technical fitting jargon or unmeasured quality claims.

Verify focused/full PHP tests, lint, scoped Pint, production build, and isolated SQLite replay against frozen research. Do not change pinned artifacts. No production calls, commit, or push. Release requires manager review and explicit push and manual generation approval.
