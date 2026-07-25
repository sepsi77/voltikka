# Seasonally correct annualised cost for market-reset contracts

## Build order

`../retail-premium-dataset/` runs **first** and is being implemented separately. It owns the
vintage-aware market reference-price lookup and the lineage price-history helper. Consume both from
there; do not rebuild them. It also produces the per-company reference and `beta` observations that
this task is blocked on. See that task's `decisions.md` for the build-order reasoning.

## Problem

Market-reset contracts (`canonical_pricing.recurring_schedule.present = true`, cadence
`monthly` / `quarterly` / `seasonal`) set a new energy price for each period. Inside the period
they behave like a fixed-price contract. Between periods the price follows the wholesale market.

`CanonicalContractPriceCalculator` fills the uncovered window tail by holding the current period
price forward for the whole 12 months (`EstimateMethod::HoldCurrentRecurringPrice`, around
`CanonicalContractPriceCalculator.php:172`). The current period price is a seasonal price. The
result is a large, systematic error:

- in summer the annual estimate is much too low;
- in winter the annual estimate is much too high.

The error is not small. Measured with 2026-07-24 contract prices, 5000 kWh, General metering, and
the live production FI forward curve of the same trade date:

| Contract | Current price | Hold-flat | Forward-shift | Error | Annual-equivalent price |
|---|---|---|---|---|---|
| Kokkolan Energia Tyyni (monthly) | 4.98 c/kWh | 279 €/yr | 588 €/yr | −308 € | 11.14 c/kWh |
| Korpela Kvartaali (quarterly) | 5.54 c/kWh | 315 €/yr | 435 €/yr | −121 € | 7.95 c/kWh |
| Helen Markkinahintasähkö (monthly) | 7.59 c/kWh | 427 €/yr | 735 €/yr | −308 € | 13.75 c/kWh |
| Cheap Kvartaalisähkö (quarterly) | 7.49 c/kWh | 433 €/yr | 554 €/yr | −121 € | 9.90 c/kWh |
| Kokkolan Vuodenaika (quarterly) | 7.97 c/kWh | 429 €/yr | 550 €/yr | −121 € | 10.38 c/kWh |

For scale: genuinely fully-fixed 12-month household contracts
(`pricing_model = FixedPrice` and `canonical_calculation.status = exact`) had a median energy
price of 10.48 c/kWh on the same date, while Tyyni showed 4.98 c/kWh. Market-reset contracts
therefore win the summer rankings for no real reason, and they will lose the winter rankings for
no real reason.

The reference period matters, and July 2026 is an extreme case. The FI month future for July 2026
is 2.45 c/kWh incl. VAT, the Q3/2026 month strip is 6.20 c/kWh, and the 12-month strip is
8.61 c/kWh. A monthly reset anchored to the July month future therefore gets a +6.16 c/kWh
correction, while a quarterly reset anchored to the Q3 strip gets +2.41 c/kWh. Read the risk note
in `decisions.md` before you choose the reference period.

Scope: 32 interpreted contracts in the local database (7 monthly, 22 quarterly `FixedPrice`,
3 quarterly `Hybrid`). All of them have `future_price_known = false`, so the next period price is
never available from the source.

## Method: shape-only forward-curve shift

Keep the known current period exact. Replace only the held-forward tail. For each later month `m`
in the 12-month window:

```
P_m = P_current_period + beta * (F_m - F_current_period)
```

- `F_x` is the FI EEX futures settlement price for delivery period `x`, converted to c/kWh
  including VAT (`settlement_price / 10 * config('price_forecasting.fixed_term.vat_multiplier')`).
- `beta` is a pass-through coefficient. Default 1.0.
- Cost each month with the existing per-month usage profile from `MonthlyUsageProfileBuilder`.

`PhaseTimelineBuilder` already cuts the window at calendar-month boundaries, so the calculator
needs only a per-month energy-price offset for the filled segments.

### Why this exact form

**It uses only differences in the curve.** It imports the seasonal *shape* of the curve and not
the price *level*. A uniform shift of the whole curve cancels out. The estimate therefore stays
anchored on the provider's own real disclosed price, and it is robust to a wrong or stale curve
level. It also keeps market-reset contracts on a comparable basis with Spot contracts, which stay
anchored to observed rolling-365 spot.

**The reference must be a forward price, not realized spot.** A reset price is a forward price
for the coming period. It is set before the period starts, from the forward market as it stood at
that time. Do not de-seasonalise against realized spot, because a realized average for a period
that is still in delivery is not the price the provider priced from, and it moves for a different
reason. Use the futures curve as the period reference.

**Do not anchor to the fixed-term retail market.** A fixed-price contract includes a hedging
premium that a market-reset customer does not pay. Anchoring reset contracts to the fixed-term
median would over-price them by design.

**Do not use the contract's own price history as the primary source.** The same product exists
under many contract ids (10 rows are named "Tyyni"), so the per-contract price series is
fragmented and often only weeks deep. Keep own-price history for validation only.

