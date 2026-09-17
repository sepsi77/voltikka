# Market-reset annualised price (forward-curve shift)

> **Approved target, implemented locally; release blocked:** read the [annualized comparison policy (2026-09-15)](../AGENTS.md#approved-annualized-comparison-policy-2026-09-15) first. It governs intended future changes where older routing, own-reference fallback, or calibration constraints below conflict. The implementation and dated rollout notes below do not establish deployment of the new policy. Known-period, VAT, dated-evidence, billing, and cache safeguards remain; no production mutation is authorized by this documentation.

This directory annualises **market-reset** contracts — `canonical_pricing.recurring_schedule.present
= true` with cadence `monthly`, `quarterly`, `seasonal`, or `other`. Those products publish one price
per period and follow the wholesale market between periods. Cadence `other` means that the source
confirms recurring resets but does not publish exact calendar boundaries; it uses the quarterly
calendar and reference proxy and remains an estimate.

It fixes a live production defect: the calculator used to hold the current period price flat for
twelve months. That price is a **seasonal** price, so the annual estimate was systematically too low
in summer and too high in winter. Measured on the local 2026-07-24 snapshot at 5000 kWh, the
correction across all 32 reset lineages averaged **+149 €/yr**, up to **+255 €/yr**.

Read `../AGENTS.md` first, then `tasks/market-reset-annualised-pricing/spec.md` and its
`decisions.md`. That decisions file records several **explicitly retracted** conclusions; do not
re-derive them.

## Current missing-reference premium fallback (local, 2026-09-15)

Current pricing keeps the original own reference first. It never substitutes today's vintage for
an unavailable old-period reference. A complete, fresh current curve for the target's actual tail
can instead use the shared `ForwardPremium/CurrentPremiumEvidenceLoader`: own trusted lineage,
same company, then company-balanced comparable market evidence. Missing premium evidence still
falls through to seasonal and hold pricing. The old fallback-to-today behavior is Historical only.
`ResetEstimateRequest::policy` defaults to Historical for old direct callers; every current core
call passes Current explicitly. Existing original-reference arithmetic and beta remain unchanged.

`CanonicalContractPriceCalculator::resetPremiumCandidate` is a consumption-free typed frame. It
reuses the core rate resolver, timeline, reset tail boundary and reference-period start. It requires
complete named General/Time/Season energy rates, exact VAT and cadence, known initial coverage,
and unchanged energy across known phases. Fully disclosed fee-only phases qualify. It rejects
packages, Spot, ambiguous tariffs, energy promotions and an unknown gap before a known
future phase. Current OpenEnded and FixedTerm explicit `base_contract` consumption effects can
use this same proof for their known base rates when the canonical recurring reset is active.
A fixed contract term does not lock energy prices under that explicit reset mechanism. The short
Hybrid call passes the selected reset premium, and candidate/billing horizons clip to the real
term before annualization. Fully locked Hybrid Fixed6/12/24 and supplier-adjusted OpenEnded-only
eligibility remain unchanged. Raw Hybrid and canonical base-effect FixedPrice normalize to
Hybrid. Complete canonical base evidence is still required; effects are excluded, never set to
zero. `MarketResetHybridBase` premiums cannot mix with ordinary reset or supplier families, and
cadence, tariff and VAT still match exactly. The candidate and request carry `pricingMechanism`.
Known periods keep the existing tail/reference dates, not supplier episode-month dates. The real
forecaster supplies the primary estimate method while BaseOnlyHybrid and effect flags remain.
Fixed-term guarantees, Historical, flags and beta are unchanged. Current calculated-cost schema is 19. See
`tasks/annualized-pricing-implementation/hybrid-projection.md` for bounded local verification. Candidate proof now shares `candidateApplicablePhases` with the supplier path:
components and actual/normal rates use only phases already applicable at the phase's first covered
date. A billed monthly fee is not proof of current energy, and a missing Time/Season bucket cannot
come from a genuinely Future phase. The one exception is a fee-only typed Introductory phase with
an adjacent typed Normal baseline. A current-structured fee phase, Future baseline or non-adjacent
normal phase does not get this exception. An expired absolute-end phase can use its last covered
past day for this same full-energy equality proof. That candidate-only date is never a pricing
anchor. Different or unknown old energy still fails; future phases outside the window are not
skipped. Fee expiry therefore cannot remove an otherwise unchanged-energy candidate.
This guard changes candidate evidence only; the older
billing inheritance algorithm is not repaired by this slice. It does not create an undisclosed
normal baseline. Those wider plans remain outside this slice. Current six-month resets use only real-term tail months, then annualize real-term costs.
Ordinary locked 6/12/24-month pricing and Historical short-term routing are unchanged.

A selected premium produces `beta * (F_month * VAT + premium_bucket - anchor_bucket)` per bucket.
The named tariff mapping is shared with the supplier estimate. Actual, normal and structured bills
use the same offsets and exact `tailStartsOn` guard. Zero floors and the broad plausibility band
remain. The new guard uses actual whole-window and tail bucket weights, including the known part
of a split month. Displayed equivalents still use billed energy and actual costed kWh. For a
mid-month six-month term, those kWh need not equal exactly half the annual profile.

Method `recurring_forward_premium`, basis `forward_premium`, and policy
`recurring_forward_premium_v1` identify the changed finance. The typed payload retains selected
premium source, dates, counts, confidence and model intervals. Public copy states current futures
and comparable retail-price evidence, not unavailable futures. Hold remains an explicit recurring
estimate even when an open-ended phase appears to cover the full window. Current calculated-cost schema is 19 and the
configured annual method is unchanged. Exact-period bills receive no projected rates.

Tests and scope: `tasks/annualized-pricing-implementation/reset-premium-integration.md`.
Real EEX tests prove reuse for repeated identical period/vintage keys. They do not prove constant
SQL across distinct reference dates: the existing vintage reference service reads each distinct
reference. A release performance check must measure that cost. No broad EEX refactor is included.

## Annual segment integration

`ResetEstimate::tailStartsOn` carries the exact exclusive current-period boundary internally.
The annual calculator splits at this date and applies monthly offsets only to segments on or
after it. A mid-month boundary must not shift the known part of that month. The public
`annual_equivalent_energy_price` is costed energy EUR × 100 / costed kWh, from the same tariff
buckets and segments as the annual bill. Disclosed reset phases without explicit normal-amount
discounts do not create offer savings. Exact-period calculation receives no annual projection.

## Retry-local market evidence

`EexMarketReferenceCurveProvider::resetMemoization()` clears trade dates, curves, references, seasonal indices, and the fixed-term median. The canonical orchestrator calls it on the shared provider before price-cache retries and full candidate builds, so an EEX invalidation cannot make a new generation reuse an earlier request-local curve. Normal reads still memoize within an attempt. No market formula, vintage rule, or persistent data changes. `EexMarketReferenceCurveProviderTest` verifies a previously missing curve is reloaded through the list-cache reset boundary.

## Original-reference estimator (unchanged financial formula)

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
- `F_reference` → latest `trade_date < min(current period start, target date)`.

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
price) keeps its actual delivery anchor, but its reference lookup is bounded by the target date.
`reference_vintage_bounded_by_as_of` records this bound. Later stored futures must not enter a
historical calculation. Here and in `reference_vintage_fallback_today`, “today” means the request's
`asOfDate`, never the process clock.

