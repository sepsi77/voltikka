# Contract card derivation

Everything a contract card shows, derived once on the server. Both card templates
(`resources/views/components/contract-card.blade.php` and
`featured-contract-card.blade.php`) read the view model this directory produces.

**The contract detail page is the third consumer** (`../../Livewire/ContractDetail.php` →
`$this->card`, rendered with the same `x-card.band` / `x-card.receipt` / `x-card.footer`
components). See "The detail page" below.

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
| `DTO/CardSellerCta.php` | Where "Siirry myyjän sivuille" goes, with a guaranteed destination. |

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
- `PricingBucket::category()` gives the card category, and the visible pill row
  (`../../../resources/views/partials/pricing-bucket-pills.blade.php`) uses it for the tint of
  a selected pill, so the filter, the legend and the cards read as one system.

`ContractCardPresenterTest::test_the_bucket_scope_agrees_with_the_resolver_and_partitions_the_set`
pins the parity, the partition, and the spot-plus-reset case.

The consumer state is `ContractsList::$pricingBucketFilter`; `ContractListingPipeline`
unions the selected buckets' scopes; see `../../Livewire/AGENTS.md` ("Pricing-type filter")
for the URL state, the legacy `?pricingModelFilter=` mapping and the caching rules.

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

## Dated rows for a mid-window mechanism switch

`CardReceiptLines::mechanismSwitchPhases()` reads validated `ContractPricingViewData::phases()` records and,
when two adjacent phases price the same kWh by **different mechanisms** (a flat energy rate
then a spot margin, or the reverse), replaces the ordinary rows with two dated ones:
"Energia 25.8. asti 6,99 c/kWh" and "Marginaali 26.8. alkaen 1,29 c/kWh".

- **The trigger is the mechanism, not the rate.** A rate change inside one mechanism is
  already covered by the scheduled-change rows (pre-published increase) and by the reset
  rows. This case is different in kind: the number stops meaning the same thing.
- Cheap Markkinahintasähkö is the live example, and it is why this exists. One flat month at
  6,99 c/kWh, then Nord Pool's monthly average + 1,29 c/kWh. Nothing in the relational data
  says so — the upstream API sends only the promotional 6,99 as a `General` component — so
  the detail page, which labelled a Spot contract's `General` component "Marginaali",
  printed "Marginaali 6,99" a few hundred pixels above the seller's own text saying the
  margin is 1,29. Measured blast radius: 3 active contracts, all fixed-then-spot.
- **The dates and rates come from the cost payload, not from a second timeline derivation.**
  `CanonicalContractPriceCalculator::buildBreakdown()` records each governing phase's resolved
  `window_start` / `window_end`, its `uses_spot`, `energy_cents`, `spot_margin_cents` and
  `monthly_fee`. Re-resolving phase boundaries in a presenter would be a second implementation
  of the phase-timeline algorithm.

## Canonical current-price boundary

When `CANONICAL_PRICING_ENABLED=true`, the presenter accepts current pricing only from a
`calculated_cost` payload whose `pricing_basis` is `canonical`. It does not read passed
`prices`, a loaded `priceComponents` relation, `hasActiveDiscounts()`, or relational discount
formatters. A missing canonical value stays missing. An excluded comparability verdict clears
all receipt rates and totals even if a stale payload contains them. Feature-off mode keeps the
legacy calculated-cost-first relational fallback in a separate branch.

Canonical phase rows come from `calculated_cost.phase_breakdown`; integrity rate fields are
used only in the feature-off branch. A consumption-effect receipt resolves its optional base
rate once and adds `Perushinta` only when that rate exists; `Kulutusvaikutus` stays visible when
the rate is missing. The strict `amount(float)` formatter is unchanged because null omission
belongs at this optional-fact boundary. This is a broad historical-detail safety rule, not one
contract exception: the 2026-08-01 production snapshot has 896 inactive Hybrid contracts with
null `canonical_pricing`, and zero active Hybrids in that state. Canonical mode must not repair
those historical receipts from relational current prices.

Package facts come from `energy_package`, and offer membership comes from canonical
`includes_discounts`, so a package is never called an offer.
The shared receipt names its three facts `Kuukausipaketti`, `Sisältää`, and `Ylittävä kulutus`;
the excess rate is not an ordinary energy price for every kWh.
For `term_price_only`, card benefit copy uses the unannualized `contract_term` saving and normal
total. Top-level annualized savings remain comparison data. Both templates render the same
prepared strings from `ContractCardView`; do not add offer copy to Blade.

