# Contract card derivation

Everything a contract card shows, derived once on the server. Both card templates
(`resources/views/components/contract-card.blade.php` and
`featured-contract-card.blade.php`) read the view model this directory produces.

## Why this exists

The two card templates each carried ~120 lines of the same PHP and drifted apart. The
featured card, which is the #1 slot on the homepage, `/sahkosopimus`, every SEO listing page
and `/halvin-sahkosopimus`, ended up with **no** price-increase warning, **no** market-reset
figures and **no** estimate marker. The most-seen card on the site was the least honest one.

**Add card facts here, never in a Blade file.** A template that starts computing a price or
writing a Finnish sentence is how the drift happened.

## Read first

- `../../../../tasks/contract-card-redesign/spec.md` (the rules) and `decisions.md` (the why)
- `../../../../tasks/contract-card-redesign/mockups/kuitti-v2.html` (the approved layout)
- `../../../../DESIGN.md` sections "Semantic — Pricing Category", "Contract Cards", "Popovers"
- `../CanonicalPricing/AGENTS.md` for where `calculated_cost` / `pricing_integrity` come from

## Components

| File | Purpose |
|---|---|
| `Enums/PricingCategory.php` | The three categories. Not `pricing_model`, not `metering`. |
| `Enums/PricingBucket.php` | The four filter buckets: the categories with Market split spot / reset. |
| `PricingCategoryResolver.php` | Contract → category + mechanism facts. Also `scopeCategory()` / `scopeBucket()` for queries. |
| `ContractCardCopy.php` | Every Finnish sentence, generated from typed fields. |
| `CardReceiptLines.php` | The itemised price rows, capped at three. |
| `CardFooterItems.php` | Warnings (priority ordered, max two) and fact tags. |
| `ContractCardPresenter.php` | Orchestrates the above into `DTO/ContractCardView`. |

## The three categories

| Category | Rule |
|---|---|
| **Markkinahinta** | `pricing_model = Spot` **or** `recurring_schedule.present` with cadence in monthly/quarterly/seasonal/other |
| **Kulutusvaikutus** | `pricing_model = Hybrid` **or** `consumption_effect.present` with `applies_to` in base_contract/both |
| **Kiinteä hinta** | everything else |

Decisions that must not be casually changed:

- **Market wins when a contract is both.** The consumption-effect category means "fixed
  otherwise", so a quarterly-reset base does not qualify (Korpela Kvartaali, Vaasan Sähkö
  Vaikuttaja). The effect is not lost: it becomes the footer caveat "Ei sisällä
  kulutusvaikutusta".
- **The `pricing_model = Hybrid` fallback is required.** One active contract (Helen
  Välkkysähkö Yritys) is Hybrid with no `consumption_effect` block at all.
- **Cadence `none`/`unknown` is not a reset.** An unknown cadence is not evidence of a
  mechanism.
- **This must not depend on `CANONICAL_PRICING_ENABLED`.** It reads `canonical_pricing`,
  which the interpretation pipeline publishes independently of the pricing flag, so the
  category stays right if canonical costing is switched off.
- **Metering is not a category.** `Aikasähkö`/`Kausisähkö` say when consumption is measured,
  not whether the price moves. They live in the grey meta line.

`scopeCategory()` is the SQL form of the same rules, used by `SeoContractsList` for the
kiintea-hinta / yleissahko / kulutusvaikutus pages. **Keep it in step with `resolve()`** — a
divergence means a contract is listed on a page whose category contradicts its own card.
`ContractCardPresenterTest::test_the_query_scope_agrees_with_the_resolver` pins the parity.

## The four filter buckets

`Enums/PricingBucket` is the granular form of the same taxonomy, used by the visible
pricing-type filter (`?hintatyyppi=`, comma-separated). It is the three categories with
**Market split into spot and non-spot resets**, because "follows the hourly exchange" and
"the seller republishes a price each quarter" are different amounts of risk for the customer.

