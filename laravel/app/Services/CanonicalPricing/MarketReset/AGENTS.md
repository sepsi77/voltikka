# Market-reset annualised price (forward-curve shift)

This directory annualises **market-reset** contracts — `canonical_pricing.recurring_schedule.present
= true` with cadence `monthly`, `quarterly`, or `seasonal`. Those products publish one price per
period and follow the wholesale market between periods.

It fixes a live production defect: the calculator used to hold the current period price flat for
twelve months. That price is a **seasonal** price, so the annual estimate was systematically too low
in summer and too high in winter. Measured on the local 2026-07-24 snapshot at 5000 kWh, the
correction across all 32 reset lineages averaged **+149 €/yr**, up to **+255 €/yr**.

Read `../AGENTS.md` first, then `tasks/market-reset-annualised-pricing/spec.md` and its
`decisions.md`. That decisions file records several **explicitly retracted** conclusions; do not
re-derive them.

## The estimator

```
P_m = P_current_period + beta * (F_m - F_reference)
```

- `P_current_period` — the consumption-weighted energy price of the rates the calculator would have
  held forward. Contract-specific and never estimated: it is the provider's own published price.
- `F_m` — FI EEX Base settlement price for delivery month `m` at **today's** vintage, c/kWh incl. VAT
  (`settlement / 10 * config('price_forecasting.fixed_term.vat_multiplier')`).
- `F_reference` — settlement price for the delivery period the current price applies to, at the
  **pricing** vintage (see rule 1).
- `beta` — pass-through coefficient, **one global value**, `config('canonical_pricing.reset_forward_shift.beta')`.

Read the identity it computes as: `P_current - F_reference` is the seller's spread over the wholesale
price they could have hedged the period at, and `F_strip(today) + spread` is the honest annual
equivalent. It stays anchored on the provider's own published price, and it is contract-specific
because the spread is observed, never estimated.

## Non-negotiable rules

### 1. TWO vintages: `F_m` from today, `F_reference` from the pricing date

- `F_m` → latest `trade_date < today` (the window start).
- `F_reference` → latest `trade_date < the current period's start date`.

**Both halves matter, and an earlier version of this file got the second one wrong.** The retracted
argument was that one shared vintage is needed to "cancel level drift". It is not:

- The seller set `P_current` at some `T0` before the period, from the forward for that period as it
  stood then, so their spread is `pi = P_current - F_ref(T0)`.
- If the whole curve rose by X between `T0` and today, the honest estimate `F_strip(today) + pi`
  rises by X too. **That is correct, not noise.** The market really did get more expensive and the
  next resets will reflect it; the customer really will pay more. Cancelling it would hide real
  information.
- Reading the reference at today's vintage instead computes `pi' = P_current - F_ref(today)`. For a
  period already in delivery, `F_ref(today)` has converged toward realized spot, so `pi' > pi`
  systematically. That is a pure artifact.

