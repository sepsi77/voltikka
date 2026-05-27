# AGENTS.md

Context for Livewire components under `laravel/app/Livewire`.

Use this file as a shortcut to find component-specific behavior. It does **not** replace reading the code.

See also:
- `../AGENTS.md` for Laravel-level behavior
- `../Services/ContractReplacement/AGENTS.md` for replacement matching/linking rules

## `ContractPriceStatistics`

Primary files:
- `ContractPriceStatistics.php`
- `../../resources/views/livewire/contract-price-statistics.blade.php`
- `../Services/ContractStatistics/AGENTS.md`

Purpose:
- renders `/sahkosopimus/tilastot`
- reads precomputed `contract_price_daily_statistics` rows
- groups daily rows into daily/weekly/monthly UI views by averaging daily statistics

Important semantics:
- the page does not calculate contract prices directly during requests
- the component caches its prepared view payload per period + consumption + source-data fingerprint until the next day; keep this cache in place unless a replacement avoids full-table aggregation on every page load
- `getDailyStatsProperty()` keeps an explicit request/job-scoped collection cache and selects only the columns used by the view-data builder; queued warmers instantiate the component directly, so do not rely only on Livewire computed-property memoization for this full-table read
- `warmPreparedViewDataCache()` is public so queued/background warmers can fill the same prepared-data cache without rendering a public request; keep its key semantics aligned with `statisticsViewData()`
- the warmer must batch source reads used across many segment/date loops: daily spot-market averages are loaded once and sliced in memory for rolling 12-month spot summaries, and latest per-segment statistic rows come from the already-loaded `dailyStats` collection rather than one query per segment
- cache invalidation is automatic through cheap `contract_price_daily_statistics` / `contract_price_snapshots` / spot-price max-date/update fingerprints, so daily imports/backfills should not need manual page-cache clearing
- run `contracts:backfill-price-statistics` before expecting historical data
- spot metrics are split between `spot_margin` and `spot_total_energy_price`
- the “Hinnat sopimustyypeittäin” spot row must display a trailing-12-month realized spot daily average + latest typical margin, not the latest daily spot price; show p20–p80 daily-price variation under the value without adding a column
- the “Hinnat sopimustyypeittäin” sparkline must track the displayed median energy-price basis; the annual-cost sparkline belongs in the “Hintahaarukka” table below
- deep-dive spot c/kWh charts and top editorial spot callouts must use the same trailing-12-month spot average + typical margin as the upper spot row, with p20–p80 daily-price variation as the shaded band; do not show latest-day spot there unless explicitly adding a separate volatility view
- non-spot “vs pörssisähkö” quotable comparisons must use `annual_cost` at the selected consumption so unusually cheap/expensive spot days do not distort contract-type comparisons
- the lead chart caption must be generated from `leadChartPayload` / `annual_cost`, not from c/kWh callouts, so the text always matches the plotted trend
- segment and consumption tables hide rows with fewer than 10 contracts to avoid over-interpreting sparse segment statistics
- the consumption “Hintahaarukka” table intentionally omits absolute cheapest/minimum annual cost values because single-row/import anomalies can make the minimum misleading; use p20/median/p80 for the displayed range

## `SpotPrice`

Primary files:
- `SpotPrice.php`
- `../../resources/views/livewire/spot-price.blade.php`
- `../Models/SpotPriceForecast.php`
- `../Services/SpotForecasts/AGENTS.md`

Purpose:
- renders `/spot-price`
- shows official ENTSO-E/Nord Pool actual spot prices for today/tomorrow, historical summaries, appliance timing helpers, and a separate third-party forecast section when imported forecast rows exist

Important semantics:
- official actual prices live in `$hourlyPrices` / `spot_prices_hour` and quarter-hour actuals live in `spot_prices_quarter`
- imported forecasts live in `$forecastPrices` / `spot_price_forecasts` and must not be merged into `$hourlyPrices`
- forecast display starts after the latest future official actual price so users do not see third-party predictions where official prices exist
- forecast rows must stay labelled as estimates and cite `nordpool-predict-fi` by vividfog with the GitHub URL
- forecast rows must not affect current price, today/tomorrow actual sections, CSV export, spot averages, or appliance helper calculations unless explicitly redesigned
- appliance helper cards intentionally exclude the current hour as well as past hours, because a displayed hour must be a fully upcoming actionable slot; tomorrow's official prices may be used when available and cards should label tomorrow/date context

