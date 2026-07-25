# Market-reset annualised price (shape-only forward-curve shift)

This directory annualises **market-reset** contracts — `canonical_pricing.recurring_schedule.present
= true` with cadence `monthly`, `quarterly`, or `seasonal`. Those products publish one price per
period and follow the wholesale market between periods.

It fixes a live production defect: the calculator used to hold the current period price flat for
twelve months. That price is a **seasonal** price, so the annual estimate was systematically too low
in summer and too high in winter. Measured on the local 2026-07-24 snapshot at 5000 kWh, the
correction across all 32 reset lineages averaged **+154 €/yr**, up to **+333 €/yr**.

Read `../AGENTS.md` first, then `tasks/market-reset-annualised-pricing/spec.md` and its
`decisions.md`. That decisions file records several **explicitly retracted** conclusions; do not
re-derive them.

## The estimator

```
P_m = P_current_period + beta * (F_m - F_reference)
```

- `P_current_period` — the consumption-weighted energy price of the rates the calculator would have
  held forward. Contract-specific and never estimated: it is the provider's own published price.
- `F_m` — FI EEX Base settlement price for delivery month `m`, c/kWh incl. VAT
  (`settlement / 10 * config('price_forecasting.fixed_term.vat_multiplier')`).
- `F_reference` — the settlement price for the delivery period the current price applies to.
- `beta` — pass-through coefficient, **one global value**, `config('canonical_pricing.reset_forward_shift.beta')`.

Only *differences* on the curve are used, so the estimator imports the seasonal **shape** and not
the price **level**. A uniform curve error cancels out. That is also why it stays comparable with
Spot contracts, which remain anchored on observed rolling-365 spot.

## Non-negotiable rules

### 1. ONE curve vintage for both `F_m` and `F_reference`

Every lookup resolves the same vintage: the latest `trade_date < asOfDate` (the window start).
`EexMarketReferenceCurveProvider` loads that whole curve once and memoizes it.

**Do not** switch `F_reference` to the pre-period vintage. `../../RetailPremium/` deliberately uses
the pre-period vintage because it measures the seller's spread at the moment they priced. This
estimator needs a pure shape difference on one consistent curve; mixing vintages reintroduces
exactly the level drift the design cancels.

The cost of the rule is measured and accepted, not unknown — see "Known bias" below.

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

**In practice `quarter` almost never resolves.** EEX stops publishing a quarter contract once that
quarter enters delivery, and this estimator reads *today's* vintage, which is inside the current
quarter for all but the last days before a quarter starts. On 2026-07-25 all 25 quarterly lineages
resolved to `quarter_month_average`. That is correct behaviour, not a fallback failure.

### 4. `F_m` uses the month → quarter → year ladder

Same ladder as `../../PriceForecasting/FixedTermHedgeCostService`, reusing its public
`maturityForMonth()` and `latestTradeDateBefore()`. **Do not refactor that service** — it runs on the
production 07:30 schedule and feeds immutable stored forecasts. A missing delivery month aborts the
forward shift entirely rather than silently holding one month flat inside a shifted estimate.

### 5. `beta` stays one global value

Per-company `beta` and per-company reference periods are documented future work; see the calibration
section in `../AGENTS.md`. The observed-reset sample cannot support them yet. Measured support for
1.0, both on a month reference: Pohjois-Karjalan Sähkö **0.90** (R² 0.99), Kokkolan Energia **1.01**
(R² 0.66).

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
- **Stale curve.** A vintage older than `max_curve_age_days` (default 14) drops to rung 2. A stale
  curve carries a stale *shape*, which is the one thing consumed here.
- **Plausibility band.** The resulting annual-equivalent energy price must sit inside
  `[min_multiple, max_multiple]` × the fully-fixed 12-month retail median (10.48 c/kWh on
  2026-07-24, read from `contract_price_daily_statistics`, which is **read-only** here), and inside
  the absolute band. Outside it the estimate drops one rung and the reason is flagged.
- **Spot contracts are never shifted.** Moving Spot to a per-month vector is separate deferred work
  with a much smaller payoff.

## Known bias, measured and accepted

The one-vintage rule means a **monthly** cadence reads its reference from the month currently *in
delivery*, whose future has largely converged to realized spot. That converged price is not what the
seller priced against, so `P_current - F_reference` overstates the spread.

