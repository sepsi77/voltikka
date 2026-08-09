# Decisions

## 2026-07-26 — task created, plan only (no implementation yet)

### The four filter buckets reuse the card category system

The filter does not invent a new taxonomy. It uses `ContractCard\PricingCategoryResolver`
(Markkinahinta / Kulutusvaikutus / Kiinteä hinta) and splits Markkinahinta into spot and
non-spot resets. Reasons:

- The buckets partition all contracts with no overlap, so counts add up and
  multi-select include semantics are well defined.
- The card band a user sees always agrees with the bucket that listed the card. The
  same drift risk was already fixed once for the SEO pages (see
  `PricingCategoryResolver::scopeCategory()` docblock); the filter must go through a
  shared scope, never hand-written SQL in the Livewire component.
- The old accordion "Hinnoittelumalli" options mixed pricing mechanism
  (Spot/FixedPrice/Hybrid) with metering/name-matching pseudo-types
  (Quarterly/TimeOfUse/Seasonal LIKE queries). Aikasähkö/kausisähkö are metering, not
  risk transfer; they do not belong in the new filter.

### Include semantics, not tri-state exclude

No pill active = show all; active pills = show only those buckets. Excluding spot is
"activate the other three". A tri-state include/exclude control was rejected as harder
to understand and unnecessary because the buckets partition the set.

### Placement

The pill row is always visible above the list; the accordion keeps duration, energy
source, and postcode. This answers the actual user problem (spot-heavy page 1 with no
visible way out) without re-opening the compact-layout decision that collapsed the
accordion on all sizes.

### Hybrid band copy

Current: "Kiinteä hinta + kulutusvaikutus · Korjaus riippuu kulutusprofiilistasi".
"Korjaus" and "kulutusprofiilistasi" are unnatural. Recommended replacement detail:
"Vaikutus riippuu siitä, mihin aikaan käytät sähköä" — it matches the popover sentence
already in `ContractCardCopy::hybridBody()`, so band and popover use one phrasing.
Copy lives only in `ContractCardCopy` (typed-fields rule); do not patch templates.

### Resolved with the user (2026-07-26)

- **Market-following bucket label**: two-line pill — main label **"Jaksoittain vaihtuva hinta"**,
  sub-line **"kvartaali- ja kuukausisähkö"**. "Markkinaa seuraavat" was rejected as
  unclear to a layperson. The main label states what the customer experiences; the
  sub-line anchors it to product names people recognise. No ambiguity with spot,
  because Pörssisähkö is its own pill beside it. If a strictly single-line pill is
  ever required, fall back to "Kvartaali- ja kuukausisähkö".
- **Rollout scope**: the pill row renders on **every contract listing page** except
  single-company pages (`CompanyDetail` contract lists keep the current layout).
- **URL param**: `hintatyyppi`, comma-separated bucket keys.
- **Bucket keys** (manager decision, 2026-07-26): `porssisahko`, `paivittyva`,
  `kulutusvaikutus`, `kiintea`. Finnish because the values are user-visible in the
  URL; `porssisahko`/`kulutusvaikutus` match the existing SEO slugs.
- **Execution model**: a manager session delegates units to the `executor` subagent
  (`.claude/agents/executor.md`, Opus). The manager maintains the `tasks/` files;
  executors do not edit them.

## 2026-07-26 — goal 2 (band copy) done

Band detail is now "Vaikutus riippuu siitä, mihin aikaan käytät sähköä"
(`ContractCardCopy.php:63`); receipt row is "± käyttöajan mukaan"
(`CardReceiptLines.php:78`, same character length as the old value). Tests pinned in
`ContractCardPresenterTest` (44 passed) plus listing/filter suites (148 passed).
Longer detail wraps inside its own band column on ~360 px cards (no overflow); the
compact fallback, if ever needed, is "Hinta tarkentuu sähkönkäyttösi mukaan".

**Open follow-up**: `SeoContractsList.php:797` — the `/sahkosopimus/kulutusvaikutus`
meta description still says "…kulutusprofiilistasi riippuva korjaus…". Left as is
because it is an indexed SEO meta description, not card copy; rewrite needs a user
decision.

## 2026-07-26 — step 1 (bucket scope) done

