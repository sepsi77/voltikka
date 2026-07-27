# Decisions

## 2026-07-27 — Canonical pricing is the public current-price source of truth

When `CANONICAL_PRICING_ENABLED=true`, all public current-price values must come
from one typed canonical pricing outcome. A consumer must not silently use
`price_components` when canonical data has no value.

Reason: Azure structured data is seller-controlled evidence. It can contain a
mistake or a misleading promotional price. A raw fallback can publish the exact
value that the interpretation workflow corrected.

## 2026-07-27 — Storage model

There is no separate canonical-pricing table.

- `contract_interpretations` stores versioned interpretation results and their
  validation history.
- The current validated result is published to the `canonical_pricing`,
  `canonical_calculation`, and `canonical_source_consistency` JSON columns on
  `electricity_contracts`.
- `price_components` stores relational Azure evidence and observed history.

## 2026-07-27 — Permitted relational pricing uses

`price_components` remains valid for source evidence, interpretation input,
import diagnostics, feature-off legacy calculations, historical observations,
and cache fingerprints. It is not a second public current-price source in
canonical mode.

Historical observations are a deliberate exception. Today's interpretation
must not rewrite old prices. A historical surface must identify relational data
as observed seller data and must not call it the canonical current price.

## 2026-07-27 — Consolidate the two earlier tasks

This task is the parent for:

- `tasks/canonical-pricing-ignores-component-discounts/`
- `tasks/canonical-pricing-source-of-truth-audit/`

Keep their measurements and findings as evidence. Do not implement either one
as an isolated patch. The canonical model and calculator must be complete before
all public consumers migrate to them.

## 2026-07-27 — The discount finding contains separate defect classes

The local 5,000 kWh measurement reproduced 69 active contracts with discount
metadata, 26 zero canonical savings, 25 positive legacy savings among those 26,
and one zero in both systems.

Further inspection split the 25 cases:

- 20 canonical interpretations already contain a discounted `amount` and a
  higher `normal_amount`. Their canonical total already uses the offer. The
  missing value is mainly the normal-price comparison and measured saving.
- Surffari kesäkampanja has a real missing-current-phase problem. Its canonical
  data contains the normal phase from 1 September 2026 but omits the current
  `UntilDate` margin discount.
- Vaasan Sähkö Kuukausipaketti XS/S/M/L are package products, not promotions.
  The monthly fee includes 75/150/250/350 kWh each month. Only excess energy
  costs 16.60 c/kWh.

## 2026-07-27 — Included package energy is not an offer

Model included kWh, allowance cadence, and excess rate as typed canonical
pricing. Calculate the allowance separately for each month. Do not report the
included energy as `discount_savings_total` and do not create a promotion badge.

Kuukausipaketti L also has the same EUR 49 source fee interpreted as both a
`flat_fee` and a `monthly_fee`. Validation must prevent the same source charge
from becoming two billed fees.

## 2026-07-27 — Offer calculation policy

The canonical calculator must calculate two values on the same timing and
comparison basis:

1. the actual canonical price with the offer;
2. the canonical normal price without the offer.

Store their measured difference explicitly. Do not infer savings later from two
values that were built with different rules.

For a Hybrid, apply an offer only to billed base components. Do not apply it to
an unknown consumption effect. For a short fixed term, calculate the full term
with and without the offer, then annualize both with the same factor.

## 2026-07-27 — No implementation started

This planning work created the parent task only. It made no application-code or
production changes.

## 2026-07-27 — Phase A source invariants confirmed from current code

The current code confirms these rules:

1. In canonical mode, `CanonicalContractPricingService::evaluate()` parses only
   the published `canonical_pricing`, `canonical_calculation`, and
   `canonical_source_consistency` fields. It does not read relational price
   components. A parse failure returns `excluded_incomplete` with a null total.
2. `CanonicalPricingOutcome::toCalculatedCostArray()` is the current public
   canonical payload. An excluded outcome has no total. A consumer must not add
   a relational total or unit rate after it receives this outcome.
3. Canonical mode must fail closed by value. A missing canonical unit rate means
   that the public unit rate is unavailable. It does not mean that the latest
   relational rate is safe.
4. A canonical-only contract is a valid case. It can have a canonical annual
   total and no relational component rows. Public code must not require a
   relational row before it uses the canonical total.
5. Feature-off code can use
   `ElectricityContract::getLatestPriceComponentsForCalculation*()` and
   `ContractPriceCalculator`. This is the temporary legacy path only.
6. Historical observations must keep the value observed on that date. Today's
   canonical interpretation must not rewrite old seller observations.

These rules match the publication boundary in
`app/Services/ContractInterpretation/ContractInterpretationPublisher.php` and
`CanonicalPriceComponentWriter.php`: relational publication can be withheld
because the source value is unsafe, while the validated canonical JSON can still
be published and costed.

### Permitted relational uses confirmed

| Use | Current evidence | Decision |
|---|---|---|
| Immutable source evidence | `ContractSourceSnapshot::source_payload` and `ContractInterpretationInputBuilder::build()` retain and read the complete upstream payload. | Valid. The immutable snapshot, not a current public fallback, is the primary interpretation evidence. |
| Relational evidence and consistency work | `CanonicalPriceComponentWriter::resolveRows()` writes safe structured rows; `ContractInterpretationValidator` checks structured evidence from the interpretation input. Repair and republish commands use the same writer/gate. | Valid inside the import, validation, repair, and diagnostic boundary. |
| Feature-off calculations | The `! $canonicalPricing->enabled()` branches in listing, detail, ranking, company, API, local, and statistics code call the legacy calculator. | Valid while the flag exists. It must remain isolated to the feature-off branch. |
| Historical observed prices | `BackfillContractPriceStatistics::handle()` passes `useCanonical: false`; `PriceDevelopmentPresenter::present()` plots the detail page's observed component history; `RetailPremiumHistoryBackfillService::build()` reconstructs observed lineage periods. | Valid. These uses answer what the seller published or what Voltikka observed on the historical date. |
| Cache fingerprint and import bookkeeping | `ContractPageCacheVersion::version()` reads only component count and latest date. The import writer uses rows to track observation dates. | Valid. No public price value is calculated from the fingerprint. |
| Diagnostics | `CompareCanonicalPricing`, `RepairPriceComponentCollisions`, and `RepublishGatedRelationalPricing` compare, repair, or republish source evidence. | Valid. These are not public current-price sources. |

## 2026-07-27 — Phase A public surface audit

Classification terms:

- **canonical-compliant**: canonical mode gets the published value from the
  canonical outcome and does not add a raw fallback;
- **mixed**: one part uses canonical pricing, but another public price part uses
  relational evidence;
- **violating**: the public result can be calculated or filled from relational
  current prices in canonical mode;
- **legitimately relational/historical**: the surface describes an observed past
  value and does not claim that it is the canonical current price.

### Annual totals, list inclusion, and ranking

| Surface | Finding | Evidence and effect |
|---|---|---|
| Main and SEO listings | **Canonical-compliant for annual total, inclusion, and sort; mixed as a complete card surface.** | `ContractsList::getContractsProperty()` and `SeoContractsList::getContractsProperty()` call `CanonicalContractPricingService::metricsForContracts()` in canonical mode. They filter `is_listed=false` and sort by `sort_key`. They do not require components for the annual calculation. The card unit-rate defect is recorded below. |
| List metric cache | **Canonical-compliant.** | `ContractListCacheService::buildCachedMetrics()` does not load components in canonical mode. It stores the canonical calculated cost, comparability, integrity, and sort key. `sorted_ids` rejects excluded outcomes. Canonical-only contracts remain eligible. |
| Ranking service | **Canonical-compliant.** | `ContractRankingService::calculateRankings()` uses canonical metrics and skips a missing or unlisted outcome. It loads components only in the feature-off branch. |
| Contract detail annual price | **Canonical-compliant.** | `ContractDetail::buildCalculatedCost()` uses cached canonical metrics or `CanonicalContractPricingService::evaluate()` before the feature-off legacy branch. `getConsumptionCostTableProperty()`, rank comparisons, hero total, and annual metadata totals read this result. Excluded contracts get no hero annual total. Canonical-only contracts can be priced. |
| Company annual summaries | **Canonical-compliant for live annual totals; mixed for the full page.** | `CompanyDetail::getContractsProperty()` evaluates every contract canonically. `getCompanyStatsProperty()`, FAQ annual prices, hero figures, and spot margin rows read `calculated_cost`. Excluded contracts stay visible but have no canonical total. Promotion and card defects are recorded below. |
| Local contract sections | **Canonical-compliant for annual total, inclusion, and sort; mixed for card rates.** | `LocalContractsService::processContracts()` evaluates canonical pricing, filters unlisted outcomes, and sorts the canonical total. It does not drop a canonical-only contract. It still loads relational components for the presenter fallback. |
| Calculation API | **Canonical-compliant.** | `Api\CalculationController::calculatePrice()` returns the canonical calculated-cost and integrity payload immediately when canonical mode is on. The legacy calculator is only in the feature-off branch. Excluded outcomes keep a null total. |

### Unit-rate display and current offer display

