# Long-term qualifier release copy

## Result

Done. All focused tests pass after the approved premium metadata fixture repair. This report does not approve release.
No network, production command, financial calculation change, SQL/filter change, source guard
change, commit or push occurred. Shared task JSON and root contexts were not changed.

## Changes

- `laravel/app/Livewire/ContractDetail.php`: long fixed terms now say
  “Määräaikaisuus kertoo sopimuksen kestosta. Tarkista energian hinnat, hintajaksot ja hinnanmuutosehdot.”
  The generic OpenEnded sentence says “ei seuraa pörssin tuntihintaa” and keeps advance notice.
  Short-term wording, annualization, source-rule precedence and estimate popovers are unchanged.
- `laravel/app/Services/ContractCard/ContractCardCopy.php`: only the default fixed headline changes
  to “Ennalta ilmoitettu energianhinta”. Category, duration, scheduled published changes,
  supplier-adjusted, unknown-price, reset and Hybrid branches are unchanged. Source-backed
  guarantee notices remain in place.
- `ContractDetailPresenterTest`: Fixed12 and Fixed24 cover constant 4 c/kWh and known 4→8 phases.
  Tests check neutral copy, receipt prices, non-estimated rows and unchanged stored/payload data.
  A separate V4-compatible Fixed24 test has 6 c/kWh for year one and 8 c/kWh for year two.
  Its real calculated annual breakdown contains only 6 c/kWh, and its total remains consumption
  × 0.06. Its default card headline and duration do not promise an unchanged 24-month price.
  The generic OpenEnded branch is tested with the feature off; canonical supplier-adjusted
  pricing correctly uses its earlier source-specific branch instead.
- `ContractDetailPageTest`, `ContractCardPresenterTest`, `AnnualEstimateCopyConsistencyTest`:
  affected positive copy expectations and two test names were updated.
- Approved follow-up: the premium-reset copy fixture now has every public premium key, two
  reference records for its two independent same-company variants, known flags and enum-controlled
  provenance from `PremiumEstimate::publicProvenance()`. Both references cover Q3; their May 29
  trade precedes the June 1 and July 1 observation/pricing dates. Counts, evidence bounds, 2.0 c/kWh
  premium, 6.6 current price, 9.28 annual equivalent and all copy assertions remain unchanged.
  No donor identity or raw prose enters the payload. The strict transport guard is unchanged.
- Closest Livewire and ContractCard `AGENTS.md` files record the comparison-window limit.
  Their `CLAUDE.md` files remain symlinks. Other existing worktree changes were preserved.

## Verification

From `laravel/`:

```sh
DB_URL='' APP_CONFIG_CACHE=/tmp/qualifier-no-config php artisan test tests/Feature/ContractDetailPresenterTest.php tests/Feature/ContractDetailPageTest.php tests/Feature/ContractCardPresenterTest.php tests/Feature/AnnualEstimateCopyConsistencyTest.php tests/Feature/MarketResetEstimateSurfacesTest.php tests/Unit/ContractDetailSeoPresenterTest.php tests/Unit/EnergyRulePublicOutputTest.php
```

Final result: **232 passed, 1134 assertions, 3.61 seconds**.
Log: `/tmp/long-term-qualifier-tests-final.log`.

The previous run had 231 passes and one failure because the premium-reset fixture lacked the
new strict public metadata shape. The approved fixture-only follow-up resolved that failure;
no validation rule was relaxed.

The same command without `tests/Feature/ContractCardPresenterTest.php` passed:
**166 passed, 875 assertions, 3.01 seconds**. Log: `/tmp/long-term-qualifier-focused.log`.

```sh
vendor/bin/pint --test app/Livewire/ContractDetail.php app/Services/ContractCard/ContractCardCopy.php tests/Feature/ContractDetailPresenterTest.php tests/Feature/ContractDetailPageTest.php tests/Feature/ContractCardPresenterTest.php tests/Feature/AnnualEstimateCopyConsistencyTest.php
```

Pint passed. `git diff --check` passed. Final scoped diff and status were reviewed.
No layout, CSS or JS changed, so no build or video render was needed.

Earlier test runs found stale positive copy expectations and new-test setup defects. These were
corrected. An initial whole-page negative assertion also matched an existing source-backed notice;
the regression now checks the card headline and qualifier, without removing that notice.