New API: `Enums\PricingBucket` (string-backed: porssisahko / paivittyva /
kulutusvaikutus / kiintea) with `fromFacts()` and `category()` (for the band tint),
plus `PricingCategoryResolver::scopeBucket($query, PricingBucket $bucket)`. Both
scopes are now assembled from shared private constraint factories so they cannot
drift. Parity + partition pinned in
`ContractCardPresenterTest::test_the_bucket_scope_agrees_with_the_resolver_and_partitions_the_set`.
Full suite green (1272 passed). Documented in `ContractCard/AGENTS.md` ("The four
filter buckets").

**Known pre-existing gap (decision needed, not blocking):** the scope `whereNot`
negations use SQL three-valued logic, so a row with NULL `canonical_pricing` or NULL
`pricing_model` drops out of every category/bucket scope even though `resolve()`
would classify it Fixed. Measured today: 0 of 425 **active** contracts are affected
(all 3 249 NULL-`canonical_pricing` rows are inactive, matching "new contracts stay
inactive until first validation"), so the filter ships safely. The strict fix is a
`whereNotNull` guard inside the shared constraint factories, but it changes
`scopeCategory()` output (an unvalidated Hybrid would start appearing on
`/sahkosopimus/kulutusvaikutus`), so it needs a user decision.

Legacy param mapping decision (manager): `?pricingModelFilter=` values Spot →
porssisahko, FixedPrice → kiintea, Hybrid → kulutusvaikutus, translated once at
mount when no `hintatyyppi` is present; Quarterly/TimeOfUse/Seasonal keep their
legacy behavior (they are metering/name-based pseudo-types, not risk-transfer
buckets).

## 2026-07-26 — steps 2–4 (state + wiring + legacy mapping) done

Property is `pricingBucketFilter` (string, `#[Url(as: 'hintatyyppi')]`,
comma-separated). Public API for the UI: `selectedPricingBuckets()`,
`isPricingBucketSelected(string)`, `togglePricingBucket(string)` (Plausible
`Contracts Filter Applied`, `filter_type = pricing_category`, fired on turn-on
only). Applied in both `getContractsProperty()` paths, so bill mode and
`CheapestContracts`/`SahkosopimusIndex` inherit it; on route-typed SEO pages it
composes as AND. Legacy mapping happens in `mount()` via
`applyLegacyPricingModelFilter()`. 0 or all-4 selected = no query constraint, but
any non-empty selection counts as an active filter. New
`tests/Feature/PricingBucketFilterTest.php` (17 tests); full suite 1291 passed.
Documented in `Livewire/AGENTS.md` ("Pricing-type filter (`?hintatyyppi=`)").

Notes for the UI unit: per-bucket counts do not exist yet; the accordion
"Hinnoittelumalli" section still renders but arrives inactive on legacy query
strings (mapping clears the property); the accordion open/badge logic keys on
`hasActiveFilters()`, which now includes pill selections — the UI unit must split
those so pill use does not auto-open the accordion.

## 2026-07-26 — steps 5–6 (pill row UI + accordion + SEO links) done

Pill row partial `partials/pricing-bucket-pills.blade.php`, included above the
accordion in `contracts-list`, `seo-contracts-list`, and `cheapest-contracts`
templates (cheapest has its own template — verified; company pages have no filter
partials and are untouched). Selected pills use the band tints via
`PricingBucket::category()->tint()` so filter, legend and cards match. 2×2 grid
below `sm`. Accordion "Hinnoittelumalli" section removed; badge/open-default now
use accordion-scoped helpers (`activeAccordionFilterCount()` /
`hasActiveAccordionFilters()`), so pill use never opens the accordion.
`showSeoFilterLinks` restored (it had been deleted in commit a30bd04); only
`SahkosopimusIndex` opts in; porssisahko/kulutusvaikutus/kiintea pills render as
crawlable anchors in the no-filter state, paivittyva is always a toggle.
Verified in the browser at 1440/375 px. Full suite 1297 passed; `npm run build` ok.

**Per-bucket counts dropped (spec allowed "when cheap"):** energy-source and
consumption-range filters apply in PHP after `->get()`, so a SQL grouped count
would disagree with the visible totals; an honest count is too expensive for the
cached default payload. Revisit only if those filters move into SQL.

**Stale doc found:** root AGENTS.md claims `/` → `ContractsList`; actually `/`
serves the marketing `HomePage` (no contract list) and `ContractsList` has no
route of its own. Not fixed here (unrelated to this task).

**Minor polish option:** at 640–700 px the kulutusvaikutus sub-line wraps to
three lines; fallback copy if wanted: "kiinteä hinta ± käyttöaika".

## 2026-07-26 — pill restyle (user: first version's cards were ugly)

The four detached cards became one **segmented rail**: single `rounded-xl`
slate-200 box, cells divided by `gap-px` hairlines, 2×2 below `sm` / 1×4 above.
Selected cell = category tint `100` fill + 1 px inset tint-400 ring (load-bearing:
the tints are near-white, a fill alone does not read as "on") + check glyph in the
same slot as the rest-state icon so nothing shifts. Sub-lines raised to the 14 px
type floor; kulutusvaikutus sub shortened to "kiinteä hinta ± käyttöaika". Coral
stays reserved (focus ring only). No behavior/test changes. Documented as
"Segmented Filter Rail" in DESIGN.md with a forbidden list (no coral selected
state, no tint at rest, no colored edge bar).

**Pre-existing, out of scope:** `/sahkosopimus/halvin-sahkosopimus` at 375 px
overflows horizontally by 16 px — caused by the dark hero section's `-mx-4`
full-bleed, not the pill row (verified by removing the row and re-measuring).

## 2026-07-26 — Arvio popover misplacement fixed

Root cause was NOT a detached anchor: the `x-teleport`ed panel's `id` was
`Str::random()` per render, so Livewire's teleport-bridge morph key-mismatched it
and `swapElements` replaced the live panel with a scopeless `cloneNode` — Alpine
re-initialised it against an empty scope (`x-show="open"` hit `window.open`, the
style binding rendered literal `style="undefined"`), and a `fixed` element with no
top/left sits at the viewport origin. Fix in `info-popover.blade.php`: constant
`wire:key` on the panel, client-generated `panelId`, and imperative positioning at
open time (`place()` writes top/left/id directly; reactive `:style`/`:id` bindings
removed because a morph strips attributes the server markup does not carry).
`info-tip.blade.php` had the same latent pattern and got the same treatment. Rules
documented in `laravel/AGENTS.md` ("Teleported Alpine panels inside Livewire
components"). Only cards that survived a morph in place were affected, which is
why it looked intermittent. Browser-verified across chained morphs at desktop and
~375 px; full suite 1297 passed.
