# Contract detail page overhaul

## Goal

Redesign and harden the single contract detail page (`/sahkosopimus/sopimus/{id}`,
`app/Livewire/ContractDetail.php`, `resources/views/livewire/contract-detail.blade.php`) so that:

1. A visitor landing cold from Google gets the answer to "what does this cost, is that good,
   can I trust this?" in the first viewport.
2. The page gives strong reasons to engage (interact, click deeper) instead of bouncing.
   **Bounce reduction and engagement are the key SEO success criteria for these pages** -
   bounces will kill them in organic search.
3. The page justifies ranking above the provider's own product page by carrying content the
   provider cannot or will not publish (rank, price gap, observed price history, honest
   estimates, bill comparison).

Baseline: multi-agent design critique scored the page 21/40 (Acceptable band). Full critique:
`.impeccable/critique/2026-07-26T07-06-40Z__resources-views-livewire-contract-detail-blade-php.md`.

## Gate: HTML mockups before implementation

No implementation starts until static HTML mockups of the updated page are reviewed and
approved by Seppo. Mockups live in `tasks/contract-detail-overhaul/mockups/`.
**Status 2026-07-26: Seppo approved the reworked `korpela-kvartaali.html` structure
("good enough for now"). `rank1-spot.html` still shows the older card-based layout and
must be ported to the approved structure below before it is used as a reference.**

## Approved page structure (codified from korpela-kvartaali.html, 2026-07-26)

Seppo rejected the first mockup round as "AI slop: cards within cards everywhere; the hero
lacks structure and every element competes." A dual-agent critique (28/40) confirmed it.
The approved rework is **fully editorial**: sections are flat typography on the page
surface (h2 + hairline rule + whitespace), with card chrome reserved for the alternative
contract cards only. Nothing else gets border+radius+padding; no nested containers.

Section order:

1. **Site header** (nav + live spot pill).
2. **Dark hero** (slate-950), single column at content width, structured as quiet
   metadata + two beats:
   - Quiet metadata: breadcrumb; seller logo + name · pricing-category label. The
     category label is a **tappable link** that opens and scrolls to the FAQ item
     explaining the pricing mechanism.
   - H1 contract name.
   - **Beat 1, price + verdict fused** (the one dominant statement): price label;
     monthly price with Arvio popover (popover text links to the methodology section);
     yearly/kWh/VAT line; verdict line "Sija N / 299 sopimuksesta · X €/v kalliimpi
     kuin halvin (Y €/kk) · katso halvemmat ↓" with a slim halvin-kallein scale and
     marker; small print stating the date, the basis, and why the contracts ahead are
     mostly spot. Then the category-specific price qualifier sentence (for resets:
     current price + until-date, then estimated price from futures).
   - **Beat 2, action**: consumption chips + working free kWh input; coral CTA
     "Siirry myyjän sivuille" + no-commission note. Seller link keeps the coral CTA on
     every rank (decided against a rank-dependent CTA).
3. **Kannattaako X?** - h2 verdict paragraphs; cheaper/pricier counts and the gap are
   reactive.
4. **Hintatiedot** - dated receipt rows, receipt note, static crawlable cost table
   (2000/5000/10000/18000 kWh), spot counterfactual line.
5. **Vertaa nykyiseen sähkölaskuusi** - `<details>` module, **collapsed in production**;
   result must be arithmetically consistent with the receipt rows on the same page.
6. **Näin hinta on kehittynyt** - chart: contract line ink slate-900, median dashed
   slate-500 with direct label (coral stays reserved for actions); fact tags; collapsed
   version history (deltas shown in c/kWh, not %).
7. **Sopimusehdot lyhyesti** - flat terms grid, collapsed sanitized seller description.
8. **Usein kysyttyä** - FAQPage schema; the pricing-mechanism item carries an id so the
   hero category link can target it.
9. **Sähkön alkuperä ja päästöt** - kg CO2e + km-equivalence (both reactive), severity
   badge, residual-mix explainer, link to green alternatives (the valley gets an exit).
10. **Halvemmat vaihtoehdot** - 2 cheapest + 1 same-type as cards (the only cards),
    link to the full comparison.
11. **Footer trust statement.**
12. **Mobile sticky CTA bar** - appears only after the hero CTA has been scrolled past
    (not while it is merely below the fold), hidden while the alternatives section or
    footer is in view.

Behavior requirements carried from the critique:

- **Everything reacts to the consumption selector**, including rank, the scale marker,
  and cheaper/pricier counts (P0: a rank that claims "valitulla kulutuksella" but never
  moves reads as a rigged ranking). Any figure that cannot react must be scoped in copy.