## `ArticleContractPriceComparisonChart`

Primary files:
- `ArticleContractPriceComparisonChart.php`
- `../../resources/views/livewire/article-contract-price-comparison-chart.blade.php`

Purpose:
- embeds the contract-price statistics lead chart on editorial pages such as `/sahkosopimus/kannattaako-porssisahko`
- reuses precomputed `contract_price_daily_statistics` annual-cost rows for spot, 12-month fixed-term, open-ended, and hybrid segments
- uses the shared `resources/js/contract-price-statistics.js` `data-line-chart` renderer

Important semantics:
- the article embed intentionally shows one static view: weekly aggregation at 5 000 kWh/year, with no period or consumption selectors
- keep its aggregation aligned with `ContractPriceStatistics`: weekly views average daily median statistics so trends remain market-day weighted
- do not calculate contract prices during the article request; the component only reads aggregate statistics rows
- article chart data is cached with short TTLs (typically 6 hours) because it is derived from daily/hourly precomputed market tables and does not need per-request freshness
- do not Livewire-lazy-load the article chart widgets unless their pushed scripts/chart initializers are moved to a non-lazy parent bundle; otherwise the widget markup can hydrate without the chart drawing

## `CompanyDetail`

Primary files:
- `CompanyDetail.php`
- `../../resources/views/livewire/company-detail.blade.php`

Query guardrails:
- `contracts` and `companyStats` are memoized per render because layout title/meta, JSON-LD, H1/hero text, and the visible list all read the same company contract set.
- Keep company contract queries eager-loading `company`, `priceComponents`, and `electricitySource`; company detail cards need the loaded company relation for logos, and stats/calculations use source and price relations.
- Clear the memoized contract/stat caches whenever the selected consumption changes.

## `ConsumptionCalculator`

Primary files:
- `ConsumptionCalculator.php`
- `../../resources/views/livewire/consumption-calculator.blade.php`

Important semantics:
- calculator inputs are deliberately nullable/string-tolerant because Livewire can send blank strings/nulls when users clear number/select fields before tabbing away.
- `calculate()` must read public inputs through safe helper methods and use enum `tryFrom()` fallbacks so blank/stale browser state does not become `PropertyNotFoundException` or enum `ValueError`.
- blank/too-small numeric inputs are normalized back onto the component so the UI displays minimum allowed values: 20 m² living area, 1 resident, and 0 for optional numeric extras.
- fallback select defaults are apartment, electric heating, central region, and 2000-era energy rating.

## `ContractTypeComparison`

Primary files:
- `ContractTypeComparison.php`
- `../../resources/views/livewire/contract-type-comparison.blade.php`

Purpose:
- interactive editorial comparison widget for pörssisähkö vs fixed price and fixed-term vs open-ended contracts

Important semantics:
- widget actions can be slow because contract candidates are recalculated; keep visible `wire:loading` feedback on mode, consumption, and contract-selector updates
- do not server-render every available contract as `<option>` elements; the editorial article embed must avoid dumping all contract names into the initial DOM for crawler quality and UX
- contract selection is interaction-gated: the default view shows only auto-selected/explicit contracts, and searchable async results render only after the user opens a selector and types at least 2 characters
- default `contract_term` mode compares määräaikainen vs toistaiseksi voimassa oleva for the määräaikainen article
- `comparisonContext="spot_article"` keeps pörssisähkö as the left-side anchor in both tabs: pörssisähkö vs kiinteähintainen and pörssisähkö vs määräaikainen

## Contract listing page caching

Primary files:
- `ContractsList.php`
- `SeoContractsList.php`
- `CitySolarEstimate.php`
- `../Services/Caching/ContractPageCacheVersion.php`
- `../Services/ContractMarketInsights/ContractMarketInsightService.php`

Purpose:
- cache prepared view payloads for high-traffic canonical/default contract listing landings such as `/sahkosopimus` and SEO listing pages

