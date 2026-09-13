# Frozen offline forecast experiment

## Scope and freeze
This specification is written before this task calculates test metrics. Use only the complete verified April 8–September 13 export at `tasks/supplier-premium-coverage/export-20260913T094138Z`. No application edits, production access, database replacement, dependencies, release, index construction, or parameter search. The controlled-index task is separate.

Primary: 30-day exact target, seven-day input changes. Secondary: 14-day target with separately fitted coefficients. A separate fixed 14-day input sensitivity is required at both horizons; it does not compete to become primary. Terms 6/12/24 share coefficients with equal row weights. Target is the public `energy_price`, null consumption, `unit_statistics_v1` median (including eligible Time/Season offers), not supplier General medians.

## Fixed definitions
All learned models predict target minus current, in c/kWh. Features are exact same-basis retail change and either rolling-H change or fixed-basket-H change over the input window. No missing date fill or canonical/observed momentum bridge. H uses latest FI Base trade strictly before the feature endpoint, complete next-full-month term strip, month/quarter/year fallback, VAT 1.255 and actual month days. Fixed-basket change prices the issue date's EXACT delivery months at both endpoints with identical day weights; fallback may differ. This removes mechanical basket movement, not a forecast-level change.

Models: unchanged (zero delta); repaired v3 fixed gap reference (reuse verified exact replay mathematics/rows); learned retail only; learned retail plus rolling futures change; learned retail plus fixed-basket futures change. Retail-only and futures models share the retail feature and all training/test identities. Null added futures is the retail-only candidate. Primary fixed-basket also has a separately reported abstention variant: absolute predicted change below 0.15 c/kWh becomes zero. No rounding of learned predictions. Direction uses down/flat/up with the same absolute 0.15 threshold (strictly below is flat).

Ridge: training population-standardized features, unpenalized intercept equal to training target mean in standardized coordinates. Minimize mean squared error + 1.0 times sum of squared non-intercept coefficients. Constant features have slope zero. No term dummies, supplier parameters, grid, lag search or tuned shrinkage. Report feature means/scales, standardized and raw coefficients, both intercept forms, and exact training identities.

## Evaluation
Freeze training at 2026-07-27: observed-basis exact pairs with target STRICTLY before cutoff, same-basis input endpoints, complete curve features, and at least 20 distinct training issue DAYS. Frozen test uses canonical issues on/after cutoff and exact canonical targets through September 13. No canonical outcomes fit or select this model. The observed-to-canonical transfer is a history-continuity ASSUMPTION, not the same data-generating process. Annual-v2 history is not banned. This retrospective chronological holdout was inspected in prior research and is contaminated by prior analysis, not untouched out-of-sample proof.

Within each horizon/input window use the full all-model paired cohort (including reference availability); fit all learned models on identical rows. Expect raw canonical 30-day pairs on 19 days, seven-day input on 12 days (August 3–14), and 14-day input on five days (August 10–14). Do not expand these after results. Report common issue/term identities across target horizons separately; do not interpret smaller MAE as an optimal horizon.

Also attempt same-basis rolling-origin fits at every eligible issue using only labels strictly before issue and at least 20 distinct prior issue days. Canonical fits must not borrow observed labels. Older observed-regime results remain separate. Unavailable fits stay unavailable.

## Evidence and limits
Report MAE, RMSE, mean signed error, three-class direction accuracy and MAE skill versus unchanged, overall and per term. Keep dates, identities, cohort counts, missing reasons, overlap, source revisions and source/spec hashes. Independently verify fixed baskets with Decimal day-expanded arithmetic and actual rolling H at the same issue vintage. Check cutoff, trade timing, same basket weights, missing values, zero scales, synthetic lambda-zero and known ridge solutions, metrics, common cohorts and repeatable artifact hashes. Preserve source exports.

Report whether fixed basket improves on rolling/retail and whether abstention helps, with no production recommendation from this small selected sample. No archived-vintage collector has been deployed by this work. Propose a minimal future prospective experiment contract, but add no dated follow-up or schedule.
