---
target: single contract detail page
total_score: 21
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 4
timestamp: 2026-07-26T07-06-40Z
slug: resources-views-livewire-contract-detail-blade-php
---
# Critique: Contract detail page (`contract-detail.blade.php`)

Method: multi-agent (A: design-director review · B: detector/browser evidence · C: naive-user simulation), isolated until synthesis.
Live pages inspected: Cheap Markkinahintasähkö (spot promo, rank 1), SE Oikea Yleissähkö (fixed), Korpela Kvartaali (market reset, rank 113), Helppo Pörssisähkö (hybrid w/ consumption cap, rank 1 at 2000 kWh). Desktop + 390px (emulated via iframe; window resize failed).

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | Rank-1 contracts render "Ei vertailutietoa / Ei tietoa" in the hero verdict box; title says 291 contracts, hero says 299 |
| 2 | Match System / Real World | 2 | Reset-notice bullets in broken/model-speak Finnish; "Hybridisähkö" jargon chip vs card's plain "Kiinteä hinta + kulutusvaikutus" |
| 3 | User Control and Freedom | 2 | "Takaisin sopimuksiin" drops the visitor's kulutus selection; no free kWh input; missing consumption chips on capped contracts unexplained |
| 4 | Consistency and Standards | 1 | Two CO₂ severity taxonomies on one page; emerald/amber/red as price semantics vs DESIGN.md; "EUR/kk" vs "€/kk"; em dashes; CTA present on some contracts, absent on others |
| 5 | Error Prevention | 3 | Consumption clamping works; little else to prevent on a read-only page |
| 6 | Recognition Rather Than Recall | 2 | User must reconcile 6,95 vs 9,63 c/kWh vs 44,1 €/kk with no bridging arithmetic; margin 6,99 vs seller's stated 1,29 unresolved |
| 7 | Flexibility and Efficiency | 2 | 4 presets only; no per-consumption table; no same-type alternative comparison |
| 8 | Aesthetic and Minimalist Design | 2 | Environment section rendered twice in full, price three times; uncollapsed 11-version timeline; raw marketing dump closes the page |
| 9 | Error Recovery | 2 | Broken empty states ship live ("Ei tietoa" beside an active Vertaa button); 404 logos render as blank squares, fallback never triggers |
| 10 | Help and Documentation | 3 | "Näin laskemme" beside the price and CO₂ sources are good; no FAQ or glossary |
| **Total** | | **21/40** | **Acceptable — significant improvements needed** |

Scores 1, 3, 9 were lowered one point from the unanchored design review after live-browser and user-simulation evidence showed the failures are systematic on production, not edge cases.

## Design Specificity Verdict

The top half is genuinely Voltikka; the bottom half is nobody's. The dark commit-moment hero, giant tabular €/kk, verdict strip ("Keskihintainen — sijalla 113/299"), and honesty copy ("Vuosihinta on arvio, ei hintalupaus") could not ship on a competitor's site. Below the hero the page decays into interchangeable Tailwind sidebar cards and a raw provider-marketing dump, and the color language is a previous design generation: emerald/amber/red used as price semantics, which DESIGN.md reserves strictly for CO₂ tiers, plus amber warnings (banned; warnings are coral).

Deterministic scan: 1 finding across 7 files — a `border-l-4 border-amber-400` side-tab accent on the inactive-contract banner (contract-detail.blade.php:386), a pattern DESIGN.md bans outright. The browser pass found a second side-stripe (orange bar on the TARJOUS price card) the CLI missed, plus a mechanical tap-target sweep: 44 of 46 visible links/buttons at 390px are under 44px. Overlay injection failed (Chrome private-network-access blocks a localhost script on a public HTTPS page), so no in-page overlay exists; evidence is from screenshots and DOM measurement.

## Overall Impression

