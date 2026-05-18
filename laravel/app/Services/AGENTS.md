# AGENTS.md

Context for services under `laravel/app/Services`.

This file should stay short. It is a pointer file for service subtrees, not a dumping ground for detailed service-specific documentation.

See also:
- `../../AGENTS.md` for Laravel-level guidance
- `../../../AGENTS.md` for project-level guidance

## Important directory rule for services

When services gain non-trivial domain logic, matching rules, import behavior, decision-heavy logic, or their own local context needs:
- group them into a **logical feature/domain subdirectory** under `app/Services/`
- do **not** default to one subfolder per service/class if several services belong together
- create or update an `AGENTS.md` inside that subdirectory
- keep this root service-level `AGENTS.md` as a high-level pointer to those subdirectories

## Important decision: do not let `app/Services/AGENTS.md` become a giant service encyclopedia

Reason:
- `app/Services` can grow quickly
- if all detailed service decisions live here, this file becomes long, noisy, and less useful
- local service documentation is easier for agents to discover and maintain when it lives beside the relevant code

Preferred pattern:
- `app/Services/SomeFeature/`
  - `FooService.php`
  - `BarService.php`
  - `BazService.php`
  - `AGENTS.md`

The grouping unit should be a cohesive feature/domain, not an individual class unless that truly makes sense.

## Current service subtrees

### Contract pricing / discounts
Files currently living directly under this directory:
- `ContractPriceCalculator.php`
- `LocalContractsService.php`

Important pricing guardrails:
- structured discounts are attached to individual price components, not to the whole contract price
- calculation inputs should preserve component discount metadata (`has_discount`, value/type/months/kWh/until-date, `payment_unit`)
- use `ElectricityContract::getLatestPriceComponentsForCalculation()` for single-contract calculations and `ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()` for listing/cache batches instead of rebuilding calculator arrays ad hoc
- do not eagerly load full `priceComponents` history for contract-list/cache calculations; the active dataset has tens of thousands of historical price rows and can exceed PHP's 128M request memory limit
- city/local contract sections must also avoid caching full `priceComponents` history; attach only latest normalized components needed by contract cards
- city/local company-distance logic must bulk-load company postcodes; do not call `Postcode::find()` per company because crawler hits to city SEO pages otherwise trigger Sentry N+1 reports
- first-year promo-aware pricing should return both discounted totals and base totals/savings so UI can explain the effect of the offer
- do not assume `monthly_costs` represent calendar Jan-Dec once promo timing matters; they are the calculator's 12-month estimate timeline

### Solar estimates
Files currently living directly under this directory:
- `PvgisService.php`
- `CitySolarService.php`

Important guardrails:
- `PvgisService` must use explicit HTTP connect/request timeouts (`services.pvgis.connect_timeout`, `services.pvgis.timeout`) so slow PVGIS responses cannot consume the full PHP request timeout inside `curl_exec()`.
- City SEO page solar snippets are lazy Livewire widgets. Crawler-triggered hydration must use cached city solar estimates only; do not make uncached PVGIS requests for bot user agents.

### Weekly offers / promo output
Files currently living directly under this directory:
- `WeeklyOffersVideoService.php`
- `WeeklyOffersPromptFormatter.php`

Important discount guardrail:
- imported `price_components.price` comes from API `OriginalPayment.Price`, i.e. the base/original component price
- weekly-offer output must not assume absolute discounts are always `c/kWh`
- use the discounted component's `payment_unit` / `price_component_type` when formatting promo text
- prefer calculator-provided discounted/base totals and savings over separate duplicate promo math when possible

### Contract replacement
Directory:
- `ContractReplacement/`

Purpose:
- detect high-confidence replacement contracts for inactive contracts
- persist replacement links for historical chains and SEO redirects

Read first:
- `ContractReplacement/AGENTS.md`

### Public page caching
Directory:
- `Caching/`

Purpose:
- shared cache fingerprint helpers for public contract listing/detail pages
- see `Caching/AGENTS.md` before changing cache invalidation behavior

### Contract price statistics
Directory:
- `ContractStatistics/`

Purpose:
- calculate daily contract-price snapshots and aggregate statistics for the `/sahkosopimus/tilastot` trend page
- enrich spot contracts with stored spot-price history so spot totals are comparable with fixed/hybrid/open-ended contracts
- backfill historical statistics from `price_components.price_date`

Read first:
- `ContractStatistics/AGENTS.md`

Related files outside services:
- `../Models/ElectricityContract.php`
- `../Livewire/ContractDetail.php`
- `../Console/Commands/DetectReplacementContracts.php`
- `../Console/Commands/LinkReplacementContracts.php`
- `../Console/Commands/FetchContracts.php`

## Documentation rule for this subtree

If you touch files directly under `app/Services/` and they are becoming decision-heavy:
- move them into an appropriately named **feature/domain** subdirectory
- group related services together when they belong to the same area
- create/update a local `AGENTS.md` there
- add a short pointer section to this file