| Surface | Finding | Evidence and effect |
|---|---|---|
| Standard and featured cards | **Mixed, violating for unit rates and promotion facts.** | `ContractCardPresenter::rates()` prefers calculated-cost fields but then falls back to the passed relational `prices` and the loaded `priceComponents` relation. `ContractsList::loadVisibleContracts()` loads latest components even in canonical mode, and all listing Blade files pass `ContractsList::getLatestPrices()` to both card templates. `CardFooterItems::discountText()` also derives the current `Tarjous` fact from relational discount metadata. A missing canonical unit rate can therefore publish the raw current rate. An excluded listing contract is removed before rendering, but a canonical-comparable correction can still be overwritten field-by-field by this fallback. |
| Contract detail receipt | **Mixed, violating.** | `ContractDetail::getCardProperty()` passes `latestPrices` to the same presenter. The annual total and canonical phase rows are correct when present, but `ContractCardPresenter::rates()` fills any missing rate from relational rows. The Blade renders `card->receiptLines` even when `isPricingExcluded=true`, so an excluded detail page can still show raw unit rates below the honest no-total hero. |
| Contract detail title | **Violating.** | `ContractDetail::titlePricePhrase()` reads `latestPrices` directly. A structured intro price can become the title's current `c/kWh` phrase even when canonical phases corrected it. A canonical-only contract loses the unit phrase, which is honest; the problem is the raw fallback when rows exist. |
| Contract detail meta description | **Mixed, violating in the price-history branch.** | `getMetaDescriptionProperty()` uses the canonical annual total in its annual-cost branches. However, `priceHistoryMetaDescription()` says the contract "maksaa nyt" using `generalPriceHistoryChange()` from relational history and `metaMonthlyFeePhrase()` from `latestPrices`. This can publish the raw promo as the current rate and fee. |
| Company promotion table and FAQ | **Violating for current offer identity and label.** | `CompanyDetail::getPromotionContractsProperty()` uses `hasActiveDiscounts()` on relational rows. The Blade calls `formatActiveDiscountValue()`. The annual total and positive saving come from canonical `calculated_cost`, but the claim that an offer is active and its public amount can bypass canonical conflict handling. |
| Company spot table and FAQ | **Canonical-compliant for margin and total.** | The Blade and `CompanyDetail::getFaqItemsProperty()` read `calculated_cost.spot_price_margin`, `monthly_fixed_fee`, and `total_cost`. They do not use `General` directly for these values. |
| SEO offer page filter | **Mixed, violating for offer membership.** | `SeoContractsList::getContractsProperty()` selects promotion candidates through `pricing_has_discounts` and a direct `price_components` discount subquery. Canonical metrics still control totals and exclusions, but the public current-offer classification is relational. |
| SEO listing JSON-LD descriptions | **Violating for current offer text.** | `SeoContractsList::generateJsonLd()` calls `hasActiveDiscounts()`, `getActiveDiscountInfo()`, and `formatActiveDiscountValue()` and appends that raw offer text to Product descriptions. |

### Period pricing and comparison calculators

| Surface | Finding | Evidence and effect |
|---|---|---|
| Standalone, in-listing, and detail bill comparison | **Mixed, violating for period cost.** | `BillComparisonService::buildMarketRow()` uses canonical only as an exclusion gate. `extractRates()`, `spotPeriodCost()`, `seasonalPeriodCost()`, and the fixed/time branches calculate the public period cost from latest relational components. `annualCost()` is canonical in canonical mode. A canonical-only contract is returned as `no_pricing`; a promo-then-spot contract can use the intro price as a margin. All three public bill surfaces call this path. |
| Contract-type comparison auto-selection | **Canonical-compliant only for cheapest-candidate selection.** | `ContractTypeComparison::calculateContractCost()` uses the canonical total and gives excluded outcomes `PHP_FLOAT_MAX`. |
| Contract-type comparison chart, winner, savings, and display rates | **Violating.** | `calculateProjectedCosts()` always calls `ContractPriceCalculator` with relational components for each month. `getContractPriceInfo()` and `getDisplayPrice()` expose the same raw rates. `getComparisonResultProperty()` uses those relational projected totals. A selected canonical-only contract becomes a zero/empty series, and an excluded contract selected by the user can still be priced. |

### Generated offer data and public APIs

| Surface | Finding | Evidence and effect |
|---|---|---|
| Weekly-offers video and `/api/video/weekly-offers` data | **Violating; canonical use is exclusion-only.** | `WeeklyOffersVideoService::getContractsWithActiveDiscounts()` selects and ranks offers by relational discount metadata. `transformContractToOffer()` uses canonical only to skip unlisted or detected contracts. It then publishes relational monthly fee, energy price, legacy annual costs, and legacy savings from `calculateCostsForAllConsumptions()`. Canonical-only offers cannot enter because the query requires a relational discount row. `WeeklyOffersPromptFormatter::formatSingleOffer()` repeats those values in generated text. |
| Contract list/show API | **Mixed, violating.** | `Api\ContractController::calculateContractCost()` is canonical in canonical mode. However, `ContractController::index()` and `show()` always eager-load `priceComponents`, and `ContractResource::toArray()` always publishes them through `PriceComponentResource`. The API can therefore expose unsafe current rows, including for canonical-excluded contracts. It also gives canonical-only contracts no unit-price representation. |
| Calculation API | **Canonical-compliant.** | See the annual-total table. It does not include `ContractResource` or raw component rows in the response. |

### Current forward statistics and their public consumers

| Surface | Finding | Evidence and effect |
|---|---|---|
| Forward statistics writer | **Mixed.** | `ContractPriceStatisticsService::buildSnapshot()` uses canonical outcomes for `annual_cost_*` when `useCanonical=true`. This recovers canonical-only contracts and leaves their unit fields null. The same method always derives `energy_price_cents_per_kwh`, `monthly_fee_eur`, `spot_margin_cents_per_kwh`, `spot_total_energy_price_cents_per_kwh`, and `has_discount` from relational components. Current unit statistics are therefore not canonical source-of-truth values. |
| `/sahkosopimus/tilastot` | **Mixed.** | `ContractPriceStatistics` annual-cost charts and ranges read canonical-backed forward `annual_cost` rows. Its current energy-price, monthly-fee, margin, and spot-total panels read the relational-backed unit metrics above. Historical dates are valid observations; the latest current unit point is not canonical-compliant. |
| Statistics CSV | **Mixed, with a provenance-label gap.** | `ContractPriceStatisticsCsvController::__invoke()` exports all annual and unit metrics without distinguishing canonical forward annual totals from observed relational unit/history rows. Historical relational rows are valid evidence, but the CSV does not identify them as observed seller data. |
| Company market comparison | **Mixed and canonical-only violating.** | `CompanyMarketComparisonService::build()` reads `annual_cost` snapshots and market rows, so current annual amounts use canonical forward totals. It still requires `energy_price_cents_per_kwh > 0` and `<= 50` before it accepts a seller snapshot. A canonical-only snapshot has a valid annual total and a null unit rate, so this guard drops it. The trailing chart is valid historical evidence for old dates, but the latest eligibility guard makes relational data a requirement for a canonical current total. |
| Consumption calculator contract-price table and FAQ | **Violating for non-Spot rows.** | `ConsumptionCalculator::priceEstimatesFor()` uses latest relational `energy_price` and `monthly_fee` statistics and recalculates fixed/open-ended/hybrid annual totals in `annualCostFromEnergyAndMonthlyFee()`. Spot uses the stored annual-cost path. |
| Home contract-price trend | **Mixed, violating at the current unit-rate point.** | `HomePage::getContractPriceTrend()` reads `energy_price` and `spot_total_energy_price`. The historical series is observed relational evidence, but the latest point is presented as the current market price and is not canonical-backed. |
| Listing market-insight pills | **Canonical-compliant for current annual trend, legitimately historical for older points.** | `ContractMarketInsightService` reads only `annual_cost`. Forward rows use canonical outcomes; historical rows remain observed values. It does not calculate a unit price. |
| Annual-cost article charts and spot article market snapshot | **Canonical-compliant for the latest annual point, legitimately historical for older points.** | `ArticleContractPriceComparisonChart::getDailyStatsProperty()`, `ArticleSpotWinRateChart::getChartDataProperty()`, and `ArticleSpotElectricity::getMarketSnapshotProperty()` read only `annual_cost`. The time series correctly keeps old observed totals and uses canonical forward totals after canonical collection. |
| Detail price-development chart and version timeline | **Legitimately relational/historical.** | `ContractDetail::getPriceHistoryProperty()`, `getContractHistoryProperty()`, and `PriceDevelopmentPresenter::present()` show the prices Voltikka observed across dates and replacement versions. The chart uses stepped observed values and tracking dates. It does not recalculate the past with today's canonical phases. This is valid historical evidence, not a current canonical price. The current-title/meta methods must not reuse it as "maksaa nyt"; that separate violation is recorded above. |
| Historical statistics backfill | **Legitimately relational/historical.** | `BackfillContractPriceStatistics::handle()` explicitly passes `useCanonical: false`, gets contract availability from component dates, and does not carry prices across missing dates. This preserves the observation on each date. |
| Retail-premium history reconstruction | **Legitimately relational/historical.** | `RetailPremiumHistoryBackfillService::build()` reconstructs semantic periods from daily lineage rows because inactive ancestors can have no interpretation snapshot. It labels rows `historical_observed`, stores `retail-premium-history-v2`, keeps template interpretation IDs only as provenance, leaves unsafe discount effects null with `discount_effect_unresolved`, and does not claim that the active tip's current canonical amount was the historical amount. This is the correct use of relational evidence. |

### Sitemap and schema price fields

| Surface | Finding | Evidence and effect |
|---|---|---|
| Sitemap XML | **Canonical-compliant / no price consumer.** | `SitemapService` emits URLs, dates, frequency, and priority only. It contains no price field or price fallback. |
| Main listing ItemList schema | **Canonical-compliant / no numeric price field.** | `ContractsList::getItemListSchemaProperty()` gives product identity and a consumption-basis description only. It does not publish an Offer or numeric rate. |
| SEO listing ItemList schema | **Mixed.** | It has no numeric Offer, but `SeoContractsList::generateJsonLd()` adds relational current-discount text. This violation is also in the unit/offer table. |
| Company ItemList schema | **Canonical-compliant / no price field.** | `CompanyDetail::getItemListSchemaProperty()` publishes identity, brand, URL, and optional seller description only. |
| Contract detail Product JSON-LD | **Violating.** | `ContractDetail::getProductSchemaProperty()` builds Offer unit prices directly from `latestPrices`. The canonical exclusion check only removes offers for excluded outcomes; it does not stop a comparable contract's raw promo/unit rate from being published instead of its canonical current display rates. A canonical-only contract gets no offer fields. |