Measured on FI month 202607: **4.03 c/kWh** on 2026-06-30 (when July prices were set) against
**2.45 c/kWh** on 2026-07-24. A **1.58 c/kWh** drift, about **+79 €/yr** at 5000 kWh on every
monthly-cadence reset. This is why monthly resets currently annualise *above* the fully-fixed market
median (12.5-13.9 c/kWh against 10.47).

The quarterly cadence is barely affected: the Q3 month-average reference moved only 5.92 → 6.15
c/kWh over the same period (about −11 €/yr), because two of its three months are still forward.

Do not "fix" this by moving `F_reference` to the pre-period vintage — that is rule 1, and the level
drift it would reintroduce is larger and not self-cancelling. The proper fix is the deferred
per-company calibration, which identifies `beta` and the reference from observed resets at the
vintage the price was set.

## Files

- `MarketResetPriceEstimator.php` — the arithmetic, the ladder, and the guards. Container-free:
  settings arrive as `DTO/ResetEstimatorSettings`, market data through the provider seam.
- `MarketReferenceCurveProvider.php` — the market-data seam.
- `EexMarketReferenceCurveProvider.php` — FI EEX curve, realized-spot seasonal index, fixed-term
  median. Memoizes one curve per vintage; a listing rebuild otherwise costs hundreds of queries.
- `ResetEstimateCopy.php` — Finnish public copy, generated **only** from typed fields. No
  interpretation `summary` string ever reaches a user.
- `DTO/ResetEstimate.php` — offsets by `Y-m` plus the basis evidence, surfaced as
  `calculated_cost['reset_estimate']`.
- `DTO/ResetEstimateRequest.php` — cadence, vintage anchor, tail months, anchor price, month weights.
- `Enums/ResetEstimateBasis.php` — which rung was used.

Caller: `../CanonicalContractPriceCalculator.php` (`resolveResetEstimate`, `resetTailStart`,
`segmentMonthWeights`, `heldForwardMonthWeights`, `weightedEnergyPrice`).

Bindings: `app/Providers/AppServiceProvider.php`. The provider is a **singleton** (shared
memoization); the estimator is **not** (its settings are a config snapshot and a singleton would keep
a stale flag value).

## Flag and rollout

`RESET_FORWARD_SHIFT_ENABLED`, default **false**, in `config/canonical_pricing.php` under
`reset_forward_shift`.

It is a **separate** flag from `CANONICAL_PRICING_ENABLED`, which is already true in production and
therefore cannot stage this change. With the flag off, behaviour is byte-identical to holding the
current period price flat, and the estimator touches no market data at all.

The flag **participates in the cache keys**, the same way the `c1`/`c0` canonical marker does:

- `ContractListCacheService` → `contract_list_metrics:v{n}:c{0,1}r{0,1}:{consumption}`
- `ContractRankingService` → `contract_rankings_5000kwh:c{0,1}:r{0,1}`
- `Caching/ContractPageCacheVersion` → `reset_forward_shift_enabled`

Without this a stale hold-flat payload would survive the flip.

Staging command:

```bash
php artisan contracts:compare-canonical-pricing --resets --consumption=5000
php artisan contracts:compare-canonical-pricing --resets --json=storage/app/reset-diff.json
```

It costs hold-flat and shifted side by side in one process, independent of the deployed flag, and
prints per contract: current price, reference kind, hold-flat total, shifted total, delta in euros,
and the implied annual-equivalent energy price. It needs a current FI curve; refresh a stale local
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
same offsets, so their difference keeps measuring only the promotional effect. Pinned by
`tests/Unit/CanonicalPricing/MarketResetForwardShiftTest.php`.

## Tests

- `tests/Unit/CanonicalPricing/MarketResetForwardShiftTest.php` — the ladder, the guards, and the
  arithmetic against a fake curve. Includes flag-off byte-identity and the negative floor.
- `tests/Feature/EexMarketReferenceCurveProviderTest.php` — the one-vintage rule, no same-day
  leakage, the month/quarter/year ladder, the in-delivery quarter, the seasonal index.
- `tests/Feature/MarketResetEstimateSurfacesTest.php` — cache-key participation, container wiring,
  and the Finnish copy.
