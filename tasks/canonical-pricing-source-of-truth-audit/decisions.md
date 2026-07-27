# Decisions

Nothing decided yet — this task has not been started. The sections below record what is already
known so the first session does not re-derive it.

## Where this came from

Split out of `tasks/hybrid-relational-pricing-gate/` on 2026-07-27. That task fixed the
interpretation publication gate and then found that `/sahkosopimus/tilastot` was dropping every
contract the gate withheld, publishing promo prices as annual costs until it did. The same class
of defect may exist on other surfaces; none has been checked.

## The candidate list is a grep, not an analysis

The suspect table in `spec.md` came from two greps over `app/`: files referencing
`price_components` / `priceComponents` / `PriceComponent::` / `getLatestPriceComponentsForCalculation`,
minus files referencing `CanonicalContractPricingService`. That is a starting point with two known
weaknesses:

- **A false positive is likely for the contract card.** `ContractCardPresenter` may receive already-
  canonical figures from its caller and never need the service itself. Trace the data flow before
  concluding anything.
- **A false negative is possible.** A surface that references `CanonicalContractPricingService` may
  still use it only for *ranking* or *exclusion* while displaying a relational price. Reading the
  code is required; the grep cannot see this.

## Known-correct reference implementation

`ContractPriceStatisticsService::calculateForDate()` after commit `5a893d8`:

- when canonical pricing is on, a contract with no relational components is still priced from its
  canonical phases rather than skipped;
- a contract canonical refuses to total is skipped rather than written as an all-null row;
- the legacy path still requires components, and historical backfills always take it;
- a canonical-only row carries the whole-year cost and leaves per-unit c/kWh null, because
  inventing a unit price would be fabrication.

Copy that shape unless a surface has a documented reason to differ.

## Two contracts worth using as fixtures

Both are real, active, and currently withheld by the gate:

| contract | relational recorded | canonical |
|---|---|---|
| Kokkolan Energia **Tyyni** | 279 €/v (promo) | 555 €/v |
| Aalto energia **Tyyni Vakiohinta** | 310 €/v (promo) | 748 €/v |

Tyyni Vakiohinta (5.49 → 13.65 c/kWh, increase disclosed only in prose) is the worked example in
`laravel/app/Services/ContractInterpretation/AGENTS.md` for the deceptive-pricing case. If a surface
shows either at its promo price, that is the bug.