### Exact surface reference index

- Listings and list schema:
  `laravel/app/Livewire/ContractsList.php` — `getContractsProperty()`,
  `getLatestPrices()`, `loadVisibleContracts()`,
  `getItemListSchemaProperty()`;
  `laravel/app/Livewire/SeoContractsList.php` —
  `getContractsProperty()`, `generateJsonLd()`.
- Cache and ranking:
  `laravel/app/Services/ContractListCacheService.php` —
  `buildCachedMetrics()`;
  `laravel/app/Services/ContractRankingService.php` —
  `calculateRankings()`.
- Standard and featured cards:
  `laravel/app/Services/ContractCard/ContractCardPresenter.php` — `present()`,
  `rates()`, `pricesFromRelation()`;
  `laravel/app/Services/ContractCard/CardFooterItems.php` — `build()`,
  `discountText()`;
  `laravel/resources/views/components/contract-card.blade.php` and
  `featured-contract-card.blade.php`.
- Contract detail:
  `laravel/app/Livewire/ContractDetail.php` — `buildCalculatedCost()`,
  `getCardProperty()`, `titlePricePhrase()`, `getMetaDescriptionProperty()`,
  `priceHistoryMetaDescription()`, `metaMonthlyFeePhrase()`,
  `getProductSchemaProperty()`, `getPriceHistoryProperty()`, and
  `getContractHistoryProperty()`;
  `laravel/resources/views/livewire/contract-detail.blade.php` — hero and
  `card->receiptLines` rendering.
- Company page:
  `laravel/app/Livewire/CompanyDetail.php` — `getContractsProperty()`,
  `getCompanyStatsProperty()`, `getPromotionContractsProperty()`,
  `getSpotContractsProperty()`, `getFaqItemsProperty()`, and
  `getItemListSchemaProperty()`;
  `laravel/resources/views/livewire/company-detail.blade.php` — promotion and
  spot tables;
  `laravel/app/Services/CompanyStatistics/CompanyMarketComparisonService.php`
  — `build()` and `buildChart()`.
- Bill comparison:
  `laravel/app/Services/BillComparison/BillComparisonService.php` —
  `compare()`, `periodRowsForContracts()`, `buildMarketRow()`,
  `extractRates()`, `spotPeriodCost()`, `seasonalPeriodCost()`, and
  `annualCost()`.
- Contract-type comparison:
  `laravel/app/Livewire/ContractTypeComparison.php` —
  `calculateContractCost()`, `calculateProjectedCosts()`,
  `getComparisonResultProperty()`, `getContractPriceInfo()`, and
  `getDisplayPrice()`.
- Local sections:
  `laravel/app/Services/LocalContractsService.php` — `processContracts()`.
- Weekly offers:
  `laravel/app/Services/WeeklyOffersVideoService.php` —
  `getContractsWithActiveDiscounts()`, `transformContractToOffer()`, and
  `calculateCostsForAllConsumptions()`;
  `laravel/app/Services/WeeklyOffersPromptFormatter.php` —
  `formatSingleOffer()`;
  `laravel/app/Http/Controllers/Api/VideoController.php` — `weeklyOffers()`.
- Contract and calculation APIs:
  `laravel/app/Http/Controllers/Api/CalculationController.php` —
  `calculatePrice()`;
  `laravel/app/Http/Controllers/Api/ContractController.php` — `index()`,
  `show()`, `calculateContractCost()`;
  `laravel/app/Http/Resources/ContractResource.php` — `toArray()`;
  `laravel/app/Http/Resources/PriceComponentResource.php` — `toArray()`.
- Forward and historical statistics:
  `laravel/app/Services/ContractStatistics/ContractPriceStatisticsService.php`
  — `calculateForDate()` and `buildSnapshot()`;
  `laravel/app/Livewire/ContractPriceStatistics.php` —
  `getDeepDivePayloadsProperty()`, `getSegmentRowsProperty()`, and
  `getConsumptionRowsProperty()`;
  `laravel/app/Http/Controllers/ContractPriceStatisticsCsvController.php` —
  `__invoke()`;
  `laravel/app/Console/Commands/BackfillContractPriceStatistics.php` —
  `handle()`.
- Other statistics consumers:
  `laravel/app/Livewire/ConsumptionCalculator.php` — `priceEstimatesFor()` and
  `annualCostFromEnergyAndMonthlyFee()`;
  `laravel/app/Livewire/HomePage.php` — `getContractPriceTrend()`;
  `laravel/app/Services/ContractMarketInsights/ContractMarketInsightService.php`
  — `segmentTrend()` and `aggregateTrend()`;
  `laravel/app/Livewire/ArticleContractPriceComparisonChart.php` —
  `getDailyStatsProperty()`;
  `laravel/app/Livewire/ArticleSpotWinRateChart.php` — `getChartDataProperty()`;
  `laravel/app/Livewire/ArticleSpotElectricity.php` —
  `getMarketSnapshotProperty()`.
- Historical evidence:
  `laravel/app/Services/ContractPriceHistory/PriceDevelopmentPresenter.php` —
  `present()`, `contractVariant()`, and `spotVariant()`;
  `laravel/app/Services/RetailPremium/RetailPremiumHistoryBackfillService.php`
  — `build()`, `compressPeriods()`, and `buildPeriodObservations()`.
- Sitemap:
  `laravel/app/Services/SitemapService.php` — `getContractUrls()`,
  `getOfferUrls()`, and the other URL builders. None emits a price.

## 2026-07-27 — Direct raw current-price fallbacks outside the earlier suspect list

The verified code search found these direct public fallbacks or raw current-price
uses in addition to the earlier audit task's named prime suspects:

- `ContractDetail::titlePricePhrase()`,
  `priceHistoryMetaDescription()`, `metaMonthlyFeePhrase()`, and
  `getProductSchemaProperty()`;
- `ContractTypeComparison::calculateProjectedCosts()`,
  `getContractPriceInfo()`, and `getDisplayPrice()`;
- `SeoContractsList::getContractsProperty()` offer filter and
  `generateJsonLd()` discount text;
- `CompanyDetail::getPromotionContractsProperty()` and the promotion-table
  `formatActiveDiscountValue()` call;
- `ConsumptionCalculator::priceEstimatesFor()` and
  `annualCostFromEnergyAndMonthlyFee()`;
- `HomePage::getContractPriceTrend()`;
- `CompanyMarketComparisonService::build()` requiring a relational energy unit
  rate for a canonical annual result.

`CalculateContractPercentiles::handle()` also reads current relational unit rates
after a canonical exclusion/detection gate. It is not a current public consumer:
card percentile callouts are removed and the card prop is a no-op. Keep it in the
later migration inventory if those thresholds become public again.

## 2026-07-27 — Phase A implementation boundary

This phase records findings only. No application code, test, configuration,
schema, prompt, production data, or `AGENTS.md` file changed. The next phases
must first define a typed canonical outcome that includes all required public
unit rates, period pricing, offer state, and provenance. Consumer fixes must not
replace these violations with another local calculation.

## 2026-07-27 — First application unit: canonical `normal_amount` savings

The canonical calculator now measures an offer when a billed canonical component
has an actual `amount` and a higher `normal_amount`.

Decision:

- Keep the existing actual phase timeline, total, comparability, inclusion, and
  sort key unchanged.
- Cost the promotion-free result over the same phase segments and usage profile.
- Replace only eligible billed component amounts. Do not change or estimate a
  Hybrid consumption effect.
- Reuse the exact Spot assumptions and the one resolved market-reset offset
  vector in both calculations. Do not resolve a second reset estimate from the
  normal amount.
- Annualize actual and normal held-forward results over the same 12-month basis
  for short fixed terms.
- Store measured total and monthly savings in `CanonicalPricingOutcome`; do not
  derive them in a card or copy the legacy calculator result.
- When no component has a higher `normal_amount`, preserve phase-only promotion
  behavior by using the latest disclosed normal phase over the same window
  segments.

This fixes the Vattenfall-shaped EUR 2.37 monthly fee with a EUR 4.74 normal fee:
the actual 12-month total stays EUR 328.44 at the test usage and the measured
saving is EUR 28.44. A three-month offer followed by a normal phase reports only
the first three months as savings.

The cached `calculated_cost` payload changed because
`base_monthly_costs` and `monthly_discount_savings` now contain measured values.
`ContractListCacheService` and `ContractPageCacheVersion` therefore moved from
payload schema v3 to v4.

Out of scope and still pending: relational component input, Surffari's missing
canonical campaign phase, monthly included-energy packages, duplicate package
fees, raw public-surface fallbacks, reinterpretation, and production rollout.

## 2026-07-27 — Short-term benefit is derived before annualization

Do not add a usage-dependent euro benefit to stored `canonical_pricing`
interpretation JSON. For `term_price_only`, the canonical calculator now captures
the complete disclosed term's actual total and promotion-free total before it
applies the annual comparison factor. The typed calculated payload exposes:

- `contract_term.months`;
- `contract_term.total_cost`;
- `contract_term.base_total_cost`;
- `contract_term.discount_savings_total`.

The term saving is the measured term base total minus the term actual total on
the same usage and timing basis. The top-level fields remain annualized and keep
the same ranking and comparison semantics. `contract_term` is null unless a
finite term is fully costed and annualized, including all non-term, Hybrid,
Spot, reset, and excluded outcomes.