Measured size of the artifact on FI month 202607: **4.03 c/kWh** on 2026-06-30 (when July retail
prices were set) against **2.45 c/kWh** on 2026-07-24 — a 1.58 c/kWh inflation of the spread, about
**+79 €/yr** at 5000 kWh, on every monthly-cadence reset. Fixing it lowered the five July-anchored
monthly lineages by exactly 1.55 c/kWh each (the artifact scaled by the tail's share of the window).

So the thing that genuinely needed cancelling was **front-month convergence**, and that only ever
affects `F_reference`. This is the same vintage rule `../../RetailPremium/` uses for spread
measurement, and for the same reason.

The reference vintage is expected to be old — up to a full quarter for a quarterly cadence — so the
`max_curve_age_days` staleness guard applies to the **forward** vintage only. Do not extend it to the
reference.

**Fallback.** A period that began before the FI curve history starts (2026-04-08) has no pricing
vintage and never will, because EEX serves an approximately 45-day rolling window. Those fall back to
today's vintage and are flagged `reference_vintage_fallback_today`, rather than dropping to the much
weaker spot index. Verified 2026-07-25: **0 of 32** lineages needed it.

A period that has not started yet (a disclosed `role: future` phase, e.g. Kokkolan Tyyni's August
price) resolves its pricing vintage to today's trade date, because that is genuinely the latest trade
date before its start. Correct and expected: an unstarted month has not converged.

### 2. The current period stays exact

Only the tail after `resetTailStart()` is repriced. `CanonicalContractPriceCalculator::resetTailStart()`
takes the **latest** of:

- the end of the cadence period containing the window start;
- the disclosed `recurring_schedule.current_period_end` (for a non-calendar period);
- the end of the latest coverage from a phase with a **dated** end.

A phase whose end is `none` is an open-ended claim, **not** a credible reset boundary: a product that
resets quarterly does not have a known price for twelve months. That shape is where most of the live
defect hid — 12 of 32 lineages, and the old code did not even mark them as an estimate fill because
the window looked fully covered. Do not "simplify" this by trusting `ends: none`.

### 3. Reference period by cadence

- `monthly` → the **month** contract for the month containing the anchor period.
- `quarterly` / `seasonal` → the **quarter** contract, falling back to `quarter_month_average` (the
  day-weighted average of that quarter's three month contracts).

`VintageAwareReferencePriceService::forResetPeriod()` supplies both candidates. Do not write a second
lookup for them.

**Which quarterly candidate resolves does not matter numerically, and this is verified.** On FI Base
production data, across **96** trade-date/maturity pairs where both exist, the quarter settlement and
the day-weighted average of its three month settlements agree to a mean absolute difference of
**0.002 EUR/MWh** and a maximum of **0.006 EUR/MWh (0.0007 c/kWh)**. An EEX quarter settlement *is*
the day-weighted average of its months. So `quarter_month_average` is an **exact reconstruction**, not
a degraded proxy.

That matters because EEX stops publishing a quarter contract a few trading days **before** delivery
begins, not on the first day of it: FI quarter `202607`'s last settlement is **2026-06-26**, while the
pricing vintage for a Q3 period starting 2026-07-01 is 2026-06-30. So all 25 quarterly lineages
resolve to `quarter_month_average` even with the pricing-vintage rule. **Do not add a look-back rule
to reach the direct quarter contract** — it would buy 0.0007 c/kWh of precision and add a second
vintage knob.

### 4. `F_m` uses the month → quarter → year ladder

Same ladder as `../../PriceForecasting/FixedTermHedgeCostService`, reusing its public
`maturityForMonth()` and `latestTradeDateBefore()`. **Do not refactor that service** — it runs on the
production 07:30 schedule and feeds immutable stored forecasts. A missing delivery month aborts the
forward shift entirely rather than silently holding one month flat inside a shifted estimate.

### 5. `beta` stays one global value

Per-company `beta` and per-company reference periods are documented future work; see the calibration
section in `../AGENTS.md`. The observed-reset sample cannot support them yet. Measured support for
1.0, on a month reference (`retail-premiums:calibrate`, production 2026-07-25): gated monthly headline
**0.81** (VAT incl.) / **0.94** (VAT excl.) across the two companies with at least 3 pass-through pairs —
Kokkolan Energia 1.01 and Pohjois-Karjalan Sähkö 0.61. That is −0.19 / −0.06 from the configured 1.0,
inside the 0.25 review threshold. Quarterly remains uncalibrated.

### 6. No deceptive-pricing label

Active recurring resets stay exempt. The price change is the **published mechanism** of the product,
not hidden promotional text. The existing suppression rule in `../ContractPricingIntegrityService.php`
is correct and must not be relaxed here. The "Arvio" marker plus the two-figure display carry the
uncertainty.

## Fallback ladder

`ResetEstimateBasis` records which rung produced the estimate, and it reaches the UI and
`contracts:compare-canonical-pricing --resets`.

| Rung | Basis | When |
|---|---|---|
| 1 | `forward_curve_shift` | a curve exists, is not staler than `max_curve_age_days`, the reference resolves, and every tail month resolves |
| 2 | `spot_seasonal_index` | no usable curve; `P_m = P_current * s_m / s_reference` from a multi-year realized-spot index. **Lower confidence** |
| 3 | `hold_flat` | no market data at all — the behaviour that existed before this estimator |

The seasonal index is deliberately last. Its realized monthly index has a year-to-year sd of about
**0.42** across 2022-2025 and **0.77-0.80** in the winter months that drive the correction. It is
better than flat but must never outrank an available curve. Do not promote it.

## Guards

- **Negative floor.** Each bucket rate is applied as `max(0, rate + offset)`, in `costSegment()` and
  `holdForwardTotal()`. A steeply falling curve can never produce a negative energy price.
- **Stale curve.** A **forward** vintage older than `max_curve_age_days` (default 14) drops to rung 2.
  A stale curve carries a stale shape, which is the one thing consumed here. This never applies to the
  reference vintage, which is legitimately old (rule 1).
- **Absurdity band only.** The resulting annual-equivalent energy price must sit inside an **absolute**
  band (`absurdity_band`, default 0-60 c/kWh). Outside it the estimate drops one rung and the reason is
  flagged.

  This is deliberately **not** a band against the fully-fixed retail market. An earlier version banded
  it to a multiple of the fully-fixed 12-month median, which quietly encoded the prior *"a market-reset
  product must be cheaper than a fixed deal"*. That prior is weak. Helen at 7.59 c/kWh against a
  4.03 c/kWh forward for the same month implies a spread near 3.6 c/kWh — entirely plausible for an
  incumbent with inert customers on a near-default product. If such a product's honest annual
  equivalent really is above a 10.47 c/kWh fixed deal, **that is a true and useful finding**, and
  suppressing it would be the same error as tuning an anchor until the output looked reasonable.
  `test_the_guard_does_not_suppress_a_reset_that_annualises_above_the_fixed_market` exists to break if
  a market-relative band comes back. The fully-fixed median is still read, but **only** as reported
  context in the comparison command.
- **Spot contracts are never shifted.** Moving Spot to a per-month vector is separate deferred work
  with a much smaller payoff.

## Residual uncertainty

The pricing vintage is a **proxy** for the date the seller actually set the period price, taken as the
last trade date before the period began. Sellers publish earlier than that: Cheap states the next
quarter's price is announced by the 15th of the preceding month, and Helen by the 15th of the preceding
month or the prior business day. So the proxy runs a couple of weeks late, and for the quarterly
cadence a mid-June pricing date would have read the Q3 reference around 43.5-44.3 EUR/MWh rather than
47.2.

That residual is exactly what the deferred **per-company calibration** identifies — the reference
period *and* the effective pricing date each seller uses, from observed resets. Do not guess at it
here; see the calibration section in `../AGENTS.md`.

## Files

- `MarketResetPriceEstimator.php` — the arithmetic, the ladder, and the guards. Container-free:
  settings arrive as `DTO/ResetEstimatorSettings`, market data through the provider seam.
- `MarketReferenceCurveProvider.php` — the market-data seam.
- `EexMarketReferenceCurveProvider.php` — FI EEX curve, realized-spot seasonal index, fixed-term
  median. Memoizes one curve per vintage; a listing rebuild otherwise costs hundreds of queries.
- `ResetEstimateCopy.php` — Finnish public copy, generated **only** from typed fields. No
  interpretation `summary` string ever reaches a user. Three surfaces: `cardEquivalent()` and
  `cardTooltip()` on a listing card, and `receiptNote()` on the contract detail page.
  `receiptNote()` deliberately states **only** what the detail page's other surfaces do not:
  that future period prices are unknown, when the estimated tail starts, and which forward
  vintage it reads. It replaced `detailNotice()`, a boxed notice that repeated the current
  price, its end date and the 12-month equivalent, all three of which the hero price
  qualifier and the dated receipt rows already state. Do not re-add a surface that restates
  the figures; check what the page already says first.
- `DTO/ResetEstimate.php` — offsets by `Y-m` plus the basis evidence, surfaced as
  `calculated_cost['reset_estimate']`.
- `DTO/ResetEstimateRequest.php` — cadence, both vintage anchors (`asOfDate` for the forward months,
  `currentPeriodStart` for the reference), tail months, anchor price, month weights.
- `Enums/ResetEstimateBasis.php` — which rung was used.

Caller: `../CanonicalContractPriceCalculator.php` (`resolveResetEstimate`, `resetTailStart`,
`resetPeriodStart`, `segmentMonthWeights`, `heldForwardMonthWeights`, `weightedEnergyPrice`).

Bindings: `app/Providers/AppServiceProvider.php`. The provider is a **singleton** (shared
memoization); the estimator is **not** (its settings are a config snapshot and a singleton would keep
a stale flag value).

## Flag and rollout

> **STATUS: LIVE.** `RESET_FORWARD_SHIFT_ENABLED=true` was set in production on **2026-07-25** and the
> page cache was cleared. These estimates are what visitors see and what rankings use. The config
> *default* below is still false, which only means a fresh environment starts disabled — do not read
> it as "not yet rolled out". Production effect at 5000 kWh: 38 reset lineages, 36 shifted, 2 fell
> back to hold flat, mean **+153 €/yr**, max **+255 €/yr**, every delta positive (the expected sign in
> July).

`RESET_FORWARD_SHIFT_ENABLED`, default **false**, in `config/canonical_pricing.php` under
`reset_forward_shift`.

It is a **separate** flag from `CANONICAL_PRICING_ENABLED`, which is already true in production and
therefore could not stage this change. With the flag off, behaviour is byte-identical to holding the
current period price flat, and the estimator touches no market data at all.

The flag **participates in the cache keys**, the same way the `c1`/`c0` canonical marker does:

- `ContractListCacheService` → `contract_list_metrics:v{n}:s{schema}:c{0,1}r{0,1}:{consumption}`
- `CompanyListCacheService` → `company_list:v{n}:s{schema}:lv{list-version}:c{0,1}r{0,1}:{consumption}`
- `ContractRankingService` → `contract_rankings_5000kwh:s{schema}:lv{list-version}:c{0,1}:r{0,1}`
- `Caching/ContractPageCacheVersion` → `reset_forward_shift_enabled`

Without this a stale hold-flat payload would survive the flip.

**Caveat:** the `c`/`r` markers track flags, not code. A code-only change to reset maths or aggregate
membership must bump every affected payload schema marker (`ContractListCacheService`,
`CompanyListCacheService`, `ContractRankingService`, and/or `ContractPageCacheVersion`). Otherwise an
old payload can survive until its TTL or the next import-driven cache clear.

Staging command:

```bash
php artisan contracts:compare-canonical-pricing --resets --consumption=5000
php artisan contracts:compare-canonical-pricing --resets --json=storage/app/reset-diff.json
```

It costs hold-flat and shifted side by side in one process, independent of the deployed flag, and
prints per contract: current price, reference kind, reference vintage, hold-flat total, shifted total,
delta in euros, and the implied annual-equivalent energy price. It needs a current FI curve; refresh a stale local
snapshot with `php artisan futures:backfill-eex --area=FI` (throttled, several minutes).

## UI contract

- The total stays marked **"Arvio"**.
- The card shows two figures in the energy column: `{label} nyt` (the known current-period price) and
  a quieter `12 kk arvio {x} c/kWh` below it, with a tooltip stating the basis.
- The detail page shows a **neutral** (not amber) notice after the hero: heading
  "Hinta tarkistetaan {kuukausittain|neljännesvuosittain|kausittain}", then the current-period price,
  the 12-month estimate, when the estimated part starts, and the basis with the curve date.
- Never present the estimate as a contractual price, and never render it in amber — a published reset
  mechanism is not deceptive pricing.

## Why `baseTotalCost` and `structuredOnlyTotal` also carry the shift

`baseTotalCost` drives the card's "Säästö" / "ilman tarjousta" copy, and
`structuredOnlyTotal` drives the integrity label's euro impact. If only `totalCost` were shifted, a
winter reset would show a **fabricated discount**, and a reset that does carry conflict codes would
report an impact mixing the promo effect with the seasonal repricing. Both totals therefore get the
same offsets, so their difference keeps measuring only the promotional effect. The promotion-free
pass also replaces eligible canonical component amounts with `normal_amount` only after the one
shared reset estimate is resolved; it never builds a second curve shift from the normal price. A
fully covered Hybrid now costs all disclosed base-price phases and uses the normal segment-based
reset path. Only an uncovered Hybrid still uses the one-phase held-forward fallback.
Pinned by `tests/Unit/CanonicalPricing/MarketResetForwardShiftTest.php`.

## Tests

- `tests/Unit/CanonicalPricing/MarketResetForwardShiftTest.php` — the ladder, the guards, and the
  arithmetic against a fake curve. Includes flag-off byte-identity and the negative floor.
- `tests/Feature/EexMarketReferenceCurveProviderTest.php` — per-lookup vintage resolution, no
  same-day leakage, the month/quarter/year ladder, the quarter before and during delivery, the
  seasonal index.
- `tests/Feature/MarketResetEstimateSurfacesTest.php` — cache-key participation, container wiring,
  and the Finnish copy.
