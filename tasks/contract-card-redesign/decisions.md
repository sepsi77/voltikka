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

## Design-direction decisions (mockup iteration with the user)

Five HTML directions were mocked up ("Selkäranka" spine chip, "Pörssilattia" dark
terminal, "Kuitti" receipt, "Signaali" tinted grid with verdict sentences, "Mittari"
12-month price-certainty timeline). The user chose **Kuitti** for its taller, airier
cards, and separately supplied a mockup with a full-sentence header band. The merged
result is `mockups/kuitti-v2.html`, which is the approved layout.

- **The type band is single-purpose.** First merge used the band for both the category
  and the deceptive-pricing warning; the user rejected that. The band communicates only
  the pricing type. All warnings render in the footer as filled coral pills. For the
  scheduled-increase card the band stays truthful as a fixed-category statement
  ("Kiinteät hinnat · Julkaistu etukäteen, ei sidottu markkinaan"): both prices are
  pre-published, so the type is fixed; the increase is a footer warning and two dated
  receipt rows.
- **Arvio must look interactive, not decorative.** The receipt direction's rotated
  "ARVIO" ink stamp read as part of the visual design; the user rejected it. It became a
  white pill button with a border, an info icon, hover/focus states, and a popover with
  the methodology link.
- **Kuitti's serif was dropped** to honour the DESIGN.md single-family rule. The receipt
  character comes from itemised lines, dotted leaders, and the dashed stub divider.
- **Category colours are a semantic axis, not a second accent.** Sky (market) and violet
  (usage effect) would violate the no-second-accent rule unless documented as data
  colours like the emissions tiers. Fixed is deliberately grey: certainty is the default
  state, colour marks deviation.
- **Warnings use coral, not amber**, because red/amber/green are reserved for emissions
  data in DESIGN.md. Coral is the brand's attention colour and stays under the ~10%
  area rule.
- **Em dashes removed from band copy** (the user's own mockup used "— detail"; DESIGN.md
  forbids em dashes in product copy, so the separator is "·").
- **Consumption caps show only when a reasonable consumer might hit them: ≤ 30 000
  kWh/v** (user decision). The largest consumption preset is 18 000 kWh/v, so "Max
  80 000 kWh/v" style caps are noise. Exception: when the selected consumption actually
  exceeds the cap, the warning always shows because it explains the greyed card.

## Implementation decisions

### The popover is teleported, unlike the mockup

`mockups/kuitti-v2.html` positions the Arvio bubble absolutely inside the band. That would be
clipped in production: the card sets `overflow-hidden` so the band and footer can run edge to
edge inside the 24px radius, and the trigger sits in the band. `x-info-popover` therefore
teleports the panel to `<body>` and fixed-positions it from the trigger's rect, the same
approach `x-info-tip` already uses. `x-info-tip` stays as-is for plain tooltips; its bubble is
`pointer-events-none` on purpose and must not gain a link.

### The featured card is no longer a coral gradient

It became a white card with a `coral-200` border, a coral rank bar above the band, and a coral
price. The band is a tinted surface stating the pricing category; sky and violet tints on a
full-bleed coral gradient would be unreadable. This matches DESIGN.md's featured variant
(coral border + corner badge) rather than the old gradient card.

### The spot receipt row shows the day average, not a blend

For General metering the calculator prices the entire bucket at `spot_price_day_avg + margin`
(`resolvePhaseRates`, the `MeteringType::General` branch), and every active spot contract is
General metered. Showing the day average is therefore exact rather than an approximation. The
night average appears in the Arvio popover, where the full basis is stated.

### Typed rates were added to the integrity payload

`ContractPricingIntegrity` gained `promoRateCents` / `normalRateCents`. Both values were
already computed inside `promoLabel()` but only survived as Finnish sentences in
`detailFacts`. The card renders them as two dated receipt rows ("Energia 31.7. asti" /
"Energia 1.8. alkaen"), and parsing a sentence back into numbers would have been absurd.

### Cached payload shapes are now versioned

