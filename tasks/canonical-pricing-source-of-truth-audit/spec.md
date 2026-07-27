# Audit every price surface for raw-API fallback

## Why

The raw Azure API structured price is a **seller-controlled input** and is subject to
manipulative presentation. The recurring case is a promotional rate in the priced fields with
the increase disclosed only in prose (Kokkolan Tyyni 5.49 → 13.65 c/kWh; Cheap kampanja spot
margin 0.39 → 0.78). Detecting that is the entire reason the LLM canonical-pricing layer
exists.

The project rule, now recorded in the root `AGENTS.md`:

> Canonical pricing is the intended source of truth for every price Voltikka publishes. Where
> canonical pricing and the relational components disagree, canonical wins. A surface that
> silently falls back to relational rows, or drops a contract because it has none, is a bug:
> it re-exposes the manipulation the pipeline caught.

`/sahkosopimus/tilastot` violated that rule until 2026-07-27 and was found only by accident,
through a user noticing a missing chart line. It had been recording **Kokkolan Tyyni at
279 €/v** and **Aalto Tyyni Vakiohinta at 310 €/v** — their promo prices, published as the
year's cost — against canonical figures of 555 and 748. See
`tasks/hybrid-relational-pricing-gate/decisions.md`.

Nobody has checked the other surfaces. That is this task.

## Scope

Audit every surface that reads `price_components` (directly, via
`ElectricityContract::getLatestPriceComponentsForCalculation*()`, or via the legacy
`ContractPriceCalculator`) and answer, for each:

1. Can it display a **promotional price as the ongoing price**?
2. Does it **drop or mis-handle a contract that has no relational components** (i.e. one the
   interpretation publication gate withheld)? There were 18 such active contracts on
   2026-07-27, 14 of which canonical can price.
3. If it legitimately needs relational data, is that documented with the reason?

### Prime suspects — read price components, no `CanonicalContractPricingService`

| File | Surface | Concern |
|---|---|---|
| `app/Services/ContractCard/ContractCardPresenter.php` | contract cards (both templates) | Builds the itemised receipt rows and the €/kk stub — the most-read price text on the site |
| `app/Services/ContractCard/CardFooterItems.php` | card warning/fact pills | |
| `app/Http/Resources/ContractResource.php` | JSON API output | Third parties may consume it |
| `app/Services/ContractPriceHistory/PriceDevelopmentPresenter.php` | "Näin hinta on kehittynyt" chart on the detail page | Plots observed relational prices over time |

Note the card presenter may be fed canonical figures by its **caller** rather than reading them
itself — verify by tracing the actual data flow from `ContractsList` / `ContractDetail`, not by
grepping for the class name.

### Also confirm (these do reference canonical — check they use it for the *published* price)

`BillComparisonService`, `ContractRankingService`, `ContractListCacheService`,
`LocalContractsService`, `WeeklyOffersVideoService`, `CompanyDetail`, `ContractTypeComparison`,
`Api/CalculationController`, `Api/ContractController`.

### Deliberately out of scope

- `RetailPremium/RetailPremiumHistoryBackfillService` — measures spread over actually-published
  retail prices against wholesale, so it *must* read the raw relational history. Confirm the
  reason is documented; do not "fix" it.
- `BackfillContractPriceStatistics` — always passes `useCanonical: false` on purpose; today's
  interpretation must never be applied retroactively to a historical date.
- `Caching/ContractPageCacheVersion` — reads `price_components` only for a cheap fingerprint.
- The publication gate itself (`ContractInterpretationPublisher`) — settled, see the other task.

## Constraints

- `CANONICAL_PRICING_ENABLED` is **true in production**, but the flag still exists. Any surface
  changed must keep working when it is off, falling back to the legacy path as it does today.
- Do not invent a per-unit c/kWh figure for a contract that has only a canonical whole-year
  total. A canonical-only row legitimately has a year cost and no unit price.
- A contract canonical refuses to total (`calculation.status = incomplete`, e.g. Vimpelin Voima's
  undisclosed pre-discount list) must stay excluded, not be shown at a guessed price.

## Acceptance

- A written finding per surface: compliant / violates / legitimately relational-with-reason.
- Every violation either fixed or logged here with the reason it was deferred.
- Each surface's nearest `AGENTS.md` records which pricing source it uses and why.
- A regression test per fixed surface, using a promo-shaped contract (intro phase + higher
  normal phase) and asserting the surface does not publish the intro price as the ongoing one.

## Starting points

- `laravel/app/Services/CanonicalPricing/AGENTS.md` — how to evaluate a contract
- `laravel/app/Services/ContractStatistics/ContractPriceStatisticsService.php` — the fix already
  applied to the statistics page, including the "canonical refuses to total" guard
- `laravel/tests/Feature/ContractPriceStatisticsCanonicalSourceTest.php` — a promo-shaped
  fixture to copy
- `php artisan contracts:compare-canonical-pricing` — diffs legacy vs canonical totals
