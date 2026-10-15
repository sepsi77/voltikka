# Voltikka design system

*Working draft, 16 October 2026. Paper file "Voltikka", page "Direction · first boards". The v2 boards are the current direction. The v1 boards on the same page are kept only for comparison until the user approves v2.*

The name stays **Voltikka** and the domain stays **voltikka.fi** (user, 2026-10-16). Brand, story, fox rules and voice are in `voltikka-brand-guide.md`.

## 1. The idea: a Finnish winter picture book

Voltikka is a picture book about Finnish winter evenings and electricity. The fox, the snow, the homes and the aurora are the world. The data is part of that world, not a white dashboard below it.

User decisions that set the direction (2026-10-16):

1. The current site is "quite boring". The redesign must be **bold and brave**, and it must not be "a different flavour of SaaS".
2. **No standard SaaS cards** (white card, thin border, shadow, 22px radius).
3. **No hard, sharp corners either.** Use Finnish sources for shape (§4).
4. The fox and the story belong on **every page type's hero**, including the contract listings. Each page type gets its **own distinct hero** (§6).
5. The fox never stands next to a specific contract or seller (endorsement risk).
6. A themed visual must be understood without explanation. Data uses ordinary, readable charts, and choices in a scene must look like controls (§5).
7. **The fox is approved** (`mascot/fox-v2.png`). All art comes from the image-gen skill; no illustrator redraw.

### The main rule: the design stays at the side (user, 2026-10-16)

"We are sacrificing readability and usability for the sake of design. We never did that in the Fetchalot redesign." The scene, the fox and the art frame the page; they never carry data or controls in a way that costs readability. Below the hero, content is plain and usable: ordinary charts, numbers, tables and forms. The spot price **v4 picture book** board is the reference. v2 boards are rejected where they break the rule (data-as-landscape, poster-only price, colour-block categories).

### Content sits on the page, not in cards (user, 2026-10-16)

v3 (white rounded panels) "brings back the rounded cards SaaS vibe". Content is laid out like a picture book page: no containers. Structure comes from a display heading with a hand-inked wavy fox-orange underline and an optional small emblem vignette; sections are separated by a small inked snowflake ornament between two hairlines; day numbers sit in one row with ornaments between them; the chart uses the full width with an inked baseline; illustrated items stand on a painted snow line with captions below. Controls (tabs) use a hand-inked underline for the active item. Form controls and the contract listing still need a card-free treatment (open item).

## 2. Color

All text pairs pass WCAG AA 4.5:1 (checked 2026-10-16). Paper tokens use the same names.

| Token | Value | Use |
|---|---|---|
| `--color-ground` | `#F5F6F1` | Page ground: snow |
| `--color-card` | `#FFFFFF` | Tiles, inputs |
| `--color-frost` | `#E8ECEA` | Quiet panels |
| `--color-edge` / `-strong` | `#D9DFDC` / `#B9C2C0` | Rules |
| `--color-field-edge` | `#8D9893` | Form controls (3:1) |
| `--color-ink` | `#16202E` | Headings, numbers (15:1) |
| `--color-ink-body` | `#3A4656` | Body (8.8:1) |
| `--color-ink-muted` | `#5E6876` | Captions, only on white or ground |
| `--color-night` | `#14213A` | Night sky |
| `--color-night-lead` | `#C9D3E3` | Text on night (10.6:1) |
| `--color-fox` | `#C4501C` | **Primary**: buttons, selection, "now". White text 4.65:1. Keeps Voltikka's orange |
| `--color-fox-deep` | `#9E3C10` | Fox text on light grounds (6.2:1) |
| `--color-price-high` | `#B3261E` | Expensive hour, price up |

### Pricing categories (one color and one knit motif each)

| Category (`PricingBucket`) | Color | Soft fill | Knit motif | Why |
|---|---|---|---|---|
| Pörssisähkö | aurora `#1C7A5E` | `#DDF0E8` | zigzag | changes all the time |
| Jaksoittain vaihtuva | lake `#2B5F8C` | `#E0EAF3` | steps | changes in periods |
| Kulutusvaikutus | violet `#6A4C9C` | `#ECE6F5` | diamonds | ± effect |
| Kiinteä hinta | granite `#535D68` | `#E6E9EC` | spruces | steady |

