# Voltikka design system

*Working draft, 16 October 2026. Paper file **"Voltikka"** (`01M50B1D4JDT66PK35AAW7BSE4`) has these pages:
- **Direction · first boards**: the two approved reference boards, "Pörssisähkö — /spot-price v4 picture book" (data page) and "Sähkösopimukset — /sahkosopimus v4 picture book" (listing page).
- **Design system**: boards 01 Foundations, 02 Components, 03 Theme and illustration.
- **Pages · group A–F**: the boards for every other live page (see `pages-plan.md`).

The user deleted the rejected drafts (v1, v2 BOLD, v3 white panels), so the file contains no old designs.*

The tokens live in the Paper file (88 tokens, Tailwind v4 groups) and in `voltikka-tokens.css`. If the Paper tokens, `voltikka-tokens.css` and this file disagree, fix the one that is wrong and say so. The name, story, fox rules and voice are in `voltikka-brand-guide.md`. This file does not repeat them.

The name stays **Voltikka** and the domain stays **voltikka.fi** (user, 2026-10-16).

---

## 1. The idea: a Finnish winter picture book

Voltikka is a picture book about Finnish evenings and electricity. The fox, the snow, the homes, the villagers and the aurora are the world. The prices are exact and plain. Electricity prices are a boring subject, so the design makes them a bit more fun (user, 2026-10-16: "Do not be too timid because this app deals in prices").

1. **The design stays at the side.** The scene, the fox and the art frame the page. They never carry data or controls in a way that costs readability (user: "We are sacrificing readability and usability for the sake of design. We never did that in the Fetchalot redesign.").
2. **Content sits on the page, not in cards.** No containers. Structure comes from display headings with a hand-inked fox-orange underline, hand-inked hairlines, paw-print dividers and inked text marks.
3. **Ink, not chrome.** Marks are drawn, not boxed: sparkles, stars, loops, arrows and wavy underlines. No pills, badges, shadows, gradients or glass.
4. **One fox per page, in the hero.** The sleeping fox in the footer and the logo do not count. The fox is never next to a specific contract, its price or a seller.
5. **The art and the page use the same snow.** The page ground is the snow of the art, so cutouts and strips sit on the page with no frame.
6. **Every board shows the whole live page.** A design is complete only when it contains all the content, data and text of the live page.

### User decisions that set the direction (2026-10-16)

- The old site is "quite boring". The redesign is **bold**, and it must not be "a different flavour of SaaS".
- **No standard SaaS cards** (white card, thin border, shadow, 22px radius) and **no white rounded panels** (v3 "brings back the rounded cards SaaS vibe"). No hard, boxy frames either.
- **No pills or badges.** Rounded containers around short labels ("Edullinen päivä", "UUDET", the top-bar price, the Preferred Sources button) "still have the SaaS vibe". Use a sparkle mark, small caps in the status colour, or an inked underline.
- **No watercolour washes behind text or data** ("smudging").
- **Plain charts.** The data-as-landscape chart was rejected: "nobody will understand the pricing graph". Charts are bar or line charts with an inked baseline.
- **A choice must look like a control.** Choices placed loosely on a picture look like parts of the image.
- **Vignettes sit at the left of a heading**, never floating in open space (a floating owl looked "lost and out of place").
- **Listing pages put contracts high**: "All of the filters and options push the contract cards down too much." Short hero, one compact controls block.
- **No per-contract mini charts** in entries ("pointless busyness"). The space between price parts and price stays empty.
- **Category signs must explain the pricing logic.** The painted emblems (stormy peak, moon phases, sun-moon, mossy stone) were rejected because they did not connect to the pricing logic. Use the inked price-shape glyphs (§5).
- **The fox is approved** (`mascot/fox-v2.png`). All art comes from the image-gen skill; no illustrator redraw.

## 2. Color

The pairs below are measured on `--color-ground` `#F5F6F1` unless the "Use" column says otherwise. Body text needs 4.5:1; large text, glyphs and control edges need 3:1.

### Surfaces and ink

| Token | Value | Use |
|---|---|---|
| `--color-ground` | `#F5F6F1` | Page ground: snow. All content sits on it. |
| `--color-snow-white` | `#FFFFFF` | Text and numbers on the night or dusk hero (16:1 on night); checkbox and radio fill. Never a card. |
| `--color-edge` | `#D9DFDC` | Hand-inked hairlines between entries and rows (decoration, no contrast need) |
| `--color-edge-strong` | `#B9C2C0` | Dotted leaders in price-part rows |
| `--color-field-edge` | `#838E89` | Checkbox, radio and field underline (3.1:1). Changed from `#8D9893` (2.7:1) on 2026-10-16. |
| `--color-ink` | `#16202E` | Headings, numbers, values (15.1:1) |
| `--color-ink-body` | `#3A4656` | Body text (8.8:1) |
| `--color-ink-muted` | `#5E6876` | Captions, secondary lines (5.2:1). Not below 13px. |
| `--color-night` | `#14213A` | Night sky behind hero text |
| `--color-night-lead` | `#C9D3E3` | Lead text on night (10.6:1) |

