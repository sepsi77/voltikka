# SEO release copy repair

## Result

Complete locally. This work does not approve release or change pricing.

## Changes

- `laravel/app/Livewire/SeoContractsList.php`: the offer intro describes an annualized comparison in canonical mode. It distinguishes the real short contract term from a 12-month bill and states that savings against an estimated normal price are not guaranteed. Feature-off copy describes the legacy annual estimate without claiming real-term annualization. Offer metadata no longer promises a saving.
- FixedPrice title, H1, metadata and intro no longer promise an unchanged price or complete certainty. The intro explains disclosed price phases and seller changes to OpenEnded prices. The ConsumptionEffect cross-reference no longer promises complete certainty from this category.
- GeneralElectricity intro describes one rate across clock times, not a whole-year guarantee.
- SQL, membership, ranking, routes, canonicals, pagination, Preferred Sources and pricing flags are unchanged. Updated SQL comments explain the actual constraint without changing it.
- `laravel/tests/Feature/SeoContractsListTest.php`: regressions check literal claims and both offer flag states. Valid canonical OpenEnded and phased Fixed6 fixtures remain listed on both FixedPrice and GeneralElectricity pages. The Fixed6 fixture has known 6 then 8 c/kWh phases and a positive real-term annualized result.

## Intentional limit

`FixedPrice` plus `canonical_calculation.status = exact` proves neither an unchanged contract-wide energy price nor a source-backed guarantee. This unit retains that existing filter. It adds no guarantee inference.

The manager's documentation agent owns shared context and task-status files. No such file was edited here. Add the above constraint and copy policy to Livewire context when consolidating the release documentation.

## Verification

From `laravel/`:

```sh
DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-seo-no-config php artisan test --filter='SeoContractsListTest|SeoCityRoutesTest|SeoEnergyRoutesTest|SeoHousingRoutesTest|CanonicalOfferSurfacesTest|PageActionStripSourcePolicyTest|PricingBucketFilterTest'
vendor/bin/pint --test app/Livewire/SeoContractsList.php tests/Feature/SeoContractsListTest.php
```

Final result: **179 tests passed, 454 assertions, 2.97 seconds**. Pint passed for both files. Test log: `/tmp/voltikka-seo-release-tests.log`. `git diff --check` passed. Final scoped diff and working-tree status reviewed. No CSS or JS changed; no build was required. No application/provider call, production action, database export, commit or push was made.

UI guidance was reviewed in-thread without another agent. The Impeccable context script reported its legacy design-sidecar path; it was not changed. It also reported available Impeccable v4.3.1. No update or install was run.