Important semantics:
- only cache canonical default GET states: page 1, no query string, no interactive filters/search input
- do not cache arbitrary filter/query combinations because they can explode cache cardinality and are less important for search-landing TTFB
- cache keys include route/filter context plus `ContractPageCacheVersion::hash()` so contract imports and source-table changes bust stale payloads
- this is prepared-data caching, not full HTML caching; Livewire actions still recompute/serve their interactive state normally
- page-level caching is disabled when `app()->runningUnitTests()` to avoid cross-test cache pollution from Laravel's array cache driver
- listing metric rebuilds should use `ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()` so crawler hits do not produce one `price_components` query per contract while still avoiding eager-loading full price history
- contract card Blade partials (`resources/views/components/contract-card.blade.php`, `featured-contract-card.blade.php`) must not lazy-load `company`, `electricitySource`, or `priceComponents`; listing components should batch-load what cards need, and cards should fall back to scalar fields if relations are missing
- city-page solar potential must stay in the lazy `CitySolarEstimate` child component; `SeoContractsList` must not call `CitySolarService`/PVGIS while building initial page HTML because a cache miss can add blocking time
- `CitySolarEstimate` must not make uncached PVGIS requests for crawler user agents (Googlebot, generic bots/spiders); bot-triggered Livewire lazy updates should render cached data only or nothing, because PVGIS can hang long enough to hit PHP's request timeout
- `SeoContractsList` memoizes city municipality lookups, including not-found slugs, because city metadata is read by contracts filtering, title/meta generation, headings, JSON-LD, and local-contract sections during one render; do not revert to direct `Municipality::where('slug', ...)` calls from those accessors
- `ContractsList::calculateFromInlineCalculator()` reads calculator fields through safe typed helper methods. Keep this tolerant of blank mobile number inputs and stale/partially hydrated Livewire snapshots from SEO pages so user edits do not turn into `PropertyNotFoundException` / enum errors.
- `CheapestContracts` calls `SeoContractsList::getContractsProperty()` through inheritance. Read consumption with `ContractsList::selectedConsumptionValue()` in inherited listing paths and cheapest-page render data so stale Livewire snapshots that miss the URL-bound `consumption` property fall back to 5 000 kWh instead of throwing `PropertyNotFoundException`.
- Contract comparison hero market-insight pills are intentionally small and must not push results down. They use cached precomputed statistics/forecast payloads from `ContractMarketInsightService`; do not calculate contract prices or scan raw `price_components` for these pills during page requests.
- Market insights show on `/sahkosopimus`, SEO pricing/duration pages, and cheapest contracts. They are hidden on business, housing-type, energy-source, and consumption-level SEO pages. The cheapest page uses the same aggregate trend as the main page.

## `ContractDetail`

Primary files:
- `ContractDetail.php`
- `../../resources/views/livewire/contract-detail.blade.php`
- `../Models/ElectricityContract.php`
- `../Services/Caching/ContractPageCacheVersion.php`

### SEO metadata

Contract detail meta descriptions are generated from Voltikka-owned comparison data, not provider marketing descriptions. The templates intentionally avoid Finnish inflection for arbitrary company names and use neutral wording like `yhtiöltä {company}`. Product JSON-LD `description` must stay aligned with `metaDescription` so provider `short_description` / `long_description` does not become Google's preferred snippet source.

When a contract has meaningful `General` price history (at least two dates and >= 3% change), the meta description prefers a price-history template with current c/kWh + monthly fee, change direction/percentage, and rank. Spot contracts describe the `General` component as margin; other contracts describe it as energy price.

Active ranked contract title tags lead with Voltikka-specific facts when available, but avoid receipt-like titles. Preferred hierarchy: for top-25 contracts use rank-first titles such as `Sija 5/336 · 6,50 c/kWh | {name} | Voltikka`; for rank > 25 with cheaper alternatives use money-difference titles such as `122 € kalliimpi kuin halvin | {name} | Voltikka`; otherwise fall back to rank + compact price. Keep title price phrases short (for example `6,29 c/kWh` or `Marg. 0,49 c/kWh`) and do not include the base fee in title tags.

### Hero verdict thresholds

The `Yksi halvimmista` hero verdict is intentionally limited to contracts ranked in the absolute top 25, not a percentile. For example, rank 26 / 300 should fall through to the broader `Edullinen vaihtoehto` tier even though it is within the cheapest 10%.

### Prepared view-data caching

Contract detail pages cache their contract lookup and prepared default GET payload until tomorrow with a `ContractPageCacheVersion` key.