### Brand

| Token | Value | Use |
|---|---|---|
| `--color-fox` | `#C4501C` | **Primary.** Inked underlines, the selected radio, "nyt", the cheapest loop, arrows. 4.3:1 on ground: use it for marks and text of 24px and up only. |
| `--color-fox-deep` | `#9E3C10` | Fox-colour text: inked actions ("Katso →"), rank 1, the cheapest price, kicker caps (6.2:1) |

### Map colours and pricing categories

Each pricing category (`PricingBucket`) has one colour and one inked price-shape glyph. Use the colour only for that category's glyph, name and checked state. Never use it for decoration.

| Token | Alias of | Value | Category | Glyph | Contrast |
|---|---|---|---|---|---|
| `--color-cat-spot` | `--color-aurora` | `#1C7A5E` | Pörssisähkö | uneven hourly bars | 4.8:1 |
| `--color-cat-periodic` | `--color-lake` | `#2B5F8C` | Jaksoittain vaihtuva hinta | step line with period marks | 6.2:1 |
| `--color-cat-usage` | `--color-violet` | `#6A4C9C` | Kulutusvaikutus | flat line in a dashed ± band | 6.2:1 |
| `--color-cat-fixed` | `--color-granite` | `#535D68` | Kiinteä hinta | flat line with a padlock | 6.2:1 |

The glyph shape, not the colour, identifies the category, so the system works for colour-blind visitors.

### Price and data states

| Token | Value | Meaning |
|---|---|---|
| `--color-price-low` | aurora | Cheap hour, price down |
| `--color-price-high` | `#B3261E` | Expensive hour, price up, warning text (6.0:1) |
| `--color-saving` | aurora | "Säästö 24 € / 12 kk" and other good outcomes |
| `--color-warning-ink` | `#6B4A00` | Caution text without alarm (7.4:1) |
| `--color-forecast` | `#B7C6D9` | Forecast bars (third-party forecast, not the official price). Always with a legend. |
| `--color-star` | `#E0A21B` | Fill of the inked star on the cheapest hour or the best value. Never text. |

A neutral number uses `--color-ink`.

## 3. Type

Two families from Google Fonts:

- **Bricolage Grotesque** (`--font-display`), weight 800: H1, section headings, contract and item names, prices, rank numbers, poster numbers and inked actions. Tight tracking.
- **Figtree** (`--font-sans`), weights 400, 700 and 800: body, labels, values, captions and controls.

All board text uses Figtree (fixed 2026-10-16).

| Role | Font | Size / line | Weight, tracking | Color |
|---|---|---|---|---|
| Poster number in hero (current spot price) | Bricolage | 64 / 66 (`--text-hero`) | 800, −0.03em | snow-white on sky |
| H1 on hero | Bricolage | 52 / 54 (`--text-page`) | 800, −0.035em | snow-white on dusk/night, ink on light |
| Contract price €/kk | Bricolage | 52 / 54 (`--text-price`), decimals 30 | 800, −0.04em (`--tracking-price`) | ink; cheapest fox-deep |
| Rank number | Bricolage | 36 / 34 (`--text-rank`) | 800, −0.03em | rank 1 fox-deep, others ink |
| Section heading | Bricolage | 34 / 38 (`--text-section`) | 800, −0.02em | ink + fox underline |
| Key figure in a row | Bricolage | 32 / 36 (`--text-number`) | 800 | ink or state colour |
| Contract or item name | Bricolage | 22 / 26 (`--text-title`) | 800, −0.01em | ink |
| Inked action ("Katso →") | Bricolage | 18 / 22 | 800 | fox-deep + wavy underline |
| Lead | Figtree | 18 / 28 (`--text-lead`), max 720 | 400 | night-lead on night, ink-body on light |
| Body | Figtree | 16 / 25 (`--text-body`), max 680 | 400 | ink-body |
| UI, controls, links | Figtree | 15 / 20 (`--text-ui`) | 700–800 | ink |
| Label, value in a row | Figtree | 14 / 18 (`--text-label`) | 400 label, 800 value | ink-body / ink |
| Caption, kicker caps | Figtree | 13 / 16 (`--text-caption`) | 400; caps 800, +0.1em (`--tracking-caps`) | ink-muted; kicker in state colour |

