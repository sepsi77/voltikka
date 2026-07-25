# Decisions — contract card redesign

## Audit findings that motivated the change

### The footer callouts were inconsistent by construction

- `:percentiles` was passed by exactly one caller, `seo-contracts-list.blade.php`. The same
  contract showed "Edullinen marginaali" on `/sahkosopimus/porssisahko` and nothing on
  `/sahkosopimus`, the homepage, `/halvin-sahkosopimus`, or a company page.
- The callout switch used `$contract->pricing_model`, but that column holds only
  `FixedPrice`, `Hybrid`, `Spot` (verified against the database). The `Seasonal` and
  `TimeOfUse` cases were unreachable, so `contracts:calculate-percentiles` computed
  `seasonal_winter` and `time_day` thresholds that nothing could read.
- Non-featured cards kept one callout via `array_slice`. The energy callout was appended
  first, so the monthly-fee callout appeared only when there was no energy callout.
- The callouts repeated numbers already on the card and could contradict the sort order: a
  card at rank 3 could say "Kallis perusmaksu".

The percentile buckets are still calculated by `contracts:calculate-percentiles` and are
still used elsewhere; only the card callouts are gone. `ContractsList::getPercentiles()`
remains for other consumers.

### The estimate marker was in three places at once

Spot contracts showed `· arvio` after the €/v figure **and** an `Arvio` footer tag. Market
resets showed `12 kk arvio X c/kWh` in the energy column **and** the same footer tag.
Term-only and hybrid base-only contracts had footer text but no marker on the number at
all. The featured card had no marker except a spot-only caption.

### `x-info-tip` cannot hold a link

Its teleported bubble is `pointer-events-none`, so a pointer can never reach a link inside
it. That is correct for a plain tooltip and wrong for the estimate explanation, which has
to link to `/tietoa#menetelma`. Hence the separate `x-info-popover` component with hover
intent and a close delay.

### The featured card had drifted from the normal card

Both files repeated the same ~120 lines of derivation. The featured card — the #1 slot on
the homepage, `/sahkosopimus`, every SEO listing and `/halvin-sahkosopimus` — had no
integrity pill, no market-reset 12-month equivalent, and no estimate marker. That is why
the derivation moved into `ContractCardPresenter` instead of being copied a third time.

## Category rule decisions

- **Market-following wins over consumption effect.** The user defined the consumption-effect
  category as "fixed otherwise". A quarterly-reset base is not fixed, so `Korpela Kvartaali`
  and `Vaasan Sähkö Vaikuttaja` are `Markkinahinta`, with the consumption effect kept as a
  footer caveat.
- **`pricing_model = Hybrid` is a fallback for the consumption-effect category.** One active
  contract (Helen Välkkysähkö Yritys) is `Hybrid` with no `consumption_effect` block.
- **`cadence` of `none` or `unknown` does not make a contract market-following.** The
  interpretation writes `recurring_schedule.present` together with a cadence; an unknown
  cadence is not evidence of a reset mechanism.
- **Metering is not a category.** `Aikasähkö` and `Kausisähkö` describe when consumption is
  metered, not whether the price moves. They moved to the grey meta line. Before this change
  the single meta slot mixed `Pörssisähkö` / `Hybridisähkö` / `Aikasähkö` / `Kausisähkö` /
  `Kiinteähintainen sähkö`, which hid the category entirely.
- The category is derived without the canonical feature flag, so the chip is correct even if
  `CANONICAL_PRICING_ENABLED` is turned off. It reads `canonical_pricing` directly, which is
  published by the interpretation pipeline and not by the pricing flag.

## Separate defect found during the audit (not fixed here)

`Vattenfall Helppo Pörssisähkö` (`rwzpqh-vattenfall-oy-helppo-porssisahko`) publishes
**107 €/v** on its live detail page. It is a spot contract with `energy_general = 0` and an
8,95 €/kk fee. The interpretation read its "0,50 snt/kWh above 350 kWh/month" as a
`consumption_effect`, so the verdict is `base_only_hybrid` and only the monthly fee is
costed. The spot base (~350 €/yr at 5000 kWh) is missing.

The `SPOT_MARGIN_CEILING_CENTS` guard in `CanonicalContractPriceCalculator` does not fire,
because it only applies when `pricing_model = Spot`, and this contract is classified
`Hybrid`.

The ranking is protected by accident: the 350 kWh/month limit makes the contract exceed
5000 kWh/y, so it is filtered out of the listings. The detail page still publishes the
wrong number. This needs its own task.