### 2. The current period stays exact

Only the tail after `resetTailStart()` is repriced. `CanonicalContractPriceCalculator::resetTailStart()`
takes the **latest** of:

- the end of the cadence period containing the window start;
- the disclosed `recurring_schedule.current_period_end` (for a non-calendar period);
- the end of the latest finite known energy coverage, excluding fee-only transitions.

A phase whose end is `none` is an open-ended claim, **not** a credible reset boundary: a product that
resets quarterly does not have a known price for twelve months. That shape is where most of the live
defect hid — 12 of 32 lineages, and the old code did not even mark them as an estimate fill because
the window looked fully covered. Do not "simplify" this by trusting `ends: none`.

A finite phase end followed immediately by a phase with the same resolved energy buckets,
Spot/fixed mechanism, and margin is a fee-only transition when only the resolved monthly or one-off
fee changes. It does not extend energy coverage. Use `resolvePhaseRates()` so missing components,
explicit zero overrides, duplicate-rate rules, and Time/Season bucket inheritance match billing.
Do not use labels, raw component counts, or a weighted average to test equality. Unresolved rates
and packages remain conservative; an explicit `period_boundary`, a declared recurring end, a finite
phase followed by a gap, and a genuine energy change keep their boundaries. Fee amounts, offer
terms, and fee savings still use the complete original timeline.

The September 11, 2026 Aalto Huoleton (7.69 c/kWh, quarterly) and Kuukausihinta (8.98 c/kWh,
monthly) first-month fee waiver must keep the Q3/September energy reference and June 30/August 31
pricing vintages. The old fee boundary at October 11 selected Q4/October and could create false
zero-floor months. The floor itself is unchanged. Schema v15 invalidates cached annual results.
New daily statistics can change after this code is released; stored historical annual rows and
aggregates are not rewritten by cache invalidation. A historical rebuild needs a separate reviewed
plan and explicit write approval. Do not infer a real seller price cut from that method correction.

Existing timeline limits are unchanged: offsets apply to calendar-month keys, even for a genuine
mid-month energy boundary. `monthly_costs` groups each slice by its start's elapsed month, so an
extra fee slice can move part of October between display bins without changing energy pricing or
the annual sum. Regression tests check the exact current grouping, not calendar-month equality.

### 3. Reference period by cadence