This unit does not add a UI consumer. Cached canonical payload schemas moved from
v4 to v5. The parent typed-outcome, all-cache, regression-fixture, and rollout
tasks remain pending because other fields and surfaces are unresolved.

## 2026-07-27 — Monthly included-energy packages use one phase-level package object

Schema v4 adds nullable `pricing.phases[].package`. A complete package object has:

- `monthly_fee_eur`;
- `included_kwh`;
- `allowance_cadence = monthly`;
- `excess_rate_cents_per_kwh`;
- source evidence for all numeric facts.

A package phase has `components=[]`. This is deliberate. The package fee and
excess-use rate are one billing mechanism and must not also be ordinary
`flat_fee`, `monthly_fee`, or `energy_general` components. It reuses the phase
timeline without adding a second calculator or a relational canonical table.
Schema v4, prompt v18, and validator v15 participate in the interpretation
fingerprint, so affected contracts need a new interpretation before rollout.
No contract was reinterpreted in this unit.

The parser accepts only complete positive package values and only monthly
cadence. It rejects a package with components. It also rejects a phase that has
both a EUR/month `flat_fee` and a `monthly_fee`. Validator v15 makes the same
checks before publication. The safe repair for identical source evidence is to
emit the one source charge as `package.monthly_fee_eur`; it is not billed twice.
Unknown or conflicting duplicates fail closed instead of using a guessed sum or
maximum.

## 2026-07-27 — Package allowance allocation and offer policy

The calculator resets the allowance for each calendar month. For each month it
costs:

```text
monthly package fee + max(month usage - included kWh, 0) * excess rate
```

A partial calendar month pro-rates fee, allowance, and modeled usage by the same
day fraction. Unused allowance never carries to another month. Time and Season
usage-profile buckets are mutually exclusive shares of that month's use, so the
deterministic allocation rule is to sum all buckets first and apply the one
shared allowance. This avoids granting the allowance once per tariff bucket and
does not invent an order in which day/night usage consumes it.

Package inclusion is a product term, not offer savings. The normal-price pass
keeps package monthly costs equal to actual package monthly costs. A package by
itself therefore produces zero `discount_savings_total`, zero monthly savings,
and `includes_discounts=false`.

## 2026-07-27 — Local Vaasan package evidence and expected 5,000 kWh totals

Read-only inspection found the four local active interpretations and their source
payloads. XS/S/M have `flat_fee + energy_general`; L has the same EUR 49 source
charge as both `flat_fee` and `monthly_fee`, plus `energy_general`. All four omit
typed allowance semantics and currently apply 16.60 c/kWh to all use. The local
canonical calculator returned these old totals at 5,000 kWh:

| Product | Fee | Monthly allowance | Old local total | New expected total |
|---|---:|---:|---:|---:|
| XS | EUR 10.50/month | 75 kWh | EUR 956.00 | EUR 806.60 |
| S | EUR 21.00/month | 150 kWh | EUR 1,082.00 | EUR 783.20 |
| M | EUR 35.00/month | 250 kWh | EUR 1,250.00 | EUR 752.00 |
| L | EUR 49.00/month | 350 kWh | EUR 2,006.00 | EUR 720.80 |

The new values use uniform monthly usage only for this comparison. The
implementation itself applies each monthly allowance separately, and a dedicated
uneven-usage test proves that unused allowance is not pooled. Calculator tests
also cover below, equal, and above allowance; XS/S/M/L shapes; multi-bucket
profiles; no offer savings; parser failure for missing allowance/excess rate and
unsupported cadence; and duplicate fees.

Calculated-cost cache payloads moved from v5 to v6 because outcomes now include
typed `energy_package` data and package totals change. Package design and
implementation are complete. The broader validator, complete typed-outcome,
full regression-fixture, public-consumer migration, production reinterpretation,
and rollout tasks remain pending.

## 2026-07-27 — Active structured discounts must survive canonical interpretation

The local household Surffari kesäkampanja snapshot proves the exact regression:

- `analysis_date` is 2026-07-23;
- source component 1 is a Spot `General` tariff slot with normal margin 0.60
  c/kWh, `has_discount=true`, an absolute 0.40 c/kWh reduction, discount type
  `UntilDate`, and end date 2026-08-31;
- deterministic source arithmetic gives the active margin 0.20 c/kWh;
- source text independently states 0.20 c/kWh through 31 August and 0.60 c/kWh
  from 1 September;
- the published canonical output contained only the 0.60 c/kWh normal phase
  starting 2026-09-01. It incorrectly said the May–August campaign had ended,
  although the analysis date was still inside it.

The compact input builder preserved every needed fact. Schema v4 could already
represent the correct two-phase result, and the prompt already described
structured discount arithmetic. The missing safeguard was deterministic
coverage validation: the old helper validated arithmetic and inactive metadata
only when a model had already emitted a discount phase. It did not ask whether
an active source discount phase existed at all.

Prompt v19 and validator v16 now enforce the source discount timeline. For each
active `UntilDate` or `NFirstMonth` component, validation:

1. recomputes `amount` from source `price`, `discount_value`, and percentage mode;
2. requires that amount and source `price` as `normal_amount` on the exact source
   component scope during the active period;
3. requires a continuation of that scope at source `price` on the next date or
   at `after_months=N`;
4. maps every Spot energy tariff slot to `spot_margin` for this check;
5. rejects an unsafe amount, timing, component scope, or unsupported active
   discount type instead of allowing relational repair.

An absolute discount ending before `analysis_date` is historical and creates no
current phase. `has_discount=false` continues to suppress stale discount fields.
The typed monthly-package `NFirstKwh` marker remains exempt only when it matches
12 times the disclosed monthly allowance. This is package pricing, not a
promotion.

The schema did not change. Both prompt and validator version strings changed, so
`ContractAnalysisFingerprint` changes and existing source snapshots will need a
new interpretation before rollout. No local or production reinterpretation was
run. The fixture
`tests/Fixtures/surffari-active-until-date-discount.json` records the mapped
local evidence, the faulty full output, and a corrected full output. The broader
unsupported-pricing, public-surface, regression, reinterpretation, and rollout
tasks remain pending.

## 2026-07-27 — Cards and ContractDetail current-price surfaces are canonical-only

When canonical mode is on, `ContractCardPresenter` now accepts current prices only
from a calculated payload with `pricing_basis = canonical`. It does not inspect
passed prices, the loaded `priceComponents` relation, relational discount helpers,
or an old legacy calculated payload. An excluded comparability verdict clears all
current unit values. Feature-off mode keeps the old calculated-cost-first relational
fallback as an explicit branch.

Canonical receipt rate changes come from `calculated_cost.phase_breakdown`, not
`pricing_integrity` rate fields. Package rows come from `energy_package` and do not
create an offer fact. Offer membership comes from canonical
`includes_discounts`. Both card templates read the same presenter fields. A short
fixed term displays the real customer benefit and normal total from
`contract_term`; the annualized top-level saving remains ranking/comparison data.

`ContractDetail` uses the same canonical current display values for its receipt,
title price phrase, current-price meta text, and Product JSON-LD. Missing canonical
unit values are omitted. Canonical-only contracts can emit available values.
Excluded contracts emit no unit value and no JSON-LD Offer. The price-development
chart, observed component history, and replacement/version timeline remain
relational historical evidence. They are not used as a current-price fallback.

Main and local listings no longer batch-load latest components for cards in
canonical mode. Feature-off still batch-loads them and avoids N+1 queries. Company
page loading was not changed because its current unrelated promotion/statistics
work still uses the loaded relation.

Cache payload schemas moved from v6 to v7, and the ContractDetail prepared payload
moved from v15 to v16. No production data was changed and no reinterpretation was
run. Remaining company, bill-period, API, comparison, statistics, and generated
media migrations stay pending.

## 2026-07-27 — Public contract list/show API is canonical-only in canonical mode

`GET /api/contracts` and `GET /api/contracts/{id}` now have an explicit feature branch.
When canonical mode is on, the controller does not load `priceComponents` and the resource
omits `price_components`. It returns `current_pricing` with the typed canonical unit rates,
package facts, comparability, estimate method, exclusion state, assumptions, and integrity.
A numeric `consumption` continues to control whether the existing top-level canonical
`calculated_cost` is returned.

The list endpoint calls `CanonicalContractPricingService::metricsForContracts()` once for the
page. This keeps canonical evaluation bounded and avoids per-contract relational or canonical
queries. When no consumption was requested, a one-kWh internal usage is used only to resolve the
typed unit and offer state; its total is not returned. An excluded outcome returns
`availability = unavailable`, its comparability verdict as the exclusion reason, null current
rates/package facts, and no raw rows. Its integrity object keeps only detected/reason/issue state,
not price-bearing integrity fields or generated fact text. `pricing_has_discounts` is also derived from the canonical
outcome, so packages do not become promotions.

When canonical mode is off, the old response remains: relational `PriceComponentResource` rows
and the legacy calculator are used. Tests cover corrected values that conflict with rows, missing
canonical fields, canonical-only contracts, excluded contracts, packages, short fixed terms,
list/show responses, legacy mode, and a bounded list query count. The calculation API tests still
pass unchanged.

There is no response cache for these API endpoints, so no cache payload version changed. No
production data changed, no reinterpretation ran, and no deployment occurred. Remaining company,
bill-period, comparison, statistics, and generated-media migrations stay pending.

## 2026-07-27 — Company and SEO offer surfaces use canonical measured facts

