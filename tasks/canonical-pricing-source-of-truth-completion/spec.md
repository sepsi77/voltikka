# Complete canonical pricing as the source of truth

Status: **in progress**.

## Purpose

Finish the migration from raw Azure `price_components` pricing to one validated
canonical pricing system.

When canonical pricing is enabled, every public current-price display,
calculation, saving, ranking, API response, and generated media item must use the
canonical pricing result. Raw Azure price components remain source evidence.
They must not silently fill gaps in a canonical result.

This parent task consolidates the work and evidence in:

- `tasks/canonical-pricing-ignores-component-discounts/`
- `tasks/canonical-pricing-source-of-truth-audit/`

Do not implement those two tasks as isolated patches. Use their measurements and
surface inventory as input to this task.

## Background

The original pricing system calculates contracts from `price_components`. These
rows come from the Azure API and are correct in most cases. They can still be
wrong or misleading. A seller can publish a low promotional value in structured
data and disclose the later price only in text.

The interpretation workflow was added to prevent this:

1. Store the complete Azure payload as immutable source evidence.
2. Send structured data and seller text to the LLM interpretation workflow.
3. validate the typed result with deterministic rules.
4. Store versioned results in `contract_interpretations`.
5. Publish the current validated result to the `canonical_pricing`,
   `canonical_calculation`, and `canonical_source_consistency` JSON columns on
   `electricity_contracts`.
6. Calculate one canonical pricing outcome for all public consumers.

There is no separate canonical-pricing table. The versioned interpretation table
and the published JSON columns form the canonical data layer.

## Problem

The migration is incomplete in two ways.

### 1. The canonical domain model and calculator are incomplete

The canonical system handles phases, deceptive future prices, Spot pricing,
market resets, short fixed terms, and Hybrid base-only estimates. It does not yet
handle every pricing structure or every offer result correctly.

Known examples:

- A canonical component can contain both `amount` and `normal_amount`, but the
  calculator does not consistently use `normal_amount` to calculate the normal
  total and offer saving.
- Some calculator paths set `baseTotalCost` equal to `totalCost`. This reports a
  zero saving even when the canonical total already uses the offer price.
- A structured discount can disappear from the canonical phase timeline. The
  Surffari summer campaign is the current known example.
- Monthly included-energy packages are not represented as package pricing.
  Vaasan Sähkö Kuukausipaketti includes 75/150/250/350 kWh each month and charges
  16.60 c/kWh only for excess use. Canonical pricing currently applies 16.60
  c/kWh to all use.
- Kuukausipaketti L has interpreted the same EUR 49 monthly charge as both a
  `flat_fee` and a `monthly_fee`, so canonical pricing charges it twice.

### 2. Some public surfaces still use raw component pricing

Some consumers use canonical pricing only for an annual total, ranking, or
exclusion decision. They then use relational components for unit prices, period
costs, metadata, structured data, or other output. Some consumers silently fall
back to raw component rates when canonical data lacks a field.

This creates two active pricing systems. A surface can bypass an LLM correction
and publish the misleading Azure value again.

## Source-of-truth rule

When `CANONICAL_PRICING_ENABLED=true`:

- canonical has a value: use it;
- canonical marks a value unknown: show an honest unavailable state;
- canonical excludes a contract: do not rank it or invent a total;
- canonical lacks a public unit rate: do not fill it from `price_components`;
- a presenter, Blade template, controller, resource, or media service must not
  calculate a price independently.

When `CANONICAL_PRICING_ENABLED=false`, the existing legacy
`ContractPriceCalculator` path must keep working until the migration is complete
and the flag can be retired in a separate reviewed decision.

## Valid uses of `price_components`

Raw components remain valid for:

1. immutable source evidence and diagnostics;
2. interpretation input and deterministic consistency checks;
3. legacy feature-off calculations during rollout;
4. historical observations where today's interpretation must not rewrite the
   past;
5. cache fingerprints and import bookkeeping.

A historical surface that uses raw observations must label them as observed
seller data. It must not present them as the canonical current price. A company
market comparison can use an observed market+seller snapshot only when both
sides share one usable date and the UI explicitly identifies it as the latest
historical fallback.

## Required target architecture

```text
Azure structured data + seller text
                |
                v
Versioned LLM interpretation
                |
                v
Deterministic schema and domain validation
                |
                v
Published canonical pricing data
                |
                v
One deterministic canonical calculator
                |
                v
One typed public pricing outcome
                |
                v
Cards, details, rankings, APIs, calculators, statistics and media
```

The canonical outcome must be sufficient for public consumers. It must include,
when applicable:

- first-year or annualized total;
- normal-price total on the same comparison basis;
- measured offer saving;
- exact typed offer terms from changed component type, actual/normal amount, and resolved timing;
- monthly costs and monthly offer savings;
- current display rates;
- phase timing and phase breakdown;
- Spot and market-reset assumptions;
- included-energy allowance and excess-use pricing;
- Hybrid exclusions;
- estimate method and reason;
- comparability and exclusion reason;
- typed provenance or conflict state.

## Domain requirements

### Offers and normal prices

- Cost the actual canonical phase timeline with `amount`.
- Cost a second promotion-free timeline on the same basis with
  `normal_amount` or a validated normal phase.
- Store the measured difference explicitly in the outcome.
- Do not derive offer savings later from unrelated totals.
- Resolve canonical and relational representations once. Do not count the same
  offer twice.

### Hybrid contracts

Apply an offer to billed base components such as a monthly fee or base energy
rate. Never apply an offer to an unknown consumption effect. Keep the existing
base-only disclosure. When a Hybrid has an exact short structural term, preserve
its unannualized real-term total and offer saving before annualizing the same
base-only result for comparison.

