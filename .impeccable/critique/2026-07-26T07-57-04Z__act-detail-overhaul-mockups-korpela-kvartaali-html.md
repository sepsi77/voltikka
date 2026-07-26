---
target: korpela-kvartaali mockup (contract detail overhaul)
total_score: 28
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 3
timestamp: 2026-07-26T07-57-04Z
slug: act-detail-overhaul-mockups-korpela-kvartaali-html
---
Method: dual-agent (A: design-director review · B: detector/browser evidence), synthesized with stakeholder verdict from Seppo ("still AI slop: cards within cards everywhere; hero lacks structure, every element competes").

# Critique: mockups/korpela-kvartaali.html (contract detail overhaul mockup)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Chips update every dependent figure, but "Sija 113" and the scale marker never move while the copy claims rank follows the selected consumption |
| 2 | Match System / Real World | 4 | Excellent plain Finnish; jargon glossed in place ("futuurien", "jäännösjakauma") |
| 3 | User Control and Freedom | 3 | Popover closes on Esc/outside-click; but the mobile sticky CTA is permanent and undismissable |
| 4 | Consistency and Standards | 3 | Micro-type breaches Readable-By-Default floors (12px slate-400 on slate-950); decimal precision mismatch (33,7 vs 44,10) |
| 5 | Error Prevention | 2 | "Oma" kWh field accepts input and silently does nothing; bill form has no validation states designed |
| 6 | Recognition Rather Than Recall | 4 | Selected consumption restated in every dependent module; exemplary working-memory bridging |
| 7 | Flexibility and Efficiency | 2 | Free-input escape hatch is dead; methodology links are href="#" |
| 8 | Aesthetic and Minimalist Design | 2 | Container soup: nearly every module is a card containing more cards; hero has ~9 sibling groups competing; "ei provisiota" ×4, "arvio, ei hintalupaus" ×3 (Assessment A scored this 3; adjusted down — see Specificity Verdict) |
| 9 | Error Recovery | 1 | No error states exist anywhere (dead input fails silently, no invalid-bill design, no no-data states) |
| 10 | Help and Documentation | 4 | Layered explanation system: Arvio popover, qualifier, receipt note, FAQ, methodology links — each next to the number it explains |
| **Total** | | **28/40** | **Good band by score; composition verdict below is harsher than the number** |

## Design Specificity Verdict

**Content architecture: authored for this product. Visual composition: generic.** Assessment A (unanchored) called it "a Voltikka page" on content grounds — no competitor ships "Sija 113/299" as the second-largest number above its own affiliate-free CTA, the two-vintage reset qualifier is bespoke, and all cross-module math checks out. That holds.

But the stakeholder verdict is also correct, and neither sub-assessment fully named it: the *form* is default-LLM container soup. Inventory: hero card > glass verdict panel > two stat boxes; bill card > result card > delta pill; Hintatiedot card > receipt box + table + counterfactual box; chart card > fact-tag pills; terms card > four term boxes; environment card > badge + stat box; alternatives card > three alt cards. Every content group got border+radius+padding regardless of whether it is an object or just prose. The hero holds nine sibling groups (breadcrumb, seller row, H1, category chip, price+Arvio, qualifier, 5-control consumption row, CTA+trust note, verdict panel with four figures) with a size ramp but no dominance order — Assessment A's own praise ("second-largest number") is evidence of the competition. Right facts, wrong form.

