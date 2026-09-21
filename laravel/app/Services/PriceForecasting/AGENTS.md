# Price forecasting services

Fixed-term forecasts, stored evaluation, and `/sahkosopimus/sahkon-hintaennuste`.

## Files

- `FixedTermPriceForecastService.php`: separate-term/quantile completed retail-change mean plus fixed-basket futures adjustment.
- `FixedTermHedgeCostService.php`: calendar-day-weighted FI Base delivery costs. Optional delivery start and build-local curves preserve existing callers' default rolling semantics.
- `FixedTermForecastEvaluationService.php`: exact-date, saved-basis evaluation.
- `ForecastOutlook.php`: saved-direction categories, Finnish copy, complete-term summary and four direction outcomes.
- `FixedTermForecastReport.php`: bounded read-only compatible stored median summaries.
- `../../Models/FixedContractPriceForecast.php`: storage and public eligibility.
- `../../Console/Commands/{Run,Evaluate,Report}FixedContractPriceForecasts.php`: command boundaries.
- `../../Livewire/FixedContractPriceForecast.php` and its Blade template: public page.
- `../../../config/price_forecasting.php`: defaults.

## Current local generation: fixed_term_futures_adjusted_v1

This replaces `fixed_term_historical_change_v1` for new generation only. It is the tested `production_futures`, **fixed** cohort in `tasks/forecast-futures-increment-test/analyze.py`, not the rolling cohort, old gap/fair-price method, or a futures-led hypothesis. See `../../../../tasks/futures-adjusted-forecast-release/` for replay and release approval requirements.

For each 6/12/24-month term, p20/median/p80 quantile and horizon h independently:

1. Keep ALL eligible completed retail changes `y_s = R_q(s+h) - R_q(s)`, with target STRICTLY BEFORE issue t. Full mean is the equal-weight expanding mean of these unique start dates, with no futures-availability restriction and no rolling window.
2. Feature `x_s` is the seven-calendar-day FI Base wholesale cost change for the SAME duration delivery basket starting next month of s. Current vintage is latest trade strictly before s; lag vintage is latest strictly before s-7. Both price that same basket, including across month roll. Do not move the lag basket to next month of s-7.
3. From those same eligible retail pairs, retain feature-complete pairs. Compute feature mean, POPULATION standard deviation and their retail-change mean. With `z = (x-xmean)/xstd`, slope is `mean(z*(y-ymean))/(mean(z²)+1)`. Ridge is fixed at 1; intercept is not penalized. Zero standard deviation gives zero contribution.
4. Raw prediction change is **full unrestricted mean** plus `slope*((current_feature-xmean)/xstd)`. Never substitute the feature cohort's mean for the full mean. Round change to four decimals before inclusive ±0.15 direction thresholds; add that saved change to current price and round the forecast to four decimals.

Each full and feature cohort requires `max(20, configured minimum_history_observations)` unique starts. Current and lag baskets must be complete. Missing futures or feature history omits the row; there is no fallback that claims this identity while using only the old mean. No tuning, extra predictors or shared parameters are used.

### Input ownership and provenance

- Current/history use `unit_statistics_v1`, `energy_price`, null consumption and exact term segments. Annual methods do not gate or bound this unit history.
- Current is the exact issue date in PricingMode's basis. Latest ID owns each date BEFORE finite-value validation. Missing/null/non-finite current skips the row. Zero and negative finite values remain valid. No observed current fallback in canonical mode.
- Canonical mode keeps an observed prefix before that term's first canonical unit-date presence through issue, followed by canonical continuation only. Invalid canonical presence still establishes the boundary. Future presence cannot establish it. Missing/invalid canonical dates never revive observed rows after it. Feature-off stays observed-only.
- BOTH endpoints of every pair must have the same timeline basis. Missing dates, seam-crossing pairs, issue-day targets and future targets are rejected. Observed/canonical population continuity remains an assumption, not proof of equal composition.
- FI Base curves are loaded once per build, newest vintage first; latest-ID values own duplicate instrument keys. Incomplete latest vintages are not replaced by older complete curves. Month → quarter → year fallback and actual calendar month days reuse the hedge calculator. The forecast snapshot uses fixed FI and VAT 1.255; existing non-batch hedge callers keep their configured area/VAT and default next-month rolling basket.
- Build-local feature memoization is per term/start date across quantiles. There are no per-pair/per-quantile futures queries and no process-wide cache. A new build reads changed curves.
- Metadata saves full mean/count/unique starts/start and target bounds/basis counts, feature mean/population std/retail delta mean/standardized slope/contribution, feature cohort counts/bounds/basis counts, fixed ridge/lag/policy/VAT, and current/lag trade dates, delivery bounds and coverage. Current retail provenance, unit method, transition and direction threshold stay saved.
- Confidence counts accepted CURRENT-BASIS full pairs only: 120 gives medium and 365 high, otherwise low. It describes history coverage, not probability or accuracy. Older-basis pairs can satisfy the minimum but cannot raise it.
- Hedge cost, retail premium, normal premium, fair price, gap, futures trade date and coverage quality database diagnostics remain NULL. Real fixed-basket facts live under explicitly named feature metadata, not legacy financial semantics. Intervals remain NULL. Existing nullable columns suffice; this release adds no migration.
- Independent quantiles can cross. Never sort, relabel or fabricate them. Formula tests cover all quantiles, but the frozen research validates median predictions only.
- Generation rejects EVERY other model name, including historical-change and gap versions, before freshness recovery can write statistics. Legacy `consumer_signal` keys remain storage compatibility only; public readers use saved direction through ForecastOutlook.

