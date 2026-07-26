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

- **Market-following bucket label**: two-line pill — main label **"Päivittyvä hinta"**,
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