The page has world-class bones and a credibility problem in the details. Its unique assets (rank verdict, cheaper-alternatives module, price history timeline, honest reset disclosure) are exactly what an anti-affiliate comparison product should have, and no Finnish provider page can copy them. But the page a user visits to decide is the one surface `ContractCardPresenter` does not serve, so it shows *less truth* than the listing card that brought them, and it ships broken data states (empty verdict on rank-1, triplicated billing interval, contradicted margin, 404 logos) on a product whose entire pitch is data trustworthiness. The single biggest opportunity: make the detail page the canonical, presenter-driven answer to "is this contract good for me?", with a plain-language verdict Google can snippet.

## What's Working

1. **Verdict strip + "112 halvempaa vaihtoehtoa"** — rank, € gap to cheapest, and cheaper cards with "Säästä 124 €/v" chips on a contract's own page. The skeptic persona explicitly concluded "not salesy, opposite of salesy." No provider does this.
2. **Sopimushistoria timeline** — "Marginaali 8,99 → 6,99, −22 %" with dated versions was the naive user's genuine delight moment ("these guys actually track things"). Uncopyable data.
3. **Market-reset honesty** — current-period price and 12-month estimate as two separate figures with "Vuosihinta on arvio, ei hintalupaus" volunteered exactly what a provider would hide.

## Priority Issues

1. **[P1] The "is this good?" answer is broken on the best pages.** Rank-1 contracts (the most-clicked from listings) render "Halvin sopimus — 299 vertailussa" with cells "HINTAERO HALVIMPAAN: Ei vertailutietoa / HALVIN SOPIMUS: Ei tietoa"; the title disagrees (291 vs 299). This was the first-viewport trust-killer in the user simulation. Fix: when rank = 1, show the gap to the second cheapest ("Seuraavaksi halvin on +4 €/v kalliimpi"); never render placeholder cells; reconcile the counts.
2. **[P1] The detail page is a third, drifted pricing surface.** The hybrid page shows "Energiahinta 0,00 c/kWh" with the consumption-effect row, category band, and "Max 4 200 kWh/v" warning all gone; the reset page states "Energiahinta 6,95 c/kWh" unqualified where the card says "Energia nyt, 30.9. asti / Loppuvuosi, arvio"; the Vattenfall page has no order CTA at all. The page users visit to verify shows less truth than the card. Fix: drive Hintatiedot, warnings, and the category band from `ContractCardPresenter`.
3. **[P1] Data-hygiene debris on a trust-first product.** Voltikka's table says "Marginaali 6,99 c/kWh" while the seller's description on the same page says "marginaali 1,29 snt/kWh" (unresolvable for a user); "Laskutusväli: 1, 2 tai 3 kk:n välein" triplicated with trailing comma on every page checked; 404 logo URLs render blank squares; the page *ends* on a raw marketing dump with a stray quote and "TÄÄLTÄ" (peak-end failure). Fix: dedupe billing frequency, onerror logo fallback, sanitize/summarize descriptions, and surface or resolve the margin conflict.
4. **[P1] Wholesale color-system violation.** Emerald/amber/red used as price-tier and savings semantics, amber warning notices, two conflicting CO₂ taxonomies ("KORKEA" vs "ERITTÄIN KORKEAT PÄÄSTÖT" for the same number), coral side-stripes. Fix: re-derive badges/notices from the card vocabulary (coral warnings, category tints, neutral deltas); pick one CO₂ taxonomy.
5. **[P2] Length and duplication.** Ympäristövaikutus rendered twice in full, the price three times, an uncollapsed ~2000px version timeline; the page is ~40% longer than its content. Fix: one compact hero CO₂ stat linking to one sidebar card; delete the mini-hero price repeat; collapse the timeline past 3 versions.
6. **[P2] Mobile ergonomics.** The hero spans ~2.5 phone screens; the order CTA sits ~1100px down; the consumption picker comes *after* the CTA (users commit at a default they never chose); 44/46 tap targets under 44px. Fix: compact hero on mobile, picker above CTA, minimum touch sizes.

## Persona Red Flags

**Jordan (first-timer):** No bridge from c/kWh to €/kk anywhere; "Hybridisähkö" never explained on this page; "13 962 km autolla" reads as a consequence of choosing this contract while the residual-mix explainer sits a viewport away; margin contradiction is unresolvable without domain knowledge.