In canonical mode, company-page offer membership now comes only from the canonical
`calculated_cost` attached during the page's contract evaluation. The company query does
not load `priceComponents` in this mode. The SEO offer page starts from the broad contract
candidate set, attaches batch canonical metrics, and then keeps only listed outcomes with
a positive measured offer. Thus a canonical-only offer is eligible, while a relational-only
discount, excluded outcome, package, missing benefit, or zero benefit is not.

`CanonicalOfferFacts` is the shared typed presentation boundary for these two surfaces. It
requires `pricing_basis=canonical`, `includes_discounts=true`, listed comparability, no
`energy_package`, and a positive benefit. Ordinary offers state the measured top-level
benefit over the 12-month comparison period. A short fixed term must have a complete
`contract_term` and states its unannualized benefit over the real term; it never describes
the annualized comparison saving as customer benefit. The label is generic Finnish copy
from these typed fields. It uses no seller text, interpretation summary, or relational
discount arithmetic.

The company visible table and offer FAQ use the same filtered collection. The SEO Product
JSON-LD appends the same canonical fact. Feature-off keeps the old relational query,
membership, label, and JSON-LD branches. Canonical CompanyDetail requests and canonical SEO
offer requests issue no `price_components` query in the regression tests.

`ContractListCacheService` and `ContractPageCacheVersion` moved from payload schema v7 to
v8 so prepared company/SEO offer output cannot survive this consumer-boundary change.
No production action, reinterpretation, deployment, commit, or push occurred. Broader bill
period, contract-type comparison, statistics, consumption, home trend, and generated-media
consumer migrations remain pending.

## 2026-07-27 — Company and SEO offer unit resumed and verified

The resumed executor found the focused implementation and tests already present in the shared
working tree. It reviewed those edits instead of duplicating or reverting the concurrent
CompanyDetail and CompanyStatistics work. The first full-suite run found one stale cache-key test
that still expected payload schema v7. The expectation now uses v8, which is the documented
company/SEO offer boundary.

Targeted offer, company-section, canonical-listing, and SEO-list tests pass. Pint passes for the
focused PHP files. The final full suite passes with 1,500 tests and 4,950 assertions. No production
action, reinterpretation, deployment, commit, or push occurred.

## 2026-07-27 — Weekly-offers generated data is canonical-only in canonical mode

`WeeklyOffersVideoService` now has an explicit source branch. When canonical pricing is on, it
starts from the full active household candidate set and never prefilters, loads, or queries
`price_components`. It evaluates all candidates with `metricsForContracts()` once at each existing
video consumption level: 2,000, 5,000, and 10,000 kWh. This permits canonical-only offers and keeps
the query count bounded.

Canonical membership requires these typed facts at the 5,000 kWh selection level:

- `CanonicalOfferFacts` gives a positive measured customer benefit;
- there is no energy package.

The outcome must also be listed and the integrity result must not be detected at all three output
levels, so one unsafe consumption profile cannot enter through a safe 5,000 kWh result.

A relational-only discount, a package, an excluded outcome, and a listed but unsafe integrity
outcome are therefore not offers. The selection metric is the measured customer benefit at 5,000
kWh, descending. The deterministic tie-breaks are canonical comparison total ascending and then
contract ID. Company diversity remains: after that global order, only the first contract for each
company is kept.

The canonical generated record and `/api/video/weekly-offers` response carry `pricing_basis`, typed
current rates, and one result for every consumption level. Each result states availability,
comparability, total and normal total, average monthly values, estimate method, measured comparison
saving, and the total basis. A short fixed term keeps the annualized top-level total only as a
labelled like-for-like comparison and uses `contract_term.discount_savings_total` as its customer
benefit over the real term. Null/unavailable facts stay null and cannot look like zero.

`WeeklyOffersPromptFormatter` has a canonical branch that reads only this payload. It does not
reconstruct percentages or component discount arithmetic. The prompt template and Remotion weekly
offer card now use measured euro benefit and the typed period label; a short term is not called an
annual saving. The feature-off service, API payload, formatter, and Remotion type keep the old
relational shape.

This endpoint has no response or prepared-data cache, so no cache schema version changed. Tests
cover canonical/relational conflicts, relational-only and canonical-only offers, package and
excluded/unsafe rejection, all consumption outputs, ordering/company diversity, real-term copy,
API shape, feature-off compatibility, no `price_components` query, and a bounded query count. No
video, external generation, production action, reinterpretation, deployment, commit, or push ran.
Focused Laravel tests passed with 17 tests and 162 assertions. The final full Laravel suite passed
with 1,503 tests and 5,017 assertions. Remotion lint/type-check and bundle build also passed.
The broader bill-period, contract-type comparison, statistics, consumption, and home-trend consumer
migration remains pending.

## 2026-07-27 — All bill-comparison surfaces use canonical exact-period pricing

`CanonicalContractPriceCalculator` now has a typed exact-period entry point beside its 12-month
calculation. It reuses parsed canonical data, `PhaseTimelineBuilder`, inherited component and
mechanism resolution, package rules, annual comparability, recurring-reset estimation/fill, and
fail-closed behavior. It is not a second canonical calculator. The separate
`CanonicalPeriodPricingOutcome` carries the exact and normal period totals, measured period saving,
relevant rates and Spot margins, phase breakdown, pricing basis, comparability, assumptions, and a
typed unavailable reason.

Period timing treats the contract as accepted at the requested bill start. Relative
`contract_start` / `after_months` phases anchor there. Absolute disclosed dates remain absolute.
Consumption is flat over the real UTC hours in the requested local date range. Fixed Time rates use
the existing 85/15 split. Seasonal rates classify the actual intersected dates and use 85/15 only
for winter day/night. A Spot phase uses every matching realized hourly price and its governing
canonical margin; this supports margin changes and fixed-to-Spot switches inside one bill period.
Missing required Spot hours return `no_spot_history` and never zero.

Ordinary monthly fees preserve the existing days/30 bill convention. A package fee and allowance
are evaluated separately in every intersected calendar month. Both are prorated by the same
calendar-month fraction for a partial month. Unused allowance does not carry. Packages remain
ordinary pricing and do not create a promo. Period promo membership requires a positive measured
canonical normal-minus-actual saving on the exact period.

`CanonicalContractPricingService::periodEvaluationsForContracts()` parses once per contract and
batches the annual and period outcomes with shared annual Spot assumptions and one shared history
load. `BillComparisonService::compare()` and `periodRowsForContracts()` both use that batch. Thus the
standalone page, listing bill mode, and ContractDetail module use one numerical path. Canonical mode
never calls the relational component loader, `extractRates()`, `spotPeriodCost()`,
`seasonalPeriodCost()`, or `ContractPriceCalculator` for market period values. Canonical-only
contracts work; missing, excluded, incomplete, and cap-ineligible cases fail honestly. Feature-off
keeps the old component path.

No cache version changed because bill output is per-user and uncached. Tests cover General, Time,
Season across a winter boundary, realized Spot, changing margins, a fixed-to-Spot switch, missing
Spot history, fee proration, full/partial package months and no carry, relative and absolute offer
timing, measured promo status, canonical/relational conflict, canonical-only, missing, excluded,
consumption caps, all three surfaces, feature-off behavior, no component query, and bounded batch
queries. ContractTypeComparison and current unit statistics remain pending. No production action,
reinterpretation, deploy, commit, or push occurred.

## 2026-07-27 — ContractTypeComparison uses one canonical outcome for all current prices

In canonical mode, `ContractTypeComparison` now evaluates each candidate once per request and
consumption basis through `CanonicalContractPricingService`. The selected contract reuses that same
typed outcome for auto-selection, the 12-month total, average monthly total, canonical
`monthlyCosts` chart series, winner, savings, current rates and fee, package facts, offer fact,
comparability, and estimate disclosure. It does not reconstruct chart months from unit rates.

Canonical candidate queries no longer eager-load `priceComponents`. A canonical-only contract can
be selected and charted. A missing, incomplete, or excluded selected contract has an unavailable
state and an empty series; if either side is unavailable, the widget does not declare a winner or
saving. Package fees, monthly allowances, and excess rates use the typed package object and do not
create an offer. Short fixed terms label the annualized comparison basis and show a real-term offer
benefit. Hybrid base-only, Spot, and market-reset outcomes keep their typed estimate reasons.

The feature-off branch still eager-loads relational components and keeps the previous
`ContractPriceCalculator` chart, winner, selector, and last-year monthly Spot behavior. The widget
has no cached prepared result payload; its legacy monthly Spot cache did not change, so no cache
version moved. Tests cover canonical/relational conflicts, missing canonical data with rows,
canonical-only pricing, one and two excluded sides, packages, short terms, Hybrid, Spot, and reset
estimate disclosures, phase-varying months, winner/savings/rate consistency, both article modes, one
evaluation per candidate, no canonical component query, and feature-off behavior.

Current statistics, the consumption calculator, home unit-price trend, and other remaining
consumers stay pending. No production action, reinterpretation, deployment, commit, or push
occurred.

## 2026-07-27 — Forward statistics and public statistics consumers use canonical current pricing

Forward `ContractPriceStatisticsService` collection now has a hard source boundary. In canonical
mode it does not load or inspect `price_components`. It parses each contract once per chunk through
`outcomesForContractsAtConsumptions()` and calculates typed outcomes for 2,000, 5,000, and 18,000
kWh. Those outcomes supply every stored current value: annual totals, representative General/Time/
Season rate, monthly fee, Spot margin and total, and measured offer state. A canonical-only contract
can contribute all available facts. An excluded or all-null outcome contributes no snapshot. A
package keeps its canonical annual total and package fee, but its excess-use rate is not published
as an all-in energy rate.

Historical and feature-off collection still uses the old relational calculator. In particular,
`BackfillContractPriceStatistics` still passes `useCanonical: false`; today's interpretation never
rewrites an old seller observation. The legacy path's calculation code was kept separate and
numerically unchanged.