The motif also identifies the category for colour-blind visitors. Never use a category color for decoration.

## 3. Type

- **Bricolage Grotesque** (`--font-display`) 700–800: titles, poster numbers (current spot price up to 240px, contract prices 64px, rank numbers 88px), tight tracking (−0.02 to −0.05em).
- **Figtree** (`--font-sans`): body, UI, labels, small numbers.

Big numbers carry the page. A page should have one number that reads from across the room.

## 4. Shape and surface (Finnish sources)

- **Knitting (neuleet):** pixel-grid knit bands from the fox's scarf. The category-tile use was rejected; keep the motif for small accents only until a better use is found.
- **Snowdrifts and lake shores:** section edges are soft waves (hero bottom), not straight lines.
- **Washed stone (kivi):** corners 20–24px on tiles and entries, 12px on buttons and inputs, pill for small chips. **No borders and no drop shadows** on tiles. Surfaces are filled colour fields.
- **Organic modernism:** big flat colour fields and illustration that breaks out of its frame (a house stands on top of its choice tile). Inspiration only; copy no product or pattern.

## 5. Readability rules for themed visuals

- **Charts are plain bar charts, not landscapes.** The data-as-landscape idea (a price ridge, also with grid lines and hour posts) was rejected: "nobody will understand the pricing graph" (user, 2026-10-16). The spot price hero uses one bar per hour, the price printed on each bar, every hour labelled, a dashed day-average line, the current hour in fox orange with "nyt", and a legend. The art frames the chart; it does not carry the data. Tomorrow is a toggle, not a second series.
- **A choice in a scene is a control.** Choices sit in a row of equal tiles with a radio circle, a name and a value, under a numbered step label ("1 Valitse kotisi"). The selected tile has a fox-orange frame and a check. User feedback 2026-10-16: items placed loosely on the picture look like parts of the image.
- Text on art must sit on calm sky or on a solid tile.

## 6. Hero map (one distinct hero per page type)

| Page type | Hero | Fox pose | Status |
|---|---|---|---|
| `/spot-price` | Night sky + aurora; poster price; plain hourly bar chart on the dark snow | Sits on the snowdrift beside the chart, looks up: `fox-sit-skyward-v1.png` | Board v2 |
| `/sahkosopimus` and household SEO listings | Village at dusk; four homes are the consumption choice | Walks with a lantern toward the chosen home: `fox-walk-lantern-v1.png` | Board v2 |
| SEO variants (housing type, pricing type) | Same village; the page's home or category is preselected and named in the H1 | Same | To draw |
| City pages | Same village with the municipality name on a road sign | Same | To draw |
| `/sahkosopimus/tilastot` | Almanac on a table by lamplight, seasons strip | Reads the almanac | To make |
| Price forecast | Fell top, weathervane and sky | Sniffs the wind | To make |
| `/maksatko-liikaa` | Cabin porch, the bill under a lantern | Holds the lantern over the bill | To make |
| Solar calculator | Summer: midnight sun over a lake | Summer coat, no scarf | To make |
| Company pages, contract detail | **No fox** (sellers) | — | Plain world header |

## 7. Components (v2)

- **Choice tile (home):** illustration on top, then a white tile (radius 20): radio, name, detail, value. Selected = fox frame 3px + filled radio with check.
- **Category choice chip:** white tile with a checkbox, the category emblem (`emblems/`), name and one-line meaning; the category colour is only a thin accent and the checked state. Full-colour category blocks with knit bands were rejected (user, 2026-10-16: "pretty horrible").
- **Contract entry:** soft category fill, radius 24, rank number 88px in category colour, kicker in category colour caps, name 30px, price parts (label/value rows), price 64px + "€/kk", annual line with "arvio". One primary (fox) button for the first entry; others outline.
- **Buttons:** primary fox fill, radius 12; secondary 2px ink outline, radius 12.

## 8. Open items

1. User approval of the v2 direction. After approval, delete the v1 boards. (The fox is approved.)
2. Mobile (390px) boards for both pages.
3. The knit band inside contract entries (removed from the board; needs a better place).
4. The remaining heroes in §6.
5. Logo: the tail-and-spark mark next to the wordmark is a placeholder.
6. Performance plan for hero art (AVIF/WebP, sizes, no layout shift, mobile crops) before implementation.