Important semantics:
- only the canonical default consumption state is cached (`5000 kWh`, clamped into the contract's allowed range)
- query-string/Livewire interaction states are not cached by this page-level cache
- inactive redirect decisions still happen in `mount()` before view-data caching
- inactive historical pages without replacements can be cached, but the cached layout data must keep `robots => noindex, follow`
- page-level caching is disabled when `app()->runningUnitTests()` to avoid cross-test cache pollution from Laravel's array cache driver

### Internal links

The contract detail hero intentionally links key entities/attributes to indexable internal pages:
- company name -> `/sahkosopimus/sahkoyhtiot/{companySlug}` when a slug exists
- duration badges -> `/sahkosopimus/maaraaikainen` or `/sahkosopimus/toistaiseksi`
- pricing/metering badges -> existing SEO listing pages such as `/sahkosopimus/porssisahko`, `/sahkosopimus/joustosahko`, `/sahkosopimus/yleissahko`, `/sahkosopimus/aikasahko`, and `/sahkosopimus/kausisahko`

Primary mapping file:
- `../Support/ContractInternalLinks.php`

Use broad existing SEO pages for duration badges instead of creating exact-duration pages unless product explicitly wants those pages and they will have substantial unique content.

### Query optimization guardrails

`ContractDetail` loads `activeContract` beside `company`, `priceComponents`, and `electricitySource`. Keep `ElectricityContract::isActive()` relation-aware so detail history rows do not issue one `active_contracts` query per version. Discount helpers on `ElectricityContract` are also relation-aware; when `priceComponents` is already eager-loaded for cards or JSON-LD, do not re-query `price_components` just to check active discounts.

`ContractDetail` also memoizes rank-related computed values and keeps one request-scoped `ContractRankingService` instance. Do not replace `rankingService()` with repeated `app(ContractRankingService::class)` calls in `liveRank`, `liveTotalContracts`, or `cheaperContracts`; those methods share the same eligible target-group lookup and otherwise repeat large `electricity_contracts` queries during one render.

`ContractRankingService` and `ContractListCacheService` intentionally memoize cache payloads per service instance. Production uses the database cache driver, so repeated `Cache::remember()` calls for `contract_rankings_5000kwh`, `contract_list_cache_version`, or `contract_list_metrics:*` become repeated `select * from cache where key in (?)` spans that Sentry can classify as N+1 even when application data is already cached.

`ContractDetail` memoizes `ContractPageCacheVersion::hash()` per component instance because both the contract lookup cache key and prepared view-data cache key need it. On the database cache driver, recomputing the version hash can create repeated cache/source-table queries before the page data is even built.

### Contract history UI

The contract detail page now builds its visible history from the replacement-link chain instead of only the current contract row.

Current intended behavior:
- active contracts render the full `contract-detail.blade.php` page
- inactive contracts without a trusted replacement also render the normal `contract-detail.blade.php` page for historical reference
- those inactive historical pages should include a `noindex` robots meta tag
- inactive historical pages should not appear in the sitemap
- start from the currently rendered contract
- walk backward with `ContractDetail::getBackwardReplacementChainIds()` using a recursive CTE, then eager-load all history contracts with `company`, `priceComponents`, and `activeContract`; do not replace this with per-version relation walking
- inactive replacement redirects use `ContractDetail::getForwardReplacementChainIds()` plus a bulk `activeContract` load so old bot-hit URLs do not lazy-load `replacedBy` / `activeContract` one link at a time
- include the current contract itself as the newest history entry
- sort versions in reverse chronological order using each version's latest known `price_date`
- show, for each version:
  - contract name
  - latest relevant prices per component type
  - promotion/discount summary when present

### Discount display guardrail

When showing a promotion/discount summary for a contract or a historical version:
- read the discounted `price_component_type` / `payment_unit`
- do **not** assume every absolute discount is `c/kWh`
- monthly-component discounts must be shown as monthly-fee discounts (for example `€/kk`), not energy-price discounts

### Important decision: do not flatten the chain

Even if an older contract could redirect straight to the newest active version, the UI should preserve intermediate versions.

Reason:
- the sequence itself is useful historical context
- pricing and campaign information can change between versions
- overwriting the visible sequence would throw away information the chain was designed to preserve

### Price-change summary semantics

`ContractDetail` also merges `priceComponents` across the backward chain for the price-change teaser/details table.

That means:
- change counts are computed across all linked versions, not only the current row
- detailed history rows may reference different contract names in the same chain

If future work changes how history is grouped or collapsed, keep the per-version timeline visible unless product explicitly decides otherwise.