The existing tables could not identify which rule produced a row. A small local migration therefore
adds `pricing_basis` to both snapshots and daily aggregates. Existing rows default to
`observed_seller_data`; forward canonical rows use `canonical_calculation`. This migration is needed
for the page and CSV to state provenance and is not a broad pricing-schema redesign. No migration
was run outside the local test database.

`CompanyMarketComparisonService` now matches seller snapshots to the market row's basis. Its
positive/plausible unit-rate guard remains for observed rows, where it protects against known
standing-charge-only and unit-import artifacts. It does not apply that relational guard to a
complete canonical annual total, so canonical-only and package contracts are no longer dropped.
The comparison metric and chart remain `annual_cost`.

`ConsumptionCalculator` now uses stored `annual_cost` rows for every contract type, with its existing
interpolation and nearest-reference behavior. It never reconstructs a public total from energy rate
and monthly fee. A missing annual metric is unavailable. The generated FAQ ranges use this same
path. `HomePage` now charts stored 5,000 kWh annual costs in EUR/year instead of unit-rate metrics;
its latest forward point is canonical-backed and its copy does not describe an old point as today's
interpretation. Its cache key moved to v5.

The statistics page prepared payload moved to v9. Current rows expose their basis, the page explains
canonical current calculations versus observed historical evidence, and CSV adds a `pricing_basis`
column and definitions. Tests cover canonical/relational conflict, canonical-only, package, excluded,
Spot, Time, Season, measured offers, historical and feature-off paths, no component query, company
comparison without a unit rate, calculator annual metrics and unavailable state, homepage latest
annual point, page/CSV provenance, and cache versions. Detail price history and retail-premium
history remain their already-documented relational evidence use cases; this unit did not change
them. No production action, backfill, reinterpretation, deployment, commit, or push occurred.

## 2026-07-27 — Forward statistics unit resumed and verified

The resumed executor inspected the shared working tree and found the forward-statistics migration
already implemented but not finally verified. It preserved the existing edits and added explicit
regressions for a missing canonical unit with a conflicting relational rate, a relational-only
offer flag, and the feature-off forward calculation. Targeted tests pass with 82 tests and 266
assertions. Pint passes for the focused PHP files. The full Laravel suite passes with 1,538 tests
and 5,161 assertions. No frontend build was needed because this resumed unit changed no CSS or
JavaScript. No production action, backfill, reinterpretation, deployment, commit, or push occurred.

## 2026-07-27 — Residual audit: the company directory is a canonical pricing consumer

The completed public-surface inventory missed `CompanyListCacheService`, which prepares the public
`/sahkosopimus/sahkoyhtiot` company directory for 48 hours. This finding is now part of the parent
surface audit. The inventory task stays completed because the missed surface is recorded and fixed.

Before this unit, the service consumed `ContractListCacheService` metrics but did not require
`is_listed=true`, a canonical pricing basis, or a finite total. It replaced missing totals with zero
for averages and `PHP_FLOAT_MAX` for minima. Thus an excluded or incomplete canonical result could
enter company counts, top-five output, or a displayed false zero. Its cache key had only an
import-driven company version and consumption, so a feature-flag flip or a code-only payload change
could serve old relational prices for up to 48 hours.

In canonical mode, company membership, contract counts, average and lowest prices, monthly-fee and
Spot-margin rankings, and environmental aggregates now use only contracts whose cached list metric
is listed, has `pricing_basis=canonical`, and has a finite canonical total. Missing and excluded
outcomes do not count. Canonical-only contracts remain valid. Price rankings omit null values and a
company card cannot format null as EUR 0. The feature-off branch still consumes the existing legacy
relational list metrics.

The company cache key now has its own payload schema (`s1`) and both canonical (`c`) and reset-shift
(`r`) markers, and the service memoizes its version and per-consumption result for one request. The
default ranking cache has a separate payload schema marker (`s1`) before its existing `c`/`r`
markers, so a code-only eligibility or payload change cannot preserve old ranks for one hour.

`Api\CalculationController` no longer eager-loads `priceComponents` before it selects a pricing
source. Canonical requests query only the contract and canonical dependencies; the feature-off model
helper still loads relational components. `ConsumptionCalculator::priceStatisticsRows()` now loads
only `annual_cost`, and its prepared rows no longer carry the unused energy-rate and monthly-fee
keys. The rendered table and FAQ already used only annual-cost fields, so output does not change.

Tests cover canonical/relational conflicts, canonical-only pricing, missing and excluded outcomes,
no false zero, counts and cheapest ordering, feature-off compatibility, company `s/c/r` cache
separation and memoization, ranking schema versioning, and no canonical calculation-API component
query. The separate fixed-term forecast residual and the broader reinterpretation/rollout work stay
pending. No production action, reinterpretation, deployment, commit, or push occurred.

## 2026-07-27 — Residual company-list/cache unit verified

The combined targeted run passed with 85 tests and 284 assertions. Pint passed after it fixed three
style issues in the focused files. The full Laravel suite passed with 1,544 tests and 5,195
assertions. `git diff --check` passed. No frontend build was needed because the Blade change is
server-rendered markup and no CSS or JavaScript changed in this unit.

## 2026-07-27 — Fixed-term forecast current input has explicit canonical provenance

The residual audit found that `FixedTermPriceForecastService::retailStatistic()` selected an
`energy_price` row without checking `pricing_basis`. In canonical mode, an observed relational row
could therefore become the current retail input and then appear on the public
`/sahkosopimus/sahkon-hintaennuste` page or in a comparison-page forecast teaser.

Model `fixed_term_ewma_gap_v2` is the provenance boundary. In canonical mode, the current statistic
must be `canonical_calculation`; in explicit feature-off mode it must be `observed_seller_data`.
There is no cross-basis fallback. A missing required current row skips that forecast. Existing v1
rows remain stored under the model-version identity and are not overwritten.

The existing `source_metadata` now records the current retail basis, source date, segment, metric,
and contract count. Historical EWMA evidence is deliberately separate: it uses observed seller
statistics strictly before the forecast date, deduplicates date+basis pairs, and records basis
counts and date bounds. Thus an observed row on the forecast date cannot enter through the history
path after the canonical current input is selected. No new forecast column or migration was needed.

Matured evaluation keeps its prior meaning as a historical realized seller observation. It
explicitly selects `observed_seller_data` and appends the actual basis/date/segment/metric/count to
`source_metadata` while preserving the forecast-input metadata.

`FixedContractPriceForecast::eligibleForPublicDisplay()` is the shared public boundary. The full
forecast page, its history series, and `ContractMarketInsightService` require the configured model
version and the basis expected by the canonical flag. Old-model, missing-provenance, and wrong-basis
rows show no current forecast; the full page uses its existing unavailable state. Feature-off shows
current-model observed-basis rows. The insight cache moved from v3 to v4 and now varies by canonical
mode and model version.

Public method copy now distinguishes the canonical current contract-price input, historical
observed seller evidence, and EEX futures input. It does not expose JSON metadata or state the
forecast as certain. The stale documentation claim that no public frontend exists was removed.

No production forecast run, migration, reinterpretation, database write outside tests, deployment,
commit, or push occurred. Reinterpretation, production comparison/rollout, final documentation,
and final full-suite rollout work remain pending.

Targeted forecast/page coverage passes with 9 tests and 59 assertions. Focused Pint passes,
`git diff --check` passes, and the full Laravel suite passes with 1,550 tests and 5,229 assertions.

## 2026-07-27 — Final statistics-basis enforcement

`ContractPriceBasis` is now the one current-statistics rule. Canonical mode expects
`canonical_calculation`; feature-off expects `observed_seller_data`. There is no cross-basis
fallback. The Consumption Calculator, company current range, listing market insight current point,
and spot article market snapshot select the latest available annual-cost date for that expected
basis instead of the global latest date.

The company cache key includes canonical state, expected basis, and a source fingerprint with both
tables' relevant latest dates and maximum `updated_at`. The article snapshot key has the same
canonical/basis/source boundary. Listing insights moved to cache schema v5; their fingerprint and
payload key vary by canonical state and basis. A canonical listing insight compares the canonical
current point with dated observed seller evidence about 30 days earlier and states that provenance
in visible supporting copy. Company charts keep observed rows only before canonical forward
collection starts, then use canonical points. Thus an observed same/latest-date row cannot replace
the canonical point.

A statistics calculation now reconciles target-date ownership inside its existing transaction.
Without broad overwrite, it deletes all opposite-basis snapshots for only the target date and
replaces prior same-basis snapshots for the run's contract set before it calculates aggregates.
This removes a stale snapshot when a later canonical run excludes that contract. Feature-off and
historical backfill runs take observed ownership by the same rule. No other date is deleted.

Tests cover newer wrong-basis dates, same-date mixed bases, canonical and feature-off behavior,
same-day fingerprint invalidation, explicit observed history, target-date ownership, stale canonical
exclusions, and historical preservation. The audit also verified the complete typed outcome,
validator rules, schema/prompt/DTO versions, and required regression fixtures, so those task items
are complete. Root and local context files now match the implemented phase, statistics, loading,
bill, and card boundaries. Production reinterpretation, production comparison, migration execution,
and rollout remain pending. No production action ran in this unit.

Verification for this unit: the four focused consumer/writer test classes pass with 69 tests and
221 assertions; the related statistics/listing integration set passes with 146 tests and 384
assertions; the full Laravel suite passes with 1,556 tests and 5,274 assertions. Focused Pint and
`git diff --check` pass.

## 2026-07-27 — Final local hardening and rollout plan complete

The complete task diff received one final source, version, cache, migration, and unavailable-state
review. It found and fixed four concrete rollout defects:

