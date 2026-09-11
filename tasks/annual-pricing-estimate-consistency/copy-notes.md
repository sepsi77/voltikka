# Annual estimate public copy

## Result
Implementation done. Dedicated tests pass. Shared presenter tests need integration updates or review, as listed below.

## Files and behavior
- `ContractCard/ContractCardCopy.php`: `hold_last_known_price` now returns the existing Arvio popover. Finnish copy separates known price periods from assumed unknown parts and names the latest applicable price or disclosed normal price. It claims no recurring schedule or price guarantee.
- Short-term copy annualizes the calculated real-term cost by `12 / months`, including any estimated parts. It does not extend the signup price across the year. It can use typed `contract_term.months` when `term_months` is absent.
- Hybrid copy uses plural known base prices and estimated unknown parts. It explicitly excludes the unknown consumption effect. Term annualization and consumption-effect exclusions compose through typed assumptions without adding another popover.
- Forward Spot copy reads typed `spot_estimate.confidence`. The actual estimator sets `lower` with `zero_intraday_shape_fallback` for missing usable historical shape. This branch states that day and night use the same assumed exchange price, not a preserved 365-day difference. Good shape and missing optional Spot records keep the existing explanation.
- `ContractCard/ContractCardPresenter.php`: permitted follow-up call-site change passes the optional `hasEstimatedUnknownPrices` band flag for `EstimateMethod::HoldLastKnownPrice` or the actual core assumption `unknown_periods_use_latest_applicable_price_or_disclosed_normal`.
- Fixed and Hybrid bands with that flag no longer promise an unchanged price. They state that unknown periods are estimated. Categories, icons, layout, existing known-price defaults, and reset/Spot mechanism bands stay unchanged. The flag takes priority over a known scheduled fixed-price change because that change does not prove all other periods.
- `tests/Feature/AnnualEstimateCopyConsistencyTest.php`: dedicated typed-payload tests cover visible one-popover rendering, held prices, real-term annualization, multiple known Hybrid prices, composed reset/term/Hybrid reasons, zero-shape fallback, compatible defaults, and presenter band integration.
- `ContractCard/AGENTS.md`: updated estimate and band rules. `CLAUDE.md` remains a symlink.

## Verification
- `cd laravel && php artisan test --filter='AnnualEstimateCopyConsistencyTest'`: 7 passed, 61 assertions.
- `cd laravel && php artisan test --filter='AnnualEstimateCopyConsistencyTest|ContractCardPresenterTest'`: 69 passed, 2 failed, 301 assertions. Both failures assert copy intentionally removed by this task:
  - `ContractCardPresenterTest:896` expects `kiinteä 6 kuukautta`; the new copy says `määräaikainen 6 kuukautta`. This test also has an old `8,59 c/kWh` assertion at line 902 that will need adjustment after the first assertion is updated.
  - `ContractCardPresenterTest:1000` expects `kiinteällä perushinnalla 8,59 c/kWh`; the new copy correctly uses multiple known base prices and estimated unknown parts. Update that test name/comment too.
- `cd laravel && php artisan test --filter='AnnualEstimateCopyConsistencyTest|ContractDetailPresenterTest'`: 24 passed, 1 failed, 174 assertions. `ContractDetailPresenterTest:272` expects one `Perusmaksu` receipt row; actual rows are empty. This is not a copy assertion and is outside this unit. The manager must review it with the concurrent core changes.
- Focused `vendor/bin/pint --test` over the two implementation files and dedicated test: passed.
- `git diff --check`: passed. Reviewed the owned diff and working-tree status. Initial dedicated-test assertion mistakes were corrected before the final passing run.

## Integration notes
- Shared test files were not edited, as instructed. The manager owns their updated expectations and the integrated suite.
- VAT wording still needs the later integration agent: both `forwardSpotBody()` and `spotBody()` contain the existing hard-coded `sis. alv` after day/night values. Company prices remain VAT-excluded, so these words must not be used for company-only VAT-excluded values. This unit does not change tax handling or that wording.
- The existing fixed category name stays `Kiinteä hinta`, as required; only its certainty claim changes when unknown periods are estimated.
- No calculator, Spot estimator, tax, shared root/canonical context, or tasks.json edits. No production work, commit, or deployment. No layout, CSS, or JS change; no asset build was needed.
