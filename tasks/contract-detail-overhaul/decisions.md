# Decisions

## 2026-07-26 Critique baseline and plan

- Multi-agent design critique (design review + mechanical detector + naive-user simulation)
  scored the page 21/40. Snapshot:
  `.impeccable/critique/2026-07-26T07-06-40Z__resources-views-livewire-contract-detail-blade-php.md`.
- Key SEO success criterion set by Seppo: **minimize bounces, maximize engagement**. The plan
  is ordered by bounce impact: first-viewport trust fixes, then presenter unification, then
  the engagement/content layer, then polish.
- Presenter unification is in scope (not symptom patches): the detail page becomes the third
  consumer of `ContractCardPresenter`, chosen because the page had already drifted below the
  cards' honesty (hybrid consumption effect and cap warning missing, reset price unqualified,
  CTA missing on one page).
- Bill comparison comes to the detail page as a single-contract module reusing
  `BillComparisonService::periodRowsForContracts()`; **period basis only**, same rule as the
  in-listing mode, because annualizing one bill's implied unit rate is biased for
  spot/seasonal/time contracts. The module stays self-contained (does not rewrite the page's
  main price display) to keep the bill-total-as-anchor principle clean.
- Mockup gate: static HTML mockups must be approved before any implementation.

## 2026-07-26 Mockups (awaiting review)

Two static mockups in `mockups/`, both verified rendering in the browser:

- `korpela-kvartaali.html` - the full redesigned page for a market-reset contract (rank 113).
  Page order: dark hero (identity, category chip, price + one-sentence qualifier with dated
  current/estimate figures, working verdict panel with rank scale, consumption chips + free
  kWh input ABOVE the CTA, CTA with no-commission line) -> "Kannattaako X?" verdict paragraph
  -> bill comparison (shown expanded with an honest pay-more result + link to alternatives)
  -> Hintatiedot (dated presenter-style receipt, static 4-consumption cost table, spot
  counterfactual) -> price-vs-market chart (stepped coral contract line vs dashed neutral
  median, reset annotation, hover tooltip) + seller behavior fact tags + collapsed version
  history -> structured terms grid + collapsed sanitized seller description -> FAQ -> single
  compact environment module (one taxonomy: "Korkeat päästöt") -> cheaper alternatives incl.
  one same-type card -> footer trust statement. Mobile: sticky bottom CTA bar.
- `rank1-spot.html` - the rank-1 spot variant: verdict box shows gap to the SECOND cheapest
  and to the market median (never "Ei tietoa"); cleaned H1 (promo text moved out of the name
  into a coral warning pill and dated receipt rows: perusmaksu 0 first month / 4,99 after);
  bill comparison save-case result; copy for the no-spot-history unavailable state.

Design decisions in the mockups:

- Delta/savings figures are neutral slate, warnings coral, per DESIGN.md; no emerald/amber
  price semantics anywhere. One CO2 severity taxonomy.
- Chart palette validated with the dataviz validator: contract line coral-600 (single real
  series), market median a dashed slate-500 reference line with a direct label.
- Numbers in mockups are plausible placeholders, not asserted production values (the Cheap
  margin contradiction is deliberately sidestepped with a clean 0,42 c/kWh example).
- Detector run over both files: only advisories inherent to the design system (single-font
  rule, dark-hero glass rgba tokens, a few 13/15px steps off the strict ramp).

## 2026-07-26 User simulation on mockups + revisions

A naive-user simulation (Googler / engaged shopper / phone skeptic) ran against the mockups.
All three personas chose to stay; the skeptic said the named-competitor line ("4 €/v halvempi
kuin Helppo Pörssisähkö") and the hero promo warning on the #1 product were the most
trust-building elements. Fixes applied to the mockups from its findings:

- Bill-comparison example numbers made computable from the page's own prices (the module
  claims "Ei arvio vaan laskettu", so the example must self-verify). **Implementation note:
  the real module inherits this bar: its result must be arithmetically consistent with the
  receipt rows shown on the same page.**
- Everything reacts to the consumption selector, not just the hero: rank-card gap and
  cheapest price, verdict paragraph, cost-table highlight, spot counterfactual, environment
  kg/km, alternative-card prices and savings, sticky mobile bar. **Implementation note: any
  figure that does not react must be explicitly scoped ("5 000 kWh:lla") in copy.**
- "Sähköfutuurit" glossed in plain language everywhere ("tukkumarkkinan ennakkohinnat");
  "marginaali" glossed in the spot hero ("yhtiön oma lisä") and the spot sum stated (8,06).
