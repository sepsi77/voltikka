# AGENTS.md

Context for the "Maksatko liikaa" bill comparison feature.

## Purpose

Compare a visitor's actual electricity bill against the current active market
contracts for the **same billing period and consumption**, and show how much
they would save by switching. The visitor enters only what is on their bill —
no annual consumption and no pricing-model knowledge required.

## Primary files

- `BillComparisonService.php` — comparison engine
- `ConsumptionProfile.php` — seasonal monthly-share profiles for annualization
- `../../../Livewire/BillComparison.php` — Livewire component at `/maksatko-liikaa`
- `../../../resources/views/livewire/bill-comparison.blade.php` — view
- `../../DTO/BillComparisonRequest.php`, `BillComparisonResult.php`, `BillComparisonRow.php`

## The anchor principle (do not break this)

The user's bill total IS their contract's true cost for the period. We never
model the user's pricing model, day/night split, or margin. We compute every
active household contract's cost for the **same date range + kWh** and slot the
user's € total into a single ranking sorted by period cost. This makes the
ranking apples-to-apples by construction.

Inputs required from the bill:
- billing period (date range)
- period consumption (kWh)
- total paid (€) — **energy-contract portion only, excluding siirto**

Optional inputs:
- `annualKwh` — if the visitor knows their annual consumption, it is used
  **directly** for the annualized savings estimate instead of the seasonal
  profile (`BillComparisonRequest::annualKwhOverride`). Improves accuracy.

Removed in the 2026-06 simplification: the standalone energy-price (c/kWh) and
base-fee (€/kk) "explanatory" inputs and the "Miksi sopimuksesi on täällä?" box
they fed. They never touched the counterfactual and had a low
payoff-to-prominence ratio, so `BillComparisonRequest` no longer carries
`energyPriceCents` / `baseFeeEur`. Do not reintroduce them without a real product
reason; the bill total stays the only anchor.

## VAT normalization

Voltikka's market contract costs are energy-only **including ALV 25.5 %** (no
siirto), which is the comparable to "the electricity portion of your bill
including taxes". The Livewire component normalizes the user's total to that
basis before constructing the request: if `includesVat` is false, the total is
multiplied by `BillComparison::VAT_MULTIPLIER` (1.255). The service always
receives a with-VAT-comparable total.

## Two costs per contract

- **period cost** — the exact-ish counterfactual for the bill's date range +
  kWh. Spot contracts use actual historical hourly `spot_prices_hour` prices
  for the period (flat hourly consumption assumption). Fixed/General is exact.
  Time-of-use uses an 85/15 day/night split. Seasonal splits kWh by winter vs
  other days **computed from the period's actual dates**.
- **annual cost** — a seasonal-adjusted estimate via the existing
  `ContractPriceCalculator` with `annual_kWh`, so the annualized "€/kk" savings
  stays consistent with the rest of Voltikka's listings (trailing-365-day spot
  averages, seasonal model, promo-aware first-year estimate).

The ranking table sorts by **period cost** (most honest), and its row-level
savings column must use the same period basis (`user period cost - row period
cost`). Do not mix the table's period prices with annualized €/kk row savings:
that made rows look contradictory when a contract was clearly cheaper for the
bill period but had a much smaller annualized estimate. The user's row does not
display a c/kWh value: the service has an implied bill average (`userTotalEur /
kWh`), but that is not a known energy price and can include base-fee effects, so
showing it as if it were the user's contract energy price is misleading. The
verdict hero leads with the annualized **"€/vuosi"** saving as the primary
number, explicitly marked `arvio`, with **"€/kk"** as a sub-line; the **period**
saving is shown separately as the actual/`toteutunut` figure. This is deliberate:
the headline number is a seasonally-annualized estimate (driven by
`includesHeating` + `annualKwh`), so it must read as an estimate, not a hard fact.
The hero caption names the seasonal / heating basis so the heating toggle's
effect on the number is legible.

## Annualization (seasonal)

`annual_kWh = period_kWh / periodShare` unless the visitor supplied a known
annual consumption (`annualKwhOverride`), in which case that is used directly.
`ConsumptionProfile::periodShare(start, end, includesHeating)` returns the
fraction of annual consumption occurring in the billing period. Two profiles:

- **no heating**: mild winter bump (winter months ×1.156, summer ×0.889),
  matching `ContractPriceCalculator::getSeasonalConsumptionFactors()`.
- **heating**: flat base load + Finnish heating-degree-day shape
  (`EnergyCalculator::HEATING_NEED_PER_MONTH`, Jyväskylä 2021) with a default
  55 % heating / 45 % base split (`DEFAULT_HEATING_SHARE`).

Do not replace annualization with a naive `period × 12` — Finnish consumption
is too seasonal, especially with electric heating.

## Spot contract handling

- Spot is detected by `pricing_model === 'Spot'` or the calculator's heuristic
  (a General rate < 0.8 c/kWh is a margin). The margin is the first non-Monthly
  component price.