Adding those two fields exposed a real gap: `ContractListCacheService`'s version only advances
on a data import, and the `c`/`r` markers only track feature flags, so **no** existing signal
moves when a deploy changes what the cached payload contains. Cards would have read the old
shape for up to 48 hours after release and silently fallen back to a single "Energia" row.
Both `ContractListCacheService::PAYLOAD_SCHEMA_VERSION` and
`Caching\ContractPageCacheVersion::PAYLOAD_SCHEMA_VERSION` now participate in the keys and
must be bumped whenever the cached `calculated_cost` / `pricing_integrity` arrays change shape.

### The reset boundary has a second source

`recurring_schedule.current_period_end` is often null even on lineages the market-reset
estimator prices happily. Where it is missing, the presenter falls back to
`reset_estimate.tail_starts`, which is the boundary the calculator itself used. Without this,
17 of 17 kvartaalisähkö cards showed "Energia nyt" with no date while the estimate underneath
them clearly assumed one. A stale boundary already in the past is dropped rather than shown.

### SEO category pages now share the card's rule

`PricingCategoryResolver::scopeCategory()` is the SQL form of `resolve()`, used by the
kiintea-hinta, yleissahko and kulutusvaikutus pages. Previously those pages had hand-written
variants, so `/sahkosopimus/kulutusvaikutus` could list a contract whose own card said
Markkinahinta. The fixed pages keep their extra `canonical_calculation.status = 'exact'`
condition on top, because their copy promises a fully known first year, which is a stricter
promise than the category. `test_the_query_scope_agrees_with_the_resolver` pins the parity.

## Post-critique fixes (design review, score 30/40)

A critique pass found that the card had almost no edges. Measured contrast against the page:

| Surface pair | Before | After |
|---|---|---|
| page `slate-50` vs footer | **1.00** | 1.05 (footer is now white) |
| card border vs page | 1.05 (`slate-100`) | **1.18** (`slate-200`) |
| footer separator | `slate-100` on `slate-50` | `slate-200` hairline on white |
| band bottom edge | none | tinted hairline, 1.13 to 1.16 |

### The card is white end to end; only the band is tinted

The footer was `slate-50`, which is exactly `layouts/app.blade.php`'s body background, so a
rank-4+ card had no visible bottom edge at all: white body, invisible footer, then the next
card's `slate-100` band, which is *darker* than the footer above it. A caveat floating between
two cards is worse than no caveat, because it is ambiguous about which contract it qualifies.

This came straight from `mockups/kuitti-v2.html`, where `.board { background: #fff }` gave the
cards a white surround. In production the surround is the same slate-50 the footer used.

Fixed by giving the card real edges rather than tinting the footer: border to `slate-200`,
footer to white with a `slate-200` hairline, and a tint-matched hairline under the band
(`slate-200` / `sky-200` / `violet-200`) since the tints sit only 1.05 from white. The band is
now the card's only tinted surface, which also makes the category read harder.

### slate-400 text was a WCAG AA failure, and DESIGN.md contradicted itself

`slate-400` on white is **2.56:1**. It was on the unit labels (`c/kWh`, `€/kk`, 12px, needs
4.5:1) and on the price decimal (~21.6px bold, needs 3:1). Both failed, on the numbers
households are here to compare.

DESIGN.md §2 sanctioned slate-400 for "price units" while the Readable-By-Default rule
forbade exactly that drift. Resolved in favour of the rule: §2 now restricts slate-400 to
non-text use, and adds an explicit "inline units" clause (a unit attached to a number may sit
at 12px, but never below `slate-500`). All card text is now `slate-500` or darker. The
featured price decimal moved from `coral-400` (2.16:1, failed) to `coral-600` (3.56:1); the
size step alone carries the de-emphasis.

### Rank badges removed entirely

The badge rendered only for ranks 1 to 3, inside the identity flex row, so it pushed the logo
~37px right on the first three cards and back left on the rest. Nothing aligned down the list.
The user's call was to drop ranks altogether rather than reserve the column: position in the
list already is the rank, and the badge carried nothing the sort order does not give. `rank`
and `showRank` remain as no-op props so callers do not break.