**Casey (mobile, one-handed):** CTA ~1100px deep; consumption picker below the CTA; 11-version timeline is a ~2000px scroll wall between price and terms; "Vertaa" and "Siirry myyjän sivuille" are similarly weighted dark-surface actions in one thumb zone.

**Sam (screen reader/keyboard):** Heading order h1→h3→h2 with duplicate heading text across sections; emissions scale is bare positioned divs; `<time>` without `datetime`; "Vertaa" link's accessible name says compare-what?; CTA relies on UA default focus style.

**Skeptic (project persona, from simulation):** The no-commission line ("harrasteprojekti, joka ei ota provisiota") is the single best trust weapon and it's buried in the footer; "Spot 0,00 c/kWh" in the header reads as a broken widget; triplicated billing text reads as "what else is wrong?"

## Search-worthiness: why Google (and the searcher) should pick this page over the provider's

Already a real moat: independent 12-month total, rank among ~300, € gap to cheapest, observed price history, reset annualization, CO₂ derivation, rank-first meta titles, Product/Offer JSON-LD. Providers cannot show their own rank or price history. What's missing is the interpretive layer, ranked by leverage:

1. **Plain-language verdict paragraph ("Kannattaako X?")** — 2–3 generated sentences from rank/gap/pricing-type: who this contract suits, who should skip it. A provider legally cannot write "112 cheaper options exist"; it's the snippet for "onko X hyvä".
2. **Seller price-behavior record + price-vs-market chart** — "margin raised twice in 6 months", contract price overlaid on segment median from existing daily statistics. The honest substitute for "kokemuksia" queries, and pure dwell time.
3. **FAQ block with FAQPage schema** — "Paljonko X maksaa 5 000 kWh:lla?", "Miten kvartaalihinta määräytyy?", "Voiko X:n irtisanoa?" — captures People-Also-Ask for exact long-tail queries; generate from typed data as ConsumptionCalculator already does.
4. **Static per-consumption cost table** (2000/5000/10000/18000 kWh) — the interactive picker is invisible to crawlers; a plain table makes the page the canonical "X hinta" answer at every household size.
5. **Structured terms summary** — irtisanomisaika, fixed-term end behavior, availability caps, extracted from canonical interpretation and shown above (or instead of) the raw provider dump. The #1 thing users open the provider's PDF for.
6. **Spot counterfactual** — "Pörssisähköllä sama kulutus olisi maksanut n. X €/v viime 12 kk" on every fixed/reset contract; one sentence, data already exists.
7. **Same-type cheaper alternatives** — today's alternatives module shows only the absolute cheapest (all market-priced); a fixed-price evaluator is shown only spot escapes.
8. **Promote the no-commission declaration** from the footer to beside the CTA, where the buying decision and the trust question coincide.

## Minor Observations

- Possible duplicate "Sopimuksen kuvaus" h2 when both description fields exist.
- All-caps provider marketing survives into H1 and title tag.
- Hero grid leaves dead right-column space when the CO₂ factor is null.
- Mobile receipt rows wrap mid-parenthetical ("8,13 / c/kWh" on two lines) — no truncation, but ragged.
- One live flake observed: a fully unstyled page render (raw HTML) that persisted through one re-navigation until hard reload — worth checking asset-pipeline cache headers.
- Em dashes hardcoded in the verdict strip template despite the DESIGN.md ban.

## Questions to Consider

1. `ContractCardPresenter` exists because two card templates drifted; the detail page is a third hand-rolled pricing surface that has already drifted below the cards' honesty. Why is the page users *decide on* the one surface the presenter doesn't serve?
2. The second-largest number on the page is a CO₂ figure that mostly encodes "seller declared nothing." Hero-scale residual mix: honest data display, or a structural penalty on small municipal sellers that your own explainer admits is misleading?
3. Voltikka's uncopyable asset is observed seller behavior over time. Why does the page lead with a receipt any provider can print and bury the behavioral record, when "how this seller treats its customers' prices" is the actual answer to "kokemuksia"?