| Bucket | Value (URL) | Rule |
|---|---|---|
| Pörssisähkö | `porssisahko` | `pricing_model = Spot` |
| Päivittyvä hinta | `paivittyva` | not spot, and a reset schedule with a cadence in monthly/quarterly/seasonal/other |
| Kulutusvaikutus | `kulutusvaikutus` | the `PricingCategory::ConsumptionEffect` rule |
| Kiinteä hinta | `kiintea` | the `PricingCategory::Fixed` rule |

- **The buckets partition the contract set.** Every contract is in exactly one, and
  `porssisahko ∪ paivittyva` is exactly the `Market` category scope. That is what makes
  multi-select include semantics well defined and per-bucket counts add up.
- **Spot wins inside the market category**, mirroring "market wins over consumption effect":
  a spot contract that also carries a reset schedule is Pörssisähkö, not Päivittyvä hinta,
  because the hourly exchange price is what the customer pays.
- `PricingBucket::fromFacts()` maps resolver output to a bucket;
  `PricingCategoryResolver::scopeBucket()` is its SQL form. Both `scopeCategory()` and
  `scopeBucket()` are assembled from the same private `spotConstraint()` /
  `marketConstraint()` / `effectConstraint()` closures, so a rule cannot be changed in one
  and not the other. **Never hand-write this SQL in a Livewire component.**
- `PricingBucket::category()` gives the card category, so a filter pill can reuse the band
  tint and the filter, the legend and the cards read as one system.

`ContractCardPresenterTest::test_the_bucket_scope_agrees_with_the_resolver_and_partitions_the_set`
pins the parity, the partition, and the spot-plus-reset case.

**Known gap, not yet fixed:** the SQL negations (`whereNot`) rely on three-valued logic, so a
contract with `canonical_pricing` NULL (or a NULL `pricing_model`) evaluates to SQL NULL and
falls out of *every* category and bucket, although `resolve()` calls it Fixed. No active
contract is affected today (measured 2026-07-26: 0 of 425 active rows have a NULL
`pricing_model` or NULL `canonical_pricing` leaf; the 3 249 NULL-`canonical_pricing` rows are
all inactive), because new contracts stay inactive until an interpretation publishes. If that
ever changes, guard each leaf with `whereNotNull` inside the positive constraints so they
return false instead of NULL.

## The type band is single purpose

It states the pricing category and nothing else. Warnings never go in the band, however
important. A fixed contract with a **pre-published** later price keeps a truthful fixed band
("Kiinteät hinnat · Julkaistu etukäteen, ei sidottu markkinaan") because both prices were
published in advance; the increase is a footer warning plus two dated receipt rows. This was
an explicit user decision after a first version put the warning in the band.

## Estimate disclosure

Any estimated 12-month total shows one Arvio chip at the band's right end, opening a popover
that states the typed reason and links to `/tietoa#menetelma`.

**The reasons compose; they are not alternatives, and `estimate_method` reports only one.**
`ContractCardCopy::estimate()` builds the sentence in two parts:

1. **Price level** — where the annual number came from. Read from the MECHANISM, in this
   order: an active reset (`facts->isReset`) → `rolling_365_spot` → `term_price_annualized` →
   `hybrid_base_only`.
2. **Exclusion** — appended when `hybrid_base_only` applies on top of a reset: "Arvio ei
   sisällä kulutusvaikutusta…".

The reason this matters: a contract that is both a market reset and an unsupported Hybrid
(Vaasan Sähkö Vaikuttaja, Korpela Kvartaali) reports `estimate_method = hybrid_base_only`,
because `CanonicalContractPriceCalculator` decides the unsupported-Hybrid branch **before**
the recurring-reset branch. It still carries a `reset_estimate` payload and its total is still
forward-curve shifted. Keying the copy off `estimate_method` alone claimed the year was priced
at a flat current rate (6,60 c/kWh) while the receipt rows next to it showed the shifted
figure (9,28 c/kWh for the year, a 134 EUR/yr difference on that contract).