- Rank card explains why a below-median quarterly contract ranks 113 ("edelle sijoittuvat
  ovat pääosin pörssisähköä").
- "Arvio" chip is now a working popover; trophy emoji removed from the rank-1 verdict
  (skeptic read it as the one sales note); version-history count fixed.

Reversals requested by Seppo (both applied):

- **Promo savings restored as a quiet fact**: one receipt-note line "Tarjous on huomioitu
  arviossa vain voimassaoloajaltaan: säästät n. X €/v verrattuna normaalihintaan." The old
  TARJOUS mini-hero (orange side stripe, strikethrough normal price, green savings chip) is
  not coming back: it duplicated the hero price and used sales-page visual language. The
  promo cost impact stays framed as a coral warning with quantified +€/v.
- **CO2 km-equivalence restored** inside the single environment module as kg CO2e/year +
  "vastaa n. X km ajoa bensiiniautolla", derived from the selected consumption (reacts to
  chips). Hero-scale duplication stays removed; the residual-mix caveat sits adjacent.

## 2026-07-26 Mockup critique round 2 and the editorial rework

Seppo's verdict on the first mockup round: **"still very much AI slop design"** - cards
within cards everywhere, and a hero with no structure where every element competes. A
dual-agent impeccable critique of `korpela-kvartaali.html` (28/40, snapshot
`.impeccable/critique/2026-07-26T07-57-04Z__act-detail-overhaul-mockups-korpela-kvartaali-html.md`)
confirmed both: the content architecture was praised as bespoke (rank, gap, two-vintage
qualifier, self-verifying math) but the form was default-LLM container soup, and the
detector found 6 real WCAG contrast failures (white on coral-500 = 2,8:1 on the CTA,
active chip, logo), 21 sub-44px tap targets, and a rank that never moved while claiming
to follow the selected consumption (P0).

Decisions made by Seppo via AskUserQuestion:

- **Structure: fully editorial.** Zero nested cards; flat typographic sections on the
  page surface; card chrome only on the alternative-contract cards.
- **Hero: price + verdict fused** into one dominant statement; breadcrumb/seller/category
  demoted to quiet metadata; chips + CTA as the second beat; the glass verdict panel is
  gone.
- **CTA: the seller link keeps coral on every rank** (rank-dependent CTA rejected).

Rework applied to `korpela-kvartaali.html` and verified in the browser; Seppo accepted
the result ("good enough for now"). The full structure is codified in spec.md under
"Approved page structure". Notable implementation-relevant points:

- Rank now interpolates with consumption (98/113/121/134 at the four reference
  consumptions in the mock); the free kWh input works via piecewise-linear interpolation
  between the reference consumptions, so every figure stays arithmetically consistent
  with the static cost table. The real page computes these server-side; the requirement
  that survives is **rank, marker, and counts must be per-consumption**.
- Contrast fixes: CTA coral-600 19px/700 (flat, no gradient, no glow); active chip is
  white-on-dark inversion instead of coral; chart contract line is slate-900 ink, coral
  no longer used as a data series.
- The category label in the hero links to (and opens) the FAQ item that explains the
  pricing mechanism - the Jordan-persona fix for "the most important concept was a dead
  ghost pill".
- Sticky mobile CTA uses scroll-past logic, and hides over alternatives/footer. The
  mockup uses a scroll listener because **IntersectionObserver callbacks never fire in
  the browser-automation environment** (tab treated as non-rendering); production code
  can use IntersectionObserver but must handle the below-the-fold case (only show when
  the hero CTA's bottom < 0, not merely "not intersecting").
- Version-history deltas shown as c/kWh, not % (two consecutive -8 % rows read as a
  copy-paste bug).
- Repetition trimmed: "ei provisiota" hero + footer only; "arvio, ei hintalupaus" only
  in the qualifier.

`rank1-spot.html` was then ported to the approved editorial structure as a **full page**
(it was previously only a partial variant showing the differing sections). Spot-specific
points worth keeping in implementation:

- **All figures derive from one flat pricing model** (spot 365 pv avg 6,57 + marginaali
  0,42 = 6,99 c/kWh; perusmaksu 4,99 with the first month free), so the hero, cost
  table, counterfactual, alternatives, and the bill example all self-verify. The bill
  example: 380 kWh x (4,10 + 0,42) c/kWh + 4,99 = 22,17 ~ 22,20 EUR vs 36,50 paid.
- **The rank honestly flips at low consumption**: at 2 000 kWh Helppo Pörssisähkö
  (lower perusmaksu) is ~1 EUR/v cheaper, so the page shows "Sija 2" and the verdict
  line switches to "kalliimpi kuin halvin". A rank-1 page must support both directions
  of its comparison line.
- The rank-1 verdict compares to the SECOND cheapest by name, plus a median-savings
  line in the verdict note; never "Ei tietoa".
- The bill save-case delta pill is **slate-900, not green** (positive-case design was
  previously unspecified); the pay-more case on the korpela page stays a coral warning
  pill.
- The chart shows monthly average (spot + margin) as the ink line against a dashed
  12-month-average reference: it teaches volatility, which is the spot contract's key
  fact. Fact tags name the cheapest and priciest month.
- The no-spot-history fallback copy is preserved in the demo note: "Tälle jaksolle ei
  ole vielä pörssihintatietoja, joten vertailua ei voi laskea tälle sopimukselle."
- The mock runs `apply(5000)` on load so the JS model is the single source of truth for
  every displayed figure.

## 2026-07-26 Phase 1: rank-1 verdict, contract counts, price qualifier

Implemented on the existing page layout (the editorial restructure stays in the later phases).

- **The 291 vs 299 mismatch is measured, not guessed.** On the local production snapshot the
  title said 291 (`ContractRankingService::getTotalActiveContracts()`, which applies
  `isConsumptionInRange(5000)`) and the hero said 299 (`getTotalContractsForConsumption()`,
  which did not). The difference is **exactly 8 contracts whose consumption limits exclude
  5 000 kWh**. Unified on the narrower, per-consumption scope: a contract the visitor cannot
  buy at this consumption is not part of the comparison, and the listings already drop it.
  `getEligibleSortedIds()` now filters consumption limits beside the target group, and
  `ContractDetail::seoRankSummary()` makes the SEO surfaces read that same universe at the
  pinned 5 000 kWh basis. Second bug fixed on the way: the global rankings always count
  household contracts, so a business contract's title quoted a market its hero was not in.
- **Rank 1 compares to the runner-up.** `cheaperContracts` is empty by definition at rank 1,
  so the two verdict cells rendered "Ei vertailutietoa" / "Ei tietoa" on the single page with
  the strongest claim to make. New `ContractRankingService::getNextCheapestContract()` returns
  the contract directly behind, and the box states "n. X €/v halvempi kuin seuraavaksi halvin
  (Nimi)" exactly as the approved mockup does. On the live data that is Cheap
  Markkinahintasähkö vs SE Oikea - Yleissähkö, 4 €/v. Degradations: equal cost states "Yhtä
  edullinen kuin seuraavaksi halvin (Nimi)"; a genuinely single-contract universe states
  "Ainoa vertailukelpoinen sopimus tällä kulutuksella" and the second cell is dropped.
- **The qualifier sentence lives in PHP, not in Blade.** `ContractDetail::getPriceQualifierProperty()`
  resolves the category through `ContractCard\PricingCategoryResolver` (so the sentence cannot
  contradict the card band) and builds one sentence from typed fields. This is deliberately the
  same rule as `ContractCardCopy`, and it is placed so Phase 2 can move it onto
  `ContractCardPresenter` without rewriting copy. Order of decision is spot → reset →
  consumption effect → fixed, mirroring "market wins over consumption effect".
- The market-reset qualifier now repeats facts that the existing neutral reset notice below the
  hero also states. Left in place on purpose: removing that notice is a composition decision for
  Phase 4, not a Phase 1 side effect.

## 2026-07-26 Phase 1 investigation: the 6,99 vs 1,29 margin contradiction (RESOLVED)

Contract `9nspx1-cheap-energy-finland-oy-cheap-markkinahintasahko-perusmaksu-0-ensimmaisen-kuukauden-ajan`
(Cheap Energy Finland Oy, `pricing_model = Spot`, `spot_price_selection = NasdaqMonthly`).

**Upstream is not contradictory. Both numbers are true and they are different things.** The
seller's own `extra_information_fi` states the whole mechanism:

> Cheap Markkinahintasähkö -sopimuksessa on kiinteä energiahinta **6,99** snt/kWh + perusmaksu
> 0 €/kk **ensimmäisen kuukauden ajan**. Ensimmäisen kuukauden jälkeen sähköenergian hinta
> muodostuu NordPool-sähköpörssin Suomen hinta-alueen kuukauden keskihinnasta, johon lisätään
> **marginaali 1,29** snt/kWh + perusmaksu 4,99 €/kk.

So 6,99 c/kWh is the **first month's flat all-in energy price** and 1,29 c/kWh is the **margin
from month two onward**. The upstream API sends only the promotional 6,99 as its `General`
component; the ongoing mechanism exists in the description alone. The LLM interpretation read it
correctly — `canonical_pricing` has two phases, `energy_general 6.99` for one month then
`spot_margin 1.29` open-ended. The price history confirms it is a real repricing product, not a
stale marketing string: the same lineage's `General` component moved 9,77 → 8,99 → 7,73 → 6,49 →
6,99 between February and July 2026.

Voltikka had three separate defects on top of correct source data.

**C. Pricing (fixed, the important one).** `CanonicalContractPriceCalculator` priced the entire
twelve months at the one-month promo rate: 404,39 €/v instead of 486,36 €/v at 5 000 kWh. Cause:
`effectiveBilledComponents()` lets a phase inherit any component type it does not itself state, and
`basePricingPhase()` broke a component-count tie in favour of the *earliest* phase. The
continuation phase states only `spot_margin`, so it inherited `energy_general 6.99` from the intro
phase, and `resolvePhaseRates()` prefers a fixed rate over the spot base (`$rate = $general ??
$spotDay`) — the inherited promo rate silently overrode the phase's own spot mechanism. This is
exactly the deceptive-promo shape the canonical engine exists to catch, and it was live.

Fixed by making the two per-kWh mechanisms mutually exclusive for inheritance: a phase that states
`spot_margin` never inherits `energy_*`, and vice versa. Inheritance inside one mechanism is
untouched. **Blast radius measured on all 425 active contracts at 5 000 kWh: exactly 3 change**, all
fixed-then-spot shapes, and the other two move *down* because their spot continuation is cheaper
than the fixed term they had been inheriting (Hehku KIINTEÄ 6 kk 609 → 568 €/v, Cheap Määräaikainen
6 kk 570 → 542 €/v). No comparability verdict changes. Detail in
`laravel/app/Services/CanonicalPricing/AGENTS.md`.

**A. Display (FIXED in Phase 2).** `contract-detail.blade.php` hard-labelled the
relational `General` component "Marginaali (yhtiön lisä)" for every `pricing_model = Spot`
contract. On this contract that value is the intro energy price, so the page prints "Marginaali
6,99 c/kWh" a few hundred pixels above the seller text saying the marginaali is 1,29. Canonical
pricing already knows better (`spot_price_margin` is null in the current phase because the current
phase has no margin); the template ignores it and reads the raw relational row.

**B. Display (FIXED in Phase 2).** The same block computed
`Energiahinta (arvio) (spot + marginaali)` from `$calculatedCost['spot_price_margin'] ?? 0`. When
canonical returns a null margin the row silently prints the bare spot average as if it were the
total energy price — a third number that agrees with neither of the other two.

A and B were left alone in Phase 1 deliberately: another executor held `contract-detail.blade.php`
in the same working tree, and Phase 2 replaced those rows with `ContractCardPresenter` output.

**Recommended display treatment for Phase 2 (implemented as recommended).** Never label a
relational component from `pricing_model` alone; read the margin from the calculated payload. For
a phase-switching product like this, show two dated receipt rows the way the reset contracts
already do, plus a matching perusmaksu pair. See the Phase 2 entry below for what shipped.

## 2026-07-26 Phase 1 investigation: the unstyled-render flake (cause found)

Root cause is the classic stale-HTML-vs-purged-hash race, and both halves are confirmed against
production.

- `App\Http\Middleware\SetPublicCacheHeaders` puts `contract.detail` (and the comparison routes)
  behind `public, max-age=300, s-maxage=3600, stale-while-revalidate=86400`. Verified live:
  `curl -I` on the contract detail page returns exactly that. Railway's edge can therefore serve
  that HTML for an hour, and serve it **stale for a further 24 hours** while revalidating.
- Asset filenames are content-hashed and the Docker image runs a clean `npm ci && npm run build`,
  so the previous release's file is simply absent from the new container. Verified live: a
  non-existent hash returns 404 (`/build/assets/app-DEADBEEF.css`), and the local build hash
  (`app-DJivE0kp.css`) already differs from what production serves (`app-C9oEGsDO.css`).

A visitor served post-deploy stale HTML therefore requests a stylesheet that 404s and gets a page
with no CSS at all. A reload fixes it once the edge has revalidated, which is exactly the observed
behaviour (seen once, fixed by reload).

Second, smaller finding: `/build/assets/*` went out with **no `Cache-Control` at all**, only
`ETag`/`Last-Modified`, so every page view revalidated the CSS and JS over the network.

**Fixed:** `laravel/Caddyfile` now sends `Cache-Control: public, max-age=31536000, immutable` for
`/build/assets/*`. Scoped to `assets` on purpose — `build/manifest.json` has a fixed name and must
stay revalidated. Verified by running the real `dunglas/frankenphp:1-php8.4` image against a fixture
tree: the hashed asset gets the immutable header, `manifest.json` and `robots.txt` are untouched.

**Not fixed, needs Seppo's decision** (each is a policy or deploy-process change, not a code fix):

1. *Shorten the stale window on HTML.* `stale-while-revalidate=86400` is what makes the race last a
   whole day. Dropping it to roughly the deploy frequency (say 300 s) shrinks the exposure to
   minutes. Costs some edge hit rate.
2. *Retain the previous release's assets.* The proper fix, but it needs the build to publish
   `public/build` to a persistent store (bucket or volume) instead of baking it into an immutable
   image. Largest change, removes the race entirely.
3. *Client-side recovery.* Detect a failed stylesheet load in `layouts/app.blade.php` and hard-reload
   once, guarded by `sessionStorage` so it cannot loop. Cheap and works regardless of edge
   behaviour, but it puts an automatic reload on every page and needs care: with
   `max-age=300` the browser may re-serve the same stale HTML, so the retry has to bust the cache.

Option 1 plus option 3 is the cheapest combination that actually closes it. None was implemented
without a decision because all three change production caching or navigation behaviour.

## 2026-07-26 Phase 2: the detail page becomes the third presenter consumer

`ContractDetail::$card` presents the viewed contract through `ContractCardPresenter` in a new
`detailed: true` mode, and the page renders the shared `x-card.band`, `x-card.receipt` and
`x-card.footer` components from it. What now comes from the presenter: the pricing category band
(with the card tints), the itemised price rows, the coral warning pills, the seller CTA, and the
contract name normalization. What stays page-local: the hero price and its qualifier sentence, the
market-reset notice, the integrity notice, the rank/verdict box, the full component history and its
trend chart, the version timeline, and the VAT note. The hand-rolled `x-contract-price-row` block is
gone; the component file and `ContractDetail::$discountedComponents` are now unused and can be
deleted in a later phase.

Decisions worth keeping:

- **The promo-then-spot rows are driven by the mechanism, not by the rate.** A new
  `CardReceiptLines::mechanismSwitchPhases()` fires only when two adjacent phases price the same
  kWh by different mechanisms (flat energy → spot margin, or the reverse). A rate change inside one
  mechanism keeps the existing scheduled-change or market-reset rows. On the detail page Cheap
  Markkinahintasähkö now reads: "Energia 25.8. asti 6,99 c/kWh", "Pörssin keskihinta 12 kk 7,77
  c/kWh" (soft), "Marginaali 26.8. alkaen 1,29 c/kWh", "Perusmaksu 25.8. asti 0,00 €/kk",
  "Perusmaksu 26.8. alkaen 4,99 €/kk". On a card the same contract gets the first, third and a
  single fee row, because the three-row card cap is unchanged.
- **The dates and rates travel with the cost payload.** `CanonicalContractPriceCalculator::buildBreakdown()`
  now records each governing phase's resolved `window_start` / `window_end` and its `uses_spot` /
  `energy_cents` / `spot_margin_cents` / `monthly_fee`. The alternative was re-resolving phase
  boundaries inside a presenter, which would be a second implementation of the phase-timeline
  algorithm. Cost: a payload shape change, so `ContractListCacheService` and `ContractPageCacheVersion`
  are at schema v3 and the detail prepared key is v11.
- **The CTA ladder ends somewhere true.** `order_link` → `product_link` → `company.company_url` →
  the company's Voltikka page, and the label changes on that last rung ("Katso myyjän tiedot"), so
  a fallback never promises the seller's order form. Coral on every rank, per the earlier decision.
- **`ContractDetail::getPriceQualifierProperty()` stays in the component.** Moving it onto the
  presenter is only worth doing together with the hero rewrite in the editorial phases; it already
  resolves the category through `PricingCategoryResolver`, so it cannot contradict the band.
- Two existing tests were asserting artefacts of the removed block:
  `test_metering_type_is_displayed` passed on the HTML comment `<!-- General metering (non-spot) -->`
  (it now asserts "Yleissähkö"), and a preset-filter test asserted the bare substring "2 000 kWh",
  which the new "Max 12 000 kWh/v" cap pill contains.

## 2026-07-26 Phase 3A: consumption picker, static cost table, counterfactual

- **The rank cannot follow an arbitrary kWh number, so it snaps and says so.** Rank,
  comparison size, cheaper tiles, the verdict gap, the counterfactual and the same-type
  alternative all need every active contract priced at one consumption, and only
  `ContractListCacheService::PRESET_CONSUMPTIONS` holds that. Building it for a number typed
  into a public text field would put an uncached full-market calculation behind an input and
  give the cache unbounded cardinality. `ContractDetail::rankConsumption()` therefore snaps a
  free value to the nearest preset and `rankBasisNotice` states the basis. All four chips are
  presets, so the primary interaction stays exact; the hero price, receipt, cost-table
  highlight and CO2 figures use the typed number exactly. The failure mode this replaces is
  worse than the snap: `getCachedMetrics()` returns null for a non-preset, so the entire
  verdict box would vanish at 7 000 kWh.
- **`?kulutus=` stays mount-only and is deliberately not `#[Url]`.** Arriving deep links
  preselect, changing a chip does not rewrite the URL. A URL-bound consumption would turn
  every interaction into a crawlable variant of a page whose canonical is param-free, and a
  strict typed `#[Url]` int is the exact shape that produced hydration errors for
  `ContractsList::$page` when bots request `?kulutus=`. It also keeps `request()->query() === []`
  meaningful as the prepared-cache guard.
- **The cost table lives inside the cached canonical payload; the highlight does not.** The
  four rows are the same for every visitor, so they are cache-safe; only the highlighted row
  reads the selected consumption, and it is resolved in the template. Per-user consumption
  state never reaches that cache anyway, because a Livewire update is a POST.
- **Two different reference contracts, on purpose.** A fixed or reset contract is compared
  with the **median** spot contract, because "what if I had taken pörssisähkö" asks about the
  typical outcome; a spot contract is compared with the **cheapest** fixed contract, because
  certainty is bought deliberately and you would buy the cheapest of it. Both sides are read
  at the rank-basis consumption so the two numbers are always on the same footing, and the
  sentence names that consumption. The korpela mockup used the cheapest spot contract for the
  first case; the median is the honest form of "typical" and matches the statistics-page basis
  the task specified.
- **No second spot-price derivation.** Every spot total in `getBucketCostSummary()` already
  comes from the trailing-12-month realized spot average plus that contract's own margin, so
  the bucket median embodies a typical margin without recomputing anything.
- **`ContractRankingService::getBucketCostSummary()` is where the bucket SQL goes**, not the
  Livewire component: it reuses the existing eligible-sorted-ids filtering, so the
  counterfactual and the same-type tile describe the same market as the rank beside them.
- **Found while testing: NULL `canonical_pricing` makes a contract invisible to every
  bucket.** This is the known three-valued-logic gap recorded in `ContractCard/AGENTS.md`. No
  active production contract is in that state, but every fixture was, so both new features
  found nothing until `createComparisonContract()` started setting a minimal
  `canonical_pricing`. Worth remembering for any future bucket-scoped feature.
- Two query guards in `ContractDetailPageTest` moved from 4 to 8. The extra reads are the
  shared listing metric cache building one payload per reference consumption on a cold cache;
  the count is constant, not proportional to the replacement chain, and production warms all
  four presets during `contracts:fetch`.
- Savings chips on the alternative tiles moved from emerald to neutral slate, per DESIGN.md
  (green and red are the CO2 delta's).
- The old standalone white consumption-picker section below the hero is gone; it is now the
  hero's second beat. The active chip is a white-on-dark inversion, never white on coral.

## 2026-07-26 Phase 3B: verdict paragraph, FAQ + FAQPage schema, terms grid

- **The top-25 tier needs a percentile guard that the hero verdict does not.** The hero's
  `Yksi halvimmista` tier is deliberately absolute top-25 (recorded above). The verdict
  paragraph cannot reuse that rule, because it prints the counts in the same sentence: in a
  two-contract universe rank 2 is the most expensive contract there is, and "vertailun
  kärkipäässä: 2 sopimuksesta 1 on halvempi" contradicts itself. The paragraph requires
  `rank <= 25` **and** `percentile <= 0.33`. Found by a test, not by reading.
- **The FAQ's cost item is per-consumption, so the FAQPage schema is too.** That is safe only
  because the prepared payload caches the canonical 5 000 kWh state alone and every `?kulutus=`
  variant is non-indexable behind the param-free canonical. Do not lift the FAQ out of the
  prepared payload into a separately cached surface without re-checking that.
- **`irtisanomisaika` does not exist as data.** No column and nothing in the interpretation
  schema carries it. The two-week consumer notice period on an open-ended electricity contract
  is a market fact the site already states in `SeoContractsList` and the määräaikainen article,
  so the terms row derives it from `contract_type` alone and the grid ends with "Tarkista
  ajantasaiset ehdot myyjän sivuilta ennen tilausta". Do not invent a per-contract figure.
- **"Hinta määräajan jälkeen" is a typed verdict, not a gap.** It renders only for
  `comparability = term_price_only`, which means the canonical engine established that the only
  unpriced part of the year is after the term. That is information; "Ei tietoa" is not, and no
  row is emitted for anything else.
- **Consumption caps reuse the card's relevance threshold.** Every page printed "Enintään
  200 000 kWh/v" until the terms grid adopted `CardFooterItems`' 30 000 kWh rule. Keeping one
  threshold means the coral cap warning and the terms row can never disagree about whether a
  cap matters.
- **Two billing-frequency values are noise and are now removed at the sanitizer.** 273 contracts
  store the interval as a bare `"12"` (renders as "Laskutusväli 12") and 112 store
  "Ei ilmoitettu". `ContractContentSanitizer::billingFrequencyLabels()` expands the first from
  the unambiguous vocabulary in the same column ("12 laskua vuodessa", "12 krt/v") and drops the
  second. The rule went into the shared sanitizer, not the component, per the source-text
  hygiene note in `app/Livewire/AGENTS.md`.
- **`termMonths()` falls back to `fixed_time_range`.** `calculated_cost.term_months` is null
  whenever the Hybrid branch claimed the contract first, so a 6 kk hybrid's cancellation answer
  read "sovitun sopimuskauden ajan" with no number. Only the exact buckets (Fixed6/12/24) are
  used; `Between711` is a range, not a number.
- **The three new sections are flat inside the existing shells, not flat on the page.** The
  approved structure is fully editorial, but the rest of the page is still card-based until
  Phase 4. "Kannattaako X?" sits flat on the page surface (it lives between the hero and the
  alternatives, where `#halvemmat` already renders a bare h2), while the FAQ and terms sections
  keep their column's white surface and are flat **inside** it: h2 + hairline rule, no nested
  card containers. Phase 4 removes the shells with the rest of the composition, and the internals
  are already in the target shape.
- The seller description was deliberately left expanded. The approved structure collapses it
  inside the terms section; that is a composition move for Phase 4, and doing it here would have
  been a side effect rather than a decision.

## 2026-07-26 Phase 3C: bill comparison module on the detail page

- **The form was extracted, not copied.** The spec allowed reuse or extraction; the in-listing
  form was inline in `seo-contracts-list.blade.php`, so a detail-page copy would have been a
  third divergent field set within a week. The bill inputs now live in
  `app/Livewire/Concerns/BillComparisonInputs.php` and the fields in
  `resources/views/partials/bill-comparison-form.blade.php`; `ContractsList` and `ContractDetail`
  both consume them and keep only `recomputeBill()` (invalidation + its own Plausible `source`)
  and `billInputsEnabled()`. `/maksatko-liikaa` deliberately stays on its own property names: it
  owns the annualized hero, the ranking table and the `annualKwh` override, so folding it in
  would have meant rewriting the one surface nobody asked to change.
- **The heating toggle from the mockup was not implemented.** In a period-basis surface it only
  selects the seasonal annualization profile, and no annualized figure is shown; its single real
  effect is which annual kWh the consumption-cap check uses. A control that appears to matter and
  does not is worse than its absence, and the in-listing surface does not offer it either.
- **The service now explains a missing row instead of only omitting it.**
  `periodRowsForContracts()` returns an `unavailable` id → reason map
  (`consumption_cap`, `not_comparable`, `no_spot_history`, `no_pricing`) filled through a
  `&$reason` out-param on `buildMarketRow()`. A listing can silently drop a contract; a
  one-contract module cannot, and the alternative was re-deriving cap and spot-history logic in
  the Livewire component, i.e. a second implementation of the eligibility rules.
- **Delta presentation.** Saving is the neutral dark `slate-900` pill and paying more is a coral
  warning pill (the same language as the card warning pills), per the two approved mockups;
  green/red stay the CO2 delta's. A delta below 0,50 € reads "Kustannus olisi ollut suunnilleen
  sama" rather than naming a winner the arithmetic does not support. The pay-more case links to
  `#halvemmat`, guarded on that section actually existing.
- **Self-verification.** The module states the implied c/kWh of the period including the base
  fee beside the two totals, so the answer can be checked against the receipt rows on the same
  page instead of being asserted. Measured on live data: SE Oikea Yleissähkö, 380 kWh in June,
  31,41 € = 8,27 c/kWh; the spot example resolves to 25,13 € = 6,61 c/kWh against a realized
  period spot average of 5,71 c/kWh.
- **Cache safety has three independent guards**, because one of them is invisible in tests:
  `render()` merges the module beside the prepared payload rather than into it; the derived
  result is a `protected` cache, so it also stays out of the Livewire snapshot (the
  `CorruptComponentPayloadException` lesson from `BillComparison::$resultArray`); and
  `isDefaultContractDetailCacheable()` refuses an active bill on top of the GET + empty-query
  rule. The prepared cache key is unchanged by bill state, which is asserted.
- **Known limitation, inherited and left alone.** `BillComparisonService` prices the period from
  the **relational** components, so on a mechanism-switch contract (flat promotional energy price
  then a spot margin, e.g. Cheap Markkinahintasähkö) it reads the promo rate as the spot margin.
  The receipt rows above the module come from canonical pricing and state the mechanism
  correctly, so the two surfaces can disagree on that one contract shape. It is pre-existing and
  identical in the in-listing mode; fixing it belongs in the service, for all three surfaces at
  once, not in this module.
- One existing assertion had to be scoped:
  `test_free_consumption_input_clamps_and_clears_the_chip_selection` asserted the absence of
  `aria-pressed="true"` anywhere on the page to prove no consumption chip was selected. The bill
  module's period preset chips are a second `aria-pressed` control, so the assertion now matches
  `data-consumption-preset="…" … aria-pressed="true"` specifically.

## 2026-07-26 Phase 3D: "Näin hinta on kehittynyt"

- **Two variants, because the honest question differs by pricing model.** A non-spot contract
  gets its own observed energy price as a stepped ink line over the median of its
  `contract_price_daily_statistics` segment. A **spot** contract instead gets the realized
  monthly market average plus its margin, against the trailing-12-month average, exactly as
  `rank1-spot.html` does: a spot contract's own price history is only its margin, which is
  almost always flat and teaches nothing, while volatility is the fact a spot buyer needs.
- **One chart, not two.** The page already had a "hero trajectory" sparkline above the version
  timeline. It was removed rather than kept beside the new chart: it told a weaker second
  version of the same story, coloured price movement amber/emerald against DESIGN.md, and
  stated the change as a percentage. The version timeline itself survives, collapsed past its
  three newest entries into a `<details>`, with both lists rendering one new partial so the
  visible and collapsed paths cannot drift.
- **Server-rendered SVG, not the `data-line-chart` JS renderer.** `/sahkosopimus/tilastot`
  hands a JSON payload to `resources/js/contract-price-statistics.js`, but that bundle is a
  page-specific entry and the detail page does not load it. Rendering in Blade from a PHP
  payload also keeps the numbers in the initial HTML (this page is an SEO landing page), keeps
  them inside the prepared view-data cache, and makes them assertable in feature tests. The
  hover tooltip is a small Alpine handler over transparent SVG bands, and an `sr-only` table
  mirrors the same rows.
- **The contract series uses the statistics service's own representative-energy weighting**
  (General, else day/night 15:9, else seasonal 5:7). Charting a time-metered contract on its
  `DayTime` component alone would place it above a median that blends day and night, and the
  overlay would misstate the gap. For the same reason
  `ContractPriceStatisticsService::segmentKey()` became public/static and `SEGMENT_LABELS`
  moved beside it: the overlay must name the segment the daily aggregation actually wrote.
- **Change points and the observation window are separate things.** Voltikka imports every
  contract daily, so the raw series is mostly repeats. The first implementation appended a
  terminal "still this price today" point to the change list for drawing, and the behaviour
  record then counted it as a change: a contract whose price never moved reported
  "Energianhintaa laskettu kerran". `changeSeries()` now returns the real changes plus
  `last_date`, and only `plateauPoints()` adds the terminal point.
- **A window under 21 days renders a sentence, no chart and no fact tags.** Two observations
  five days apart cannot support "ennallaan koko seurannan ajan" either, so the behaviour
  record is empty in that case rather than confidently wrong. The spot variant needs three
  completed months and excludes the running month, whose average is partial.
- **A null `spot_price_margin` is left out, never guessed.** This is the Cheap Markkinahintasähkö
  shape again: its tracked `General` component is a flat 6,99 intro price, not a margin. When
  the calculated payload has no margin the chart plots the bare market average, the note says
  so, and the "Marginaali ennallaan" tag is suppressed because the tracked component is not the
  margin. The tag is emitted only when the tracked value matches `spot_price_margin`.
- **Point markers are dropped past 12 change points.** Found on real data: Lumme Vuosisähkö
  6 kk repriced 45 times in about 3 months across its 49-version replacement chain (verified as
  genuine daily repricing, not same-date chain collisions), and 45 dots made the staircase
  unreadable. The fact tag "Energianhintaa muutettu 45 kertaa 3 kuukaudessa" is exactly the
  seller-behaviour record this module exists to publish.
- Verified in the browser on three live shapes: a fixed open-ended contract with a median
  overlay, a spot contract with 12 monthly averages, and the 49-version near-daily repricer.
  The two direct end labels are pushed apart when the series end within 14 px of each other,
  because they share the right edge.

## 2026-07-26 Phase 4: the editorial composition pass

The page is now the approved structure: one column on a white surface, sections as
`h2` + hairline + whitespace, card chrome only on the three alternative tiles. Verified
in the browser on a market reset (Cheap Kvartaalisähkö), a spot contract (Nurmijärven
Pörssisähkö Plus), the rank-1 contract (Vaasan Vaikuttaja yrityksille, a consumption
effect), a 12 kk fixed term and a 6 kk hybrid, at 1280 px and at 390 px.

- **The boxed verdict card was dissolved into one sentence beside the price.** It carried a
  tier strip (`Halvin sopimus — N vertailussa`, `Yksi halvimmista`, `Keskihintainen`, …) in
  emerald / amber / red, which is both a second price-colour semantic and an em dash, and its
  two labelled cells competed with the number they were qualifying.
  `ContractDetail::getHeroVerdictProperty()` now returns rank, comparison size, one money
  clause, the rail marker position and the small print, all generated in PHP at
  `rankConsumption()`. Rank 1 still names the runner-up and still never renders an empty
  state; the three degradation branches survive unchanged.
- **The "why the leaders are spot" sentence is measured, not asserted.** The mockup's line
  ("edelle sijoittuvat ovat pääosin pörssisähköä") describes every contract ahead, which
  needs a query the page does not run. The implementation counts the already-loaded
  `cheaperContracts` and says "vertailun halvimmat", which is exactly the set it counted.
  Do not upgrade the claim without upgrading the evidence.
- **Three price displays became one.** Hintatiedot carried a "Kuukausihinta (12 kk
  keskihinta)" mini-hero, and a promoted contract additionally got a TARJOUS ticket with a
  coral side stripe, a strikethrough normal price and a green savings chip. Both are gone.
  The promotion is one quiet receipt note ("Tarjous on huomioitu arviossa vain
  voimassaoloajaltaan: säästät noin X € ensimmäisenä vuonna…"), which is the treatment Seppo
  asked for in the mockup round. The hero's emerald "Sisältää tarjouksen" chip went with it.
- **The market-reset notice was dissolved, not moved.** Measured against what the page
  already says: the band states the cadence, the receipt rows separate the known current
  period from the estimated tail, and the qualifier states the current price, its end date
  and the 12-month equivalent. Only two facts were unique to the notice, so
  `ResetEstimateCopy::detailNotice()` was replaced by `receiptNote()`, which states the tail
  start month and the forward vintage and nothing else. Its three copy tests moved with it.
- **One environment module, one CO2 taxonomy.** The hero aside used four tiers
  (emerald/lime/amber/red), the section below used five (adding orange/rose), and the origin
  breakdown sat in a third panel. They are one section, "Sähkön alkuperä ja päästöt", on
  DESIGN.md's three tiers. The Finnish-scale gauge was dropped (a decorative gradient ramp
  with two markers) and the kg/km figures are set at `text-3xl`, well under the hero price,
  because the brief is explicit that the residual mix must not rival the money. The methods
  disclosure kept its per-source tables and lost its **Energiavirasto** citation, which was
  a live violation of the never-name-Energiavirasto rule.
- **The hero badge links moved to "Sopimusehdot lyhyesti".** They are SEO-load-bearing
  internal links (`/maaraaikainen`, `/yleissahko`, `/porssisahko`, `/joustosahko`, …) and
  four tests assert them, but a pill row in the editorial hero reintroduced exactly the
  competing-elements problem the rework removed. They read as a "Vertaa samankaltaisia" line
  where the terms they describe are stated. Link equity is unchanged.
- **Deviation from the approved structure, with reason: there is no separate page-level
  footer trust block.** The mockup has one because it is a standalone file; the real page
  inherits the site footer, which already says "ei ota provisiota … eikä rahoita toimintaansa
  mainoksilla". Adding a third statement would break the explicit "ei provisiota x2 max"
  rule. The page closes with a method statement instead, which says something the footer does
  not. The literal phrase "Takaisin sopimuksiin" also went with the old back link: the
  breadcrumb is the back navigation now, and its `Sähkösopimukset` crumb carries `?kulutus=`,
  so the state requirement behind that item is met.
- **Deviation, with reason: the hero is about 1 000 px at 390 px, not 844 px.** Price,
  verdict line and rail are inside the first screen; the chips and CTA follow. Every element
  the approved structure lists is present, and reaching one screen would mean deleting one of
  them. Paddings and the breadcrumb were tightened as far as they go without that.
- **The sticky bar uses a scroll listener, not IntersectionObserver.** The rule is a position
  test (`#hero-cta` rect `bottom < 0`), not a visibility test, and the same rect check also
  answers "is `#halvemmat` in view" and "is the footer in view". One listener, three
  conditions, and the below-the-fold case cannot be got wrong. Verified: hidden at scroll 0
  and 300, shown at 1400, hidden again over the alternatives.
- **Contrast and tap targets.** The CTA is flat `coral-600` at 19px/700 (the gradient pair
  put white on `coral-500`, 2,8:1); this is a deliberate deviation from DESIGN.md's gradient
  CTA. The `Arvio` pill needed `!py-[11px]` to reach 44 px past the component's card-sized
  padding. Links inline in running text are **not** padded to 44 px, because that breaks the
  line box; they stay above the 24 px AA floor.
- **Dead code removed**, re-verified unused first: `components/contract-price-row.blade.php`,
  `ContractDetail::getDiscountedComponentsProperty()` and
  `ContractDetail::getPriceChangeInfoProperty()` (the last was only ever passed to the view
  and no template read it). The prepared payload lost `latestPrices`, `discountedComponents`
  and `priceChangeInfo`, so its cache key is at **v15**.
- Six existing assertions moved with the restructure and none were weakened: the two verdict
  strip strings became `Sija N` + `N sopimuksesta`, the rank-1 cell labels became the
  comparison clause the hero now prints, `yhteensä 145 €` became `145 € vuodessa`, and the
  last-seen-on-sale date is asserted apart from its sentence because it gained a
  `<time datetime>`.


## Open questions

- Whether two-decimal precision on the futures-derived estimate (9,63 c/kWh) undercuts the
  "arvio" framing; the forecasting backend already produces p20-p80 ranges that could be
  shown instead. (The CO2 km-equivalence question is resolved: it lives in the environment
  module and reacts to the consumption selector.)
