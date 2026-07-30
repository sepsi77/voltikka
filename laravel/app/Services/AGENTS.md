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
- `ContractListCacheService` memoizes its version and per-consumption metrics per service instance to avoid repeated database-cache reads during one request; clear per-consumption memo entries inside cache-warming loops so workers do not retain every preset payload at once
- `CompanyListCacheService` consumes those list metrics. In canonical mode it accepts only listed canonical outcomes with a finite total for company membership, counts, averages, displayed prices, and price rankings; canonical-only contracts work and excluded/missing outcomes do not become zero or sentinels. Its separate 48-hour cache key has its own payload schema, the shared contract-list data version, and `c`/`r` markers, and the service memoizes reads per instance. The shared version makes interpretation publication invalidate company output without waiting 48 hours. Feature-off keeps the legacy relational metrics.
- city/local contract sections do not load `priceComponents` in canonical mode. Feature-off must still avoid full history and attach only the latest normalized components needed by contract cards
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

Important pricing guardrails:
- in canonical mode, query the broad active household set and evaluate the three consumption levels with one batch call per level; do not prefilter or load `price_components`
- canonical membership requires a positive `CanonicalOfferFacts` benefit and no package at 5,000 kWh, plus a listed outcome and no detected integrity state at every requested consumption level
- canonical order is measured customer benefit at 5,000 kWh descending, then canonical comparison total ascending, then contract ID; keep at most one contract per company after sorting
- canonical records and prompt text use typed totals, normal totals, rates, comparability, estimate state, and benefit basis only; a short fixed term uses its real `contract_term` benefit while its annualized total is labelled as a comparison value
- the public `/api/video/weekly-offers` payload carries `pricing_basis`; canonical offers use `consumptions`, while the explicit feature-off branch keeps the old `discount` / `costs` / `savings` shape
- legacy mode still reads `price_components.price` as API `OriginalPayment.Price`; use the discounted component's unit/type and do not assume an absolute discount is always `c/kWh`

### Contract interpretation
Directory:
- `ContractInterpretation/`

Purpose:
- fingerprint and preserve complete upstream contract payloads
- run versioned strict-output LLM interpretations
- validate and automatically publish compatible canonical classifications without human review

Read first:
- `ContractInterpretation/AGENTS.md`

### Canonical phase-aware pricing
Directory:
- `CanonicalPricing/`

Purpose:
- consume validated `electricity_contracts.canonical_*` interpretation JSON to calculate accurate
  12-month prices across pricing phases (so promotional prices do not flatter contracts)
- assign a deterministic comparability verdict (list inclusion / sort key)
- derive the deterministic deceptive-pricing label ("Hinta nousee …")
- annualise monthly/quarterly/seasonal/other market-reset products with a shape-only forward-curve
  shift instead of holding one seasonal price flat; `other` uses the quarterly proxy
  (`CanonicalPricing/MarketReset/`)
- gated behind `CANONICAL_PRICING_ENABLED`; when off, `ContractPriceCalculator` behavior is unchanged.
  The market-reset shift has its own separate flag, `RESET_FORWARD_SHIFT_ENABLED`

Read first:
- `CanonicalPricing/AGENTS.md`
- `CanonicalPricing/MarketReset/AGENTS.md` before touching the market-reset estimator

### Contract card derivation
Directory:
- `ContractCard/`

Purpose:
- one server-side view model for both contract-card templates (normal + featured), which
  previously duplicated ~120 lines of Blade PHP and drifted apart
- resolve the three consumer-facing pricing categories (Kiinteä hinta / Markkinahinta /
  Kulutusvaikutus) and the type-band sentence that states them
- generate the estimate explanation, the itemised receipt rows, and the footer warnings/facts
  from typed fields only

Read first:
- `ContractCard/AGENTS.md`
- `../../../tasks/contract-card-redesign/spec.md`

### Contract price development ("Näin hinta on kehittynyt")
Directory:
- `ContractPriceHistory/`

Purpose:
- build the contract detail page's price-development chart (server-rendered SVG),
  its seller-behaviour fact tags, and the copy that scopes both
- overlay the contract's own observed price on its `contract_price_daily_statistics`
  segment median; spot contracts instead get monthly realized market averages
  against the trailing-12-month average

Read first:
- `ContractPriceHistory/AGENTS.md`

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

### Contract market insights
Directory:
- `ContractMarketInsights/`

Purpose:
- build cached, low-prominence market trend/forecast teaser payloads for contract comparison page heroes from precomputed statistics and forecast tables

Read first:
- `ContractMarketInsights/AGENTS.md`

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

### Electricity futures
Directory:
- `ElectricityFutures/`

Purpose:
- fetch and normalize EEX electricity futures end-of-day settlement data for Voltikka's own futures history dataset
- currently configured for EEX Nordic System Price and Nordic zonal Base Year futures

Read first:
- `ElectricityFutures/AGENTS.md`

### Header spot price
File:
- `HeaderSpotPriceService.php`

Important semantics:
- the header badge should prefer current 15-minute `spot_prices_quarter` data, but must fall back to the current hourly `spot_prices_hour` row when quarter data is absent; otherwise the menu indicator can stay in its inactive placeholder state even though hourly spot data exists
- availability is based on whether a current row exists, never numeric truthiness; exactly zero and negative prices are valid active header values
- the layout fetches the Blade fragment through one shared desktop/mobile JavaScript coordinator, retries failures, and refreshes every 60 seconds; do not add `wire:poll` to the fragment injected through `innerHTML`

### Spot forecasts
Directory:
- `SpotForecasts/`

Purpose:
- fetch and normalize third-party Finnish spot-price forecasts for display on `/spot-price`
- current MVP source is the public `vividfog/nordpool-predict-fi` `prediction.json` feed

Read first:
- `SpotForecasts/AGENTS.md`

### Retail premium observations
Directory:
- `RetailPremium/`

Purpose:
- preserve per-contract and per-company spread-over-wholesale observations by semantic price period and curve vintage
- store disclosed Spot premiums exactly and inferred premiums against every candidate futures reference

Read first:
- `RetailPremium/AGENTS.md`

### Price forecasting
Directory:
- `PriceForecasting/`

Purpose:
- calculate and persist fixed-term contract price forecasts from retail statistics, FI EEX futures hedge costs, and simple EWMA retail-premium gap closure
- evaluate matured forecasts against realized contract-price statistics so model performance can be tracked over time

Read first:
- `PriceForecasting/AGENTS.md`

### Bill comparison ("Maksatko liikaa")
Directory:
- `BillComparison/`

Purpose:
- compare a visitor's electricity bill against active market contracts for the same billing period and consumption
- rank the user's bill alongside market contracts by period cost and annualize savings with a seasonal consumption profile

Read first:
- `BillComparison/AGENTS.md`

Related files outside services:
- `../Livewire/BillComparison.php`
- `../../resources/views/livewire/bill-comparison.blade.php`
- `../DTO/BillComparisonRequest.php`, `BillComparisonResult.php`, `BillComparisonRow.php`

## Documentation rule for this subtree

If you touch files directly under `app/Services/` and they are becoming decision-heavy:
- move them into an appropriately named **feature/domain** subdirectory
- group related services together when they belong to the same area
- create/update a local `AGENTS.md` there
- add a short pointer section to this file