1. Expired `UntilDate` metadata is now discarded before active-discount amount validation. Stale,
   incomplete historical discount fields cannot block a current interpretation.
2. The statistics page and homepage trend now end on the latest date for
   `ContractPriceBasis::expectedCurrent()`. A newer opposite-mode row cannot become the public
   current point. Their prepared cache keys include the expected basis; statistics moved to v10
   and the homepage trend to v6 with source fingerprints.
3. Company-list and ranking cache keys now include the shared contract-list data version. Each
   published interpretation already bumps that version, so company output cannot stay stale for
   48 hours and rankings cannot stay stale for one hour after reinterpretation.
4. `contracts:compare-canonical-pricing --fail-on-parse-errors` now parses each active canonical
   payload directly. A malformed non-null payload fails the command instead of being mistaken for
   an ordinary incomplete outcome.

Current canonical DTO comments now name schema v4. Current interpretation test provenance reads the
configured schema/prompt/validator versions. Retail-premium fixtures that retain schema-v3,
prompt-v17, and validator-v14 values now state that they deliberately test pre-rollout published or
historical provenance. Forecast v1 references remain only in the test that proves immutable v1 rows
survive beside current v2 rows.

The pricing-basis migration was tested from a fresh current schema in a temporary SQLite database,
rolled back, reapplied, and inspected for both columns. The full in-memory test suite also exercised
all migrations and the date-ownership upserts. No local non-test database was changed.

`rollout.md` now gives the staged production plan with exact Railway IDs, read-only baseline capture,
deploy/migration behavior, temporary unavailable states, bounded reinterpretation, old/new and
legacy/canonical comparisons, named price/ranking gates, statistics/forecast/cache rebuilds, smoke
tests, backup rules, and stop/rollback criteria. It preserves interpretation history and requires
explicit confirmation for every production mutation.

Final verification passes: Laravel has 1,559 tests and 5,286 assertions; focused Pint covers all 105
task-changed PHP files; the Laravel Vite build passes; Remotion lint/type-check and bundle pass; JSON
validation and `git diff --check` pass. No production action, Railway command, deployment,
reinterpretation, external database write, commit, or push occurred. Only production
reinterpretation and production comparison/material-review tasks remain pending.

## 2026-07-27 — Read-only production pre-deploy baseline

This stage inspected only project `6d8cae01-1006-409f-8108-1d51f1abc676`, production environment
`9245cef8-41d0-486e-862f-193726511dba`, app service
`700d0624-fa96-4266-876c-e37640d220ea`, and MySQL service
`beb2ba12-4a7b-416b-b4b1-596434dc3215`. All Railway CLI calls used
`RAILWAY_CALLER=skill:use-railway@1.2.2` and one session ID. No deploy, migration, interpretation,
job dispatch, statistics run, cache warm, forecast run, variable change, database write, restart,
commit, or push occurred.

The fixed comparison start date is **2026-07-27 Europe/Helsinki**.

### Platform, workers, logs, and backups

- App deployment `fccb9a43-64dc-4f1d-a96c-6cf809a1c544` is `SUCCESS`, from
  2026-07-27 05:28:59 UTC, at commit `290676250cde22245c2b500edd24613fd3cf77ed`.
- The MySQL deployment is `SUCCESS` and not stopped. The environment reports both app and MySQL
  services as `SUCCESS`, with one active deployment each.
- Startup logs show FrankenPHP, scheduler, and queue worker in the `RUNNING` state. The database
  has 0 queued jobs, 0 reserved jobs, and 0 failed jobs.
- One-hour app metrics were 0.0013–0.0417 CPU and 0.4901–0.5352 GB memory. One-hour MySQL metrics
  were 0.0057–0.0375 CPU, about 3.323 GB memory, and 1.7813–1.8108 GB disk use.
- The bounded Laravel log tail found normal upstream Spot fetch 503 errors at 11:01 and 12:01 UTC.
  More importantly, `/sahkosopimus/kannattaako-porssisahko` exhausted the 128 MB PHP memory limit
  at 11:35 and 12:12 UTC. A direct repeat also returned HTTP 500 in about 3.3 seconds. This is an
  active **health stop condition** before a production mutation. The specified baseline pages and
  APIs below still returned HTTP 200.
- The encrypted S3 backup set is reachable and healthy: 31 archives, newest about 12 hours old,
  about 559.74 MB total. The bounded `backup-monitor.log` tail repeatedly states that the S3 backup
  set is healthy.
- Railway-native MySQL backups are present. The latest daily backup was created at
  2026-07-27 09:40:02 UTC. Daily, weekly, and monthly schedules are enabled; the latest weekly
  backup is from 2026-07-25 and the latest monthly backup is from 2026-07-01. No manual backup was
  needed or run.

### Production data baseline

- Active contracts: **420** total; **304** household or both; **35** active companies.
- Active contracts without a published interpretation: **0**.
- Current configured interpretation versions are `schema-v4` / `prompt-v19` / `validator-v16`.
  There are **0** interpretation rows at that version, as expected before controlled
  reinterpretation.
- Active published pointers: **419** are `schema-v3` / `prompt-v17` / `validator-v14` /
  `published`; **1** is `schema-v3` / `prompt-v14` / `validator-v10` / `published`.
- All interpretation history has **1,501 published** rows and **53 failed** rows. These are old
  versioned attempts; no new interpretation ran in this stage.
- Both `contract_price_snapshots` and `contract_price_daily_statistics` have latest date
  **2026-07-27**. Neither table has `pricing_basis` yet. This is the expected pre-deploy schema.
  No migration was attempted, so basis counts are not available.
- Fixed forecasts contain **909** `fixed_term_ewma_gap_v1` rows, latest date 2026-07-27. Their
  `current_retail_pricing_basis` provenance is missing. There are no model-v2 rows. The configured
  model is `fixed_term_ewma_gap_v2`, so canonical public consumers must keep these old rows hidden
  until the separately approved forecast stage.
- Named production flag states are: `CANONICAL_PRICING_ENABLED=true`,
  `RESET_FORWARD_SHIFT_ENABLED=true`, and
  `PRICE_FORECASTING_MODEL_VERSION=fixed_term_ewma_gap_v2`. No other variable values were listed.

The sanitized count and provenance output is in
`baseline/production-metadata.json`.

### HTTP/API baseline

Read-only GETs returned HTTP 200 for `/`, `/sahkosopimus`, `/sahkosopimus/sahkoyhtiot`,
`/sahkosopimus/tilastot`, `/maksatko-liikaa`, the Vaasan Sähkö company page, the Vaasan
Kuukausipaketti XS contract page, `/api/contracts?consumption=5000`, and
`/api/video/weekly-offers`. Response bodies, about 1.9 MB in total, are in
`/tmp/voltikka-http-baseline-2026-07-27/`. Status, byte count, content type, duration, and SHA-256
hash are in `baseline/http-baseline-metadata.json`.

### Canonical comparison baseline

Command help confirmed read-only options `--consumption`, `--start-date`, `--json`, `--resets`,
and `--fail-on-parse-errors`. The four main runs used `--fail-on-parse-errors` and completed with
no parse error. Each run reviewed all **420** active contracts. The distribution was stable at all
four consumption levels:

- 207 `comparable_exact`;
- 132 `comparable_estimate`;
- 49 `base_only_hybrid`;
- 22 `term_price_only`;
- 10 `excluded_incomplete`.

Thus **410 are listed and 10 are excluded**. Six contracts have an integrity state: five `promo`
and one `data_conflict`. The material legacy-versus-current-canonical union, using more than
EUR 25/year or 5%, is 47 contracts at 2,000 kWh, 48 at 5,000 kWh, 53 at 10,000 kWh, and 55 at
18,000 kWh. At 5,000 kWh, 36 of the 48 material rows are reset products and 12 are other products.
These are current published-data differences, not schema-v4 reinterpretation differences.

The 5,000 kWh reset review found **38** recurring lineages: 36 used the forward-curve shift and 2
kept the same total under hold-flat review. The 36 shifted lineages consist of 9 monthly and 27
quarterly rows. Mean shift versus hold-flat is EUR 152.5/year and the maximum is EUR 254.9/year.
The two unchanged rows are Fortum Yritys Spot Portfolio 1v, which uses the rolling Spot estimate,
and Kosken käyttöWoima 12 kk, which uses the Hybrid base-only estimate. The shift rows all report
`forward_month_from_quarter_contract`; no parse failure occurred.

Machine-readable files are local and contain no variables or credentials:

- `baseline/before-schema-v4-2000.json`;
- `baseline/before-schema-v4-5000.json`;
- `baseline/before-schema-v4-10000.json`;
- `baseline/before-schema-v4-18000.json`;
- `baseline/before-schema-v4-resets-5000.json`;
- `baseline/critical-contracts-5000.json`.

### Named critical review at 5,000 kWh

- **Vaasan packages are a required reinterpretation gate.** Current published canonical output has
  no package object and applies 16.60 c/kWh to all use. Canonical totals are XS EUR 956.00,
  S EUR 1,082.00, M EUR 1,250.00, and L EUR 1,418.00. The legacy comparison totals are the expected
  package values EUR 806.60, 783.20, 752.00, and 720.80. Schema-v4 reinterpretation must create the
  monthly allowance objects and must not keep the current canonical totals.
- **Surffari passes the current-phase gate.** The household row has 0.20 c/kWh through
  2026-08-31 and 0.60 c/kWh from 2026-09-01. Its canonical total is EUR 415.84 and measured benefit
  is EUR 1.94. The VAT-excluded row has the matching 0.16/0.48 c/kWh phases.
- **Vattenfall named 50% fee offers pass.** Their current canonical totals equal legacy at
  EUR 445.48 and EUR 433.28. Their measured benefits are EUR 35.76 and EUR 28.44.
