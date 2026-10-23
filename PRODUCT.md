# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

**Primary: Finnish households making an energy decision.** They compare electricity contracts at their own annual consumption, check whether their current bill is too high, check the spot price to time sauna, laundry, dishwasher or car charging, and estimate solar panel or heat pump savings. Many arrive from a Google search, often on a phone, sometimes with a bill in hand.

They often arrive with low trust in the comparison niche, because many comparison sites rank contracts by commission. They need clarity, complete coverage, transparent calculations, and proof that the order of results comes from data and not from payment.

**Secondary:**

- Small businesses comparing business contracts (`/sahkosopimus/yritykselle`). Company prices exclude VAT.
- Journalists and researchers who cite Voltikka's price statistics, forecasts and CSV downloads.

## Product Purpose

Voltikka helps Finnish households understand and lower their energy costs: contract comparison, a bill check, spot-price visibility, contract-price statistics and forecasts, and solar and heat pump calculators.

It makes energy decisions easier, more transparent and more evidence-based. It takes the complete list of electricity contracts on sale in Finland and makes it useful through filtering, cost calculation at the user's consumption, context and household tools.

Success means a user quickly sees their realistic options, understands the tradeoffs, and trusts why one option can be better for their situation.

## Positioning

- **Independent and commission-free.** Voltikka is an independent service run by a private individual as a hobby project. It takes no commission from electricity companies, sells no advertising, charges no fee for listing or placement, and has no sponsored contracts. No company can pay to rank higher.
- **The whole market.** The comparison includes all electricity contracts currently on sale in Finland, not only partner offers.
- **Prices recalculated, not copied.** Seller-published structured prices can show a promotional price while the later increase appears only in the description text. Voltikka interprets every contract, calculates a comparable 12-month price across all known price phases, and labels a hidden price increase. Where its own calculation and the seller's structured price disagree, its calculation wins.

## Operating Context

- Results depend on annual consumption (presets 2,000–20,000 kWh or the user's own value) and, for availability, the user's postcode.
- A seller link goes directly to the seller's own page. Voltikka records a minimal, anonymous click event and never stands between the user and the seller.
- Contract data is imported daily; spot prices hourly; futures and forecasts each morning. Pages show data dates and say when a figure is an estimate.
- Most entry points are SEO landing pages (pricing type, duration, housing type, energy source, city, company, single contract).

## Capabilities and Constraints

- Finnish-first copy for a general audience. No specialist knowledge required.
- Every published price comes from the canonical pricing calculation. An unavailable price is shown as unavailable, never as zero.
- Estimates are always marked as estimates ("arvio") and explain their basis.
- Contract data comes from the national electricity contract product list. **Never reference Energiavirasto in public copy**; the data lineage is internal only.
- The site name is **Voltikka** and the domain is **voltikka.fi**. They do not change.

## Brand Commitments

**Personality: trustworthy and warm, playful at the edges.** In the data Voltikka is clear, exact and on the user's side, like a competent Finnish energy advisor: plain-spoken, data-literate, honest about uncertainty. Around the data it is warm, Finnish and a bit funny, so that a dull subject is pleasant to use. The playfulness never touches a price, a contract or a seller.

- **Voice:** plain, short Finnish. The number comes first. Say what a number is compared with. Mark estimates "arvio". At most one light line per screen. No hype, no urgency.
- **The fox:** Voltikka's fox is a brand asset (approved reference `design/mascot/fox-v2.png`; rules in `design/voltikka-brand-guide.md`). The fox never appears next to a specific contract, seller or price recommendation, because Voltikka is neutral. Never a funny pose on bad news.
- **Fox fires:** the story that *revontulet* means "the fox's fires" may be told once, in quiet places. Only the fox and the sky; no Sámi or other cultural symbols.
- **Seasons:** the site always has the feel of the current Finnish season.

## Anti-references

Voltikka must not feel like a scammy affiliate comparison site, a commission-first lead-generation funnel, or a fake-neutral ranking page. Avoid what creates suspicion: exaggerated savings claims, aggressive calls to action, vague ranking logic, dark-pattern urgency, and layouts where a paying provider appears best by default.

It must also not feel bank-like and cold, utility-company corporate, crypto/neon, or generic SaaS.

## Evidence on Hand

- **Complete market coverage:** the comparison contains every electricity contract currently on sale in Finland.
- **Public data downloads:** contract-price statistics (`/sahkosopimus/tilastot.csv`) and spot prices (`/spot-price.csv`).
- **Does not exist; do not invent:** press coverage, user testimonials, usage or visitor numbers, ratings, awards, partner logos.

## Product Principles

1. **Trust before conversion.** Explain sources, assumptions and ranking logic clearly enough that users understand why results appear in this order.
2. **Data earns the attention.** Prices, savings, timing and assumptions carry the page. Charm frames the data and never competes with it.
3. **Make the complete market usable.** Filtering, sorting and comparison make the breadth of the market feel empowering, not overwhelming.
4. **Show uncertainty honestly.** Estimates, ranges and assumptions are visible; nothing pretends to be exact when it is not.
5. **Help people act at the right level.** The user always knows the next useful step: compare a contract, time an appliance, check a bill, test solar or heat pump savings.

## Accessibility & Inclusion

Target **WCAG 2.2 AA**. Interfaces work for keyboard and screen-reader users, respect reduced-motion preferences, and never depend on colour alone (for example, pricing categories always have a glyph and a name). Energy decisions affect all households, so language stays plain, Finnish-first and understandable without specialist knowledge.
