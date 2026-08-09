# AGENTS.md

Context for Livewire components under `laravel/app/Livewire`.

Use this file as a shortcut to find component-specific behavior. It does **not** replace reading the code.

See also:
- `../AGENTS.md` for Laravel-level behavior
- `../Services/ContractReplacement/AGENTS.md` for replacement matching/linking rules

## Permanent form input rule

- A control in which a visitor enters a value normally uses `wire:model.blur`. This includes text, number, email, password, telephone, URL, date, month, week, time, datetime-local, and textarea controls.
- Never use `wire:model.live`, debounce, `wire:input`, or `wire:change` for those ordinary editable values. Processing while the value is incomplete causes requests, normalization, validation, and calculations to interrupt typing. This has caused repeated broken calculator behavior.
- Search and autocomplete controls are the deliberate exception. Mark each one with `data-search-input` and use `wire:model.live.debounce.Nms`. A search that waits for blur is broken because its result list appears only after the visitor leaves the field.
- Checkboxes, radio buttons, selects, range sliders, file inputs, and explicit buttons are complete discrete choices and can stay immediate. This distinction is intentional, not an exception for one component.
- If an Enter shortcut commits a typed value, it must blur the field. Do not create a second processing path.
- Numeric fields that represent consumption, dimensions, people, prices, costs, usage, capacity, or periods define a non-negative HTML `min`. Browser constraints are not trusted: the component must normalize or reject an invalid value before it reaches a service or calculation, write any corrected value back to the field, and show an adjacent or form-level accessible notice. Use `data-allow-negative` only for a genuinely signed domain value and document it here.
- `tests/Unit/FormInputBlurPolicyTest.php` scans all Blade views, including views that are not on a current route. A dormant template must not bring the defect back when reused.

## `BillComparison`

Primary files:
- `BillComparison.php`
- `../../resources/views/livewire/bill-comparison.blade.php`
- `../Services/BillComparison/AGENTS.md`
- `../Services/DTO/BillComparisonRequest.php`

Purpose:
- renders `/maksatko-liikaa` — the "Maksatko sähköstä liikaa?" bill comparison tool
- visitor enters one bill's date range, kWh and total (energy only, excl. siirto); component compares against all active household contracts for the same period+consumption

Important semantics:
- the bill total is the anchor — the user's pricing model / day-night split / margin are never modelled. The standalone energy-price/base-fee "explanatory" inputs and the "miksi kallis" box they fed were removed in the 2026-06 simplification (low payoff, never touched the counterfactual); `BillComparisonRequest` no longer carries `energyPriceCents`/`baseFeeEur`. Do not reintroduce without a real product reason.
- the verdict hero leads with the annualized **€/vuosi** saving as the primary number, explicitly labelled `arvio` (with €/kk as a sub-line and the **period** saving shown as the actual/`toteutunut` figure). The headline is a seasonally-annualized estimate driven by `includesHeating` + `annualKwh`, so it must stay labelled as an estimate; the hero caption names the heating/seasonal basis so the toggle's effect on the number is visible. Keep `includesHeating` + `annualKwh` — they drive the hero, they are not decorative.
- the ranking table must keep row-level values on the same period basis: `Jakson hinta` and `Säästö jaksolta` both compare the visitor's entered bill period and kWh. Do not show annualized €/kk row savings in that table; annualized savings belong in the verdict hero.
- the ranking table must not show the user's implied c/kWh value in the `c/kWh` column. That value is only `bill total / kWh` (a blended bill average, possibly affected by base fees), not the user's known energy price.
- `includesVat` (default true) normalizes a pre-VAT total to Voltikka's with-VAT basis via `VAT_MULTIPLIER` (1.255) before comparison. Market contract costs are energy-only incl. ALV 25.5 %, excl. siirto.
- period presets are the last 3 **completed** calendar months (the current
  unbilled month is intentionally excluded) plus a custom date range; spot/
  seasonal math uses exact dates so the comparison stays honest for
  non-calendar-month bills.
- `annualKwh` is an optional override: when provided (>0) it replaces the
  seasonal-profile annualization for the savings estimate. `includesHeating`
  selects the seasonal annualization profile (see `ConsumptionProfile`);
  annualized savings are labelled "arvio".
- numeric inputs are `float|string|null` tolerant (mobile blank states); `nullableFloat()` is public so the view can guard optional-input logic. Required kWh and price fields restore the last accepted positive value with an accessible error. The optional annual-kWh field rejects a negative value, clears it, and shows its own error.
- zero is not accepted for either required number. A numeric consumption below 1 kWh or bill price at or below 0 € is replaced with that field's locked last-accepted value, is never sent to `BillComparisonService`, and shows an accessible Finnish validation error. Existing results stay consistent with the restored valid inputs. The inputs also use HTML `min=1` and `min=0.01`, but the Livewire guard is authoritative because native number inputs can still submit an invalid value. A later valid value becomes the new accepted value, clears the error, and recalculates.
- **Derived result state (`$resultArray`, `$calculated`, `$errorMessage`) is intentionally `protected`, never `public`. Do not make it public/synced.** As a public property the 300+ row DB-derived `$resultArray` made Livewire's deep-array dehydration produce a snapshot whose own checksum could not be reproduced on verify, raising `CorruptComponentPayloadException` on every update so the page silently froze on stale numbers (snapshot was ~168 KB). It is recomputed from the inputs each request by `calculate()`, so syncing it bought nothing. `render()` passes `resultArray`/`errorMessage` to the view and runs a cheap `calculate()` guard so every render path has results. Tests read the result via `->viewData('resultArray')`, not a public property. `test_result_is_not_synced_into_the_livewire_snapshot` pins this invariant.
- `updatedStartDate()` / `updatedEndDate()` call `calculate()` after their date inputs blur (the comparison period drives the counterfactual, and the result is no longer persisted, so a manual date edit must recompute). The shared in-listing/detail bill fields use the same blur boundary for dates, kWh, and total price.
- this is a per-user calculator: no public prepared-data caching and it is intentionally not in `SetPublicCacheHeaders` (matches the heat-pump / solar calculators).
- loading feedback uses the shared `<x-spinner>` Blade component (`resources/views/components/spinner.blade.php`) inside a `wire:loading.delay` fixed bottom-right pill plus a `wire:loading.delay.class="opacity-50"` dim on the results region. Reuse `<x-spinner>` for any new loading indicator rather than re-inlining the SVG, so the coral spinner stays visually consistent across calculators.
- both WebApplication + FAQPage schemas render in the view via `<x-schema-markup :schemas="[$jsonLd, $faqJsonLd]" />`; `getFaqItemsProperty()` is the single source of truth for the FAQ.

## In-listing bill comparison (`ContractsList` bill mode)

Separate from the standalone `/maksatko-liikaa` page: the contract listings can
optionally take the visitor's bill and rank it against the listed contracts
in-place, showing **EUR savings vs their current contract** on the cards. Proven
on `/sahkosopimus` first.

Primary files:
- `Concerns/BillComparisonInputs.php` (the shared bill inputs + actions)
- `ContractsList.php` (rollout switch, `recomputeBill()`, `buildBillModePaginator()`)
- `SeoContractsList.php` / `SahkosopimusIndex.php` (the latter sets `$showBillComparison = true`)
- `../Services/ContractListing/ContractListingPipeline.php` (visible loading and manual pagination)
- `../../resources/views/partials/bill-comparison-form.blade.php` (the shared form fields)
- `../../resources/views/livewire/seo-contracts-list.blade.php` (disclosure + anchor + period-mode card loop)
- `../../resources/views/components/contract-card.blade.php` (`billMode` / `periodComparison` props)
- `../Services/BillComparison/AGENTS.md` (`periodRowsForContracts`, "Three surfaces, one form")

Important semantics:
- **The inputs live in a trait, the fields live in one partial.** `ContractsList`
  and `ContractDetail` both `use BillComparisonInputs` and both `@include`
  `partials/bill-comparison-form`, so the two period-basis surfaces cannot drift
  in field set, VAT normalization or period presets. Each component keeps only
  what is genuinely its own: `recomputeBill()` (invalidation + its own Plausible
  `source`) and `billInputsEnabled()`. Do not add a field to one template.
- **Postcode eligibility is shared listing state.** An empty postcode shows only
  contracts with `availability_is_national = true`. A valid exact Finnish postcode
  adds contracts linked through `contract_postcode`. The selector is outside the
  advanced-filter disclosure and stores the validated selection in browser
  `localStorage`; an explicit URL/Livewire selection wins over stored state. Invalid
  or stale values clear the selection and fail closed. City-page regional sections
  accept the selection only when its municipality matches the page, while nearby
  local-company contracts still use the visitor's actual selected eligibility.
- **Period basis only (facts).** When a valid bill is entered, the listing
  reranks by each contract's *exact billing-period* cost (`periodCostEur`) via
  `BillComparisonService::periodRowsForContracts()`, not the annual estimate.
  Savings on a card = `user bill total − contract period cost`. Annualized
  savings are intentionally **not** shown here (annualizing one month's implied
  unit rate is biased for spot/seasonal/time). See `tasks/promote-bill-comparison-in-listings`.
- `$showBillComparison` is the rollout switch. Rolled out to **all
  household-oriented listing pages**: `true` on `SeoContractsList` (so every SEO
  pricing/housing/energy/city/duration/consumption-level page + `CheapestContracts`,
  which inherits it) and on `SahkosopimusIndex`; `false` on the base
  `ContractsList` (homepage). It is forced **off for business pages**
  (`SeoContractsList::mount()` sets it false when `targetGroup === 'Company'`)
  because a household energy bill is not comparable to business contracts.
- **Bill mode skips the annual-slider consumption-range pre-filter.** In
  `getContractsProperty()` (both `ContractsList` and `SeoContractsList`) the
  `isConsumptionInRange($this->consumption)` filter is wrapped in
  `! isBillModeActive()`. In bill mode the relevant consumption is the bill's
  *annualized* kWh, not the slider set before the bill; capped flat-fee tiers are
  still excluded correctly by `BillComparisonService::fitsConsumptionLimits()` on
  the bill-derived `$annualKwh` inside `buildMarketRow()`. Pre-filtering with the
  stale slider would wrongly drop/keep capped tiers on a mismatched basis.
  Regression: `test_bill_mode_ignores_stale_annual_slider_for_consumption_caps`.
- `$billActive` + the bill inputs are **interactive state only, never `#[Url]`**,
  so a fresh GET always starts in normal mode and the cached default-listing
  payload is unaffected. `isDefaultListingCacheable()` also guards `! $billActive`.
  When bill mode first becomes active, `ContractsList::recomputeBill()` dispatches
  the existing Plausible `Bill Comparison Completed` tracking event with
  `source=contract_listing`; keep it on the inactive→active transition so valid
  follow-up edits do not spam duplicate events. The installed Livewire 3 passes
  the named dispatch detail to the browser bridge as an object, not `data[0]`;
  `tests/JavaScript/plausible-tracking.test.js` executes the real bridge and guards
  the event name and nested `props` forwarding.
- `getContractsProperty()` (in both `ContractsList` and `SeoContractsList`)
  branches to `buildBillModePaginator()` after applying the page's filters, so
  filters still apply in bill mode (period costs are computed for the filtered set).
- `buildBillModePaginator()` keeps period calculation and sorting in Livewire. It uses
  `ContractListingPipeline::paginate()` for visible loading and paginator construction,
  then attaches a `period_comparison` array per visible contract and recomputes
  `emission_factor` (the visible reload has no annual metrics in bill mode, so the CO2
  stripe would otherwise default wrong). `$billSummary` (rank, monthly cost, cheapest
  saving) is filled here for the dark "Sinun sopimuksesi" anchor.
- Card period block: €/kk from `period cost ÷ months`, a period-scoped secondary
  line (`X € / laskutusjakso`, never an annual `€/v`), and a neutral-slate
  "Säästö €/kk" delta (green/red reserved for CO2 per `../../../DESIGN.md`).
  Framed "laskutusjaksollasi" so a winter bill's higher €/kk is not read as a
  typical going-forward monthly cost.
- Period preset / annualization helpers mirror the standalone tool (last 3
  completed months); `booted()` seeds default dates + preset labels each request.
- **Listing cards deep-link the visitor's consumption to the detail page** so the
  detail price matches the listing. `contract-card`/`featured-contract-card` build
  `?kulutus=N` from `detailConsumption ?? consumption`, only when it differs from
  the 5000 default. In bill mode the view passes `billSummary['annual_kwh']` as
  `detailConsumption` (the bill-annualized kWh). `ContractDetail::mount()` reads
  + clamps `request()->query('kulutus')`. SEO-safe because
  `ContractDetail::getCanonicalUrlProperty()` is always the clean param-free URL
  and prepared-cache bypasses on any query string, so `?kulutus=` variants are
  non-indexable. Tests: `ContractDetailPageTest::test_kulutus_*`.
- **Compact layout (vertical space).** Goal: contracts sit near the top on every
  comparison page. `resources/views/partials/contract-consumption-selector.blade.php`
  is the one source for the selector markup in `ContractsList`, `SeoContractsList`,
  and `CheapestContracts`; each listing view includes it instead of copying the UI.
  The hero is slimmed; the consumption selector is one compact segmented rail of
  presets (label + description + kWh, so they keep their meaning) plus a free-text
  "Tiedän kulutukseni" segment. The full calculator is behind a header toggle
  ("Arvioi kulutus laskurilla", desktop) or an in-panel toggle (mobile), rather
  than an always-visible tab. The bill entry uses the factual label "Vertaa
  nykyistä sähkölaskuasi" and the **filters** (`partials/contract-filters.blade.php`)
  are collapsed Alpine disclosures (`x-collapse` + `x-cloak`). Filters collapse on
  **all** sizes; the "Rajaa hakua" trigger shows an active-filter count and opens
  by default only for filters inside that disclosure. Pricing behavior stays
  visible as the primary path.
  **The control stack has one fixed order** (2026-08 cleanup): the primary
  choices first (consumption rail, pricing-behavior rail), then the collapsed
  tools cluster (availability disclosure, bill disclosure, filters accordion,
  8px apart so they read as one group). Do not put the bill disclosure back
  between the consumption selector and the pills; it split the primary sequence.
  The consumption and pills labels share one style (`text-sm font-semibold
  text-slate-600`, rendered as `<p>`, not headings), and all three disclosure
  triggers share one anatomy (slate-500 icon, 14px bold slate-900 title,
  slate-500 chevron).
  **The postcode form is collapsed on purpose** (explicit user decision,
  2026-08): most visitors never enter a postcode and the always-open form drew
  the most attention in the stack. The disclosure trigger itself states the
  current availability ("Saatavuus: koko Suomi" or the selected postcode, plus
  the helper sentence), so the fail-closed national-only scope stays glanceable
  without opening anything; the input, suggestions, and "Poista postinumero"
  live inside the panel. The "Postinumero (5 numeroa)" label is `sr-only`
  because the trigger sentence and placeholder already name the field. "Tyhjennä suodattimet" lives **inside** the
  filters panel (outside it, the link floated inside the collapsed box whenever a
  pill or postcode selection made `hasActiveFilters()` true). The
  results caption is a plain divider, not another bordered card. The "Vertailu
  kulutuksella" pill is `lg:hidden` in presets mode (the cards confirm the value
  on desktop) but shows on mobile (cards collapse behind "Vaihda") and in
  calculator mode.