- **Hybrids stay base-only.** All 49 Hybrid outcomes are `base_only_hybrid`, all 49 retain a typed
  consumption effect, 9 have a positive billed-base offer, and 4 also have a recurring schedule.
  The Vaasan Vaikuttaja reset-plus-consumption-effect example remains base-only, uses the recurring
  forward shift, and reports no false offer saving.
- **Short terms use separate bases.** There are 22 `term_price_only` rows and 4 positive offers.
  For the four Vaasan six-month rows, the real-term benefits are EUR 5.90 or EUR 4.70, while the
  annualized comparison benefits are EUR 11.80 or EUR 9.40.
- The 10 excluded rows keep null totals. No excluded row became EUR 0 in this read-only output.

### Commands and stop conditions

Commands were run in this non-secret form:

```text
railway environment/deployment/service/log/metric read commands --project <project-id> --environment <environment-id> --service <service-id>
railway api <read-only volume backup queries>
railway ssh ... tail -n <bounded-count> <log-file>
railway run --project <project-id> --environment <environment-id> --service <app-id> env DB_URL= DB_HOST=<MySQL TCP proxy> DB_PORT=<proxy port> MYSQL_ATTR_SSL_CA= php artisan contracts:compare-canonical-pricing ...
php artisan backup:list
curl GET <public baseline URL>
```

Do not start a deploy or any production write while either stop condition remains:

1. `/sahkosopimus/kannattaako-porssisahko` repeatedly returns HTTP 500 from a 128 MB memory
   exhaustion. Review and fix or explicitly accept this health incident first.
2. The checkout is not a clean deploy artifact. Local `HEAD` equals the deployed commit, but the
   working tree has 104 modified tracked files and 30 untracked status entries, including task
   implementation, agent files, `.pi/`, migrations, tests, and generated baseline data. A deploy
   from this tree would include all of those uncommitted changes. Separate and review the intended
   release before any deploy.

The two production tasks remain pending. This stage did not reinterpret active snapshots and did
not run an after-schema-v4 material or ranking comparison.

## 2026-07-27 — Article memory blocker is locally addressed, not production-cleared

Read-only Railway inspection stayed within project `6d8cae01-1006-409f-8108-1d51f1abc676`,
production environment `9245cef8-41d0-486e-862f-193726511dba`, and app service
`700d0624-fa96-4266-876c-e37640d220ea`. Bounded HTTP logs confirmed the 11:35 and 12:12 UTC
requests returned 500 in about three seconds. A bounded Laravel-log read gave the allocation site:
`Illuminate\Collections\Collection::mapToDictionary()` while the article's contract-price chart
grouped its daily rows. No production write, cache clear, deploy, restart, or data change occurred.

The failure was cumulative. The eager article renders five child widgets in one request. Both
contract-statistics widgets loaded and transformed all matching dates as full Eloquent models; the
comparison widget also cached that raw model collection. Production had 10,980 aggregate rows,
including 748 rows for each article query. The volatility widget additionally hydrated 8,870 hourly
Eloquent models. On the equivalent local data, one 736-row all-column statistics collection used
about 4 MB and serialized to 1.68 MB, versus about 2 MB and 90 KB for three base-query columns. The
8,800-row hourly Eloquent read used about 14 MB before transformation. The final allocation happened
inside grouping, but these retained model graphs and duplicate transformations consumed the request
headroom first.

The local fix keeps the visible widgets and SEO markup. The two statistics widgets now:

- select only date, segment, and the plotted median or p20 value through the base query builder;
- read at most the trailing year;
- cache only prepared arrays, not Eloquent collections;
- end on the latest date for `ContractPriceBasis::expectedCurrent()`, exclude a newer wrong-basis
  endpoint, and keep earlier observed rows as historical evidence.

The volatility widget now streams its existing trailing-year, three-column hourly query instead of
hydrating the complete year as Eloquent models. A regression fixture inserts more than 6,000 daily
statistics rows across over three years and proves that both article queries are date-bounded,
column-selective, preserve the canonical endpoint, exclude the newer observed endpoint, and render
the public route.

A temporary copy of the production-shape local SQLite data was migrated to the mixed-basis schema,
its latest day was marked canonical only in that temporary copy, and the complete cold-cache route
was run with PHP `memory_limit=128M`. It returned HTTP 200 with a 140,778-byte response and
61,341,696-byte peak PHP memory. The same local route before this focused change used 78,118,912
bytes. The production blocker is therefore **locally addressed but remains active** until a reviewed
deploy and direct HTTP/log verification. The mixed working tree remains the second deploy blocker.
No commit or push occurred.

## 2026-07-27 — Company offers state exact typed terms and company comparison has an honest historical fallback

The generic canonical offer label did not tell a visitor what the campaign was. The canonical
calculator now derives `calculated_cost.offer_terms` from each governing resolved phase and exact
changed billed components. It prefers `amount` plus a higher `normal_amount`; when those fields do
not carry the complete offer, an `introductory` phase can be compared with its typed `normal` or
`continuation` phase. Recurring market resets cannot use that phase-only fallback. A held-forward
Hybrid uses only its known resolved phase spans for the term, so a first-month billed-base offer is
not described as a year-long term and the unknown consumption effect stays excluded. A term carries
the component type/unit, actual and normal amounts, resolved start/end dates, and an exact
first-N-month, month-range, complete short-term, or absolute end-date basis. Multiple changed
components share one timing. Raw phase labels are deliberately absent.

`CanonicalOfferFacts` formats only controlled Finnish component names and exact prices. It supports
monthly fees, fixed energy buckets, and Spot margins; one month, first-N-month, complete short-term,
and absolute-date copy; and multiple changed components. It does not derive a percentage. Missing,
unsupported, duplicate, or unresolved typed terms fail closed even when a separate measured saving
exists. Packages, excluded outcomes, relational-only discounts, and zero savings stay out. A short
fixed term still uses its unannualized real-term saving. Feature-off keeps the relational path.
List and prepared-page payload schemas moved from v8 to v9.

The company offer table now uses `Säästö`, natural basis labels, and the exact offer term. The nearby
copy explains the comparison once and no longer uses `Mitattu etu`.

`CompanyMarketComparisonService` now finds the latest usable joined market+company date, not only
the latest market date. Canonical mode first requires a same-date canonical pair and ignores newer
wrong-basis rows. If no canonical pair is usable, it can return the latest same-date
`observed_seller_data` pair with `comparison_state=historical_observed_fallback` and
`is_historical_fallback=true`. The Blade names the date and says that the result is historical, not
today's comparison. Its chart uses observed history through that date. Canonical charts still join
older observed history to canonical points, and `fixed_term_12` stays first in the chart preference.
The six-hour cache key moved to v4 and fingerprints both canonical and observed sources in canonical
mode.

Focused tests cover exact monthly-fee and Spot-margin terms, multiple changed components, hostile
phase-label isolation, real-term saving, no canonical component query, fail-closed offer membership,
feature-off behavior, canonical basis preference, dated observed fallback rows/chart/copy,
fixed-term chart preference, and fallback fingerprint invalidation. After the Hybrid and phase-only
follow-up, the final Laravel suite passes with 1,568 tests and 5,364 assertions. Focused Pint, the Vite build, JSON validation,
`git diff --check`, and the Impeccable detector all pass.

For local page verification only, the pending `pricing_basis` migration was applied and the
2026-07-27 canonical statistics command rebuilt 298 snapshots and 47 aggregate rows from 425 active
contracts. The local HTTP server returned 200 for the Vaasan Sähkö company page. It rendered
18 specific offer rows, the `Säästö` heading, the current comparison and chart, and no generic offer
label. Example local output: `Perusmaksu 0 €/kk ensimmäiset 2 kk`, with its dated saving basis.
A follow-up local audit initially found five real typed offers without terms: four Vaasan
`Kiinteä Vaikuttaja` Hybrid rows and Vihreä Älyenergia's phase-only Vire/Verraton shapes. The
held-forward and introductory-to-normal derivation above now covers all of them. The final audit over
all active local canonical outcomes found one positive calculator delta without `offer_terms`:
Kokkolan Energia Tyyni. It is an active recurring market-reset product whose current and future
period prices differ, not a promotion, so its deliberate omission proves the false-offer guard.

No production or Railway action ran.

## 2026-07-27 — A short Hybrid keeps its real contract-term offer basis

The company offer table exposed a remaining outcome-shape defect on Vaasan Sähkö's `Kiinteä
Vaikuttaja 6 kk` products. Their relational structural context correctly said `FixedTerm` +
`Fixed6`, but canonical calculation status `unsupported` entered the Hybrid branch before the
ordinary short-term branch. The resulting `base_only_hybrid` outcome had no `term_months` or
`contract_term`, so `CanonicalOfferFacts` correctly but undesirably selected its only available
basis: `12 kuukauden vertailussa`.

The calculator now detects an exact short structural term inside the Unsupported Hybrid branch. It
costs the base-only actual and normal phase timelines through that real term, captures the
unannualized term totals and saving, and then applies the same `12 / term_months` factor to the
comparison totals. The outcome remains `base_only_hybrid` with `hybrid_base_only`; it still excludes
the unknown consumption effect and now also records `term_price_annualized`. No presenter parses a
name or reads relational prices. Fixed12 and Fixed24 Hybrids keep the ordinary 12-month offer basis.

On the current local Vaasan business contract, the corrected payload has a 6-month term saving of
EUR 4.70 and a 12-month annualized comparison saving of EUR 9.40. The public offer fact uses only
the former and now says `6 kuukauden sopimuskaudella`. List and prepared-page payload schemas moved
to v10 so stale v9 outcomes cannot keep the wrong basis after deploy.