### Short fixed terms

Cost the complete disclosed term with its offer, then annualize the complete
term result. Apply the same comparison factor to the normal-price result and the
saving. The UI must identify an annualized value where required.

### Included-energy packages

Represent package pricing as a typed structure, not as a temporary promotion.
The model must express:

- package fee;
- included kWh quantity;
- allowance cadence, initially monthly;
- excess energy rate.

Cost each month as:

```text
package fee + max(month usage - included kWh, 0) * excess rate
```

Included energy is not an offer saving and must not create a promotion badge.
The validator must prevent one source fee from becoming two billed canonical
fees.

### Structured discount integrity

If source data contains a current structured discount, canonical interpretation
must either:

- represent it in a costable canonical structure; or
- record a typed reason why it is rejected or conflicts with stronger evidence.

A valid structured discount must not silently disappear.

## Surface scope

Audit and, where required, migrate all public current-price consumers, including:

- contract listings, list caches, rankings, cards, and featured cards;
- contract detail hero, receipt, title, metadata, and JSON-LD;
- company pages;
- bill comparison, including period pricing;
- contract-type comparison;
- local contract sections;
- weekly-offers video and generated offer data;
- calculation and contract APIs;
- current forward price statistics;
- sitemap or schema fields that contain a price.

Treat historical price charts, historical statistics backfills, and retail
premium reconstruction as separate evidence use cases. Document why each one
stays relational.

## Delivery plan

### Phase A: inventory and invariants

- Complete the per-surface audit.
- Write one pricing-source decision for every surface.
- Add tests that fail on raw current-price fallback while canonical mode is on.
- Define the canonical outcome contract before changing consumers.

### Phase B: canonical domain completeness

- Add explicit normal-price and saving calculation.
- Add monthly included-energy and excess-rate pricing.
- Add typed conflict and unsupported states.
- Add validator rules for missing discounts and duplicate fees.
- Version the schema, prompt, and validator as required.

### Phase C: canonical calculator and outcome

- Make one calculator produce every public pricing value.
- Preserve monthly timing, term annualization, Hybrid rules, Spot assumptions,
  and market-reset shifts in both actual and normal-price calculations.
- Keep relational discount evidence inside the canonical resolution boundary.
- Do not expose it as a UI fallback.

### Phase D: consumer migration

- Remove silent raw-component fallbacks in canonical mode.
- Make all presenters and controllers format the typed outcome only.
- Keep the legacy path only under the feature-off branch.
- Version all affected cache payloads.

### Phase E: reinterpretation and rollout

- Reinterpret affected active snapshots with the new versions.
- Compare old and new canonical totals on a current production-data snapshot.
- Run `php artisan contracts:compare-canonical-pricing`.
- Review every material total and ranking change.
- Stage actual price changes separately from display-only saving fixes where
  practical.
- Update all relevant `AGENTS.md` files before deployment.

## Required regression fixtures

At minimum, add fixtures for:

1. Vattenfall 50 percent monthly-fee offer for 12 months: discounted total stays
   correct and the saving is approximately EUR 28.44 or EUR 35.76 at 5,000 kWh.
2. A short monthly-fee offer followed by a known normal fee.
3. A Hybrid with a discounted billed base fee and an unknown consumption effect.
4. A six-month fixed contract with an offer and annualization.
5. Surffari `UntilDate` margin campaign: the current campaign cannot disappear.
6. Kuukausipaketti XS/S/M/L: monthly included kWh, excess price, and no promotion
   saving.
7. Kuukausipaketti L duplicate-fee protection.
8. A deceptive intro price that differs from the later description price.
9. A canonical-only contract with no relational components.
10. A canonical-excluded contract that no public surface prices by fallback.
11. A Spot or market-reset contract where wholesale estimate movement does not
    appear as an offer saving.
12. A historical observed-price surface that does not apply today's canonical
    interpretation retroactively.

## Acceptance criteria

- With canonical mode on, no public current-price consumer calculates or fills a
  price directly from `price_components` outside the documented canonical input
  boundary.
- Every public current-price value can be traced to one typed canonical outcome.
- Canonical-only priceable contracts are not dropped because relational rows are
  absent.
- Canonical-excluded contracts are never shown with a guessed relational total.
- Offer savings are measured from canonical actual and normal-price calculations.
- Public canonical offer copy states supported typed prices and exact durations/dates; an unsupported or absent typed term is not shown as an offer.
- A phase-modeled and component-modeled offer cannot be counted twice.
- Included-energy packages use monthly allowance and excess-rate pricing and do
  not appear as promotions.
- Vattenfall offer benefits display correctly without changing their already
  correct discounted totals.
- Surffari current campaign pricing is represented or rejected with a typed
  reason; it cannot silently use the later normal price.
- Kuukausipaketti L cannot charge the same EUR 49 fee twice.
- Feature-off legacy behavior remains covered until its retirement is approved.
- Full tests pass, cache schemas are versioned, and the reviewed pricing/ranking
  diff is recorded in `decisions.md`.

## Main implementation areas

- `laravel/app/Services/ContractInterpretation/`
- `laravel/resources/contract-interpretation/`
- `laravel/app/Services/CanonicalPricing/`
- `laravel/app/Services/ContractCard/`
- `laravel/app/Services/BillComparison/`
- `laravel/app/Services/ContractPriceCalculator.php`
- `laravel/app/Models/ElectricityContract.php`
- `laravel/app/Livewire/`
- `laravel/app/Http/Controllers/Api/`
- `laravel/app/Http/Resources/`
- `laravel/app/Services/WeeklyOffersVideoService.php`
- `laravel/tests/`

Read the nearest `AGENTS.md` before changing any area.