- **Direct consumption input.** `directConsumption` (int|string|null, tolerant of
  mobile blank states) is the free-text kWh field. `updatedDirectConsumption()`
  applies only a positive value to `$consumption` and clears `$selectedPreset`
  (blank/zero is ignored so a cleared field never zeroes consumption). It mirrors
  `$consumption` for display: seeded in `booted()` and kept in sync by
  `selectPreset()` / `calculateFromInlineCalculator()`. On initial mount,
  `syncExplicitConsumptionSelection()` reconciles an explicit `?consumption=`
  value after URL hydration: an exact value selects its current preset, while a
  custom value clears the preset and fills the direct input. SEO housing,
  consumption-level, and business defaults still apply only when that query
  parameter is absent. User-initiated
  consumption changes dispatch the Plausible `Contracts Consumption Changed`
  event through `trackConsumptionChanged()` with the raw `consumption` prop and a
  `method` (`preset`, `direct`, or `calculator`); only fire it when the numeric
  consumption value actually changes. Test:
  `ContractsListPageTest::test_direct_consumption_input_updates_consumption`.
- Tests: `tests/Feature/SahkosopimusBillModeTest.php`.

## `HeatPumpCalculator`

Primary files:
- `HeatPumpCalculator.php`
- `../../resources/views/livewire/heat-pump-calculator.blade.php`
- `../../resources/views/livewire/partials/heat-pump-alternative-card.blade.php` (one result card)
- `../../resources/views/livewire/partials/heat-pump-payback-chart.blade.php` (collapsible cumulative-cost chart)
- `../Services/HeatPumpComparisonService.php`

Important semantics:
- `HeatPumpCalculator::PRIMARY_SYSTEMS` (`ground_source_hp`, `air_to_water_hp`, `pellets`) is the single source of truth for which options replace the current heating fully/almost fully.
- `alternatives()` filters the service output down to `PRIMARY_SYSTEMS`. Supplementary options (air-to-air, exhaust-air, the "+ tulisija" combos) are **not shown** because `HeatPumpComparisonService` costs their uncovered load as cheap direct electricity (`directElectricity = totalEnergyNeed * (1 - coverage)`) regardless of the household's actual heating fuel, which understates their real cost and produced unrealistic paybacks. Do not re-add them to the page until the service models the remaining load against the current primary heating.
- `recommendedAlternative()` leads the page with the cheapest-annualized-total primary system that actually saves money. Returns `null` when no primary pays off; the view then shows an honest "täysi lämmitysvaihto ei tuota säästöä" panel instead of inventing an answer.
- The recommended option gets the page's single dark `slate-950` focus moment with the savings as the one coral number; the current system is a quiet light baseline. Mirrors the `SolarCalculator` result treatment. Do not turn the energy-need summary back into a three-up hero-metric grid, and keep coral to the recommendation/CTA only.
- The answer card states the selection rule in plain Finnish (cheapest total cost = running costs + investment annuity, among saving full-replacement options) so the recommendation logic is transparent to the user.
- Savings/“lisäkustannus” deltas are neutral tabular slate, not green/red. Green/red is reserved for the CO₂ delta only (measured-emissions semantic, see `../../DESIGN.md`). Payback chart draws the baseline in slate-400 and the evaluated option in coral; do not use green for the alternative line.
- Recalculation feedback is a non-blocking bottom-right status pill plus a dim of the results region (`wire:loading.delay`). Do not reintroduce a `fixed inset-0` full-screen overlay. The old debounced inputs made it flash while a visitor typed; all editable numeric inputs now recalculate only on blur under the permanent form rule above.
- All seven investment costs (including `ilp_fireplace`, `exhaust_air_hp_fireplace`) are editable in advanced settings so the editable set matches what the service actually computes.
- Numeric inputs are intentionally `int|float|string|null` tolerant because Livewire/mobile browsers can send empty strings while fields are cleared. Keep `normalizeNumericInputs()` as the gate before validation/DTO construction so blank numeric fields normalize to safe defaults or nullable bill-input validation errors instead of typed-property hydration exceptions. Room height, people, prices, investments, interest, and period are clamped to their non-negative/positive HTML minima before DTO construction and show the shared visible correction notice. Active bill quantities and living area keep their more specific validation errors.
- The page is SEO-targeted at the query "kannattaako lämpöpumppu" (sub-queries "kannattaako maalämpö", "kannattaako ilma-vesilämpöpumppu"): question-first title + H1, and an H2/H3 content section using those exact questions. Keep the calculator intent; do not turn it into hype.
- `getFaqItemsProperty()` is the single source of truth for the FAQ; it drives both the visible `<details>` loop and `buildFaqJsonLd()` (FAQPage). Do not hand-write a separate FAQ `<script>` again, that previously drifted from the visible list.
- Both schemas render in the **view** via `<x-schema-markup :schemas="[$jsonLd, $faqJsonLd]" />` (WebApplication + FAQPage). The shared `layouts.app` does NOT output a passed `$jsonLd`, so schemas must be passed to `view(...)` and rendered by the component, not via the layout array.

## `FixedContractPriceForecast`

Primary files:
- `FixedContractPriceForecast.php`
- `../../resources/views/livewire/fixed-contract-price-forecast.blade.php`
- `../Services/PriceForecasting/AGENTS.md`

Purpose:
- renders `/sahkosopimus/sahkon-hintaennuste`
- presents the current 6/12/24-month fixed-term forecast and the offered-price history

Important semantics:
- the page reads current forecasts only through `FixedContractPriceForecast::eligibleForPublicDisplay()`: configured model version plus canonical current-retail provenance in canonical mode, or observed current-retail provenance in feature-off mode
- old-model, missing-provenance, and wrong-basis rows are not current forecasts; when no eligible rows exist, render the existing unavailable state
- the "Mediaanihinta viime kuukausina" history is not forecast-run history. It reads the complete non-null fixed-term median `energy_price` timeline from `contract_price_daily_statistics` with null consumption: older `observed_seller_data` evidence and canonical daily calculations after rollout. If both bases exist for one date, canonical wins, so each duration has one point per day. Model version and futures availability must not truncate it
- page copy separates the current retail input, historical observed seller evidence, and EEX futures input without exposing metadata internals or claiming certainty
- comparison-page forecast teasers use the same model scope through `ContractMarketInsightService`

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
- the warmer must batch source reads used across many segment/date loops: daily spot-market averages are loaded once and sliced in memory for rolling 12-month spot summaries, and latest per-segment statistic rows come from the already-loaded `dailyStats` collection rather than one query per segment. The loaded rows are partitioned once by segment + metric + consumption, and repeated period series are memoized; do not restore full-collection `where()` chains inside every chart/table loop. That CPU pattern exhausted the warmer's 300-second production timeout three times on 2026-08-08
- cache invalidation is automatic through cheap `contract_price_daily_statistics` / `contract_price_snapshots` / spot-price max-date/update fingerprints, so daily imports/backfills should not need manual page-cache clearing
- run `contracts:backfill-price-statistics` before expecting historical data
- spot metrics are split between `spot_margin` and `spot_total_energy_price`
- forward rows store `pricing_basis=canonical_calculation`; historical backfill and feature-off rows store `observed_seller_data`. Current unit panels and the endpoint date require request-scoped `PricingMode::expectedContractPriceBasis()`. Active annual rows on that unit-owned endpoint must use the expected basis or `mixed_evidence`; a stale canonical AsOf row cannot survive a feature-off recalculation into public output. Historical rows stay method-filtered and date-scoped. The CSV exports all method/provenance fields and marks the active annual version. Prepared view-data cache schema is v16; its key and source fingerprint include the active annual method
- a package can contribute an annual total and package fee, but its excess-use rate is not an all-in energy price and stays out of unit-price panels
- `ContractPriceStatistics::$segments` is `ContractStatisticsSegmentClassifier::SEGMENT_LABELS`. Canonical monthly/quarterly/seasonal/other resets share `market_reset` / `Jaksoittain vaihtuva hinta`; the generic reset deep dive uses plain cadence-neutral copy. Persisted observed `quarterly` keeps its historical label and CSV key. The broader canonical segment began on 2026-08-02 and must have at least 30 non-null daily observations, including the current public endpoint, before it appears in the unit table, annual table, or deep dive. Do not join the narrower historical `quarterly` rows onto it
- the “Hinnat sopimustyypeittäin” spot row must display a trailing-12-month realized spot daily average + latest typical margin, not the latest daily spot price; show p20–p80 daily-price variation under the value without adding a column
- the “Hinnat sopimustyypeittäin” sparkline must track the displayed median energy-price basis; the annual-cost sparkline belongs in the “Hintahaarukka” table below
- deep-dive spot c/kWh charts and top editorial spot callouts must use the same trailing-12-month spot average + typical margin as the upper spot row, with p20–p80 daily-price variation as the shaded band; do not show latest-day spot there unless explicitly adding a separate volatility view
- non-spot “vs pörssisähkö” quotable comparisons must use `annual_cost` at the selected consumption so unusually cheap/expensive spot days do not distort contract-type comparisons
- the lead chart caption must be generated from `leadChartPayload` / `annual_cost`, not from c/kWh callouts, so the text always matches the plotted trend. Weekly and monthly payloads append each segment's latest non-null daily median at its exact date after period evaluation and show restrained markers for every point. Daily payloads do not append a duplicate endpoint and show only each series' latest non-null marker through the uPlot point filter. This is required because the first current point after a compatibility gap has no line segment and is otherwise invisible; do not turn daily mode into hundreds of markers. The accessible table calls its first column a date or period start because the final row can be an exact day
- the explanation next to the lead chart is household-facing, not an audit log. It states what the annual estimate represents, then explains three visible features in plain Finnish: a gap means no comparable value or a changed estimate basis; a jump can come from the available contract set changing; and the last point is the newest day. Keep internal names such as `as-of`, canonical, compatibility key, futures curve, and estimator method out of this primary explanation. Detailed methodology can stay in the lower “Mistä luvut tulevat” section
- segment and consumption tables hide rows with fewer than 10 contracts to avoid over-interpreting sparse segment statistics
- the consumption “Hintahaarukka” table intentionally omits absolute cheapest/minimum annual cost values because single-row/import anomalies can make the minimum misleading; use p20/median/p80 for the displayed range

## `SolarCalculator`

Primary files:
- `SolarCalculator.php`
- `../../resources/views/livewire/solar-calculator.blade.php`

Important semantics:
- Address autocomplete is a marked search field and uses live debounce so suggestions appear while the visitor types. System size and electricity price use `wire:model.blur`. In particular, savings must not recalculate while the visitor is still replacing the electricity-price value. A negative electricity price is corrected to the supported 0 c/kWh minimum with an accessible notice; this calculator intentionally models the household contract price as non-negative even though wholesale Spot hours can be negative.
- `systemKwp` is intentionally `float|string|null` tolerant because Livewire/mobile browsers can send an empty string when the visitor leaves a cleared number input. `updatedSystemKwp($value)` must normalize from the hook argument instead of reading the public property before normalization; otherwise Livewire can unset a non-nullable typed property and trigger `PropertyNotFoundException`.
- Use `normalizedSystemKwp()` for PVGIS requests, static example scaling, analytics payloads, and the shared result heading so stale or blank snapshots are clamped to the supported 0.5–20 kWp range before calculation. Both the live result and Helsinki example receive `effectiveSystemKwp`; do not render the raw input or a fixed `5 kWp` label beside a recalculated result.

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
- hourly day strips use one shared signed domain that includes all displayed values, zero, and the 30-day average; positive bars extend above zero, negative bars below zero, and exact zero has no false minimum-height bar
- expanded 15-minute rows use the same signed/diverging geometry horizontally; server-precomputed `bar_left_percent`, `bar_width_percent`, and `zero_percent` keep the Alpine view presentation-only
- minimum visible sizing applies only to non-zero bars and must preserve direction; all-negative and all-zero datasets must remain legible

## `ArticleSpotElectricity`