### Coral belongs to the featured card alone

Listings passed `featured = true` for ranks 1 to 3, so the price and CTA went coral on three
more cards under the featured one: four coral CTAs stacked at the top of a listing, past
DESIGN.md's ~10% One Voice Rule, and the same action carrying two different weights purely by
position. Non-featured cards now always use the outline CTA and `slate-900` price whatever
their rank; `featured` only tints the border.

### The logo placeholder no longer calls a third party

The `onerror` fallback fetched a placeholder image from `placehold.co`. When that host is slow
or blocked, every card showed a blank gap where the logo should be (visible during this
session), and in production every visitor's browser made a request to an external host the
site does not control.

There were **five** copies of the same `@if (getLogoUrl())` / `@else` pair: both contract
cards, the company-detail hero, and two places in the company list. They are now one
`<x-company-logo>` component (`resources/views/components/company-logo.blade.php`). Sizing,
radius and colours come from the caller, because the tile appears on white cards, on the dark
company hero, and inside the green renewable-energy panel.

The reveal logic took three passes to get right, and the intermediate states are worth
recording because each looked fine until tested:

1. `onerror="this.remove()"` with the logo painted over the initials. Correct on error, but
   while the request is **in flight** the image's white backing already covers the initials,
   so a slow host leaves a blank tile for as long as it takes to time out.
2. Start at `opacity:0`, reveal on `onload`. Fixes the pending case, but a **200 response that
   is not a decodable image** (an error page, a redirect to HTML) fires `onload`, not
   `onerror`, so the browser painted its own broken-image icon over the initials. Observed
   live: local `APP_URL` is `http://localhost`, so logo URLs resolve to port 80 where
   something answers with non-image content.
3. `onload="this.naturalWidth ? this.style.opacity = '' : this.remove()"`. A response that did
   not decode has `naturalWidth === 0` and is removed. All four states now degrade to the
   initials tile with no blank frame and no broken-image icon.

The style is inline rather than a utility class so the reveal cannot depend on a CSS build
step.

### The Arvio copy described the wrong calculation on reset + hybrid contracts

Found by the user reading the popover on Vaasan Sähkö Vaikuttaja: the band said "Hinta
tarkistetaan neljännesvuosittain" while the popover said the year was "laskettu kiinteällä
perushinnalla 6,60 c/kWh" and never mentioned the reset at all.

The cause is a decision-order detail in `CanonicalContractPriceCalculator`: the
unsupported-Hybrid branch is decided **before** the recurring-reset branch, so a contract that
is both reports `estimate_method = hybrid_base_only`. It still carries a `reset_estimate`
payload and its total is still forward-curve shifted. `ContractCardCopy::estimate()` used a
`match` on `estimate_method`, treating the reasons as mutually exclusive, so it picked the
hybrid sentence and dropped the reset entirely.

Measured on `eoksoz-vaasan-sahko-myynti-oy-vaikuttaja` at 5000 kWh:

| | `RESET_FORWARD_SHIFT_ENABLED=false` | `=true` |
|---|---|---|
| total | 388,80 €/v | **522,63 €/v** |
| `estimate_method` | `hybrid_base_only` | `hybrid_base_only` |
| `reset_estimate` | null | `forward_curve_shift`, 6,60 → **9,28 c/kWh** |

So with the flag on the popover claimed a flat 6,60 c/kWh basis while the receipt row beside
it read "Loppuvuosi, arvio 9,28" and the total was built on the shifted tail. A 134 €/yr
difference, stated wrongly, on a card whose whole purpose is honest uncertainty.

Fixed by composing instead of matching. The copy now has a **price level** (from the
mechanism: reset → spot → term → hybrid) and an optional **exclusion** clause appended when
`hybrid_base_only` applies on top of a reset. `resetBody()` also now reads the tail basis from
`reset_estimate.basis` rather than `estimate_method`, because the payload records what
actually happened to the numbers while the method string may belong to another branch.