### Reference-price fallback ladder

Record which step produced the estimate, so every UI surface can state the basis:

1. Month futures for the delivery month (only about 6 month maturities are published).
2. Quarter futures, applied **flat across the three months of the quarter**. Window months 7-12
   normally need this step. Do not disaggregate a quarter into months with a historical shape; the
   within-quarter ratio is nearly as unstable as the full-year index (mean sd 0.33 against 0.42).
   See `decisions.md`.
3. Year futures, applied flat across the year.
4. Multiplicative seasonal index from **multi-year** spot history:
   `P_m = P_current * (s_m / s_current)`. Last resort only, marked as a lower-confidence basis. It
   is better than a flat estimate, but the realized index has a year-to-year sd of about 0.42, and
   0.77-0.80 in the winter months that matter most.
5. Hold flat (current behaviour). Use only when every step above fails.

The futures curve is the primary source because it states which year we are in. The realized
history is not a better curve — see the measurements in `decisions.md`.

Reuse the month → quarter → year fallback logic in
`App\Services\PriceForecasting\FixedTermHedgeCostService` instead of writing a second copy.

### Guards

- Never let an adjusted energy price fall below 0.
- Reject a curve that is stale beyond a configured threshold and fall back to step 4.
- Fall back and flag if the resulting annual equivalent falls outside a plausible band against the
  fixed-term retail market.
- Keep `beta` configurable. Do not change it from 1.0 before it is measured. Full pass-through is
  the correct prior for a provider that hedges one month or one quarter ahead, but some providers
  smooth their resets.

## User interface requirements

- The total stays marked "Arvio". It is a market expectation and not a promise.
- Show the known current-period price and the estimated 12-month equivalent as two separate
  figures on the contract card and on the detail page.
- The same change applies to `/sahkosopimus/kvartaalisahko`, because these contracts are its
  content.
- Do not add a deceptive-pricing label. These are legitimate market products. The existing
  suppression rule for active recurring resets stays as it is.

## Validation

- Extend `contracts:compare-canonical-pricing` to show before/after totals for the affected
  contracts.
- Store the per-month estimated reset prices, then compare them with the reset prices that we
  observe later. Mirror the pattern in `forecasting:evaluate-fixed-contracts`. This gives a
  measured error and an empirical `beta`.
- Add regression tests: monthly reset in summer, quarterly reset in winter, missing futures data
  (fallback to the spot seasonal index), and completely missing market data (hold flat).

## Preconditions

The method needs a current FI forward curve. **Verified on 2026-07-25:** the EEX endpoint and the
collector both work, and production is current (latest `trade_date` 2026-07-24). Only the local
development snapshot is stale; refresh it with `futures:backfill-eex` before local work.

## Reference period and averaging rule

Use the **latest available settlement** with `trade_date < today`. Do not use a long trailing
average: daily noise is only about 0.112 c/kWh per maturity, while the curve trends enough that a
30-day average would add more lag bias than the noise it removes. A short 3-5 day median is
allowed purely as an outlier guard.

The reference period is **not settled**. It is mathematically the same knob as `beta`
(`annual = P_current + beta * (F_strip - F_reference)`), so it must be identified from observed
reset behaviour and not chosen by judgement. Anchoring Tyyni on the Q3 strip is identical to
anchoring it on the front month with `beta = 0.39`. Until it is measured, use the quarter strip as
the **conservative provisional** choice, because it understates the correction rather than
overstating it, and understating is the safer error when the output is a public ranking of named
companies. See `decisions.md`.

Before this ships, classify the 32 contracts by observed price behaviour. Early evidence suggests
two populations: genuine market trackers that reset every period, and nominally-declared resets
whose price is sticky in practice (Aalto Kuukausihinta held one price for 136 days). One anchor and
one `beta` cannot serve both. The measurement must follow `replaced_by_contract_id` chains, because
a reset may appear as a new contract id.

## Second phase: Spot contracts

Spot contracts use one flat rolling-365 spot average for all twelve months. Replace it with the
same per-month price vector, so Spot, market-reset, and fixed contracts share one mechanism.
Anchor the level on rolling-365 spot and take only the shape from the curve:

```
P_m = S_rolling365 + beta * (F_m - F_strip12m)
```

Expect a small gain. The measured spot profile cost ranges from −0.3 % to +8.2 % across 2022-2025
(−3 to +27 €/yr at 5000 kWh), and the live curve implies +4.0 % (+17 €/yr) for the coming twelve
months. The correction is exactly zero for the flat default usage profile. Take the coming year's
profile cost from the curve, not from a trailing year. Two related decisions are deliberately kept
out of this phase:

- whether the spot level anchor moves from rolling-365 to the forward strip (about +20 % on every
  spot estimate);
- whether the monthly usage profile becomes winter-weighted for `General` and `Time` metering
  (this changes the ranking of every contract, not only Spot).

See `decisions.md` for the measurements behind both.