- The `Markkinatilanne nyt` snapshot uses only `annual_cost` rows on the latest date for `PricingMode::expectedContractPriceBasis()`. Canonical mode cannot fall back to a newer observed date; feature-off uses observed rows.
- Page order is hero, breadcrumb, current market snapshot when available, short answer, contents list, and article sections. Evidence comes before in-page navigation.
- The short answer compares the current market median annual costs of Spot and fixed 12-month contracts at 5,000 kWh. Its conclusion follows the current snapshot. It names Spot or fixed 12-month contracts only when that median is lower. It gives a neutral result when the medians are equal or unavailable.
- Public method copy uses plain Finnish and does not expose internal canonical terms. The snapshot date is the market-data date. The bottom byline labels 29.5.2026 as the editorial review date.
- An individual contract can differ from the median for its contract type. The median result does not decide each contract pair.
- The article does not embed `ContractTypeComparison`. An individual-contract calculator can give a different result from the market median and make the editorial answer unclear. The summary links to the normal contract comparison instead.
- Its six-hour cache key includes canonical state, expected basis, latest relevant date, and maximum `updated_at`, so flag changes and same-day rewrites create a new payload.
- The two contract-statistics article series end on the latest date for `PricingMode::expectedContractPriceBasis()`, keep older observed rows as historical evidence, and read only the trailing year. Their cold-cache reads select only date, segment, and the plotted value through the base query builder; they cache small prepared arrays, not unbounded Eloquent collections. The volatility widget streams its already date-bounded hourly rows with the same selective base-query pattern. These limits keep the eager article route below the 128 MB production PHP limit.
- Each of the four evidence charts has a visible data-based takeaway and a native `details` disclosure with a semantic table of all plotted values. The chart refers to the takeaway with `aria-describedby`. Null values stay as a dash. Build these views only from the existing prepared payloads.

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
- annual article and homepage series use the shared `AnnualSeriesCompatibility` guard and its AsOf aggregate display key. Valid `basis_counts.estimate_method` maps group by the dominant method, so minority contributors do not split a weekly market median. Mixed dominant-method weeks and the first week after a dominant transition are null, including a transition on a Monday boundary. Their bounded queries select `method_version` and `basis_counts`; cache schemas are article v5 and homepage v8
- the embed shows at most the trailing 12 months and caches only its weekly prepared payload; never cache or group an unbounded all-column Eloquent statistics collection here
- `ArticleSpotWinRateChart` filters Spot and each comparison segment to that segment's newest compatibility regime before counting overlap days. It never pools rolling and forward Spot annual pricing across the 2026-05-01 cutover, and the first daily point after a transition stays a gap
- do not Livewire-lazy-load the article chart widgets unless their pushed scripts/chart initializers are moved to a non-lazy parent bundle; otherwise the widget markup can hydrate without the chart drawing

## `CompanyDetail`

Primary files:
- `CompanyDetail.php`
- `../../resources/views/livewire/company-detail.blade.php`
- `../../resources/views/partials/company-market-comparison.blade.php`
- `../Services/CompanyStatistics/AGENTS.md`

Query guardrails:
- `contracts` is one memoized collection of all active company contracts. Household (`Household`, `Both`, or legacy null) and business (`Company` or `Both`) lists filter this collection in memory. Do not run a second contract query for the business section.
- Direct canonical and legacy evaluations are adapted into `ContractPricingViewData` before sorting, statistics, offer membership, or other decisions. Both canonical and feature-off promotion branches read the request-local typed map, including measured legacy savings. Only the existing `calculated_cost` Eloquent presentation attribute is serialized for Blade transport. Listed statistics use finite typed totals; exclusions stay null and sort last.
- `companyStats` uses only the household list and is memoized per render because layout title/meta, JSON-LD, H1/hero text, and the visible list reuse it.
- Keep company contract queries eager-loading `company` and `electricitySource`. Load `priceComponents` only in the explicit feature-off branch; canonical calculations, offers, cards, and current company facts must not require or query relational prices.
- `updatedAt` uses aggregate maximum dates only. Do not load source snapshot JSON.
- Clear the memoized contract/stat caches whenever the selected consumption changes. `marketComparison` is memoized with a separate `marketComparisonResolved` flag, because null is a valid result and must not be recomputed every render.

### Company page sections (2026-07)

The page is household-first. The hero, summary, offers, Spot facts, and main
card list use active `Household`, `Both`, and legacy null-target contracts. A
`Company` contract cannot affect those facts. The business section at the bottom
uses active `Company` and `Both` contracts, so `Both` appears in both lists. Both
card lists keep the shared calculated outcomes and order from the one
all-contract collection.

Company pages have no delivery-area section. Do not add DSO or postcode queries
to this page. The company address is labelled only as the address reported by
the company. Organization JSON-LD includes Finland in `areaServed` only when at
least one active contract has `availability_is_national=true`.

Search Console showed three clusters on these pages that the page answered with
nothing but a list of cards: `[yhtiö] tarjoukset`, `[yhtiö] hinta` /
`sähkön hinta`, and `[yhtiö] pörssisähkö`. The sections below use household
contracts or precomputed household statistics.

1. **`#tarjoukset` — "{yhtiö} tarjoukset".** In canonical mode,
   `getPromotionContractsProperty()` uses only the `calculated_cost` outcome
   already attached by `getContractsProperty()`. Membership requires a listed
   canonical outcome with `includes_discounts=true` and a positive measured
   benefit. It never reads `priceComponents`, relational discount flags, or
   relational discount formatters. Company contract queries therefore omit the
   full component-history relation in canonical mode; feature-off still eager-loads
   it and keeps the legacy behavior.
   **The heading always renders.** Only 13 of 35 sellers had a live promotion on
   2026-07-24. The empty state says that no campaign-price contract is in the
   comparison and that Voltikka updates contract data each day. Do not hide the
   section when the list is empty.

   `CanonicalOfferFacts` receives the contract's `ContractPricingViewData` and supplies the specific typed term and measured saving.
   The calculator's `offer_terms` records supported changed component types,
   actual/normal amounts, and exact resolved duration/date. It can use either a
   component `normal_amount` or an exact introductory-to-normal phase comparison;
   recurring market resets are excluded from the latter so market movement is
   not called an offer. Held-forward Hybrids keep the real known offer span and
   still exclude the consumption effect. Controlled Finnish copy states facts
   such as `Perusmaksu 0 €/kk ensimmäisen kuukauden`; raw phase labels, seller
   text, and interpretation summaries are never rendered. Ordinary
   outcomes state the saving over the 12-month comparison period. A short fixed
   term states `contract_term.discount_savings_total` over the real term and
   labels its month count; the annualized top-level saving is never described as
   received. A package, excluded outcome, missing/zero benefit, or unsupported or
   absent typed term is not an offer.
   Feature-off keeps `hasActiveDiscounts()` and
   `formatActiveDiscountValue()` in its separate legacy branch, including the
   dash for a relational promotion whose old calculator cannot measure a saving.
2. **`#hintavertailu` — "{yhtiö}: hinnat markkinaan verrattuna."** Range rows per
   contract-type segment plus a trailing-12-month trend chart, from
   `../Services/CompanyStatistics/CompanyMarketComparisonService`. **Read that
   `AGENTS.md` before changing the metric, the segment floor, or the geometry.**
   Current canonical rows always win when an internally consistent market+company
   date exists. The service follows the configured annual-cost method: legacy
   comparisons read seller snapshot annual columns, while AsOf comparisons read
   versioned seller totals and active-method market rows. Otherwise, canonical mode
   may render the latest internally consistent `observed_seller_data` date only as
   a payload-marked historical fallback with explicit dated copy. It never calls
   that fallback today's price.
   **The heading always renders.** When neither source has a usable same-date pair,
   the page states that comparable data is not available and points to the current
   contract prices. It does not make a market claim.
3. **`#porssisahko` — "{yhtiö} pörssisähkö".** The seller's `Spot` contracts state
   the count and show the two supplier-controlled charges: margin in c/kWh and
   monthly base fee. Nord Pool's market price is common to Spot products and is
   never presented as seller competitiveness. When the company comparison has a
   non-historical current payload, each available charge is compared with the
   `spot_benchmarks` market median from the same date and pricing basis. Missing
   facts or unusable benchmark rows produce no comparison claim, and an observed
   historical fallback never supplies a benchmark beside current contract facts.
   **The heading always renders.** When the seller has no household Spot contract,
   the page states this and links to the market-wide Spot listing.

Company pages deliberately have no visible FAQ and no FAQPage schema. Keep the
schema aligned with visible content if this decision changes later.

The title uses a colon before the search phrase because Finnish inflection of an
arbitrary company name is unsafe. The HTML title is `{company}: sähkön hinta
verrattuna markkinaan | Voltikka`. The H1 is different on purpose:
`{company}: sähkön hinta ja sähkösopimukset`. Do not add the old price rank again.
The hero uses complete sentences for the household contract count, lowest annual
price at the selected consumption, and Spot count. Its broad-intent sentence names
contracts, prices, offers, market comparison, and seller-specific Spot charges.
Zero-contract copy does not imply that a current price is available.

`Päivitetty` compares two active-contract groups: maximum pointed-episode
`last_observed_at` for episode-backed contracts, and maximum relational
`price_components.price_date` only for legacy contracts with a null observation
pointer. It returns the later value, so a mixed company does not hide a newer
legacy date. Snapshot aggregates and relational dates from episode-backed
contracts do not select it. It is hidden when neither stored date exists. The same
date supplies WebPage `dateModified`; request time is never a fallback.

The annual-consumption control uses the same compact segmented preset rail as
the main comparison page. `Vuosikulutus` is a control label, not a content
heading. It includes the tolerant `directConsumption` input.
A preset clears that input; a positive direct value clears the preset and
recalculates both audience lists. The calculator action links to the standalone
`/sahkosopimus/laskuri` because CompanyDetail does not host the inline listing
calculator.

The offers and spot sections render as compact tables inside `overflow-x-auto`
wrappers rather than as contract cards, because Vaasan Sähkö has a promotion on
all 22 of its contracts and a card list would duplicate the whole page above the
card list.

## `CompanyList`

Primary files:
- `CompanyList.php`
- `../../resources/views/livewire/company-list.blade.php`
- `../Services/CompanyListCacheService.php`

Route: `/sahkosopimus/sahkoyhtiot` (`companies.list`).

Pricing and cache rules:
- In canonical mode, `CompanyListCacheService` derives every company price, price average, contract count, and price ranking only from listed canonical metrics already produced by `ContractListCacheService`. A missing, excluded, non-canonical, or non-numeric total does not count and cannot become EUR 0 or a sentinel rank. A canonical-only contract is valid.
- The feature-off branch keeps the legacy relational metrics. The component never calculates a contract price itself.
- A company without a price at the selected consumption is not in the cheapest ranking, and its card states that the price is unavailable instead of formatting null as zero.
- The 48-hour company cache key includes its own payload schema marker, the shared `ContractListCacheService` data version, and canonical/reset-shift markers. Interpretation publication bumps the shared version, so company output cannot remain stale for 48 hours. Bump `CompanyListCacheService::PAYLOAD_SCHEMA_VERSION` after a code-only change to company metric membership or payload fields.

### SEO metadata decisions (2026-07)

Google Search Console showed roughly 1 100 impressions and about 22 clicks. The queries fall into
clusters, and the decisions below follow from which cluster the page can actually serve:

- **List intent** (`sähköyhtiöt` 214 impressions at position 14.3, `sähköyhtiöt suomessa`,
  `suomalaiset sähköyhtiöt`, `kaikki sähköyhtiöt`, `energiayhtiöt suomessa`, `sähkönmyyjät`) — this
  is the page's cluster. The head term sits on page 2, which is a position problem, not a title
  problem.
- **Small-company intent** (`pienet sähköyhtiöt`, 58 impressions at position 9.9) had the page's
  **best CTR at 6.9 %** with no content behind it. It now has a section and an FAQ answer.
- **Quality intent** (`parhaat sähköyhtiöt` at position 7.6, `luotettavat sähköyhtiöt`,
  `sähkö parhaat arviot`) and **review intent** (`... kokemuksia`) are **deliberately not served.**
  Voltikka holds no customer-satisfaction or review data. Do not add a "paras/luotettavin
  sähköyhtiö" answer, ranking, or title claim until a real data source exists behind it;
  `test_faq_makes_no_unsupported_quality_claims` guards this.

**The differentiator is completeness of the live market, not roster length.** Competing pages
publish longer *name* lists (Sähkövertailu.fi claims 55, Kilpailuta-sähkö.fi claims 71) that include
sellers no longer trading and quote no price at all; most comparison sites list only their affiliate
partners. Voltikka lists every contract on sale, so the title leads with the **contract** count next
to the company count. Do not rewrite the title to compete on company count alone — we would lose
that comparison while giving up the stronger claim.

Consequences to preserve:
- **Keep the year dynamic** (`now()->year`), never a literal. Every competing title in this SERP
  carries a year.
- **No `| Voltikka` suffix.** Google prints the site name beside the title and truncated the old
  77-character title. This component used to append the suffix itself in `render()`.
- **`pageTitle` and `pageHeading` are separate** so the H1 can stay natural language while the title
  is tuned. The Blade H1 reads `$pageHeading`.
- The public copy claims Voltikka does not restrict the comparison to partner companies and lists
  all contracts on the market. **That claim must stay true**; it depends on the upstream
  postcode-driven import staying market-wide.
- `energiayhtiö` and `sähkönmyyjä` appear in the intro and FAQ on purpose. Those synonym clusters
  are about 170 impressions and neither word was previously on the page.
- The FAQ feeds both the visible block and the `FAQPage` entry in the JSON-LD `@graph`. The JSON-LD
  moved from a bare `ItemList` to an `@graph`; keep `ItemList` in it.

### Company size classification

`COMPANY_SIZE_GROUPS` in `CompanyList.php` is a **static editorial map** of company name to
`national` / `regional` / `local` / `challenger`. It is static because size cannot be derived from
our data: `companies` holds no customer count or turnover, `contractCount` measures product breadth
rather than size, and `postal_name` is unreliable (Fortum stores `FORTUM`, Lankosken Sähkö stores
its own company name). **Do not build a city or size section off `postal_name`.**

The judgement is static but the rendering is dynamic: `smallCompanies` intersects the map with the
live company list, so a company that leaves the market disappears from the section by itself, and a
new company that is not in the map is simply left out rather than guessed into a group. Add new
sellers to the map when they appear.

## `ConsumptionCalculator`

Primary files:
- `ConsumptionCalculator.php`
- `../../resources/views/livewire/consumption-calculator.blade.php`