**Deterministic scan**: 111 CLI findings — 108 are advisory token-drift noise inherent to a self-contained mockup (own :root ramp, dark-hero rgba glass, single deliberate font). Real residue: an 11-step font-size ramp (13–56px), one off-ramp red (#b91c1c/#fee2e2). In-page detector: 19 findings — **6 real WCAG contrast failures (white on coral #f97316 = 2.8:1 on the primary CTA, active chip, logo mark)**, 12 paragraphs at 88–105 ch/line, a pulsing dot, a coral glow on the CTA. Mechanical: 21 of 40 interactive elements under 44×44px (Arvio chip 74×29.5, both summary rows 21px tall, 13px checkboxes); page height 4721px; zero console errors; chip interactivity verified working.

**Overlays**: in-page injection succeeded via a local static server (the skill live-server 404'd the path and its error page broke detect.js decoding); servers were stopped after evidence collection. No overlay left running for viewing.

## Overall Impression

The page knows *what* to say — rank, gap, honest estimate mechanics, no-commission trust — better than any prior version. It does not yet know *how* to look while saying it. The single biggest opportunity: flatten the container hierarchy and give the hero one dominant beat, so the strong content reads as editorial confidence instead of widget clutter.

## What's Working

1. **The verdict panel content is the product thesis**: "Sija 113/299", halvin→kallein scale, "+124 €/v", "katso halvemmat ↓" — quantified honesty on a page that would convert better hiding it.
2. **Cross-module numeric integrity under live consumption state**: gap, counterfactual, CO₂, alternatives, table highlight and sticky bar all recompute from one selection and the arithmetic self-verifies; the dual-vintage qualifier (6,95 known / 9,63 estimated) is the right presentation of a market-reset product.
3. **The stepped reset chart + fact tags** teach the quarterly mechanism in seconds ("tarkistus 1.7.", "Hintaa tarkistettu 2 kertaa", "Viimeisin muutos −0,60 c/kWh").

## Priority Issues

**[P0] Rank claims consumption-sensitivity but never moves.** Marker hardcoded at 37%; "Sija 113" identical at 2 000 and 18 000 kWh while the foot says "valitulla kulutuksella". Riley screenshots the contradiction and posts it as proof the ranking is fake — the exact accusation the product exists to avoid. Fix: wire rank/counts/marker to consumption in implementation, or scope the copy to the reference consumption. (/impeccable harden)

**[P1] Cards within cards everywhere.** Stakeholder-identified; inventory above. Fix: rebuild the page as typographic sections on the page surface (h2 + rule + whitespace), reserving card chrome for true objects only: the three alternative contracts, arguably the verdict. Receipt, terms, FAQ, environment, chart become flat editorial sections. (/impeccable distill + layout)

**[P1] The hero has no dominance order.** Nine sibling groups compete; on mobile it is worse — price appears twice (hero + permanent sticky bar) with a coral CTA before any verdict, so the honesty is scroll-gated on the majority device. Fix: two beats — beat 1 is price + one-line verdict fused into a single statement; beat 2 is chips + CTA; breadcrumb/seller row/category chip demoted to quiet metadata. Sticky bar appears only after the hero CTA leaves the viewport and is suppressed in the alternatives section. (/impeccable layout + distill)

**[P1] The "Oma" kWh input is a dead control.** Typed 7500 + Enter: nothing, no error, stale chip stays active; every subsequent figure is then wrong for the user who trusted it. Fix: implement (debounced recompute, clears chip state, aria-label) or cut it from the mock until specced. (/impeccable harden)

**[P2] Text fails the audience twice.** (a) Real WCAG failures: white on coral #f97316 is 2.8:1 on the primary CTA, active chip, and logo mark — a brand-level problem, not mockup-only; needs a darker coral for text-bearing fills. (b) Readable-By-Default breaches: 12px slate-400 on slate-950 ("halvin/kallein"), 13px slate-500 labels throughout, 12 paragraphs over the 80-ch measure. (/impeccable typeset + audit)

## Persona Red Flags

**Jordan (first-timer)**: survives the hero via chip labels and plain-Finnish qualifier; fails at the Arvio popover (no link deeper, covers the "529 € vuodessa" line he was reading) and the category chip "Markkinahinta · hinta tarkistetaan 4 kertaa vuodessa" — the page's most important concept styled as a non-tappable ghost pill, unexplained until FAQ item 2.

**Casey (distracted mobile)**: pre-scroll sees the price twice and an order push, no verdict; tapping the pinned "Myyjän sivuille" in the first 10 seconds orders a rank-113 contract without ever seeing "Sija 113". Bill form open-by-default puts a date-picker form at first scroll; chart illegible at 375px (~6px text, unscaled viewBox).

**Riley (stress tester)**: dead "Oma" input; "Vertaa" does nothing (mock note only 12px slate-400); all methodology links href="#" jump to top; rank-never-moves screenshot scenario; chips lack aria-pressed, Arvio span[role=button] lacks aria-expanded, chart tooltip mouse-only.

## Minor Observations

- Coral used as the chart's data series dilutes the coral-is-action voice; plot the contract line slate-900, keep the median dashed slate-500.
- "Kannattaako Korpela Kvartaali?" is a p.kicker, not an h2 — loses a high-value SEO heading.
- Repetition reads as protesting too much: "ei provisiota" ×4, "arvio, ei hintalupaus" ×3. Once prominently, once in the footer.
- Positive-case bill result ("olisit säästänyt") is undesigned; spec it before implementation so it doesn't come out green.
- Two empty p.sub stubs (FAQ, Ympäristö); "−8 %" twice in version history looks like copy-paste; decimal mismatch 33,7 vs 44,10.
- scroll-behavior:smooth without prefers-reduced-motion guard; pulsing spot-pill dot; sticky bar covers the footer's last line at full scroll.
- Environment "Korkeat päästöt" valley has no exit link ("katso vihreät vaihtoehdot").
- 9,63 c/kWh to two decimals on a futures-derived guess undercuts the "arvio" framing; the forecasting backend already produces p20–p80.

## Questions to Consider

1. Should a rank-113 contract's primary coral CTA be "Siirry myyjän sivuille" at all, when the page's own verdict argues against it? What if coral went to "Katso 112 halvempaa vaihtoehtoa" and the seller link were slate secondary?
2. Is the bill comparison earning module-#2 position? It is the highest-effort interaction on the page, placed before the zero-effort chart and receipt.
3. What does this page look like with zero nested cards — pure editorial typography with one coral action?