- `monthly` → the **month** contract for the month containing the anchor period.
- `quarterly` / `seasonal` / `other` → the **quarter** contract, falling back to
  `quarter_month_average` (the day-weighted average of that quarter's three month contracts).
  `other` also uses the Q3-to-Q4 calendar boundary, for example, because no exact boundary exists.

`VintageAwareReferencePriceService::forResetPeriod()` supplies both candidates to full retail-premium
callers. The shared market provider uses `forResetPeriodAtTradeDate()` with its already-resolved
vintage and only the requested candidate kinds. Thus a monthly reference does not calculate the
quarter-month average. Do not write a second lookup for these candidates.

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

### Seasonal reference period (schema v17)

The seasonal fallback uses the exact anchor month's index for `monthly`. For `quarterly`,
`seasonal`, and `other`, it uses the calendar-day-weighted mean of all three indices in the
containing quarter, with day counts from the anchor year (including leap February). The anchor
month is only a month inside the known period: a Q2 price anchored in June is not a June price.
This matches the forward reference's cadence and the quarterly proxy for `other`. Missing,
non-finite, or nonpositive required reference or tail indices fall through to hold flat.
The multiplicative formula, global beta, current-period exactness, dated inputs, forward priority,
and absolute guards stay unchanged. Supplier-adjusted monthly references do not change.
Schema v17 invalidates cached calculated costs after deployed v16; no stored statistics are rewritten.
Tests cover all three nonmonthly cadences, anchor-month invariance, leap weighting, and June-to-July
shared-calculator and historical AsOfV2 results. See `tasks/annual-statistics-v2-rollout/seasonal-reference-fix.md`.

### Dated input rule (2026-09-11)

`spotSeasonalIndex(CarbonImmutable $asOfDate): ?array` requires an explicit target. Both estimators
pass their request date. Only Helsinki calendar months before the target month with declared
`period_end < target date` contribute; even a stored whole-month average for the current month is
excluded. Raw DATE/datetime columns supply calendar bounds, without model-cast timezone shifts.
The four-year window starts from the latest eligible completed month, not the latest stored row.
Finite positive prices use the existing yearly normalization, minimum years, and full 12-month
coverage rules. Cache entries (including null) are keyed by Helsinki target date. Missing history
still falls through to hold flat; no Spot observations are changed.

## Remaining local audit review

Supplier/Reset hold-beta, floor, vintage metadata and guard review remain open. The completed
SourceEnergyRule floor-flag repair does not prove that all estimator disclosures are correct.
Seasonal anchor weight policy also remains unresolved. No guard, beta, flag or Spot policy change
is authorized by these notes. See `tasks/source-validated-energy-rules/audit-fixes.md`.

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
  median. One ordered/distinct scalar query loads all available FI Base trade dates for the scoped
  provider lifetime. Strict `latest trade_date < as-of date` resolution then happens in memory,
  including memoized null misses. It also memoizes one curve per vintage. Exact-vintage reads use an
  index-friendly half-open `trade_date` range, so SQLite date strings and datetimes both work while
  MySQL keeps direct DATE/index comparisons. This request/command/job-scoped shared market-data state
  prevents many supplier episode starts from issuing repeated `MAX(trade_date)` queries during a
  listing rebuild, then resets at the next Octane or queue lifecycle boundary.
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
  `currentPeriodStart` for the reference), tail months, anchor price, month weights. It maps `other`
  to the same quarter / quarter-month-average preference as quarterly and seasonal cadences.
- `Enums/ResetEstimateBasis.php` — which rung was used.

Caller: `../CanonicalContractPriceCalculator.php` (`resolveResetEstimate`, `resetTailStart`,
`resetPeriodStart`, `segmentMonthWeights`, `heldForwardMonthWeights`, `weightedEnergyPrice`).

Bindings: `app/Providers/AppServiceProvider.php`. The curve provider is **scoped**: one instance
shares memoization across a request, command, or job, and Laravel flushes it for the next Octane or
queue lifecycle boundary so its available-date list cannot stay stale. `PricingMode`, reset settings,
the estimator, and the calculator use the same scoped lifetime and one immutable flag snapshot.
`CanonicalContractPricingService` remains transient because `withSpotAssumptions()` stores
caller-specific state.

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
therefore could not stage this change. With the flag off, the reset path holds the
current period price flat and makes no reset-estimator market-data reads. This is not a global
estimator switch: Supplier estimates share numerical settings but not this enable flag.
Historical deliberately retains narrower identity/reference behavior; current shared lineage
and settings do not imply universal estimator parity.

The flag participates in `PricingMode::cacheMarker()` together with canonical state:

- `ContractListCacheService` → `contract_list_metrics:v{n}:s{calculated-cost-schema}:c{0,1}r{0,1}:{consumption}`
- `CompanyListCacheService` → `company_list:v{n}:s{outer-schema}:cs{calculated-cost-schema}:lv{list-version}:c{0,1}r{0,1}:{consumption}`
- `ContractRankingService` → `contract_rankings_5000kwh:s{outer-schema}:cs{calculated-cost-schema}:lv{list-version}:c{0,1}r{0,1}`
- `Caching/ContractPageCacheVersion` → `pricing_mode` and `calculated_cost_schema`

Without this a stale hold-flat payload would survive the flip.

**Caveat:** the pricing-mode marker tracks flags, not code. A code-only calculated-cost shape change
must bump `CalculatedCostPayloadSchema::VERSION` once. Service-specific wrapper versions stay
separate and move only when their own membership or fields change.

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
  "Hinta tarkistetaan {kuukausittain|neljännesvuosittain|kausittain|jaksoittain}", then the current-period price,
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
