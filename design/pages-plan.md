# Page redesign plan (2026-10-16)

The user approved two reference boards in Paper file "Voltikka", page "Direction · first boards":

- **Pörssisähkö — /spot-price v4 picture book** (data page reference)
- **Sähkösopimukset — /sahkosopimus v4 picture book** (listing page reference)

Every other live page gets a board that matches these two. The user asked for a bolder, more fun treatment: "Electricity prices are a boring subject, let's use design to make it a bit more fun and interesting." Do not be timid. These boards are still design proposals, not decisions about the live app.

## Rules that stay (from `voltikka-design-system.md` and `voltikka-brand-guide.md`)

- Picture-book world: fine ink outlines, soft watercolour, Finnish everyday life, gentle humour. Never name an artist in a prompt.
- No rounded SaaS cards, no white rounded panels, no pills or badges, no hard boxy frames. Structure comes from display headings with a hand-inked fox-orange underline, hand-inked hairlines, paw-print dividers and inked text marks (sparkle marks, loops, arrows, underlines).
- No watercolour washes behind text or data.
- Readability first. Charts are plain bar or line charts with an inked baseline.
- The fox is in every page-type hero, and each page type has its own hero scene. The fox appears once per page (the sleeping fox in the footer and the logo do not count). The fox is never next to a specific contract, price of a contract or seller.
- Villagers are never inside or next to a contract entry, a seller or a seller's price. They can head a section (vignette at heading left, never floating) and can be the focus of an interlude strip between page blocks.
- Pricing categories use the inked price-shape glyphs (bars, steps, ± band, flat + lock).
- Every board contains all the content and text of the live page (live content parity). Fetch the live page from https://voltikka.fi (read-only) to get the text and numbers.
- On listing pages, contracts start high: short hero (about 460px), one compact controls block, ledger entries.
- Reuse the reference boards: clone the top bar, the footer, the controls block, ledger entries, paw-print dividers, headings and FAQ rows with `<x-paper-clone>`.

## Bolder moves allowed on every page

- Each page has a distinct painted hero scene with the fox doing something that explains the page.
- One villager interlude strip per page, between two content blocks (not next to contracts), each with a different Finnish everyday activity.
- Hand-inked annotations on data: loops around the key number, a short inked arrow with a short label ("halvin", "nyt", "sinun laskusi"), stars on the best value.
- Large display numerals for the key figure of the page.
- Season can follow the topic (solar = midnight-sun summer; heat pump = deep frost; others = autumn/winter dusk).
- Section-heading vignettes (small villager or object) at the left of a heading.

## Page list, hero idea and owner

| Group | Page (route) | Hero scene idea |
|---|---|---|
| A | Etusivu `/` (hub) | Wide village at blue hour, aurora; fox on a hilltop with a lantern looks over the village. Large illustrated entry points (compare contracts, spot price, bill check, calculators). |
| A | Halvin sähkösopimus `/sahkosopimus/halvin-sahkosopimus` | Fox on a wooden sled at the bottom of a snowy hill ("the lowest point"). Listing template. |
| B | Sopimus `/sahkosopimus/sopimus/{id}` | Short band: fox far away on a frozen lake path; the contract headline sits below on the ground, not near the fox. |
| B | Sähköyhtiöt `/sahkosopimus/sahkoyhtiot` | Village street at dusk with many lit windows (no shop signs); fox walks past at the edge. |
| B | Yhtiö `/sahkosopimus/sahkoyhtiot/{slug}` | Short band with neutral landscape and fox at the far edge; company facts below. |
| C | Sähkölaskuri `/sahkosopimus/laskuri` | Fox reads an old electricity meter on a red cottage wall with a lantern. Home illustrations as choices. |
| C | Maksatko liikaa? `/maksatko-liikaa` | Fox holds a very long paper bill that unrolls down the snow, looks surprised. |
| C | Lämpöpumppulaskuri `/lampopumput/laskuri` | Deep frost; fox warms its paws at the warm air of a heat pump outdoor unit beside a cottage. |
| D | Aurinkopaneelilaskuri `/aurinkopaneelit/laskuri` | Midnight-sun summer; fox sits on a cottage roof beside solar panels, lake and sauna jetty below. |
| D | Tilastot `/sahkosopimus/tilastot` | Fox on a small wooden observatory tower with a brass telescope, looking at the night sky. |
| D | Hintaennuste `/sahkosopimus/sahkon-hintaennuste` | Fox at a little weather station (wind vane, barometer) on a hill, looking at the coming weather. |
| E | Pörssisähkö listing `/sahkosopimus/porssisahko` (SEO listing template) | Fox on a jetty watching the river ice break and move. Listing template with the category glyph large. |
| E | Paikkakunta `/sahkosopimus/paikkakunnat/helsinki` (city listing) | Fox on a sea-shore rock with a generic coastal city silhouette (no real landmarks). Includes the local contracts section. |
| E | Paikkakunnat `/sahkosopimus/paikkakunnat` | A painted picture-book map of Finland with tiny villages; fox with a compass at the edge. |
| F | Artikkeli `/sahkosopimus/kannattaako-porssisahko` (and the fixed-term article uses the same template) | Fox reads a big book by a fireplace. Editorial long read: drop cap, pull quotes, inline charts. |
| F | Tietoa `/tietoa` | Fox at a writing desk in a small cabin workshop, papers and a lamp. |
| F | Tietosuoja / Käyttöehdot (legal template) | Quiet: fox asleep under a desk lamp beside a stack of papers. Plain readable text. |
| F | 404 | Fox lost in a snowy forest with a lantern, looking at two signposts. |

Each group builds its boards on its own Paper page, named "Pages · group X". Art goes into `design/scenes/`, `design/mascot/` or `design/illustrations/` with a page-specific name and a `-v1` suffix. Use the image-gen skill (Codex first) with `mascot/fox-v2.png` as reference for every fox.
