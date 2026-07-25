---
target: contract card
total_score: 30
p0_count: 0
p1_count: 2
timestamp: 2026-07-25T18-25-07Z
slug: resources-views-components-contract-card-blade-php
---
# Design Critique: Contract Card

Target: laravel/resources/views/components/contract-card.blade.php (+ featured-contract-card, components/card/*)
Register: product. Inspected live at /sahkosopimus?kulutus=5000, 1440px.

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Estimates and reset dates stated well; nothing signals how complete a card's answer is |
| 2 | Match System / Real World | 4 | Plain household Finnish in the band. Best thing on the card |
| 3 | User Control and Freedom | 3 | Popover has Escape + focus return; card body not clickable |
| 4 | Consistency and Standards | 2 | Rank badge shifts logo column; CTA weight varies by rank; footer surface = page surface |
| 5 | Error Prevention | 3 | Cap warning and Arvio chip prevent bad picks |
| 6 | Recognition Rather Than Recall | 4 | Band + legend + labelled receipt rows |
| 7 | Flexibility and Efficiency | 2 | Cannot scan one column down the list; row not clickable |
| 8 | Aesthetic and Minimalist Design | 2 | Footer/page collision; slate-400 on comparison numbers |
| 9 | Error Recovery | 3 | No error states on a card |
| 10 | Help and Documentation | 4 | Arvio popover with methodology link |
| Total | | 30/40 | Good, solid foundation, three fixable defects |

## Anti-Patterns Verdict

Does this look AI-generated? No. Band-states-the-mechanism is an editorial decision, not a template.
No hero-metric block, no eyebrow scaffolding, no identical card grid, no gradient text.

Deterministic scan: detect.mjs over the card files and all of resources/views returned 0 findings.
The pre-redesign card (git HEAD) returned 3 x side-tab findings (the emissions border-l-4). Removing
that stripe cleared the most recognizable AI tell in the file.

Not run: URL-mode scanning needs puppeteer (not installed, not added to the project). No browser
overlay was injected, so no overlay exists to view.

## What's Working

1. The band earns its space. "Hinta seuraa porssin tuntihintaa - Muuttuu joka tunti" tells a household
   more than "Porssisahko - Toistaiseksi voimassa" did. Warnings correctly excluded from it.
2. Receipt rows make the estimate legible in the breakdown itself (slate-500 estimated vs slate-900 known).
3. The Arvio chip is a real disclosure: hover, tap, keyboard, Escape, and a link to the method.

## Priority Issues

### [P1] Footer, card border and fixed band are all invisible against the page

Measured contrast:
- page slate-50 vs footer slate-50: 1.00
- card border slate-100 vs page slate-50: 1.05
- fixed band slate-100 vs page slate-50: 1.05
- white body vs footer slate-50: 1.05

On a rank-4+ card the bottom edge does not exist. Reading down: white body, invisible footer, then the
NEXT card's slate-100 band, which is darker than the footer preceding it. A caveat becomes ambiguous
about which contract it qualifies.

Cause: the approved mockup put cards on a white board; production puts them on slate-50.

Fix: give the card real edges instead of tinting the footer. Card border to slate-200; footer to white
with a slate-200 hairline top border. Also stops band and footer competing as two tinted strips.
Command: /impeccable polish

### [P1] Unit labels and the price decimal fail WCAG AA

text-slate-400 (#94a3b8) on white is 2.56:1. Used on c/kWh and EUR/kk at 12px (needs 4.5:1) and on the
price decimal at ~21.6px bold (needs 3:1). Both fail.

Exposes a contradiction in DESIGN.md: section 2 sanctions slate-400 for price units, while the
Readable-By-Default rule forbids drift toward slate-400. One must give.
Command: /impeccable audit

### [P2] Rank badge breaks the column the eye needs

Ranks 1-3 render a badge inside the identity flex row, pushing the logo ~37px right; rank 4+ does not.
Nothing lines up down the list.
Fix: reserve the rank column on every card, or move rank out of the identity row.
Command: /impeccable layout

### [P2] The same action has two visual weights, decided by rank

"Katso" is solid coral for ranks 1-3, outline for 4+. With the featured card that is four coral buttons
stacked, past the <=10% coral rule. Close to the named anti-reference about position implying quality.
Fix: one CTA treatment for all non-featured cards; coral belongs to the featured card alone.
Command: /impeccable quieter

### [P3] Legend swatches do not match the bands they explain

Legend uses slate-200/sky-200/violet-200; bands are slate-100/sky-100/violet-100.

## Persona Red Flags

Sam (accessibility): c/kWh and EUR/kk at 2.56:1 unreadable at low vision, and they disambiguate 7,77
from 0,00. The Arvio panel is teleported to end of body, so Tab from the trigger goes to the next card;
click moves focus correctly but keyboard-only users will not discover it by tabbing. Methodology link
reachable elsewhere on the page, so degraded not blocked.

Alex (power user): cannot click the row, only the button. Shifting logo column fights scanning. No way
to pin two contracts side by side.

Casey (mobile): NOT VERIFIED in-browser; the extension's window resize did not change the rendered
viewport. Classes suggest the stub goes full width below sm, but the receipt block carries
min-w-[15rem] with max-w-[24rem] and needs a real 390px test.

## Minor Observations

- "Perusmaksu 0,00 EUR/kk" renders a zero in the heaviest weight on the row.
- Cards without footer content end flush after the body; with the strip invisible this reads as
  inconsistent bottom padding.
- The band is full width for a ~40 character sentence, leaving empty tint when there is no Arvio chip.
- "Cheap Markkinahintasahko - PERUSMAKSU 0 ..." truncates mid promotional shout.

## Questions to Consider

- If the card had real edges, would it still need a tinted footer at all?
- Does the card ever say how much a market price could move, or only that it moves?
- What is coral buying at the top of the list that rank order does not already say?