## Storage and evaluation

The command resolves its date, horizon and selections once before any execution claim or statistics recovery. Invalid manual dates, nonpositive/noninteger horizons or durations, and unsupported quantiles return `INVALID` without a checkpoint. Manual model rejection also stays before the claim. Dry runs share validation but never claim.

Generation skips existing date/horizon/term/quantile/model rows by default. `--overwrite` replaces only unevaluated rows. Completed rows remain intact even with overwrite. Date identity uses `whereDate`, so SQLite date casting cannot create rerun duplicates. Other model rows are never rewritten.

Evaluation reads exact target-date unit evidence under each forecast's SAVED basis and method, not today's feature flag. Latest-ID ownership precedes finite validity; zero/negative finite actuals are valid. Saved direction threshold controls classification. Known gap v1 may default missing basis to observed and missing threshold to 0.15; known gap v1/v2 may default absent method to unit_statistics_v1. V2 still requires basis and threshold. Other versions, including historical-change and futures-adjusted, require all three fields explicitly. Invalid present provenance, unsupported methods and non-finite/negative thresholds skip. Unknown saved directions skip. Generation identity does not restrict evaluation of old rows.

Actual-minus-current is rounded to four decimals before the inclusive threshold check. New evaluations save same_basis_v1, actual provenance, unchanged-price absolute-error baseline, categories and correct/wrong_way/missed_move/false_move. Completed evaluations are never relabelled. ID-ordered chunks and dry-run no-write behavior remain.

The report reads completed stored medians in bounded 100-row issue-date/ID pages. Model/horizon default to current config; optional model/date selectors retain separate compatibility groups. Groups include model/horizon/term/saved basis/unit and evaluation methods/threshold. Actual metadata must match exact target date, segment, metric and positive sample count. Named skips preserve legacy/unsupported provenance. It reports valid n, unique issue days, four outcomes and unchanged-direction baseline, without refetching statistics or writing rows. Empty denominators have no percentage.

## Public presentation

Page, listing teasers and article accept only configured-model, expected-basis saved forecasts. Model/horizon cache identities invalidate old prepared payloads. Switching identity hides old forecasts until the first eligible new generation; do not hide this release gap. Offered-price history stays independent and complete.

The forecast page uses short household Finnish. Lead: `Sähkön hintaennuste: mihin määräaikaisten hinnat ovat menossa?` Method: `Ennuste perustuu sähkösopimusten hintakehitykseen ja sähkön tukkumarkkinoiden hintoihin.` Explain futures once as future-month wholesale prices. One visible uncertainty notice says `Ennuste voi muuttua markkinatilanteen mukana.` Saved direction, dates/horizon and current-price comparison remain. No ridge/cohort/seam math, invented accuracy, investment framing or lock/wait instruction belongs in main copy.

Slight moves mean approximately unchanged; unknown means unavailable. Overall direction needs all three terms complete and equal. Missing terms and mixed directions remain distinct. p20/p80 describe contract price distribution, not prediction confidence intervals. Crossing forecasts retain their lanes with an explicit notice; the article keeps its ordered-distribution guard and median teasers remain independent. Layout, controls and Preferred Sources placement stay unchanged.

## Freshness and release

Local generation uses five-minute background `--scheduled` ticks from 07:30 through the 12:00 Europe/Helsinki deadline; evaluation stays 07:45. Scheduled mode accepts current-day default scope only and enforces freshness. The shared `MorningFreshness/MorningConsumerExecution` checkpoint claim excludes all same-date non-dry manual writers. Completed scheduled days are inert; failed or interrupted writers require inspection, with no automatic overwrite. Each output transaction and statistics recovery check durable ownership. Any pre-existing issue-date forecast rows stop scheduled completion conservatively, rather than certifying another model or a partial write. Zero output is terminal. Waiting is quiet; incomplete days have one independent deadline alert. See `../MorningFreshness/AGENTS.md`. Forecast freshness again requires the same-day ready EEX checkpoint, current-run prior-date FI Base proof and recent prior-date FI Base database data. Retail-premium requirements are unchanged. Full contract checkpoint, relevant household 6/12/24 source episodes/publication, expected-basis unit statistics and publication order stay required.

Only a sole publication-order failure can trigger non-dry current-date statistics recovery from all active contracts, then a complete gate recheck. Missing EEX readiness blocks this recovery. Dry run cannot recover. No deployment-time generation or producer retry is added.

Commands:
```bash
php artisan forecasting:run-fixed-contracts --as-of=YYYY-MM-DD --horizon=30 --require-freshness --dry-run
php artisan forecasting:evaluate-fixed-contracts --as-of=YYYY-MM-DD --dry-run
php artisan forecasting:report-fixed-contracts
```
Dry generation prints full pair counts/mean/bounds and feature counts/mean/std/slope/contribution/vintages. Obtain explicit approval before Git push and before exact-context first production generation, including possible statistics recovery. Choose the date only after a fresh gate check; do not assume the frozen September 13 issue date is the release date.

Local isolated replay matches all 123 eligible primary fixed-cohort median predictions from frozen Python, evaluates those 123 saved new rows, and preserves all 1,314 exported old rows. Two independent memory-database runs also match an independent raw-array formula for all nine September 13 lanes. Export history begins April 8; production has January 21 onward history, so exact production predictions differ. No untouched future holdout or measured quantile accuracy claim follows from this replay.
