---
target: "https://voltikka.fi/sahkosopimus/kannattaako-porssisahko"
total_score: 28
max_score: 40
na_heuristics:
p0_count: 0
p1_count: 3
timestamp: 2026-07-29T07-43-13Z
slug: views-livewire-article-spot-electricity-blade-php
---
Method: dual-agent (A: ab1557b6-4ee5-455 · B: ca44d822-89fe-406) + independent Google-intent review (39176498-46ec-4ef)

# Impeccable critique: Kannattaako pörssisähkö?

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|---|---:|---|
| 1 | Visibility of System Status | 3/4 | The calculator shows loading states, but chart failures have no visible page state. |
| 2 | Match System / Real World | 3/4 | Most text is plain Finnish. “Kanonisia laskelmia,” p20–p80, and mixed comparison scopes need translation. |
| 3 | User Control and Freedom | 3/4 | Breadcrumbs, section links, and contract selectors give control. Deep sections have no quick route back to the answer or calculator. |
| 4 | Consistency and Standards | 3/4 | The visual system is coherent. Some blue chart series do not follow the documented coral-and-slate data language. |
| 5 | Error Prevention | 3/4 | Presets and automatic selections prevent invalid states. The calculator does not accept an exact annual consumption value. |
| 6 | Recognition Rather Than Recall | 3/4 | Labels and assumptions are near the data. The user must still remember why the market snapshot and calculator can name different winners. |
| 7 | Flexibility and Efficiency | 2/4 | The page has section links and presets, but no exact consumption input or short path from the opening to the calculator. |
| 8 | Aesthetic and Minimalist Design | 3/4 | The page is calm, but repeated chart-section patterns create fatigue. |
| 9 | Error Recovery | 2/4 | Search states are useful. Broken logos and chart initialization failures have weak or invisible recovery. |
| 10 | Help and Documentation | 3/4 | Dates, VAT, definitions, caveats, and methodology support trust. Important method and independence details appear too late. |
| **Total** |  | **28/40** | **Good foundation. Major trust, accessibility, and task-flow issues remain.** |

## Design Specificity Verdict

**LLM assessment:** The page is partly authored for Voltikka, but its composition is still category-interchangeable. The calm slate palette, sparse coral, Finnish household language, exact dates, and measured data feel specific to Voltikka. The structure is generic: centered dark hero, badge, breadcrumb, contents list, repeated chart sections, calculator, summary, and related links. A finance or insurance comparison site could reuse it with small changes.

The most specific material is the live evidence. It should be the visual center of the opening. It now sits after a generic hero, breadcrumb, and six-link contents list.

**Deterministic scan:** The CLI detector returned `[]`, with 0 findings in `laravel/resources/views/livewire/article-spot-electricity.blade.php`. It found no rule violations. Manual and browser review still found issues that the source-pattern detector does not measure: canvas accessibility, information order, comparison-scope ambiguity, long mobile distance, and production failure states.

**Visual overlays:** Mutable browser injection passed its preflight, but Chrome blocked the production HTTPS page from loading the localhost detector. The exact error was `LocalNetworkAccessPermissionDenied`. The browser detector did not run in-page. No reliable user-visible overlay exists.

## Overall Impression

The page looks professional, calm, and data-led. It avoids aggressive affiliate design and gives more evidence than most comparison pages. Its largest weakness is not missing content. It is the order and relationship of the content.

A Google visitor gets the proof too late on mobile. The source opening also makes a broader claim than the visible first comparison supports: it says spot contracts are cheaper “on average than other contract types,” while the visible snapshot compares medians for Spot and fixed 12-month contracts. Later, the default calculator can name a fixed contract as cheaper because it compares selected contracts, not market medians. These results can all be valid, but the page does not explain the change of scope. This weakens trust.

**Independent Google-intent verdict:** Search intent is answered **partly**. Credibility is **mixed**. The page has strong evidence, but the answer, visible proof, and calculator do not use one clearly explained comparison basis.

## What’s Working

1. **Strong evidence provenance.** The page shows dates, VAT, consumption basis, median definitions, estimate limits, and a methodology link.
2. **Balanced decision support.** It covers current cost, seasonal variation, price spikes, suitable households, and reasons to select a fixed contract.
3. **Correct emotional tone.** The slate base, sparse coral, flat surfaces, and lack of urgent sales actions support the calm-adviser position.

## Cognitive Load

| Check | Result | Evidence |
|---|---|---|
| Single focus | Pass | One central question guides the article. |
| Chunking | Fail | The contents list has six items. The volatility block shows five headline metrics. |
| Grouping | Pass | Metrics, legends, charts, and notes stay in clear groups. |
| Visual hierarchy | Pass | Headings and large figures create a clear reading order. |
| One thing at a time | Fail | The calculator shows modes, five presets, two contracts, two selectors, summary figures, and a chart together. |
| Minimal choices | Fail | The contents list and consumption selector both exceed four visible options. |
| Working memory | Pass | The short answer and summary repeat the core decision. |
| Progressive disclosure | Fail | Most evidence and calculator complexity is visible at once. |

**Result: 4/8 failures.** Load is high in the calculator and moderate in the article.

Decision points above four options:
- Six contents links.
- Five consumption presets.
- Five main navigation destinations plus the live Spot link.

## Emotional Journey