Big numbers carry the page. Each page has one number that reads from across the room.

## 4. Shape, line and space

- **No containers.** There are no card, panel, pill or tag radii. The only radii are `--radius-swatch` 3 (legend swatches, bar tops), `--radius-control` 5 (checkbox) and `--radius-button` 12 (a rare filled submit button).
- **No shadows, no gradients, no blur.** Depth comes from the art.
- **Line language** (all hand-drawn, slightly uneven):
  - **Inked underline**: a wavy 3.5px fox stroke under a section heading; a 2.6px stroke under an inked action; ink under the active tab or page.
  - **Hairline**: a hand-inked 1.5px `--color-edge` line between ledger entries and FAQ rows.
  - **Dotted leader**: 2px dotted `--color-edge-strong` between a label and its value.
  - **Inked baseline**: 1.6px ink line under every chart and glyph.
  - **Paw-print divider**: a short track of fox paw prints between major sections.
- **Spacing scale (px):** 4, 8, 12, 16, 20, 24, 32, 40, 48, 64, plus `--spacing-section` 88 between major sections.
- **Layout (desktop 1440):** content 1232 wide (`--container-content`), gutter 104 (`--spacing-gutter`). Reading text max 680 (`--container-text`), hero text max 720.
- **Breakpoints:** mobile 390, tablet 768, laptop 1024, desktop 1440. Mobile boards are an open item.

## 5. Components

The Paper page "Design system", board 02, shows each component cloned from the reference boards.

### Top bar
Sits on the hero art. Logo (fox mark + "Voltikka" wordmark), navigation, and the current spot price as plain text with a sparkle mark, not a pill.

### Hero
Painted scene for the page type (§6), the fox once, H1, lead and the page's poster number on calm sky. Listing heroes are about 460px high; data and hub heroes can be taller. Text sits on calm sky, never on busy art.

### Preferred Sources strip
Directly after every hero (root `AGENTS.md`). Short text and an inked text action "Valitse suosikkilähteeksi" with a wavy fox underline, not a button.

### Breadcrumbs
One line of 13px caption text with "/" separators.

### Section heading
Bricolage 34 with a wavy fox underline. An optional 96px vignette (villager or object) sits at its left. Optional actions (for example "Lataa CSV") sit at the right on the same baseline.

### Day-figures row
Key numbers in one row with labels above and small inked ornaments between them. No boxes.

### Consumption choice row (listing)
Radio, 44px home illustration, name and kWh. The selected radio is fox with a white check; the kWh value turns fox-deep. Ends with the own-consumption field and "Arvioi laskurilla →".

### Pricing-category choice row
Checkbox, 44px category glyph, name in the category colour and a one-line meaning in ink-body.

### Field
No box: an inked `--color-field-edge` underline, the value in ink, the unit in ink-muted. Focus: the underline turns fox and 2px.

### Disclosure trigger
Text in ink 800 plus a chevron. The current state is in the label ("Saatavuus: koko Suomi").

### Results heading
Count in Bricolage 52 ("224 sopimusta."), the note line and the category legend with sparkle marks.

### Ledger contract entry
No card. One row per contract, separated by hand-inked hairlines:
1. Optional kicker: sparkle + small caps ("HALVIN SÄHKÖSOPIMUS").
2. Category line: 34px glyph, category name, a short meaning, and "Arvio ⓘ" at the right.
3. Main row: rank number above the logo (left lane 90); name and company line; price parts with dotted leaders (330); €/kk price, €/v line, savings and "ilman tarjousta" (right); "Katso →" inked action (120).
4. Tag line: sparkle-mark tags ("Tarjous", "Päästötön") or a warning mark ("! Ei sisällä kulutusvaikutusta") in price-high.
The cheapest entry gets the fox-deep price and a hand-drawn fox loop around it. Logos are cropped to their content and use multiply blend; a company without a logo shows its name in Bricolage.

### Inked marks
- **Sparkle**: four-point star before a tag or kicker.
- **Star**: `--color-star` fill on the cheapest hour.
- **Loop**: fox loop around the key number.
- **Arrow**: a short inked arrow with a two-word label ("nyt", "halvin", "sinun laskusi").
- **Warning**: "!" + text in price-high.

### Category glyphs
Inked price-shape pictures on an ink baseline: a sketchy ink outline over a slightly offset flat category colour (misregistered print), with light pencil hatching (`--opacity-hatching`). Sizes: 44 in choice rows, 34 in entries, larger as section art.