- Period spot cost = `Σ(hourly_kwh × price_with_tax) + margin × kWh + base × months`,
  using actual `SpotPriceHour` rows for the period (UTC-converted query).
- If no spot history exists for the period, spot contracts are skipped from the
  ranking (marked unavailable) rather than shown at €0.
- When a spot contract appears in the top 3, the view shows a caveat with the
  period's realized average spot c/kWh. Annualized spot savings are always
  labelled "arvio" because future spot ≠ past spot.
- `monthsInPeriod = totalDays / 30` (fractional) for base-fee scaling.

## Consumption-cap eligibility (do not remove)

Some products are flat-fee tiers with an **annual kWh cap** rather than a
per-kWh energy price. The clearest example is Helen's `Helpposähkö XS/S/M/L`
(`pricing_model = FixedPrice`, `General` price `0`, a `Monthly` fee, and
`consumption_limitation_max_x_kwh_per_y` = 1200/2400/3600). Their energy is
included up to the cap, so `ContractPriceCalculator` (and this service's
fixed-fee-only branch) price them as a flat monthly fee that does **not** scale
with kWh.

`BillComparisonService::fitsConsumptionLimits()` excludes a contract whose
`consumption_limitation_max_x_kwh_per_y` / `_min_` does not contain the
visitor's annualized consumption (`$annualKwh`). Without this, capped flat-fee
tiers sort to the top as the "cheapest" option at every consumption level and
stay immune to kWh changes, which made the ranking table look frozen when the
user changed consumption. Regression test:
`test_consumption_capped_contracts_respect_their_annual_kwh_limit`.

Note: the main site (`ContractsList` / `ContractPriceCalculator`) does **not**
apply this cap filtering, so those capped tiers can still appear as implausibly
cheap for heavy users in the main listings. That is a separate, broader fix.

## In-listing usage (`periodRowsForContracts`)

`BillComparisonService::periodRowsForContracts(iterable $contracts, BillComparisonRequest $request)`
is the entry point for the **in-listing** bill comparison on `/sahkosopimus`
(`ContractsList::buildBillModePaginator()`), as opposed to the standalone
`/maksatko-liikaa` page which uses `compare()`.

- The caller (the listing component) owns filtering, sorting and pagination and
  passes its already-filtered contract set; the service returns each contract's
  exact-period counterfactual cost, keyed by contract id.
- Rows are **available-only**: spot contracts with no spot history for the
  period and contracts with no usable pricing are omitted (never shown at €0),
  exactly like `compare()`.
- Per-request setup (dates, annualized kWh, spot history, trailing-365-day spot
  averages) is shared with `compare()` via the private `periodContext()` so the
  two paths stay numerically identical. Change period/annualization math in
  `periodContext()` only.
- The listing's period mode is **period basis only** (facts): savings shown are
  `user bill total − contract period cost`. Annualized savings are intentionally
  not computed there (annualizing one month's implied unit rate is biased for
  spot/seasonal/time contracts). See `tasks/promote-bill-comparison-in-listings`.
- **Consumption-cap eligibility is the service's job in bill mode.** The listing
  no longer pre-filters its set by the annual consumption slider when a bill is
  active (that slider is set before the bill and is the wrong basis). It relies on
  `fitsConsumptionLimits($contract, $annualKwh)` inside `buildMarketRow()` to
  exclude capped flat-fee tiers on the bill-*annualized* kWh. Keep that check in
  the row builder. See `tasks/expand-bill-comparison-and-compact-listings`.

## Canonical pricing (behind `CANONICAL_PRICING_ENABLED`)

When the flag is on, the **annual cost** estimate (`annualCost()`) comes from
`CanonicalContractPricingService` so the hero's annualized €/vuosi stays consistent with the
listings' phase-aware totals, and `buildMarketRow()` returns `null` for any contract the canonical
verdict excludes from comparison (unknown-future promo, broken data) — so a contract hidden from the
listings never appears in the bill ranking either. The **period** cost still uses this service's own
component-rate math for the historical billing period; only the annualized estimate and the
exclusion go through canonical. When the flag is off, behavior is unchanged.

## Query guardrails

- Active household contracts only: `ElectricityContract::active()->whereIn('target_group', ['Household','Both'])`.
- Use `ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()`
  to load components in one query (do not eager-load full price history).
- Spot hours for the period are loaded once and shared across all spot
  contracts.
- This is a per-user calculator; do not add public prepared-data caching.
  Match the heat-pump / solar calculator pattern (not public-cached).

## Edge cases

- Implied €/kWh < 1 or > 50 → `warnings` includes `implied_out_of_range`; the
  view shows a "tarkista hinta ja kulutus" note.
- Contracts with no usable per-kWh rate and no monthly fee are skipped.
- `route('contract.detail', $id)` is resolved per market row for deep links.

## Future improvements (not in v1)

- Optional provider/contract-name field to auto-identify the user's contract
  and enrich with promo info + deep link (reuse `ContractReplacementMatcher`
  fuzzy matching infrastructure).
- Persist anonymous comparison results for analytics ("X% overpay") — needs a
  model + privacy/consent consideration.
