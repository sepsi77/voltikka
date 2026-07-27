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
- `../../Livewire/Concerns/BillComparisonInputs.php` — bill inputs shared by the
  in-listing mode and the contract detail module
- `../../../resources/views/partials/bill-comparison-form.blade.php` — the form
  those two surfaces both render

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
- **annual cost** — a seasonal-adjusted estimate at `annual_kWh`. Canonical mode uses the normal typed 12-month canonical outcome from the same batched evaluation as period pricing. Feature-off uses `ContractPriceCalculator`. This keeps annualized "€/kk" savings consistent with the active listing basis, including trailing-365-day Spot assumptions, seasonal timing, packages, and canonical offer phases.

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

- In canonical mode, the canonical phase mechanism decides whether each hour is
  Spot. A fixed-price phase can therefore switch to Spot, and two Spot phases can
  use different margins during one bill period. No relational heuristic or
  current-margin fallback runs in this branch.
- Canonical period Spot cost uses flat consumption over the period's real UTC
  hours and the realized `SpotPriceHour::price_with_tax` for every hour governed
  by a Spot phase. Missing required history returns `no_spot_history`, never zero.
- Feature-off keeps the legacy detection (`pricing_model === 'Spot'` or a General
  rate below 0.8 c/kWh) and the first non-Monthly component margin.
- When a Spot contract appears in the top 3, the view shows a caveat with the
  period's realized average spot c/kWh. Annualized Spot savings are always
  labelled "arvio" because future Spot differs from past Spot.
- Ordinary monthly fees keep the existing `totalDays / 30` proration.

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

## Three surfaces, one form (do not let them drift)

1. `/maksatko-liikaa` (`BillComparison`) — the standalone tool, `compare()`.
   Keeps its own property names and its own richer form, because it also owns
   the annualized hero, the ranking table and the `annualKwh` override.
2. the in-listing mode (`ContractsList`) — `periodRowsForContracts()`, period
   basis only.
3. the contract detail module (`ContractDetail`, "Vertaa nykyiseen
   sähkölaskuusi") — `periodRowsForContracts()` with a **one-contract set**,
   period basis only.

Surfaces 2 and 3 share their inputs in `app/Livewire/Concerns/BillComparisonInputs.php`
(properties, preset chips, VAT normalization, `buildBillRequest()`) **and** the
Blade partial `resources/views/partials/bill-comparison-form.blade.php`, which
binds exactly those property names. **Add a field to the trait and the partial,
never to one template.** Each component supplies its own `recomputeBill()` and
its own `billInputsEnabled()` gate (listing: the rollout switch; detail page:
active, non-excluded contract).

Deliberately **not** in the shared field set: the heating toggle. It only selects
the seasonal annualization profile, and both period-basis surfaces show no
annualized figure; its one real effect there is the consumption-cap basis. The
standalone tool keeps it because its headline number is annualized.

## In-listing usage (`periodRowsForContracts`)

`BillComparisonService::periodRowsForContracts(iterable $contracts, BillComparisonRequest $request)`
is the entry point for the **in-listing** bill comparison on `/sahkosopimus`
(`ContractsList::buildBillModePaginator()`) and for the single-contract detail
module, as opposed to the standalone `/maksatko-liikaa` page which uses
`compare()`.

- The caller (the listing component) owns filtering, sorting and pagination and
  passes its already-filtered contract set; the service returns each contract's
  exact-period counterfactual cost, keyed by contract id.
- Rows are **available-only**: spot contracts with no spot history for the
  period and contracts with no usable pricing are omitted (never shown at €0),
  exactly like `compare()`.
- The return value also carries **`unavailable`**, an id → reason map
  (`consumption_cap`, `not_comparable`, `no_spot_history`, `no_pricing`) filled
  by a `&$reason` out-param on `buildMarketRow()`. A listing simply drops those
  contracts, but a single-contract surface has to say something true, so the
  detail module turns the reason into one honest Finnish sentence
  (`ContractDetail::billUnavailableMessage()`). Add a new skip branch in
  `buildMarketRow()` together with its reason string, otherwise the detail page
  degrades to the generic "no pricing" wording.
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

All three bill surfaces use `CanonicalContractPricingService::periodEvaluationsForContracts()`.
It parses each contract once and returns both the normal 12-month outcome and a separate typed
`CanonicalPeriodPricingOutcome`. The period calculator is part of
`CanonicalContractPriceCalculator`; `BillComparisonService` supplies only billing facts and the
shared realized Spot observations. It does not interpret canonical components.

Period rules:

- the contract is treated as an offer accepted at the bill-period start;
  `contract_start` and `after_months` boundaries anchor there, while absolute disclosed dates keep
  their calendar meaning;
- fixed General usage is flat across the real period hours; Time keeps the 85/15 day/night split;
  Season applies the same 85/15 split on actual winter dates and the other rate on other dates;
- ordinary monthly fees keep days/30; package fees and allowances reset per intersected calendar
  month and use the same calendar-month fraction for a partial month. Unused allowance does not
  carry, and a package is not a promotion;
- annual comparability, Hybrid base-only treatment, recurring-reset fill/forward shift, phase
  inheritance, mechanism switches, and fail-closed parsing are the same rules as annual canonical
  evaluation;
- `hasPromo` means positive canonical normal-vs-actual savings measured on this exact period;
- unavailable reasons are stable: `consumption_cap`, `not_comparable`, `no_spot_history`, and
  `no_pricing`.

Canonical mode never calls the latest-component loader, `extractRates()`, `spotPeriodCost()`,
`seasonalPeriodCost()`, or `ContractPriceCalculator` for a market row. Canonical-only contracts can
be costed. Missing, excluded, incomplete, or unsafe canonical pricing never falls back to relational
rates. Feature-off keeps the prior component calculation unchanged.

## Query guardrails

- Active household contracts only: `ElectricityContract::active()->whereIn('target_group', ['Household','Both'])`.
- Canonical mode batches annual and period evaluation and issues no
  `price_components` query. Feature-off uses
  `getLatestPriceComponentsForCalculationByContractIds()` once; never eager-load full history.
- Spot hours and the rolling annual assumptions are loaded once and shared
  across all contracts.
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