Important semantics:
- calculator inputs are deliberately nullable/string-tolerant because Livewire can send blank strings/nulls when users clear number/select fields before tabbing away.
- `calculate()` must read public inputs through safe helper methods and use enum `tryFrom()` fallbacks so blank/stale browser state does not become `PropertyNotFoundException` or enum `ValueError`.
- blank/too-small numeric inputs are normalized back onto the component so the UI displays minimum allowed values: 20 m² living area, 1 resident, and 0 for optional numeric extras. A numeric value below its minimum also writes a field-specific accessible notice to `numericNotices`; do not return to silent correction.
- fallback select defaults are apartment, electric heating, central region, and 2000-era energy rating.
- the page also renders a `sähkön hinta laskuri` section when `contract_price_daily_statistics` data exists. Every contract type uses stored `annual_cost` p20/median/p80 rows with the existing interpolation/nearest-reference behavior. The current date and rows use `PricingMode::expectedContractPriceBasis()`: canonical mode requires `canonical_calculation`, and feature-off requires `observed_seller_data`, with no newer wrong-basis fallback. It never rebuilds a public annual total from unit price + monthly fee. If annual rows are missing, that type is unavailable; this keeps canonical-only and package totals while preventing relational fallback.
- `priceEstimatesFor(int $consumption)` holds that estimate logic; `contractTypePriceEstimates` is just the visitor's own consumption. `priceStatisticsRows()` memoizes `[statDate, groupedRows]` for the request, so the FAQ can price extra fixed levels at **no extra query** (measured: 2 statistics queries per render, unchanged). Keep that memo `protected` — as a public property the grouped Eloquent collection would be dehydrated into the Livewire snapshot for nothing.
- `priceSegments()` is the single source of truth for which contract types are quoted; it includes current `market_reset` statistics as `Jaksoittain vaihtuva hinta`. `priceStatisticsRows()` derives its `segment_key` filter from `array_keys()` of it. Do not add a parallel key constant — a segment present in the config but missing from the query is silently dropped from the table instead of failing.

### SEO metadata decisions (2026-07)

Search Console showed the page split across two query clusters: consumption
(`sähkönkulutus laskuri`, position 9–10, **0 clicks**) and price (`sähkön hinta laskuri`,
`laske sähkön hinta`, `kwh hinta laskuri`, position 5.5–7.2, ~2.6 % CTR). Impressions were
roughly 50/50, but impressions are demand × visibility, so an even split earned with a
3-position handicap means consumption demand is the **larger** of the two.

The page is nonetheless tuned for the **price** cluster, and the reason is the competitive
field, not the CTR arithmetic:
- `sähkönkulutus laskuri` is held by Vattenfall, Fortum, Caruna, Helen and Turku Energia —
  utility brands that a title edit will not out-rank.
- `sähkön hinta laskuri` is held by thin single-purpose calculators (sahkosnap.fi,
  laskurix.fi, 1plus1.fi, sahko24.fi, alv-laskuri-online.fi). That SERP is winnable.

Consequences to preserve:
- `generateSeoTitle()` leads with `Sähkön hinta laskuri` and carries `now()->year`. **Keep the
  year dynamic**; a hardcoded year silently rots. The `| Voltikka` suffix was dropped on
  purpose — Google prints the site name separately and was already truncating it.
- **`getFaqItemsProperty()` is a CTR surface, not only a schema surface.** For
  `sähkön hinta laskuri` Google ignores the meta description and quotes the
  `Miten sähkön hinta lasketaan vuosikulutuksesta?` answer instead. That answer must keep the
  formula (the match that earns the ranking) *and* a reason to click, because a snippet that
  only prints the formula answers the searcher inside the SERP. Do not shorten it back to the
  bare formula.
- The `Paljonko N kWh sähköä maksaa vuodessa?` answers are generated by
  `consumptionCostFaqAnswer()` from current statistics, never hardcoded cents. They **must**
  keep saying the figure excludes siirto: competing PAA answers for the same question are
  transfer-inclusive, so an unqualified energy-only number reads as simply wrong.
- The calculator cross-links `/sahkosopimus/kulutus/{2000,5000,10000,18000,20000}-kwh`
  (`consumptionPageLinks`, plus `nearestConsumptionPage` beside the result). Those pages
  already earn PAA citations for "Paljonko maksaa 20 000 kWh?"-style queries, so the
  calculator's output should hand off to them. Keep `CONSUMPTION_PAGE_LEVELS` in sync with the
  routes in `routes/web.php`.

## `HomePage`

- The homepage contract trend uses stored `annual_cost` at 5,000 kWh, not `energy_price` / `spot_total_energy_price`. It reads only the configured active annual method. Its current endpoint requires `PricingMode::expectedContractPriceBasis()` and excludes newer opposite-mode rows; older points keep their own stored date basis. Cache key schema is `home-page:contract-price-trend:v7`, with the active annual method, expected basis, latest expected date, and source fingerprint.

## `ContractTypeComparison`

Primary files:
- `ContractTypeComparison.php`
- `../../resources/views/livewire/contract-type-comparison.blade.php`

Purpose:
- interactive editorial comparison widget for pörssisähkö vs fixed price and fixed-term vs open-ended contracts

Important semantics:
- widget actions can be slow because contract candidates are recalculated; keep visible `wire:loading` feedback on mode, consumption, and contract-selector updates
- do not server-render every available contract as `<option>` elements; the editorial article embed must avoid dumping all contract names into the initial DOM for crawler quality and UX
- contract selection is interaction-gated: the default view shows only auto-selected/explicit contracts, and searchable async results render after the user opens a selector and types at least 2 characters; the marked search input uses live debounce so results appear without leaving the field and without one request per keystroke
- default `contract_term` mode compares määräaikainen vs toistaiseksi voimassa oleva for the määräaikainen article
- `comparisonContext="spot_article"` keeps pörssisähkö as the left-side anchor in both tabs: pörssisähkö vs kiinteähintainen and pörssisähkö vs määräaikainen
- in canonical mode, candidate selection, the monthly chart, annual and average-monthly totals, winner/savings, current rates/fees, package facts, offer fact, and estimate labels all read one memoized `CanonicalPricingOutcome` per contract and consumption basis. The chart renders `monthlyCosts` directly; it must not reconstruct monthly prices from unit rates.
- a canonical-only contract is valid. An excluded or incomplete selected contract gets an unavailable state with no zero series and stops the winner/savings result. Do not fill any missing current fact from `priceComponents`.
- package facts are ordinary pricing, not a promotion. `term_price_only` totals must state that the real term was annualized, and `base_only_hybrid` must retain the unknown consumption-effect disclosure.
- canonical candidate queries load `company` only. The explicit feature-off branch still eager-loads `priceComponents` and keeps the legacy monthly calculator and last-year monthly Spot basis without N+1 queries.

## Pricing-type filter (`?hintatyyppi=`)

Primary files:
- `ContractsList.php` (state, toggle action, legacy mapping, parsed bucket selection)
- `SeoContractsList.php` (`mount()` legacy mapping call, route constraints)
- `../Services/ContractCard/Enums/PricingBucket.php` + `../Services/ContractCard/AGENTS.md`
  ("The four filter buckets")
- `../../resources/views/partials/pricing-bucket-pills.blade.php` (the visible pill row)
- `../../tests/Feature/PricingBucketFilterTest.php`

State:
- `ContractsList::$pricingBucketFilter` is a `#[Url(as: 'hintatyyppi')]` **string** holding
  comma-separated `PricingBucket` values (`porssisahko,kiintea`). It is a string, not an
  array, because the value is user-visible in the URL and must stay readable; parse it with
  `selectedPricingBuckets()`, never by hand.
- Parsing is deliberately tolerant, like `$page`: unknown keys are dropped through
  `PricingBucket::tryFrom()`, so `?hintatyyppi=<garbage>` degrades to "no constraint"
  instead of a hydration error. Crawlers request malformed variants of everything.
- **Empty and all-four both mean "no constraint"** (`constrainingPricingBuckets()` returns
  `[]` for both), because the four buckets partition the contract set.
- `togglePricingBucket(string $bucket)` is the UI action: it toggles membership, calls
  `resetPage()`, rewrites the value in canonical enum order, and dispatches the existing
  Plausible `Contracts Filter Applied` event with `filter_type = 'pricing_category'` only
  when a bucket is turned **on**.
- Any non-empty selection — **including all four** — makes `hasActiveFilters()` true, so
  `isDefaultListingCacheable()` refuses to serve the prepared-data cache for it. All four
  lists the same contracts as none, but it is not the canonical default state.
  `resetFilters()` clears it.

Query:
- `ContractListingPipeline::applyInteractiveQueryConstraints()` adds an OR-union of
  `PricingCategoryResolver::scopeBucket()` calls. **Never hand-write this SQL in a
  component** — the shared scope is what keeps the filter, the SEO pages and the card band
  from drifting.
- Both `ContractsList` and `SeoContractsList` pass parsed bucket state to the pipeline beside
  the other interactive query filters, so bill mode (`buildBillModePaginator()`) automatically
  prices exactly the filtered set. `CheapestContracts` inherits it.
- On an SEO page whose route already fixes a pricing type (`/sahkosopimus/porssisahko`) the
  interactive filter composes on top (AND). It can narrow such a page, never widen it.

Legacy `?pricingModelFilter=`:
- `applyLegacyPricingModelFilter()` runs once at mount (from `ContractsList::mount()` and
  `SeoContractsList::mount()`) and maps `Spot → porssisahko`, `FixedPrice → kiintea`,
  `Hybrid → kulutusvaikutus`, then **clears `pricingModelFilter`** so the two filters cannot
  double-apply. It does nothing when `hintatyyppi` is already present.
- `Quarterly` / `TimeOfUse` / `Seasonal` are pseudo-types, not risk-transfer buckets,
  and have no bucket equivalent. Their shared SQL rules live in
  `ContractListingPipeline`; both base and SEO interactive filters call that service.
- One behaviour change is intended: legacy `Hybrid` listed a Hybrid contract that also has a
  quarterly reset; the mapped bucket does not, because market wins over consumption effect
  and that contract's card band says Markkinahinta.

UI (`partials/pricing-bucket-pills.blade.php`):
- The row is **always visible, above the list and outside the "Rajaa hakua" accordion**. That
  placement is the whole feature: ranking makes page 1 spot-heavy, and every filter used to
  sit inside a collapsed accordion, so a visitor who wanted price certainty had no visible
  way out. Do not move it back inside the accordion.
- Included by `contracts-list.blade.php`, `seo-contracts-list.blade.php` **and**
  `cheapest-contracts.blade.php`. The cheapest page has its own template, so it needs its own
  include; `CompanyDetail` deliberately has none.
- **Shape: one segmented rail, not four cards.** A single `rounded-xl` `slate-200` box holds
  four equal cells divided by `slate-200` hairlines, drawn as `gap-px` over a `slate-200`
  background so the same markup gives 2x2 below `sm` and 1x4 above it with no per-cell border
  rules. The first version was four separate bordered cards; stacked above the contract cards
  they read as a second card grid rather than as a control. Do not go back to detached cards.
- A selected cell wears its bucket's card tint (`PricingBucket::category()->tint()`, the sky /
  violet / slate axis in `../../../DESIGN.md`), so the pill, `<x-card.legend />` and the card
  band it lists are one system: tint-100 fill, 1px inset tint-400 ring, tint-900 label,
  tint-700 sub-line. The **ring is load-bearing** — the tints sit about 1.05:1 from white, so
  a fill alone does not read as "on" across a row. Unselected cells carry no tint at all.
- The leading 16px glyph is the category icon at rest (`slate-400`) and swaps to a check when
  selected, in the same slot: a second saturated signal with no width change and no label
  shift. Spot / consumption-effect / fixed reuse the card band's own wave / pulse / lock
  glyphs; Jaksoittain vaihtuva hinta uses a calendar, because the band gives spot and resets the same
  wave and two identical glyphs side by side would say nothing.
- Every Finnish string lives in the partial's `$pricingBucketPills` array. **"Päivittyvä
  hinta" + "kvartaali- ja kuukausisähkö" is a locked user decision**; the other sub-lines are
  short restatements of `ContractCardCopy::band()`. Labels are 15px bold, sub-lines 14px
  `slate-500` — the DESIGN.md floor for secondary copy, not the 12px the first version used.
- **No per-bucket counts.** The listing applies its energy-source and consumption-range
  filters in PHP after the query, so an honest count would need the whole filtered set
  re-resolved per bucket, not one grouped query. Too expensive for a cached default page.
- Dual behaviour: with `ContractsList::$showSeoFilterLinks` (true only on `SahkosopimusIndex`)
  and **no** active filter, the three buckets that own a canonical SEO page render as
  crawlable `<a href>` to `/sahkosopimus/porssisahko`, `/kiintea-hinta` and
  `/kulutusvaikutus`, with `wire:click.prevent` so a real click filters in place. Any active
  filter turns all four back into plain toggles, so filter combinations never become
  crawlable URLs. Jaksoittain vaihtuva hinta owns no page and is a toggle in every state. SEO listing
  pages stay opted out: a pill link from `/sahkosopimus/omakotitalo` would drop the housing
  context that page ranks for.
- Accordion scoping: the accordion's open-default and badge read
  `ContractsList::hasActiveAccordionFilters()` / `activeAccordionFilterCount()`, which count
  only contract type, energy source, postcode and legacy `pricingModelFilter`. A pill
  selection must not open the accordion or inflate its badge. `hasActiveFilters()` still
  counts pills, because it gates "Tyhjennä suodattimet" and `isDefaultListingCacheable()`.