### Charts
Bar or line charts with an inked baseline, a dashed average line, value labels where they fit, every hour or day labelled, `--color-forecast` for forecast bars, fox for "nyt" with an inked arrow, and a legend above. Mini charts (forecast days) use the same rules without labels on each bar.

### FAQ row
Question in ink 18/800 with a chevron; the open answer in body text, max 680. Hairlines between rows.

### Pagination
Page numbers in ink 800; the current page in fox-deep with a wavy underline; "‹ Edellinen" and "Seuraava ›".

### Link columns ("Katso myös")
Column heads in ink 800, links in ink-body; four columns on desktop.

### Villager interlude strip
A transparent painted strip (about 1000 wide, centred, no frame) between two page blocks. Never inside or next to a contract, seller or seller price.

### Footer
Painted night village with the fox asleep in the snow; full live footer links on the night sky in night-lead.

### Page patterns (from the page boards, 2026-10-16)

These rules come from the boards for every live page (pages "Pages · group A–F"). They are proposals until the user reviews those boards.

- **Hero text needs calm sky behind every text column.** When a hero has a data block at the right, ask the art for "landscape in the bottom ~40%". When the fox fills the right side, the hero's key figure moves inline under the lead.
- **Hero key figure on the sky.** A data or hub page can put its key figure on the sky as data: the current spot price, the energy price index, the forecast outlook ("Suunnilleen ennallaan").
- **Light hero variant.** On a bright scene (summer, autumn day), the top bar and hero text use ink on a light shade. Night and dusk scenes keep white text.
- **Hero heights.** Listing 460; contract and company pages 360–460 (a landscape band); hub, data and directory pages 600–700.
- **Seller pages.** On contract and company pages, the fox stands at the far edge of a landscape band. The contract name, price and seller sit below the band or on the opposite side. These pages have no villager interlude, because every block is a contract or seller block. A heading of a seller section gets an object vignette (for example an inked map pin), never a villager.
- **Giant numeral in a sentence.** A hub page can set its key count as a giant fox-orange numeral inside the live sentence, in the live word order ("Vertaa 367 sähkösopimusta puolueettomasti.").
- **Podium entry.** On the cheapest-contracts page, the first entry is larger: rank about 88, name at section size, price about 96 with a larger loop.
- **City listings.** Local-company entries show an inked map pin and the distance ("12 km") at the right of the category line, and no rank number.
- **Company without a logo:** the company name (or its short name) in Bricolage.
- **Business contracts on a company page** are compact one-line rows.
- **Tool pages.** A form can be drawn as the real paper object that explains the input (a torn electricity bill, a meter counter). It is not a card: it has no white panel, border or shadow. (Proposal; the user must confirm.)
- **Result states.** A result board uses the real output of the live tool and states its example inputs in a note on the board.
- **Charts that explain a calculation:** range bars for p20–p80 with a median tick, a stacked bar for a total against the current cost, cumulative lines for payback, slope charts for forecast quantiles.
- **Series colours in multi-series charts.** Pricing categories use their category colour. Open-ended (Toistaiseksi) is a dashed `--color-ink-muted` line. Day/night series use fox (day) and lake (night) only in charts that show no pricing categories.
- **Long reads** (article, Tietoa, legal): a numbered section list at the left (260–300) and a 700 body column at 18/30; charts up to 860 wide; drop cap on the first paragraph; pull quotes in Bricolage.
- **Tabs on a live page** are drawn with all panels open on the board.
- **Directory pages** (Paikkakunnat) can use a painted map with live town names as text pins on the art. Map prompts must say "no tents, no tepees, no traditional costumes or cultural symbols".
- **Seasonal footer.** The footer is a winter scene; the summer solar page may need a summer variant (open item).

## 6. Hero map

One distinct hero per page type. The full list with the fox action for each page is in `pages-plan.md`. The two approved heroes:

| Page type | Hero | Fox |
|---|---|---|
| `/spot-price` | Picture-book village at night with aurora; the current price as poster number on the sky | Sits and looks up at the sky (`fox-sit-skyward-v1.png`) |
| `/sahkosopimus` and listings | Village at dusk, short (460px); energy price index on the sky | Walks with a lantern (`fox-walk-lantern-v1.png`) |

On contract and company pages the fox stays far away in the scene, away from the contract name, price and logo.

## 7. Illustration