Main and local listing paths do not load latest components for cards in canonical mode. They
still batch-load them in feature-off mode. `ContractsList::getLatestPrices()` returns before
`loadMissing()` in canonical mode so existing Blade calls cannot create an N+1 query.

## Detail mode

`present(detailed: true)` raises the receipt cap from three rows to five and lets a mechanism
switch keep two things the card has no room for: the soft "Pörssin keskihinta 12 kk" baseline
between the dated rows (a margin alone does not state a price), and a dated monthly-fee pair
when the phases disagree on the fee. The card's three-row cap is unchanged — a longer receipt
turns a scannable list back into a metric strip.

## The detail page

`ContractDetail::$card` presents the viewed contract with `detailed: true`, after copying the
page's own `calculated_cost` / `pricing_integrity` / `comparability` onto the model (the same
shape the listing metric cache attaches; none of them are database columns). The page renders
the band, the receipt and the warning pills from it.

It became a consumer because it had drifted **below** the honesty of the card that links to
it: a Hybrid showed "Energiahinta 0,00 c/kWh" with no consumption-effect row, a spot promo
price was labelled "Marginaali", `spot_price_margin ?? 0` printed the bare market average as
an energy price, a consumption cap warned on the card and nowhere on the page, and one
contract's page carried no call to action. `tests/Feature/ContractDetailPresenterTest.php`
pins one assertion per defect.

The page keeps what the cards do not have: the full component history, the version timeline,
the VAT note, the market-reset notice and the integrity notice.

`ContractListCacheService` now returns a typed `ContractPricing\ContractMetricSet`. Listing and
detail callers intentionally serialize `metric->pricing()->toArray()` when they attach the existing
`calculated_cost` Eloquent presentation attribute. The card presenter input remains the same array in
this slice; do not add a second untyped cache API for it.

## The seller CTA

`ContractCardPresenter::sellerCta()` resolves `order_link` → `product_link` →
`company.company_url` → the company's Voltikka page. Cards do not use it (they link to the
detail page); the detail page does. **The label follows the destination**: the company-page
fallback says "Katso myyjän tiedot", never "Siirry myyjän sivuille", so the button cannot
promise an order form it does not have.

## Contract names

`contractName` is `Support\ContractContentSanitizer::displayName()`, the same normalizer the
detail page's H1 and title tag use. A shouted name ("... 0€ KUUKAUSIMAKSU ENSIMMÄISET 3 KK!")
cannot be loud on a card and calm on the page it links to. **The stored `name` is never
rewritten** — imports, the replacement matcher and the price history all key off it.

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

- **Never lazy-load a relation from the presenter.** Listing pages batch-load `company` and
  `electricitySource`. Canonical mode does not load `priceComponents`; feature-off callers
  batch-load only the latest calculation components and can pass rates via `prices`. A lazy
  relation load turns every row of a 20-item list into an N+1 query and can cross the canonical
  current-price boundary.
- **All copy from typed fields.** No interpretation `summary` string, and no seller free
  text, reaches a card. Same rule as `../CanonicalPricing/MarketReset/ResetEstimateCopy.php`.
- **Cached calculated-cost shape has one version.** Bump
  `CalculatedCostPayloadSchema::VERSION` when `calculated_cost` gains or loses a field. List,
  company, ranking, and prepared-page cache keys all include its `cs{version}` dependency.
  Service-specific outer wrapper versions remain separate. Neither the import-driven version nor
  `PricingMode::cacheMarker()` moves on a code-only deploy, so the shared marker prevents cards from
  reading stale calculated-cost data for up to 48 hours. Current calculated-cost schema **v11**
  includes package and real-term fields, canonical-only current facts, exact typed offer terms,
  short Hybrid real-term totals, and listed `other`-cadence reset estimates.
  The presenter strictly hydrates the existing Eloquent `calculated_cost` transport attribute into one `ContractPricingViewData`; receipt, footer, copy, package, Hybrid, reset, phase, term, discount, estimate, and total decisions use typed access. `pricing_integrity` is hydrated into the existing typed `ContractPricingIntegrity`. Arrays do not continue inside card derivation.
  The detail page's own prepared-payload key is **v18** because its price-development
  overlay now uses the basis-aware statistics segment classifier.

## Tests

`tests/Feature/ContractCardPresenterTest.php` pins every rule above, including the scope
parity check and render smoke tests for both card templates.
`tests/Feature/ContractDetailPresenterTest.php` pins the detail page's use of the same view
model, one test per defect that made the page a consumer.