**`resetBody()` reads the tail basis from `reset_estimate.basis`, never from
`estimate_method`.** The payload is the record of what actually happened to the numbers; the
method string can belong to another branch. No payload means the tail held flat, which is what
`RESET_FORWARD_SHIFT_ENABLED=false` produces, and the copy says so.

With canonical pricing off there is no `estimate_method` at all, so a spot total falls back to
the spot explanation via `is_spot_contract`.

Bill mode suppresses the chip: the headline figure there is the billing-period cost, which
carries its own "laskutusjaksollasi" disclosure.

The spot receipt row shows `spot_price_day_avg`, not a blend. That is exact: for General
metering (every active spot contract) the calculator prices the whole bucket at
`spot_price_day_avg + margin`. The night average appears in the popover.

## Footer rules

Warnings are coral pills in priority order, **max two**: price increase → consumption cap →
short term with unknown continuation → consumption effect not costed (suppressed when the
band already says Kulutusvaikutus). Facts are quiet tags: promotion, energy source with its
real percentage.

**Consumption caps only warn at ≤ 30 000 kWh/v.** The largest consumption preset on the site
is 18 000 kWh/v, so a "Max 80 000 kWh/v" cap is noise that makes every spot contract look
restricted. Exception: when the selected consumption actually exceeds the cap the warning
always shows, because the card is greyed out and sorted last and the reason must stay visible.

## Removed from cards, deliberately

- **Percentile callouts** ("Edullinen marginaali"). They rendered only on SEO listing pages
  (one caller passed `:percentiles`), half the switch was unreachable because it keyed on
  `pricing_model` for `Seasonal`/`TimeOfUse` values that column never holds, the one-item cap
  made the shown callout depend on array order, and they could contradict the sort order.
  `contracts:calculate-percentiles` and its other consumers are untouched; the `percentiles`
  prop is retained as a no-op so callers do not break.
- **The bare "Arvio" footer tag.** It now sits beside the number it qualifies.
- **The "Vihreä" label** (claimed green at 50 % renewable) and **the emissions left stripe**.
  Both became one footer tag stating the real figure.

## Responsive shape

`sm` (640 px) is the card's only breakpoint. Below it the card is one column: band, identity,
receipt, then the price as a **full-width total row under a dashed rule** — the €/kk figure
left, its qualifiers right-aligned and baseline-aligned with it — then the full-width CTA. From
`sm` up the same dashed rule turns vertical and the price returns to a right-aligned column
beside the inline CTA.

Two rules that must not be reverted, because both produced visible defects on a phone:

- **The band row must not wrap.** With `flex-wrap` and `ml-auto` on the Arvio chip, the label
  filled line one and the chip landed alone on line two, right-aligned against empty space. The
  label is the only flexible item (`min-w-0 flex-1`) and wraps inside its own column.
- **The price block must be `w-full` below `sm`.** The stub row is `justify-between`, and the
  inline CTA is `hidden sm:inline-flex`, so on a phone the row has a single child: without
  `w-full` it shrinks to its content and sits as a narrow right-aligned island in the left half
  of the card.

## Guardrails

- **Never lazy-load a relation from the presenter.** Listing pages batch-load `company`,
  `electricitySource` and the latest `priceComponents`; a lazy load turns every row of a
  20-item list into an N+1 query. Callers that cannot batch-load pass rates via `prices`.
- **All copy from typed fields.** No interpretation `summary` string, and no seller free
  text, reaches a card. Same rule as `../CanonicalPricing/MarketReset/ResetEstimateCopy.php`.
- **Cached payload shape is versioned.** `ContractListCacheService::PAYLOAD_SCHEMA_VERSION`
  and `Caching\ContractPageCacheVersion::PAYLOAD_SCHEMA_VERSION` must be bumped when the
  cached `calculated_cost` / `pricing_integrity` arrays gain or lose a field. Neither the
  import-driven version nor the feature-flag markers move on a code-only deploy, so without
  a bump cards read a stale shape for up to 48 hours after release.

## Tests

`tests/Feature/ContractCardPresenterTest.php` pins every rule above, including the scope
parity check and render smoke tests for both card templates.