- **Opening:** Calm and confident. The intended new copy gives a direct answer, but its proof is below the contents list on mobile.
- **First peak:** The current market snapshot is the strongest trust moment. It gives annual prices, a difference, date, VAT, and pricing basis.
- **Evidence valley:** Repeated eyebrow, metric, legend, chart, and note patterns create fatigue. Small labels increase effort.
- **Risk peak:** The maximum hourly price and expensive-day count create concern, but the page does not translate them into a household-scale bill example.
- **Calculator peak:** Personal comparison makes the evidence actionable. It also introduces doubt because its default winner can differ from the market snapshot without an explanation.
- **Ending:** The “Kenelle” and fixed-price sections restore balance. The summary is calm, but it does not reconcile the different comparison results.

## Priority Issues

### [P1] The answer, snapshot, and calculator use different scopes without a clear explanation

**Why it matters:** A normal user can see three messages: Spot is cheaper on average, Spot is 28.3% cheaper than the fixed 12-month median, and a selected fixed contract saves 13 €/year. These can all be correct. Without an explicit explanation, they look contradictory. The source phrase “keskimäärin muita sopimustyyppejä” is also broader than the first visible median comparison.

**Fix:** Make the opening claim use the exact visible basis. Place the dynamic date, 5,000 kWh basis, median, and comparison segment with the answer. Before the calculator result, state that the snapshot compares market medians and the calculator compares two selected contracts. Use the same vocabulary for both.

**Suggested command:** `$impeccable clarify`

### [P1] Important chart evidence is not accessible without vision or a pointer

**Why it matters:** The seasonality, win-rate, volatility, and monthly comparison charts use canvas. They do not provide complete accessible data tables or text equivalents. Small 11 px labels also conflict with Voltikka’s readable-by-default rule.

**Fix:** Add one clear text takeaway and an accessible data table for each chart. Give each canvas a useful accessible name. Make values available without hover. Raise label sizes to the documented floor.

**Suggested command:** `$impeccable audit`

### [P1] The calculator is too complex and does not fully support “own consumption”

**Why it matters:** It shows two comparison modes, five presets, two contract cards, two selectors, result cards, and another chart. “Kiinteähintainen” and “määräaikainen” are not parallel categories. The user also cannot enter an exact annual consumption value.

**Fix:** Use one default comparison: Spot versus a fully fixed contract. Show three common presets and one exact “Muu määrä” input. Put contract replacement and the second mode in an advanced disclosure.

**Suggested command:** `$impeccable distill`

### [P2] Mobile users reach the proof and calculator too late

**Why it matters:** The market snapshot starts near 841 px and the calculator near 7,150 px in a 390-class mobile view. The full document is about 13,375 px. Many Google visitors will leave before the personal answer.

**Fix:** Put the live market snapshot directly with or below the hero answer. Move or collapse the contents list on mobile. Add a clear “Siirry laskuriin” link near the snapshot. Consider moving a compact calculator before the long evidence sequence.

**Suggested command:** `$impeccable layout`

### [P2] Small trust breaks weaken a high-trust decision page

**Why it matters:** Production showed a broken provider logo. Chart failures only reach the console. “Kanonisia laskelmia” is internal language. The byline date can look older than the dynamic market data. Independence and selection-method details appear near the bottom, after provider brands.

**Fix:** Use initials for failed logos. Show a visible chart fallback with a table or retry. Replace internal terminology with household language. Separate “data updated” from “content reviewed.” State selection neutrality and commercial relationships beside the first provider result.

**Suggested command:** `$impeccable harden`

## Persona Red Flags

### Jordan, first-time user

- The six-link contents block asks for a route choice before the strongest proof.
- “Kanonisia laskelmia” and p20–p80 require translation.
- The calculator compares “kiinteähintainen” and “määräaikainen” as if they are exclusive categories.
- “Automaattisesti edullisin sopimus” can look like an endorsement because the selection rule is not prominent beside it.
- The snapshot and default calculator can name different winners without a local explanation.

### Sam, accessibility-dependent user

- Four important charts use canvas without complete accessible tables.
- Consumption preset buttons do not expose `aria-pressed`.
- Comparison-mode controls do not use clear tab or pressed-state semantics.
- Many evidence labels and chart axes use 11 px text.
- Chart initialization failures do not give a visible recovery path.

### Casey, distracted mobile user

- The current proof is below the first screen and below the contents list.
- The calculator is more than 7,000 px down the page.
- Five presets wrap over two rows, below two comparison controls.
- There is no exact annual consumption input.
- There is no persistent section control after the contents list scrolls away.

## Minor Observations

- The market snapshot should read as the page’s central decision brief, not as a normal section.
- Blue chart series conflict with the documented coral-and-slate comparison language.
- The contents list omits “Milloin kiinteä hinta on parempi?”, although it is a primary decision section.
- “Päivitetty 29.5.2026” and a later dynamic market date can both be correct, but the labels should distinguish editorial review from data freshness.
- The current summary says Spot has “often” been cheaper while the displayed historical win-rate is 100%. The cautious wording avoids overclaiming, but it does not explain the stronger evidence.
- The production page still showed the old hero copy. Visual findings use production. Opening-copy findings use the current source.

## Questions to Consider

1. What if the first screen was one dated market brief with the question, direct answer, and three live figures?
2. Should this article have one calculator comparison only: Spot versus a fully fixed contract?
3. If every canvas disappeared, would a screen-reader user still reach the same decision?
4. Should the page explain the automatic contract-selection rule before it shows any provider logo?