- The free kWh input must work (clamp, clear chip state) or not exist.
- Contrast: no white text on coral-500 (2,8:1). CTA is coral-600 at >=19px/700
  (large-text 3:1); the active consumption chip is white bg + slate-900 text, not coral.
- Reduced-motion guard on smooth scroll and any pulse animation.
- Trust copy stated once prominently and once in the footer, not four times
  ("ei provisiota" x2 max; "arvio, ei hintalupaus" only in the qualifier).

## Phase 1 - first-viewport trust fixes (bounce killers)

- Fix the rank-1 hero verdict box: never render "Ei vertailutietoa / Ei tietoa"; for rank 1
  show the gap to the second cheapest. Reconcile contract-count mismatch (title 291 vs hero 299).
- Plain one-sentence price qualifier under the hero price per pricing category (spot estimate
  basis, reset current-vs-estimate, fixed certainty).
- Data hygiene: dedupe triplicated "Laskutusväli" values; company-logo `onerror` fallback to
  initials; sanitize raw provider descriptions (stray quotes, "TÄÄLTÄ" links, all-caps in H1
  and title tag); investigate and resolve the margin contradiction seen on Cheap
  Markkinahintasähkö (Voltikka table 6,99 c/kWh vs seller description 1,29 snt/kWh).
- Investigate the unstyled-page render flake (assets/cache headers) observed once in critique.

## Phase 2 - presenter unification

Make the detail page the third consumer of `ContractCard/ContractCardPresenter` for its
pricing surfaces: category band, dated receipt rows (reset contracts show current-period +
estimated-tail rows exactly like the cards), consumption-effect row, coral warning pills
(Max kWh, scheduled price increases), guaranteed order CTA on every active contract.
This removes the class of bugs where the detail page shows less truth than the listing card
(hybrid page showed "Energiahinta 0,00 c/kWh" with no consumption effect and no cap warning;
reset page showed the current-period price unqualified; one page had no CTA at all).

## Phase 3 - engagement layer

- **Bill comparison on the detail page ("Vertaa nykyiseen sähkölaskuusi")**: collapsed
  disclosure; visitor enters billing period, kWh, total EUR (+ VAT/heating toggles); page
  answers with this contract's same-period counterfactual cost and the save/pay-more delta.
  Reuse `BillComparisonService::periodRowsForContracts()` with a one-contract set; reuse or
  extract the in-listing bill form so the three surfaces cannot drift. Period basis only
  (same rule as in-listing mode). Honest unavailability states (no spot history, consumption
  cap). Per-user compute, never cached. Negative verdict links onward to cheaper alternatives.
- Consumption picker above the CTA, free kWh input added, missing chips on capped contracts
  explained.
- "Kannattaako X?" plain-language verdict paragraph (2-3 generated sentences from
  rank/gap/pricing type; typed fields only, never raw LLM text).
- Price-vs-market module: contract price history overlaid on segment median from
  `contract_price_daily_statistics`; seller behavior record ("margin raised twice in 6
  months"); collapse raw version timeline past ~3 versions.
- FAQ block with FAQPage schema (cost at N kWh, how pricing works, cancellation), generated
  from typed data like ConsumptionCalculator's FAQ.
- Static crawlable per-consumption cost table (2000/5000/10000/18000 kWh).
- Structured terms summary (irtisanomisaika, fixed-term end behavior, availability caps) above
  the sanitized provider description.
- Spot counterfactual line on fixed/reset contracts; same-type cheaper alternatives in the
  alternatives module.

## Phase 4 - composition, mobile, polish

- Deduplicate: one Ympäristövaikutus module, price rendered once (remove mini-hero repeat),
  compact hero CO2 stat; rebalance CO2 prominence (residual mix must not rival the price).
- Mobile: hero fits ~1 screen, CTA reachable earlier, >=44px tap targets, fix mid-parenthetical
  wraps.
- Color/copy alignment with DESIGN.md: coral warnings (no amber), one CO2 taxonomy, no price
  semantics in emerald/amber/red, "€/kk" not "EUR/kk", remove hardcoded em dashes, fix
  reset-notice Finnish.
- A11y: heading order, accessible link names, `datetime` attrs, focus styles.
- Trust placement: no-commission line beside the CTA; "Takaisin sopimuksiin" preserves
  `?kulutus=` state.

## Constraints

- Public copy never references Energiavirasto.
- Detail-page prepared-data caching covers only canonical GET payloads; the bill module is
  per-user Livewire state and must stay outside that cache.
- Inactive-contract redirect/noindex behavior must not change.
- All Finnish copy: no em dashes; plain language; estimates always labelled arvio.