- The accordion's old "Hinnoittelumalli" section was removed with this row. The
  `pricingModelFilter` property and its query logic stay so legacy links keep working; the
  metering pseudo-types (Quarterly / TimeOfUse / Seasonal) remain reachable through their own
  SEO pages and the "Katso myös" links.

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
- cache keys include route/filter context plus `ContractPageCacheVersion::hash()` so contract imports and source-table changes bust stale payloads. The current outer schemas are `contracts-list:view-data:v3` and `seo-contracts-list:view-data:v3`; v3 invalidates prepared membership after national-by-default postcode eligibility changed
- this is prepared-data caching, not full HTML caching; Livewire actions still recompute/serve their interactive state normally
- page-level caching is disabled when `app()->runningUnitTests()` to avoid cross-test cache pollution from Laravel's array cache driver
- listing metric rebuilds should use `ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()` so crawler hits do not produce one `price_components` query per contract while still avoiding eager-loading full price history
- contract card Blade partials (`resources/views/components/contract-card.blade.php`, `featured-contract-card.blade.php`) must not lazy-load `company`, `electricitySource`, or `priceComponents`; listing components should batch-load what cards need, and cards should fall back to scalar fields if relations are missing. Both templates now derive everything through `../Services/ContractCard/ContractCardPresenter`, which enforces the no-lazy-load rule in one place. **Do not put price logic, category logic or Finnish copy back into a card template** — the two templates previously held duplicate derivation and drifted, leaving the featured card (the #1 slot everywhere) with no price-increase warning, no market-reset figures and no estimate marker. In canonical mode, `loadVisibleContracts()` does not load latest components and `getLatestPrices()` returns before `loadMissing()`; feature-off keeps the bulk component load. See `../Services/ContractCard/AGENTS.md`.
- the card's type band is single-purpose: it states one of three pricing categories (Kiinteä hinta / Markkinahinta / Kulutusvaikutus) and never a warning. Warnings are coral footer pills, priority ordered and capped at two. Consumption caps only warn at ≤ 30 000 kWh/v unless the selected consumption actually exceeds the cap.
- the percentile callouts were removed from cards (they rendered only on SEO listing pages, half the switch was unreachable, and they could contradict the sort order). `ContractsList::getPercentiles()` and `contracts:calculate-percentiles` are unchanged; the `percentiles` card prop is a retained no-op.
- listing pages carry `<x-card.legend />` explaining the type-band tints; it replaced the emissions colour legend when the card's emissions left stripe was removed.
- **Canonical pricing (behind `CANONICAL_PRICING_ENABLED`):** when on, `ContractListCacheService`/the listing fallback paths attach `comparability` + `pricing_integrity` to each contract (batch, like percentiles), drop non-listed contracts from `sorted_ids`, and rank by the canonical true 12-month total. Cards consume those fields through `ContractCardPresenter`: `Hinta nousee …`, `{N} kk sopimus, jatkohinta ei tiedossa` and `Ei sisällä kulutusvaikutusta` are coral footer pills, and the estimate marker is the band's `Arvio` popover rather than a footer tag. `ContractDetail` exposes `pricingIntegrity`/`pricingComparability`/`isPricingExcluded`: excluded contracts show a "Vuosihintaa ei voi laskea luotettavasti" hero and omit JSON-LD `offers`; detected contracts render the integrity notice at the top of `Hintatiedot`, in coral (it was amber until Phase 4; amber is an emissions tier). The SEO offer page filters after canonical metrics are attached, so canonical-only offers enter while relational-only, package, zero-benefit, and excluded rows do not. Its Product descriptions use `CanonicalOfferFacts`, not relational labels. Feature-off keeps the old relational candidate query and JSON-LD text. All flag-driven caches carry a `c1`/`c0` marker (incl. `ContractPageCacheVersion`) so a toggle busts them. See `../Services/CanonicalPricing/AGENTS.md`.
- city-page solar potential must stay in the lazy `CitySolarEstimate` child component; `SeoContractsList` must not call `CitySolarService`/PVGIS while building initial page HTML because a cache miss can add blocking time
- `CitySolarEstimate` must not make uncached PVGIS requests for crawler user agents (Googlebot, generic bots/spiders); bot-triggered Livewire lazy updates should render cached data only or nothing, because PVGIS can hang long enough to hit PHP's request timeout
- `SeoContractsList` validates city slugs against `municipalities` during mount and returns 404 for unknown `/sahkosopimus/paikkakunnat/{location}` slugs so SEO pricing/duration slugs cannot become fake location pages. It still memoizes the municipality lookup because city metadata is read by contracts filtering, title/meta generation, headings, JSON-LD, and local-contract sections during one render; do not revert to direct `Municipality::where('slug', ...)` calls from those accessors
- `ContractsList::$page` is URL-bound and intentionally typed `int|string`; `normalizePageProperty()` coerces empty, malformed, or negative query values to page 1 before render/SEO pagination. Keep this tolerant because bots and browsers can request `?page=` before Livewire mount, and a strict `int` property causes typed-property hydration errors. The listing paginators are built manually and render normal anchor links, so each constructor must pass `paginationQueryParameters()` to keep non-default `consumption` and comma-separated `hintatyyppi` state while only `page` changes; default values stay absent from canonical pagination links.
- `ContractsList::calculateFromInlineCalculator()` reads calculator fields through safe typed helper methods. Keep this tolerant of blank mobile number inputs and stale/partially hydrated Livewire snapshots from SEO pages so user edits do not turn into `PropertyNotFoundException` / enum errors.
- `CheapestContracts` calls `SeoContractsList::getContractsProperty()` through inheritance. Read consumption with `ContractsList::selectedConsumptionValue()` in inherited listing paths and cheapest-page render data so stale Livewire snapshots that miss the URL-bound `consumption` property fall back to 5 000 kWh instead of throwing `PropertyNotFoundException`.
- Contract comparison hero market-insight pills are intentionally small and must not push results down. They use cached precomputed statistics/forecast payloads from `ContractMarketInsightService`; do not calculate contract prices or scan raw `price_components` for these pills during page requests. Their latest point uses the basis expected by the canonical flag. In canonical mode the 30-day comparison can use an older dated observed point, and its visible supporting copy identifies that provenance. The new canonical-only `market_reset` segment is the exception: it waits for an older canonical point instead of treating historical `quarterly`/`open_ended` rows as the same segment. Cache keys and fingerprints vary by flag and basis.
- Market insights show on `/sahkosopimus`, SEO pricing/duration pages, and cheapest contracts. They are hidden on business, housing-type, energy-source, and consumption-level SEO pages. The cheapest page uses the same aggregate trend as the main page.
- Default listing prepared-data cache writes are protected by a short cache lock to prevent crawler/user stampedes after daily import invalidation. If lock acquisition times out, render uncached instead of waiting toward PHP's 30-second request limit.
- City SEO listing pages (`/sahkosopimus/paikkakunnat/{location}`) intentionally skip prepared view-data caching because there are many long-tail city URLs and their local/regional sections make serialized database-cache payloads large. They still use shared contract metric caches. Canonical local/list card loading does not load `priceComponents`; feature-off attaches only the latest calculation components, never full history.

## `ContractDetail`

Primary files:
- `ContractDetail.php`
- `../../resources/views/livewire/contract-detail.blade.php`
- `../Models/ElectricityContract.php`
- `../Services/ContractDetail/AGENTS.md`
- `../Services/Caching/ContractPageCacheVersion.php`
- `../../../../tasks/contract-detail-overhaul/` (spec, decisions, approved mockups)

SEO responsibility boundary:
- `ContractDetailSeoPresenter` owns page/OG titles, meta description, and WebPage, Product, BreadcrumbList, and FAQPage JSON-LD policy.
- The component keeps thin computed compatibility adapters and supplies one immutable input of already-derived facts. It still owns queries, ranking, calculations, visible FAQ generation, and interactive state.
- Product offers receive the same canonical-only current display values as the receipt. Missing values stay absent, excluded contracts emit no offers, and structured brand logos stay local-only.
- This extraction does not change the prepared detail cache payload, so its schema stays v18.

Pricing cache boundary:
- `pricingViewDataFor()` is the one request-local pricing accessor for each consumption. It returns a cached metric's `ContractPricingViewData` directly, or adapts the canonical or legacy calculator result once, and memoizes the typed object by consumption.
- Generated qualifier, receipt-note, term, FAQ, current-display, package, cost-table, and counterfactual policy reads typed pricing accessors and `PricingFact`. Only `getCalculatedCostProperty()` / `calculatedCostFor()` serialize the unchanged compatibility array. The card, SEO presenter input, price-development input, and prepared payload keep that existing transport shape; detail cache schema stays v18.
- Integrity and comparability stay typed in the cache path. An excluded metric stays available to the detail page but is absent from ranking. A missing listed total fails cache hydration and cannot become a zero-price cheaper alternative.

### Page composition (the approved editorial structure)

The page is **one editorial column on a white surface**. A section is an `h2`, a hairline
rule and whitespace; there are **no nested cards**, and the only card chrome on the page is
on the three alternative-contract tiles. It replaced a two-column grid of white rounded
panels, which the design review rejected as "cards within cards everywhere" and which forced
the alternatives above the content that justifies them.

Section order, and it is load bearing:

1. **Dark `slate-950` hero** at content width (`max-w-3xl`).
2. **Vertaa nykyiseen sähkölaskuusi** (`#vertaa-laskuun`), **open by default**.
3. **Hintatiedot** (`#hintatiedot`): category band, integrity notice, receipt rows, receipt
   notes, warning pills, static cost table, spot counterfactual.
4. **Näin hinta on kehittynyt** (`#hintakehitys`)
5. **Kannattaako X?** (`#kannattaako`)
6. **Sähkön alkuperä ja päästöt** (`#ymparisto`)
7. **Sopimusehdot lyhyesti** (`#sopimusehdot`): terms grid, pientuotanto, seller identity,
   internal comparison links, and the seller's own description **collapsed** inside it.
8. **Usein kysyttyä** (`#usein-kysyttya`)
9. **Halvemmat vaihtoehdot** (`#halvemmat`) — the only cards.
10. Closing method statement.
11. Mobile sticky CTA bar.

The 2026-07 reorder that produced this list moved three things, and the reasons are the load
bearing part:

- **The bill module went to the top and opens by default.** It is the page's strongest
  personalisation surface and it now sits immediately under the hero's consumption picker,
  which is the control it escalates.
- **"Kannattaako X?" moved down, below the price history.** It reads as a verdict, and a
  verdict belongs after the evidence it judges: the visitor's own bill, the itemised price,
  and how the price has moved. It used to open the body and assert a conclusion before the
  reader had seen a single figure.
- **"Sähkön alkuperä ja päästöt" moved above "Sopimusehdot lyhyesti".** Origin and emissions
  are a buying criterion; the terms grid is reference material read after the decision, and
  it closes with the seller's own description, which is the natural end of the editorial run
  before the FAQ.

Section top borders track what is actually rendered above them, not a fixed list: the bill
module is first under the dark hero and therefore carries **no** top rule, and Hintatiedot
draws one only when `$showBillComparison` put a section above it.

Rules that must not be undone casually:

- **Do not wrap a section in a bordered / rounded / shadowed panel.** That is the exact
  change the approved structure exists to reverse.
- **The price is rendered once**, in the hero. The page used to repeat it in a
  "Kuukausihinta (12 kk keskihinta)" mini-hero inside Hintatiedot, and a third time in a
  TARJOUS ticket with its own strikethrough normal price and a green savings chip. Both are
  gone; the promotion is now one quiet receipt note (`getReceiptNotesProperty()`).
- **One `Arvio` popover per page**, in the hero next to the number it qualifies. The card
  band on this page is therefore rendered with `:estimate="null"`. Two `<x-info-popover>`
  instances would also collide: the teleported panel carries a constant `wire:key`.
- **One environment module.** The hero used to carry a second CO2 block with a four-tier
  severity taxonomy while the section below used a five-tier one, and the origin breakdown
  lived in a third panel. They are one section now with **one** taxonomy: DESIGN.md's three
  emissions tiers (`< 50` green, `< 200` amber, `>= 200` red). Its figures stay smaller than
  the price on purpose; the residual mix must not rival the money on this page.
  **The lead figure is the driving equivalent in kilometres, not the kilograms.** A reader has
  no sense of scale for 3 909 kg of CO₂e a year for an invisible product, so the number set in
  32px carried the least meaning in the block. The kilograms are directly under it as the
  measured metric the equivalence comes from, and the g/kWh intensity pill is unchanged. The
  factor is 140 g/km (Traficom/Sitra, the Finnish fleet actually on the road, **not** new-car
  type approval). A zero-emission contract keeps `0 kg CO₂e vuodessa` as its lead: its message
  is "no emissions", and "0 km" would say that badly.
- **Warnings are coral, never amber**, including the pricing-integrity notice and the
  inactive-contract notice (the latter is slate). Amber and red are emissions tiers.
- **No em dashes and no `EUR/kk` in any Finnish string on this page.** The old verdict strip
  read `Halvin sopimus — N vertailussa`.
- **Public copy never names Energiavirasto.** The emissions-method source list used to cite
  it; it now says "kansallinen jäännösjakauma".
- The hero breadcrumb's `Sähkösopimukset` crumb carries `?kulutus=` whenever the selected
  consumption differs from 5 000, so going back to the listing keeps the visitor's basis.
  The `Vertaa kaikkia N sopimusta` link at the end of the alternatives does the same.
- The internal SEO links for duration / metering / pricing model
  (`Support\ContractInternalLinks::heroBadgeLinks()`) moved out of the hero into a
  "Vertaa samankaltaisia" line in **Sopimusehdot lyhyesti**. The editorial hero keeps only
  the pricing-category label. The links themselves must stay on the page.

### Hero: quiet metadata, then two beats

- **Quiet metadata**: breadcrumb, then seller logo + name · the pricing-category label
  (`$card->category->label()`). The label is a **real link to `#faq-miten`** that opens the
  FAQ item explaining the mechanism, with a `prefers-reduced-motion`-guarded scroll. A user
  simulation found the page's most important concept rendered as a dead label. The link is
  suppressed when that FAQ item does not exist (`getHasPricingMechanismFaqProperty()`),
  because a link to a missing anchor is worse than plain text.
- **Beat 1, price fused with the verdict.** `ContractDetail::getHeroVerdictProperty()`
  produces the rank, the comparison size, the money comparison clause, the marker position
  on the halvin–kallein rail, and the small print. It **replaced a boxed verdict card** whose
  tier strip (`Halvin sopimus` / `Yksi halvimmista` / `Edullinen vaihtoehto` /
  `Keskihintainen` / `Kalliimpi vaihtoehto`) used emerald/amber/red price semantics and
  competed with the price it was meant to qualify. Rank 1 still compares against the
  runner-up by name and never renders an empty state. Every string is generated in PHP from
  typed fields and every figure is read at `rankConsumption()`.
  - `nonBreakingMoney()` glues a figure to its unit inside the parenthetical, because at
    390 px `(34,05 €/kk)` split across two lines.
  - The "why the cheapest contracts are spot" sentence is **measured, not assumed**: it
    counts the loaded `cheaperContracts` and says "vertailun halvimmat", which is exactly the
    set it counted. Do not restate it as a claim about every contract ahead without a query
    that supports it.
  - The verdict renders as **rank sentence, then the rail, then `Katso halvemmat ↓`, then the
    small print**. The link used to be a third `·` clause inside the rank sentence, where at
    390 px it landed mid-wrap and the beat's only action was buried in running text. Above
    `sm` it sits beside the rail in a `flex flex-wrap items-end` row, so pulling it out costs
    no height; below `sm` it wraps under. It is deliberately shorter than the
    "Katso halvemmat vaihtoehdot" link that closes "Kannattaako X?" further down the page.
  - The rail is `420px`, not the original `340px`: at 340 the marker read as a dot beside a
    label rather than as a position on a scale.
  - **The lit part of the rail is the share of the market that is cheaper than this
    contract**, so rank 1 leaves it dark and rank 253/291 leaves it almost fully lit. It used
    to be an even bar with a dot on it, which said only "somewhere". The fill and the marker
    both read `marker_percent`, so the dot always sits on the fill's edge; keep them on one
    figure. The whole rail group is `aria-hidden`: "halvin / kallein" read aloud after the
    rank sentence is noise, and the sentence carries every fact the rail draws.
  - **Motion is transform-only.** The fill is `scaleX()` on a full-width child and the marker
    is a full-track-width layer translated by a percentage of itself, both at 300 ms
    `cubic-bezier(0.16,1,0.3,1)` with `motion-reduce:transition-none`. Do not go back to
    animating `left`/`width`: DESIGN.md does not animate layout properties, and the old
    `transition-[left] duration-200` did exactly that. The moment this exists for is the
    consumption picker: changing household size makes the contract visibly travel through the
    market, which is the one thing on this page no seller can draw.
- **Beat 2, action**: the consumption chips + free kWh field, then the coral CTA and the
  no-commission note. The CTA is **flat `coral-600` at 19px/700, no gradient and no glow**:
  white on `coral-500` is 2,8:1 and the gradient pair failed contrast. This is a deliberate
  deviation from DESIGN.md's gradient CTA, made for contrast; keep it.
- **The no-commission line appears exactly twice on a rendered page**: beside the CTA and in
  the shared site footer. The closing method statement deliberately does not repeat it, and
  "arvio, ei hintalupaus" appears only in the price qualifier.

#### Say each fact once

The hero stated the comparison consumption three times and the word "arvio" four times inside
one screen, which is most of what made it read as a grey wall. Each fact now has one owner:

- **The consumption** belongs to the line under the price ("668 € vuodessa · 5 000 kWh
  vuosikulutuksella · sisältää alv 25,5 %") and to the selected chip. `heroVerdictNote()`
  therefore carries only the date, `Sijoitus laskettu 26.7.2026.` — the one fact nothing else
  on the page states. When the rank basis genuinely differs from the selected consumption,
  `getRankBasisNoticeProperty()` names both figures, so the note never had to hedge for it.
- **Estimate status** belongs to the `Arvio` popover. The eyebrow is `Hinta seuraavalle 12
  kuukaudelle` unconditionally; it used to switch to `Hinta-arvio ...` and duplicate the pill
  six pixels below it.
- The qualifier keeps the word `arvio` (that rule is in "Hero price qualifier" below) because
  it is a sentence about the figure, not a second badge.

The spot and reset qualifiers were also restructured from connective chains into a colon and
two sentences, so the c/kWh figures land early instead of at the end of a relative clause.
Every figure, date and required word survived; only the connectives went.

#### The hero spacing ladder

The hero is one column of eleven stacked blocks on a flat dark surface, with no rules, no
panels and no chrome, so **the interval between two blocks is the only thing that can group
them**. It ran on eleven ad-hoc values between 6 and 28 px, which made a beat boundary
indistinguishable from a line gap; blurred, the whole hero read as one grey stack under the
price. There are three steps now, and they must stay three:

| role | utility | px |
|---|---|---|
| beat boundary | `mt-8 sm:mt-10` (`mt-7 sm:mt-8` for identity to price) | 32 / 40 |
| group boundary inside a beat | `mt-5` | 20 |
| inside a group | `mt-1.5` .. `mt-3` | 6 .. 12 |

The beat gap is deliberately about **3x** the in-group gap. Narrowing that ratio, or adding a
fourth intermediate value, brings the wall back. Identity to price is the softest of the three
boundaries on purpose: the contract name and its price are strongly bound, and the
load-bearing boundaries are price|verdict and verdict|act.

Cost of the ladder: the hero grew from 730 to **783 px** at 1440 (the CTA still ends at 804,
inside a 900 px viewport) and from 932 to **996 px** at 390, which is the documented mobile
budget below.

### Mobile

- The hero is about 1 000 px tall at 390 px (one screen is 844 px). The **price, the verdict
  line, the rail and `Katso halvemmat` sit inside the first screen** (the rail block ends
  around 512 px); the chips and the CTA follow. Every approved hero element is present, so a
  strict one-screen hero would mean dropping one.
- **The sticky bottom CTA bar shows only when the hero CTA has scrolled PAST the top of the
  viewport** (`#hero-cta` rect `bottom < 0`), never merely because it is below the fold, and
  it hides again while `#halvemmat` or the footer is in view so it cannot cover the cheaper
  options. A scroll/resize listener is used rather than IntersectionObserver because the rule
  is a position test, not a visibility test, and one rect check answers all three conditions.
- Standalone controls (chips, free input, disclosure summaries, both CTAs, the `Arvio` pill)
  are at least 44 px. Links inline in running text are not padded to 44 px; that would break
  the line box, and they stay above the 24 px AA floor.

### Seller outbound analytics

Both the hero and mobile sticky seller CTAs use the shared first-party path `window.voltikkaAnalytics.trackContractOrderClick()`. The hero placement is `hero`; the mobile bar placement is `sticky`. Keep the direct seller URL as the anchor `href`, keep normal link activation, and do not add a tracking redirect or `preventDefault`.

`getContractOrderClickContextProperty()` signs the exact displayed `calculatedCost.total_cost`, selected `consumption`, `liveRank`, `liveTotalContracts`, and `rankConsumption()`. Do not use the fixed 5,000 kWh SEO `priceRank`. The signed token has a 96-hour lifetime so cached and stale edge HTML stays valid. A custom consumption can differ from its rank basis, and both values must stay in the context. Missing values stay null.

The existing Plausible custom event `Contract Order Clicked` remains a separate call. Keep its contract ID, company, and pricing model nested under Plausible's `props` option. Encode Blade values with `@js` so string/UUID IDs and seller names remain valid JavaScript. A failure in either analytics path must not affect the other path or seller navigation.

Read `../Services/Analytics/AGENTS.md` before changing attribution, delivery, signing, or event storage.

### SEO metadata

Contract detail meta descriptions are generated from Voltikka-owned comparison data, not provider marketing descriptions. The templates intentionally avoid Finnish inflection for arbitrary company names and use neutral wording like `yhtiöltä {company}`. Product JSON-LD `description` must stay aligned with `metaDescription` so provider `short_description` / `long_description` does not become Google's preferred snippet source.

When a contract has meaningful `General` price history (at least two dates and >= 3% change), the meta description prefers a price-history template with current c/kWh + monthly fee, change direction/percentage, and rank. Spot contracts describe the `General` component as margin; other contracts describe it as energy price.

Active ranked contract title tags lead with Voltikka-specific facts when available, but avoid receipt-like titles. Preferred hierarchy: for top-25 contracts use rank-first titles such as `Sija 5/336 · 6,50 c/kWh | {name} | Voltikka`; for rank > 25 with cheaper alternatives use money-difference titles such as `122 € kalliimpi kuin halvin | {name} | Voltikka`; otherwise fall back to rank + compact price. Keep title price phrases short (for example `6,29 c/kWh` or `Marg. 0,49 c/kWh`) and do not include the base fee in title tags.

### Hero verdict thresholds

The tiered verdict strip is **gone** (see "Hero: quiet metadata, then two beats"): the hero now
states `Sija N / M sopimuksesta` plus one comparison clause. The absolute-top-25 rule survives
only in `getVerdictProperty()`'s tier wording, where it additionally needs a percentile guard.

**Rank 1 compares against the runner-up, never against nothing.** The verdict used to render
`Ei vertailutietoa` / `Ei tietoa` on the cheapest contract in the comparison, because
`cheaperContracts` is empty by definition at rank 1 — the page that has the most to prove said it
had no data. It shows the gap to the second cheapest contract by name
(`ContractRankingService::getNextCheapestContract()` → `ContractDetail::$nextCheapestContract`), and
degrades to `Ainoa vertailukelpoinen sopimus tällä kulutuksella` when the universe really holds one
contract. Do not reintroduce an empty state here; every branch must say something true.

**One comparison size, one scope.** `priceRank` / `totalContracts` (title, OG title, meta
description) and `liveRank` / `liveTotalContracts` (hero) both read
`ContractRankingService::getRankForConsumption()` / `getTotalContractsForConsumption()` through
`ContractDetail::seoRankSummary()`; the SEO pair is only pinned to the default 5 000 kWh basis so
the title does not move when a visitor changes the consumption chip. They used to come from two
different universes and one page stated both **291** (global rankings, which drop contracts whose
limits exclude 5 000 kWh) and **299** (the hero universe, which kept them). The global rankings also
always count HOUSEHOLD contracts, so a business contract's title quoted a market its own hero was
not ranked in. `getEligibleSortedIds()` now filters on the contract's own consumption limits as well
as the target group, so rank, comparison size and cheaper-contract list all describe the same set;
the viewed contract is never filtered out of its own ranking.

### Hero price qualifier

`ContractDetail::getPriceQualifierProperty()` generates one plain-Finnish sentence under the hero
price stating what that figure is, per pricing category resolved by
`../Services/ContractCard/PricingCategoryResolver`.

**It divides labour with the hero's `Arvio` popover, and the split is the point:**

> the popover (`ContractCard\ContractCardCopy::estimate()`) = **how** the estimate was calculated
> the qualifier = **what kind of price** this is

They used to say the same thing twice, six lines apart. On a consumption-effect contract the two
were near verbatim ("... kiinteällä perushinnalla 7,88 c/kWh ... kulutusvaikutus, jonka suuruutta
myyjä ei julkaise etukäteen"), and the popover was the better copy of the two, because it also
names what the effect depends on. On a market reset the popover additionally states the cadence.

So the qualifier is now conditional on `$this->card?->estimate !== null`:

| category | popover | qualifier |
|---|---|---|
| Pörssisähkö | always | `Pörssisähkössä maksat sähkön tuntihinnan, joten vuosihinta on arvio.` — mechanism only. The popover carries either the forward FI market strip plus historical day/night shape or the explicit rolling fallback, together with the exact margin. The receipt labels distinguish forward and realized bases |
| Markkinahinta (reset) | yes | **null** |
| Kulutusvaikutus | yes | **null** |
| Kiinteä, term < 12 kk | yes (`termBody`) | price sentence only; the popover owns the annualisation and the unknown continuation |
| Kiinteä, supplier-adjusted open-ended | yes | two short sentences: the seller's published current price is a fact, and the 12-month equivalent is an estimate. The popover owns the basis and uncertainty |
| Kiinteä, 12 kk+ / other toistaiseksi | **none** — not an estimate | full sentence, sole carrier |

**Do not make the qualifier unconditional again**, and do not delete the no-popover branches: a
fully fixed contract has no popover to defer to, so deleting them would leave its hero with no
statement of what kind of price it is. `spotPriceQualifier()` has no fallback branch on purpose —
`estimate()` falls back to `rolling_365_spot` for any spot cost payload, so a spot contract always
has the popover.

Nothing became hover-only. Every fact the qualifier stopped repeating is still visible without
opening anything: the itemised receipt rows in Hintatiedot, the card's own type band, and the
generated "Kannattaako X?" paragraphs, which explain the consumption effect and the reset mechanism
in full plain Finnish one section below the hero.

Constraints that are not stylistic:
- **Copy stays in PHP, generated from typed fields only** — never in the Blade template and never
  from seller or LLM text, for the reason recorded in `../Services/ContractCard/AGENTS.md`. It is
  still page-local (Phase 2 moved the pricing surfaces onto the presenter but left this sentence
  here, because the hero rewrite belongs to the later editorial phases); it already resolves the
  category through `PricingCategoryResolver`, so it cannot contradict the band.
- **`sähköfutuurit` never appears without the plain-language gloss `tukkumarkkinan ennakkohinnat`.**
- Every estimate sentence contains the word `arvio`; no em dashes; `€/kk`, not `EUR/kk`.

### Consumption state, and what reacts to it

The hero carries the consumption picker **above the seller CTA**: the four preset chips
plus a free kWh field. The placement is the point — the visitor must be able to enter
their own consumption before the page asks them to act on the price.

- **`directConsumption` is the free field** and is intentionally `int|string|null`, the
  same tolerance as `ContractsList::$directConsumption`, because Livewire and mobile
  browsers send an empty string while a number input is being cleared.
  `updatedDirectConsumption()` ignores blank/zero (a cleared field must never zero the
  consumption), clamps to `MIN_FREE_CONSUMPTION`/`MAX_FREE_CONSUMPTION` (1 000–30 000)
  and then to the contract's own limits, and writes the clamped value back so the field
  shows what is actually in effect. `setConsumption()` keeps it in sync, so a chip click
  and the field never disagree. The template commits on blur (`wire:model.blur`) and on
  Enter (an Alpine `@keydown.enter` that blurs the field).
- **The active chip is a white-on-dark inversion, never white on coral** (2,8:1 on
  coral-500). Every chip and the field are at least 44px high. Tests address chips
  through `data-consumption-preset="{N}"`, because the bare "2 000 kWh" substring now
  also appears in the static cost table and in the consumption-cap warning pill.
- **A missing chip is explained, not hidden.** `presetNotice` states the seller's
  consumption limits when `presets` is shorter than the four defaults; the static cost
  table keeps every reference row and marks the unbuyable ones "Ei saatavilla tällä
  kulutuksella".
- **`?kulutus=` stays read-only-on-mount and is deliberately NOT `#[Url]`.** Arriving
  deep links from the listing cards preselect the consumption (`mount()`), but changing
  a chip does not rewrite the URL. Two reasons: a URL-bound consumption would make every
  interaction a crawlable variant of a page whose canonical is param-free, and a strict
  typed `#[Url]` int property is the exact shape that produced hydration errors for
  `ContractsList::$page` when bots request `?kulutus=`. It also keeps
  `request()->query() === []` meaningful as the prepared-cache guard.

What the exact selected consumption drives: the hero price, the receipt rows, the
static cost table's highlighted row, and the CO2 figures. What it drives through
`rankConsumption()`: the rank, the comparison size, the cheaper-contract tiles, the
verdict gap, the counterfactual and the same-type alternative.

**`rankConsumption()` snaps a free value to the nearest `ContractListCacheService`
preset.** Those figures need every active contract priced at the same consumption, which
only the preset metric caches hold. Building that market-wide payload for an arbitrary
number typed into a text field would put an uncached full-market calculation behind a
public input and give the cache unbounded cardinality. The four chips are all presets,
so a chip is always exact; a free value is snapped and `rankBasisNotice` says so on the
page. **Do not "fix" this by calling `ContractListCacheService::getCachedMetrics()` with
the raw value** — it returns null for a non-preset and the whole verdict box silently
disappears, which is how this was found.

### Static per-consumption cost table, and the counterfactual

- `consumptionCostTable` prices 2 000 / 5 000 / 10 000 / 18 000 kWh through
  `calculatedCostFor()`, the same path as the hero price, so the table cannot disagree
  with the figure above it. It is server-rendered for every visitor regardless of the
  interactive selection, because "paljonko tämä sopimus maksaa 18 000 kWh kulutuksella"
  is a search query and the answer has to be in the initial HTML. It does not depend on
  the selected consumption (only the highlight does), so it stays inside the cached
  canonical payload.
- `spotCounterfactual` is the one line under that table. A fixed, market-reset or
  consumption-effect contract is compared with the **median** pörssisähkö contract
  ("what if I had taken pörssisähkö" is a question about the typical outcome); a spot
  contract is compared with the **cheapest** fully fixed contract (certainty is bought
  deliberately, and you would buy the cheapest of it). Both sides are read at
  `rankConsumption()`, so the quoted figure and the figure it is compared against are
  always priced at the same consumption, and the sentence names that consumption itself.
- Both read `ContractRankingService::getBucketCostSummary()`, which filters the eligible
  sorted ids through `PricingCategoryResolver::scopeBucket()`. Every current canonical Spot total
  in it shares one 12-month FI forward strip and historical intraday shape, or one typed rolling
  fallback, plus that contract's own margin. The summary returns the shared estimate method so the
  sentence names the correct basis without a second market-wide calculation.
- The counterfactual sentence lives in PHP, generated from typed fields, for the same
  reason as `getPriceQualifierProperty()`.
- **Fixtures must set `canonical_pricing`.** The bucket scope's negations rely on
  three-valued SQL logic, so a NULL `canonical_pricing` row falls out of *every* bucket
  and both features silently find nothing. No active production contract is in that
  state; `createComparisonContract()` in `ContractDetailPageTest` now matches it.

### Alternatives: two cheapest plus one same-type

`sameTypeAlternative` is the cheapest contract in the viewed contract's own
`PricingBucket`. Ranking puts pörssisähkö on top almost everywhere, so the two cheapest
tiles are usually spot and a visitor who came for price certainty was offered nothing
they would buy. The tile is skipped when it is already one of the two cheapest. Savings
deltas on these tiles are neutral slate, not emerald: green and red are reserved for the
CO2 delta (see `../../../DESIGN.md`).

### Pricing surfaces come from `ContractCardPresenter`

`ContractDetail::$card` (`getCardProperty()`) presents the viewed contract through
`../Services/ContractCard/ContractCardPresenter` in `detailed: true` mode, and the Blade renders
the shared `x-card.band`, `x-card.receipt` and `x-card.footer` components from it. The detail page
is the **third consumer** after the two card templates.

It became one because the page had drifted **below** the honesty of the listing card that links to
it, and every item on that list was live:

- a Hybrid printed "Energiahinta 0,00 c/kWh" with no consumption-effect row;
- the template hard-labelled every `pricing_model = Spot` contract's relational `General`
  component "Marginaali (yhtiön lisä)", so Cheap Markkinahintasähkö's flat 6,99 c/kWh intro price
  printed as a margin above the seller's own text saying the margin is 1,29;
- the same block computed "Energiahinta (arvio) (spot + marginaali)" from
  `spot_price_margin ?? 0`, so a null margin printed the bare market average as an energy price;
- a market-reset page showed the current-period price unqualified;
- consumption caps and scheduled increases warned on the card and nowhere on the page;
- a contract with neither `order_link` nor `product_link` had no call to action at all.

Rules to keep:

- **Do not put a price, a category or a Finnish sentence back into `contract-detail.blade.php`.**
  Add the fact to `../Services/ContractCard/` and let all three surfaces read it. That directory's
  `AGENTS.md` documents detail mode, the dated mechanism-switch rows and the CTA ladder.
- `getCardProperty()` copies `calculated_cost` / `pricing_integrity` / `comparability` onto the
  model first. None are database columns; this is the shape listings get from the metric cache.
  In canonical mode it passes no relational prices. The presenter also rejects any payload that
  does not identify `pricing_basis = canonical`.
- Current receipt rows, title price phrases, current-price meta text, and Product JSON-LD all read
  `currentDisplayValues()`. Canonical mode builds it only from `calculated_cost`; a missing unit
  stays absent, a canonical-only contract can show its available values, and an excluded outcome
  has no current unit value or JSON-LD Offer. A typed package suppresses `general_kwh_price` from
  ordinary energy-price title/meta/JSON-LD surfaces: that number is the excess-use rate, so Product
  JSON-LD names it `Ylittävä kulutus` beside the monthly fee and included kWh. The generated
  qualifier and mechanism FAQ state the same package facts. Feature-off mode keeps the relational values.
- `priceHistory`, `contractHistory`, the price-development chart, and the replacement timeline
  remain relational observed history. Do not use their newest observation as a canonical current
  price. The meta history sentence can describe the observed change, but its "maksaa nyt" rate and
  fee come from `currentDisplayValues()`.
- A short fixed term's receipt note uses `calculated_cost.contract_term.discount_savings_total`.
  The annualized top-level saving is ranking/comparison data and must not be called the customer's
  actual six-month benefit.
- The `ContractCardView` travels inside the prepared view payload, so
  `contractDetailViewDataCacheKey()` was bumped to **v11** with it, to **v15** for the Phase 4
  composition keys, and to **v16** for canonical-only current values, offer notes, metadata,
  and Product JSON-LD, then to **v17** so a package excess-use rate cannot become an ordinary
  energy price in title/meta/schema. It no longer carries `latestPrices`, `discountedComponents` or
  `priceChangeInfo`; no template read them after the editorial restructure, and
  `getDiscountedComponentsProperty()`, `getPriceChangeInfoProperty()` and
  `components/contract-price-row.blade.php` were deleted with them.
- The page keeps what the cards do not have: the price-development chart and its seller-behaviour
  facts, the version timeline, the VAT note and the integrity notice. The boxed **market-reset
  notice is gone**: it repeated the hero qualifier's figures. What it uniquely said now lives in
  one receipt note from `CanonicalPricing\MarketReset\ResetEstimateCopy::receiptNote()` (future
  period prices are unknown, when the estimated tail starts, which forward vintage it reads).
  Supplier-adjusted pricing similarly gets one quiet note from
  `CanonicalPricing\SupplierAdjusted\SupplierAdjustedEstimateCopy::receiptNote()`. Its qualifier
  separates the published current-price fact from the annual estimate without restating the
  popover's market basis, notice rule, unknown schedule, or no-price-promise warning.
  `detailNotice()` was removed with it.
- `ContractDetail::displayNameFor()` gives the alternative-contract tiles and the named runner-up
  the same name normalization as the H1 and the cards.

Tests: `tests/Feature/ContractDetailPresenterTest.php`, one per defect above.

### Bill comparison module ("Vertaa nykyiseen sähkölaskuusi")

The third bill-comparison surface (after `/maksatko-liikaa` and the in-listing mode). It is the
**first section under the hero**, it is **open by default**, and it answers one question: what
this contract would have cost for the visitor's own billing period and kWh.

**It is rung two of the hero's consumption ladder, and the placement is deliberate.** Rung one
is the hero's preset chips: one tap, and every number on the page moves. Rung two is this
module: several fields, and one scoped answer that changes nothing else. That difference is why
the two are *not* merged into one block — a multi-field form beside the chips reads as "fill
both in to use this page" and puts an entry price on a page that works at zero cost, and moving
the chips out of the hero would put the CTA above the only control that sets the price it acts
on. **Adjacency is what makes the two read as one ladder now** — the module starts about 40 px
below the hero's last block — so the ladder no longer needs a link to state itself.

The hero used to close its picker block with "Tiedätkö tarkan laskusi? Vertaa sähkölaskuusi ↓",
which scrolled here and dispatched `open-bill-comparison`. It was removed with the reorder: its
whole job was to stop a visitor landing on a collapsed heading, and the module is now first and
open. The section keeps its `@open-bill-comparison` listener, so any future opener still works
and a visitor who collapsed the panel can be taken back to an open form.

It used to sit after "Hintatiedot", which put it 2 138 px down on desktop and 2 890 px down at
390 px — about 3.4 phone screens, collapsed, behind the largest section on the page. Moving it
above "Kannattaako X?" brought it to 1 372 / 1 880 px; it is now first under the hero at 851 px
on desktop.

**Open by default has two consequences in the template that are easy to undo by accident.** The
panel must not carry `x-cloak` (it would hide an open panel until Alpine boots, which is the
flash `x-cloak` exists to prevent, inverted), and the chevron carries `rotate-180` in its static
class list **plus** an object-syntax `:class="{ 'rotate-180': billOpen }"`, so the server HTML is
already correct and the icon does not visibly spin once on hydration. Object syntax, not the
`billOpen && '...'` string form, because only the object form removes a class the static list
put there when the visitor collapses the panel.

Being first under the hero, the section carries **no top border** — a rule straight against the
dark hero would read as a seam. Hintatiedot below it owns that rule and draws it only when
`$showBillComparison` actually rendered this section.

- **Inputs come from `Concerns/BillComparisonInputs` and the shared partial**
  `partials/bill-comparison-form.blade.php`, the same two files the listing uses. The module
  adds only `getShowBillComparisonProperty()`, `recomputeBill()`, `clearBill()` and
  `getBillComparisonProperty()`.
- **Period basis only.** The bill total is the anchor and nothing is annualized from it, exactly
  as in the listing: annualizing one bill's implied unit rate is biased for spot, seasonal and
  time-of-use contracts. `test_the_answer_is_period_basis_only` pins that no annual/monthly key
  is ever derived and that `delta === user_total − contract_cost`.
- **It goes through `BillComparisonService::periodRowsForContracts()` with a one-contract set**,
  never a second cost calculation, so the module and the listing card that linked here price the
  same period the same way. The rendered result states the implied c/kWh including the base fee,
  so the visitor can check it against the receipt rows in "Hintatiedot", which is now the section
  **below** this one rather than above it.
- **Every unavailable state says why.** The service's `unavailable` reason map becomes one
  Finnish sentence in `billUnavailableMessage()`: no spot history for the period, a consumption
  cap the bill's annualized kWh falls outside, a contract canonical pricing excludes, or no
  usable pricing. The module never renders an empty or zero result.
- **Delta colours are not decorative.** Saving is the neutral dark `slate-900` pill; paying more
  is a coral warning pill, the same language as the card warning pills, and it links on to
  `#halvemmat`. Green and red stay reserved for the CO2 delta (`../../../DESIGN.md`). A delta
  under 0,50 € reads "suunnilleen sama" instead of inventing a winner.
- **Per-user compute, never cached.** `render()` merges `billModuleViewData()` *beside*
  `contractDetailViewData()`, so bill state cannot be written into the shared prepared payload;
  `$billResultCache` is protected, so it never enters the Livewire snapshot either (same rule as
  `BillComparison::$resultArray`); and `isDefaultContractDetailCacheable()` refuses an active
  bill explicitly, on top of the existing GET + empty-query guard. Tests:
  `ContractDetailBillComparisonTest::test_bill_state_never_enters_the_prepared_view_data_payload`.
- Hidden on inactive contracts and on contracts whose pricing is excluded: "what would this have
  cost you" is misleading for a product that is not on sale or has no trustworthy price.
- With canonical pricing on, all three surfaces now receive one typed period result from
  `CanonicalContractPriceCalculator`. The period path applies the same canonical phase timing and
  can switch from a fixed energy rate to realized hourly Spot, or between Spot margins, inside the
  bill period. Canonical-only contracts work; missing or excluded canonical pricing returns an
  honest unavailable reason and never reads relational rates. Feature-off keeps the old component
  path. Regression coverage aligns the standalone row, listing card, and detail result numerically.

Tests: `tests/Feature/ContractDetailBillComparisonTest.php`.

### Generated content modules: verdict, FAQ, terms

Three page sections carry no seller or LLM text at all. All three are built in PHP from typed
fields, for the same reason as `getPriceQualifierProperty()` and the counterfactual: a Finnish
sentence written in a Blade template drifts away from the numbers beside it.

**"Kannattaako X?" (`getVerdictProperty()`)** renders after the price history. Two paragraphs:
where the contract sits (tier, cheaper/pricier counts, money gap) and what its pricing type means
for the buyer. Constraints:

- Every figure is read at `rankConsumption()`, so the counts and the gap move with the
  consumption picker. A verdict that says "valitulla kulutuksella" and never moves reads as a
  rigged ranking; that was a P0 in the mockup critique.
- **Rank 1 states the lead over the runner-up**, never an empty state, exactly as the hero
  verdict box does. `cheaperContracts` is empty by definition at rank 1.
- The `vertailun kärkipäässä` tier needs both `rank <= 25` **and** `percentile <= 0.33`. The hero
  verdict's absolute top-25 rule is wrong here, because in a small universe rank 2 of 2 is the
  most expensive contract there is and the sentence prints the counts that prove it.
- The "Katso halvemmat vaihtoehdot" link scrolls to `#halvemmat` and is guarded by
  `prefers-reduced-motion`. It renders only when a cheaper contract actually exists.

**"Usein kysyttyä" (`getFaqItemsProperty()`)** is the single source for both the visible
`<details>` list and `getFaqSchemaProperty()` (FAQPage), the same rule as
`ConsumptionCalculator` and `HeatPumpCalculator`. Do not hand-write a second FAQ `<script>`;
that is how the heat-pump FAQ drifted once. Items, at most five: cost at the selected
consumption, the pricing mechanism, spot variation (spot only), cancellation, and Voltikka's
estimate method. An item whose facts are missing is dropped rather than answered "ei tietoa".

- **The mechanism item owns the anchor `#faq-miten`.** The hero's pricing-category label links
  to it, because the mockup critique found the most important concept on the page rendered as a
  dead label. The section opens a hash-targeted `<details>` on load and on `hashchange`.
- The spot variation item quotes realized monthly averages from `spot_price_averages`
  (`spotMonthlyPriceRange()`, at least three months) and is dropped when there is no history.
  Do not answer it from the rolling 365-day average alone; that states no variation.

**"Sopimusehdot lyhyesti" (`getContractTermsProperty()`)** is one flat grid above the seller's
own description, and it **absorbed the old "Laskutus ja ehdot" box** in the right column. Do not
add a second terms list anywhere on the page.

- Only rows whose data exists are returned. The old box printed "Alueellinen" for a NULL
  `availability_is_national` because it tested truthiness.
- **`irtisanomisaika` is not a per-contract field.** The two-week consumer notice period is a
  market fact the site already states editorially, so it is derived from `contract_type` only
  (`OpenEnded` → `14 vrk`, fixed term → `Sitoo sopimuskauden loppuun`), and the grid closes with
  "Tarkista ajantasaiset ehdot myyjän sivuilta".
- "Hinta määräajan jälkeen" appears only when `comparability === 'term_price_only'`. That is a
  typed verdict ("the only unpriced gap is after the term"), not an absence of data.
- A consumption cap is stated only when it could bind a household. `CAP_RELEVANCE_THRESHOLD_KWH`
  mirrors `ContractCard\CardFooterItems`, so the card warning and the terms row agree about
  which caps matter; without it every page printed "Enintään 200 000 kWh/v".
- `termMonths()` reads `calculated_cost.term_months` first and falls back to the exact
  `fixed_time_range` buckets. The calculator reports no term for a Hybrid (it is costed
  base-only), so a 6 kk hybrid otherwise said "sovitun sopimuskauden ajan" with no number.

Tests: `tests/Feature/ContractDetailPageTest.php` (`test_verdict_paragraph_*`, `test_faq_*`,
`test_terms_grid_*`).

### Source-text hygiene

Three defects on the detail page came from the upstream payload, not from Voltikka. All three
are fixed in one shared helper, `../Support/ContractContentSanitizer.php`, so later phases and
the card presenter can reuse the rules instead of re-deriving them in a template. **Add new
cleanup rules there, never in `contract-detail.blade.php`.**

- **`billing_frequency` is a localized map, not a list.** Every observed contract stores
  `{"EN": "1 kk", "FI": "1 kk", "SV": "1 kk", "Default": null}`, so `implode(', ', ...)` printed
  "1 kk, 1 kk, 1 kk, ". `ContractDetail::$billingFrequencyLabels` collapses it with
  `uniqueLabels()`, which compares case-insensitively on the trimmed value, so two genuinely
  different intervals both survive. Do not "simplify" this to `['FI']` — the key set is what the
  source sends today, not a contract. It then runs `billingFrequencyLabels()`, which expands a
  bare number (273 contracts store the interval as `"12"`, which rendered as "Laskutusväli 12")
  and drops "Ei ilmoitettu" (112 contracts), because the terms grid promises that every row it
  shows has data behind it.
- **Shouted contract names are normalized for display only.** `ContractDetail::$displayName`
  runs `displayName()` over `$contract->name` for the H1, the title tag, the OG title, the meta
  description, the Product/Breadcrumb JSON-LD and the history timeline. The rule is deliberately
  narrow: a word is lowered only when it is fully uppercase, has more than three letters, holds
  no digit, and is not in `KEEP_UPPERCASE`. Consecutive shouted words are one run, capitalized
  once, because Finnish is not title-cased. **The stored `name` is never rewritten** — imports,
  the replacement matcher and price history all key off it.
- **Seller descriptions are cleaned before rendering.** `extra_information_fi` is printed
  unescaped, so `descriptionHtml()` drops `<script>`/`<style>`/`<iframe>` and `on*=` handlers,
  strips quotes that wrap the whole text, unwraps an `<a>` that carries no `href`, and removes
  shouted "TÄÄLTÄ"/"TÄSTÄ"-style callouts **only where they are not inside a real link** — a live
  `<a href>` callout still helps the visitor. Sentence punctuation is kept when the word is
  removed, otherwise two sentences merged into one. When nothing readable is left the property
  returns null and the whole section is dropped rather than rendered with an empty body.

### Logo fallback

The hero tile and the alternative-contract tiles use `<x-company-logo>`
(`../../resources/views/components/company-logo.blade.php`), the same component as the cards,
the company list, company page, and editorial contract-type comparison. It paints initials first
and reveals the logo only when it actually decoded, so a 404 or an HTML error page leaves initials
instead of a broken image or a blank gap. **Do not hand-roll an `@if (getLogoUrl())` / `@else` pair
again** — that is the pattern the component replaced, and its `onerror` used to point at a
third-party placeholder host.

Visible public tiles and Product, ItemList, and Organization schemas all call
`Company::getLocalLogoUrl()`. An external-only company stays on initials, so a visitor never sends
an image request to an unverified seller host and a dead URL cannot enter JSON-LD. Local resolution
prefers an existing optimized WebP beside the recorded source file. `Company::getLogoUrl()` retains
an external fallback only for non-browser consumers such as the API/video paths. Do not add
request-time external health checks or put external seller image URLs back into public HTML.

### Prepared view-data caching

Contract detail pages cache their contract lookup and prepared default GET payload until tomorrow with a `ContractPageCacheVersion` key.

Important semantics:
- only the canonical default consumption state is cached (`5000 kWh`, clamped into the contract's allowed range); a Livewire consumption change is a POST, so per-user consumption state never reaches this cache. The static cost table is the one per-consumption surface inside the cached payload, and it is safe there because it prices four fixed reference consumptions that do not depend on the selection
- query-string/Livewire interaction states are not cached by this page-level cache
- the bill module is passed to the view from `render()` **outside** `contractDetailViewData()`, and `isDefaultContractDetailCacheable()` additionally refuses an active bill; keep both, a per-user calculation must never be shareable
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

`ContractDetail` loads `activeContract` beside `company`, `priceComponents`, and `electricitySource`. `ContractHistoryPresenter` separately bulk eager-loads `company`, `priceComponents`, and `activeContract` for the full backward chain. Keep `ElectricityContract::isActive()` relation-aware so history rows do not issue one `active_contracts` query per version. Discount helpers on `ElectricityContract` are also relation-aware; when `priceComponents` is already eager-loaded for cards or JSON-LD, do not re-query `price_components` just to check active discounts.

`ContractDetail` also memoizes rank-related computed values and keeps one request-scoped `ContractRankingService` instance. Do not replace `rankingService()` with repeated `app(ContractRankingService::class)` calls in `liveRank`, `liveTotalContracts`, or `cheaperContracts`; those methods share the same eligible target-group lookup and otherwise repeat large `electricity_contracts` queries during one render.

`ContractRankingService`, `ContractListCacheService`, and `CompanyListCacheService` intentionally memoize cache payloads per service instance. Production uses the database cache driver, so repeated `Cache::remember()` calls for `contract_rankings_5000kwh`, `contract_list_cache_version`, `contract_list_metrics:*`, or `company_list:*` become repeated `select * from cache where key in (?)` spans that Sentry can classify as N+1 even when application data is already cached.

`ContractDetail` memoizes `ContractPageCacheVersion::hash()` per component instance because both the contract lookup cache key and prepared view-data cache key need it. On the database cache driver, recomputing the version hash can create repeated cache/source-table queries before the page data is even built.

### Price development module ("Näin hinta on kehittynyt")

Primary files:
- `../Services/ContractPriceHistory/PriceDevelopmentPresenter.php` (+ its `AGENTS.md`, read it before changing chart semantics)
- `ContractDetail::getPriceDevelopmentProperty()`
- the `#hintakehitys` section of `../../resources/views/livewire/contract-detail.blade.php`
- `../../resources/views/partials/contract-version-timeline-item.blade.php`

One module, one chart. It replaced a separate "hero trajectory" sparkline that
sat above the same timeline and told a weaker second version of the same story
with amber/emerald price semantics and percentage deltas. **Do not add a parallel
price chart to this page.**

Important semantics:
- the payload is built entirely in the presenter; **do not compute chart geometry
  in Blade again**. The old block did, and the geometry drifted from the copy
  beside it
- the module is consumption-independent, which is why it lives inside the
  prepared view-data cache and needs no consumption scope sentence
- non-spot contracts get their own stepped `#0f172a` line over their statistics
  segment median; spot contracts get monthly realized market averages over the
  trailing-12-month average. Coral is never a data series here
- the chart has a hover tooltip (Alpine, plain absolute child of the section, not
  teleported) and an `sr-only` table mirror carrying the same numbers. The band
  rows drive the tooltip, the x labels and the table, so the three cannot disagree
- an observation window under 21 days renders an honest sentence and **no** chart
  and **no** behaviour tags
- behaviour tags are built only from data that exists, and every figure in them is
  c/kWh or €/kk. The percentage form is banned on this page
- a 0,00 c/kWh energy observation on a contract that is priced above zero on
  another observed date is a known ingestion artifact (duplicate null-UUID
  `General` components collapsing to one relational key) and the presenter drops
  it. It used to draw a vertical fall to zero that contradicted the version
  timeline directly below. Zero is still charted when it is the contract's whole
  history, so flat-fee package contracts are unaffected. **The stored rows are
  still wrong** — repairing them from the source snapshots is a production
  mutation. See `../Services/ContractPriceHistory/AGENTS.md`
- the version timeline shows its three newest entries and collapses the rest into
  a `<details>`; both lists render `partials/contract-version-timeline-item`, so
  the two paths cannot drift

### Contract history UI

The contract detail page now builds its visible history from the replacement-link chain instead of only the current contract row.

Current intended behavior:
- active contracts render the full `contract-detail.blade.php` page
- inactive contracts without a trusted replacement also render the normal `contract-detail.blade.php` page for historical reference
- those inactive historical pages should include a `noindex` robots meta tag
- inactive historical pages should not appear in the sitemap
- an inactive rendered contract's timeline starts with a synthetic “Sopimus ei ole enää myynnissä” status node, and the inactive version itself must not keep the `Nykyinen` badge
- availability transitions have no persisted timestamp; use the rendered contract's maximum `price_components.price_date` only as “Viimeksi havaittu myynnissä”, never as an exact removal/expiry date, and show the unknown-date fallback when it has no price rows
- start from the currently rendered contract
- `ContractHistoryPresenter` walks backward with a recursive CTE capped at depth 25, then eager-loads all history contracts with `company`, `priceComponents`, and `activeContract`; do not replace this with per-version relation walking
- `ContractDetail` keeps only thin computed history compatibility methods over one request-local cached `historyPresentation()` payload; it does not own predecessor loading, relational history mapping, labels, order, or historical promotion copy
- inactive replacement redirects stay in `ContractDetail` and use `getForwardReplacementChainIds()` plus a bulk `activeContract` load so old bot-hit URLs do not lazy-load `replacedBy` / `activeContract` one link at a time
- include the current contract itself as the newest history entry
- sort versions in reverse chronological order using each version's latest known `price_date`
- show, for each version:
  - contract name
  - latest relevant prices per component type
  - promotion/discount summary when present

### Price component label guardrail

Component labels and display order for the version timeline and its delta chips
come from `ContractHistoryPresenter` and reach the view as `$priceTypeLabels` /
`$priceTypeOrder`. **Do not hardcode either map in `ContractDetail` or
`contract-detail.blade.php` again.** (The chart above the timeline does not read
this map at all; it builds one representative energy series in
`PriceDevelopmentPresenter`.)

- A spot contract's `General` component is usually the supplier **margin**, not the
  energy price the customer pays, and the meta description already says `Marginaali`,
  so the history must agree. The old blade-local map called it `Energiahinta` for all
  215 active spot contracts that store a margin there, which read as if a 0,60 c/kWh
  margin were the whole energy price. A `Spot`-typed component is a margin regardless
  of `pricing_model`, so it does not carry that conditional.
  **This map is for the history timeline only.** The current price rows come from
  `ContractCardPresenter`, which reads the margin from the calculated payload instead of
  from `pricing_model`, because "Spot" does not guarantee the `General` row is a margin:
  Cheap Markkinahintasähkö's `General` is a flat all-in intro price. Do not extend this
  map back over the current-price rows.
- **`price_component_type` is written verbatim from the upstream API payload**
  (`Services/ContractInterpretation/CanonicalPriceComponentWriter`), so any
  whitelist of types is incomplete by construction. The presenter's ordering appends
  unrecognized types under their raw name instead of dropping them; the previous
  hardcoded order silently hid the `Spot` margin component from Turku Energia
  Louna Nero's history entirely. Both winter spellings (`SeasonalWinter`,
  `SeasonalWinterDay`) are mapped for the same reason.
- The blade's `$lookupPrice` closure matches timeline rows by **component type,
  not label**. Two types can share a label (both winter spellings are
  `Talvihinta`) and a label match would read the wrong row's price into the
  version-to-version delta chip.

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

`ContractHistoryPresenter` merges relational `priceComponents` across the backward chain. `ContractDetail` reads that prepared `priceHistory` for the price-change teaser/details table and the price-development adapter.

That means:
- change counts are computed across all linked versions, not only the current row
- detailed history rows may reference different contract names in the same chain

If future work changes how history is grouped or collapsed, keep the per-version timeline visible unless product explicitly decides otherwise.