- **Style:** fine dark ink outlines with soft watercolour, Finnish everyday life, gentle humour (brand guide §4). Never name an artist in a prompt.
- **The fox** (`mascot/fox-v2.png`): four paws, green Nordic scarf, an ordinary fox tail. No flame tail, never curled around a round shape.
- **Villagers** (hare, badger, bear, owl, hedgehog, beaver): small everyday helpers. They can head a section, do the household tasks on the spot page and star in interlude strips. Never next to a contract, a seller or a seller's price.
- **Scenes:** hero plates, the footer night village and interlude strips. Season can follow the topic (solar = midnight-sun summer).
- **Production:** image-gen skill (Codex first), `--ref mascot/fox-v2.png` for every fox, transparent cutouts for anything that sits on the ground. Check every result before use. Rejected art stays in the repo only as a record: `emblems/`, `illustrations/wash-aurora-v1.png`.

### Seasons (user, 2026-10-16)

"Finland has 4 different seasons and the site should always have the feel of the current season." Every season-bound illustration has four variants. Each variant draws from the mood of its season, not only from its colours.

| Season | Months (Europe/Helsinki) | Mood | Elements |
|---|---|---|---|
| **Talvi** (winter) | Dec–Feb | Calm, cosy, blue hour | Deep snow, aurora, kaamos blue hour, warm windows, lanterns, ski tracks, smoke from chimneys |
| **Kevät** (spring) | Mar–May | Nature wakes up, fresh and hopeful | Melting snow patches and last dirty snowbanks, meltwater streams and puddles, lake ice breaking into floes, birches with small new leaves, coltsfoot (leskenlehti) and pussy willows, swans and cranes flying back, long pale-gold evening light, villagers in rubber boots airing rugs and raking |
| **Kesä** (summer) | Jun–Aug | Lazy, warm, nightless night | The low midnight sun, gold-peach-pink sky, lush birches and meadow flowers, a mirror-calm lake, rowing boat and sauna jetty, a small midsummer bonfire far away, villagers swimming and fishing |
| **Syksy** (autumn) | Sep–Nov | Cosy but blustery | Ruska colours (yellow birches, orange aspens, red rowan berries), leaves swirling in strong wind, slanting rain showers and grey rain clouds with a gap of low warm light, wet paths and puddles, lit windows, villagers in raincoats with umbrellas that turn inside out, mushroom baskets |

Rules:

- **What varies:** hero scenes and plates, the footer scene, interlude strips, the snow line under cutouts (fox and villagers), the home illustrations and any vignette with weather or ground. Category glyphs, logos and charts do not vary.
- **What stays:** the composition (same camera, layout and calm text area; generate each variant with the winter version as `--ref`), the fox design and its green scarf, the aurora sparks at the fox's tail tip (the brand's "fox fire"), and the page's topic object (solar panels, heat pump, meter, telescope).
- **Aurora** appears only in winter scenes (and a faint one in late autumn at most). Summer nights are bright.
- **Text on bright skies.** Spring and summer heroes are bright: they use the light hero variant (ink text and top bar on a light shade). Autumn and winter heroes keep white text on a dark sky. Check the contrast of each variant.
- **Selection:** the server selects the season from the current Europe/Helsinki month and serves the matching file. The season is part of the page cache key. Files are named `<name>-<season>-vN.png` (`talvi`, `kevat`, `kesa`, `syksy`); the current winter files count as the `talvi` variants.
- **Page topics with a season of their own** (solar = summer sun, heat pump = frost) still get four variants. The topic object stays; only the season around it changes.

## 8. Accessibility

- Text pairs meet the ratios in §2. Fox orange is never small text.
- Category meaning never depends on colour alone (glyph shape + name).
- Controls look like controls: real radios, checkboxes and underlined fields with a 3:1 edge, visible focus (fox underline or ring).
- Art is decorative (`alt=""`) unless it carries meaning; data is always in text.
- Hero text sits on calm sky with at least 4.5:1.
- Motion (if any) respects `prefers-reduced-motion`.

## 9. Open items

1. Done 2026-10-16: all text uses Figtree, and CSS colours use tokens. Still open: colours inside SVG `fill`/`stroke` attributes (glyphs, inked marks, most charts) and about 20 one-off colours with no token (hero shades, a few on-night text tints). The build must map them to tokens.
2. Mobile (390px) boards for every page type.
3. Open-state boards for the listing disclosures (postcode field, bill comparison form, Rajaa hakua options).
4. Logo: the mark next to the wordmark is a placeholder.
5. Performance plan for the art (AVIF/WebP, sizes, no layout shift, mobile crops) before implementation.
6. User review of the page boards (groups A–F) and of the page patterns in §5.
7. The live 404 is Laravel's default English page. The 404 board has a proposed Finnish text.
8. A summer footer variant for the solar page.
9. Bold lead-ins inside a paragraph cannot be drawn in Paper (one style per text node). The build must restore them from the live page.