Result on the same contract, flag on: "Nykyinen hinta 6,60 c/kWh on tiedossa 30.9. asti.
Loppuvuoden hinnat on arvioitu sähköjohdannaisten markkinahinnoista, jolloin koko vuoden
keskihinnaksi tulee 9,28 c/kWh. Myyjä julkaisee todelliset hinnat neljännesvuosittain. Arvio ei
sisällä kulutusvaikutusta, jonka suuruutta myyjä ei julkaise etukäteen."

Three regression tests pin it: the composed reset+hybrid case, the flag-off hold-flat case,
and a plain hybrid (which must keep the flat-base-price sentence).

**Testing note for future sessions:** `new CanonicalContractPricingService()` does NOT get a
market-reset estimator. It is wired only through the container in `AppServiceProvider`, and
the calculator's `$resetEstimator` defaults to null. A tinker check built with `new` therefore
shows hold-flat behaviour no matter what the flag says. Resolve through `app()` when verifying
reset behaviour.

### The local environment was pricing resets differently from production

After the copy fix, the popover still read "Loppuvuoden hintoja ei tiedetä, joten arvio olettaa
nykyisen hinnan jatkuvan" on the local dev server. That copy was correct for the machine and
wrong for the product: **`RESET_FORWARD_SHIFT_ENABLED` was absent from the local `.env`**, so
it fell back to the config default of `false`, while production has had it **true since
2026-07-25**.

Verified against the live site rather than assumed. Kokkolan Vuodenaika at 5000 kWh:

| | total |
|---|---|
| voltikka.fi (live) | **556 €/v**, card reads "Energia nyt 7,97 · 12 kk arvio 10,51" |
| local, flag off | 429 €/v |
| local, flag on | **556 €/v** (exact match) |

Neither flag was in `.env.example` either, which is how the drift went unnoticed. Both are now
documented there with the production value and a note that the config defaults are false. The
local `.env` sets `RESET_FORWARD_SHIFT_ENABLED=true`.

`phpunit.xml` now pins **both** flags with `force="true"`. It already pinned
`CANONICAL_PRICING_ENABLED`; leaving the reset flag unpinned meant the suite silently inherited
whatever the developer had in `.env`, so results depended on whose machine ran them. Tests that
exercise either flag opt in through `config()->set()`.

The hold-flat copy path is still reachable in production and still tested: the estimator falls
back to holding the current price when its guards reject an estimate (stale forward curve,
implausible annual equivalent).

### Other external hosts (noted, not changed)

While auditing for `placehold.co` I checked every external `src` in the views:

- `cdn.tailwindcss.com` in `layouts/app.blade.php` is **guarded** by
  `file_exists(public_path('build/manifest.json'))`, so it only loads in a dev environment with
  no Vite build. Production always has the manifest. Fine as-is.
- `cdn.jsdelivr.net/npm/chart.js` **does** load in production on `/spot-price`, the contract
  type comparison, and three article charts. It is an unpinned version (`npm/chart.js` resolves
  to latest) from a third-party CDN on public pages. Same class of dependency as the one just
  removed, but a separate decision with its own trade-offs, so it was left alone.
- `laravel.com` assets are in the unrouted default `welcome.blade.php`.

### Card body given more vertical room

Body padding moved from `p-6` to `px-6 py-7`, receipt rows from `py-1` to `py-1.5`, band from
`py-3` to `py-3.5`, identity gap from `gap-3.5` to `gap-4`, and the logo tile from 44x56 to
48x64 (user request).

### Still open from the critique

- The card body is not clickable, only "Katso".
- The Arvio panel is teleported to the end of `<body>`, so tabbing from the trigger reaches the
  next card rather than the panel. Click moves focus correctly and the methodology link exists
  elsewhere on the page, so this is degraded rather than blocked.
- Mobile was not verified in-browser: the extension's window resize did not change the rendered
  viewport in this environment. The receipt block carries `min-w-[15rem]` with `max-w-[24rem]`
  and should be checked on a real 390px viewport.

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
