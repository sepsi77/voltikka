# design/ — Voltikka redesign

This folder owns the visual redesign of Voltikka: the brand guide, the design system spec, the art (fox, scenes, illustrations) and the link to the Paper design file. It does **not** own app code (`laravel/`). Read the root `AGENTS.md` first. Its rules apply here too: reply and write in ASD-STE100 Simplified Technical English, the simplicity rule, and no production changes without approval.

## Read first

1. `voltikka-design-system.md`: **the source of truth for the visual design** (tokens, type, layout rules, theme rule, open items and user decisions).
2. `voltikka-brand-guide.md`: the name, the fox-fire story, the fox rules and the voice.

If the two disagree, the design system spec wins. Report the conflict to the user and fix the other file.

## Impeccable files (root)

- `PRODUCT.md`: product truth for the Impeccable design skill (users, positioning, brand commitments, evidence). Not visual.
- `DESIGN.md` and its sidecar `.impeccable/design.json`: the Impeccable-format record of the shipped redesign. The spec in this folder wins over them. When you change the spec or the tokens, refresh them with `/impeccable document`.
- New surfaces are designed as Paper boards first, then built (user, 2026-10-23). Do not start a new surface from a generated comp image.

## Fixed constraints (user)

- The site name stays **Voltikka** and the domain stays **voltikka.fi** (2026-10-16). Themed words never replace SEO titles, H1 text, URLs or the `| Voltikka` suffix.
- No rounded "SaaS cards" (2026-10-16), and the design must be **bold**, not a different flavour of SaaS (2026-10-16). See the spec §1.
- The fox never appears next to a seller, a contract or a price recommendation. Voltikka is a neutral comparison site.
- Do not reference Energiavirasto in any public copy.

## Files

| Path | What it is |
|---|---|
| `voltikka-design-system.md` | Design spec |
| `voltikka-brand-guide.md` | Brand guide (name, story, fox rules, voice) |
| `voltikka-tokens.css` | The 92 design tokens as a Tailwind v4 `@theme` block. Must match the Paper tokens |
| `seasons-prompts.md` | Prompt templates and season briefs for the talvi/kevät/kesä/syksy art variants |
| `pages-plan.md` | Plan for the boards of every live page: page groups, hero ideas, rules |
| `mascot/fox-v2.png` | **Approved fox reference** (user, 2026-10-16): four paws, green Nordic scarf. Give it as `--ref` for every new pose |
| `mascot/fox-v1.png` | Upright fox with a lantern. Alternative, not chosen |
| `mascot/fox-sit-skyward-v1.png` | Cutout: the fox sits and looks up (spot price hero, at "now") |
| `mascot/fox-walk-lantern-v1.png` | Cutout: the fox walks with a lantern (listing hero) |
| `scenes/winter-evening-v1.png` | v1 spot price scene: lake, cabins, power line, fells, aurora |
| `scenes/night-sky-plate-v1.png` | v2 spot price hero: sky and aurora only; the hourly bar chart sits on its dark lower part |
| `scenes/winter-evening-village-v2.png` | Picture-book hero scene with tiny villagers (hare, badger), ink line + watercolour; the reference for the picture-book feel |
| `scenes/village-dusk-plate-v1.png` | v2 listing hero: village at dusk with an empty snowfield |
| `illustrations/home-*.png` | The four consumption homes (studio, apartment, row house, house) |
| `illustrations/villager-*.png` | Villager vignettes (sauna badger, laundry hare, car-charging bear, owl with pocket watch) |
| `illustrations/wash-aurora-v1.png` | Watercolour wash. Rejected ("smudging"); do not use |
| `scenes/footer-night-village-v1.png` | Footer scene, the fox asleep in the snow |
| `scenes/hero-paikkakunta-{yleinen,helsinki,tampere,turku,oulu}-<season>-v1.png` | City listing heroes (round 3): the fox on a rock looks over the water at the town. Helsinki (cathedral, Uspenski, Suomenlinna), Tampere (Näsinneula, Tammerkoski, factory chimneys), Turku (castle, cathedral, tall ship on the Aura), Oulu (yellow cathedral tower, red storehouses, market hall); `yleinen` = general lakeside town for every other city. The syksy file is the original. `hero-paikkakunta-helsinki-v1.png` is the old generic coastal plate (not used) |
| `illustrations/{sauna,laundry,ev-charging}-v1.png` | "Kannattaako nyt?" illustrations |
| `scenes/interlude-villagers-*.png` | Transparent villager interlude strips for pauses between page blocks (walk = on the listing board). `interlude-villagers-noticeboard-{,kevat-,kesa-,syksy-}v1.png` = the Preferred Sources strip art (round 4 option A shows it small, 190 × 74, desktop only) |
| `emblems/emblem-*.png` | Rejected as pricing category signs (meaning unclear); use the inked price-shape glyphs in the spec instead |
| `*-{talvi,kevat,kesa,syksy}-v1.png` | Season variants of an art file (spec "Seasons"); the old file without a season name is the talvi (or original-season) version |
| `.claude/skills/image-gen/` | Image generation skill (ported from Fetchalot; Codex by default, OpenRouter fallback reads `laravel/.env`) |

All art is made with the image-gen skill and is production art (user, 2026-10-16): no illustrator redraw. New fox poses are image-gen variations of `mascot/fox-v2.png`. Check every result for consistency with the reference.

## The Paper file

- File **"Voltikka"**, id `01M50B1D4JDT66PK35AAW7BSE4`. Call `get_guide({topic: "paper-mcp-instructions"})` once per session before other Paper tools.
- The file has design tokens that match the spec §2–§4. Use them as `var(--token)`. Never hard-code a color that has a token. When you add or change a token, change it in Paper, in `voltikka-tokens.css` and in the spec in the same change.
- Page **"Direction · first boards"** (`p-1-0`), desktop 1440. **Reference for the main rule (design at the side, no cards):** "Pörssisähkö — /spot-price v4 picture book". v3 (white rounded panels) is rejected. "Sähkösopimukset — /sahkosopimus v4 picture book" is the current listing board (compact controls, ledger entries). The user deleted the rejected draft boards (v1, v2 BOLD, v3) on 2026-10-16; only the two approved reference boards stay on this page. Pages "Pages · group A–F" hold the boards for the other live pages (see `pages-plan.md`).
- Page **"Design system"**: boards 01 Foundations, 02 Components, 03 Theme and illustration (like the Fetchalot file).
- Page **"Feedback · Löysitkö etsimäsi?"** (`p-C-0`, 2026-10-28, for review): the site feedback widget. Boards: listing slot 5, flow states, phone 390, solar calculator variant, and the page map with reasons and goal codes. See `tasks/site-feedback/`.
- Page **"Video · YouTube concepts"** (`p-D-0`, 2026-10-30, for review): the brief board and four 15 s YouTube ad concepts (01 Hintalappu, 02 Lyhty, 03 Haku, 04 Lumisade), each with four 16:9 key frames, timing and voice lines. See `tasks/youtube-brand-video/` and `videos/`.
- Insert local images with `paper-asset:///Users/seppo/code/voltikka/design/…`. SVG files work too; use an SVG image instead of inline SVG for many small shapes (inline SVG creates one layer per shape).
- Known Paper limits: `margin` and CSS `transform` are ignored (use padding/gap; flip art in the image itself). Screenshots can render late; take a second one before you decide that an image is missing.
- Text nodes do not inherit the font from their frame: set `font-family: var(--font-sans)` or `var(--font-display)` on every text node, or it falls back to system-ui.
- `<x-paper-clone>` of the reference top bar (`2J8-0`) stacks its nav items. Use the fixed top bar on the legal board (layer "Top bar" on "Pages · group F") or `duplicate_nodes`. A clone with `position:absolute` in its style passes that style to all descendants; use `duplicate_nodes` for absolute parts. A clone into an absolute hero lands as the first child, under the art: move it to the end.
- `position: relative` is ignored on frames, but absolute children still position against their parent frame.
- Paper keeps one style per text node, so a bold lead-in inside a paragraph is lost.
- Gradient stops must use percent, not px: Paper drops a px stop and spreads the gradient over the whole frame.
- A text node copied with `duplicate_nodes` can fall back to system-ui; set the font again.

## Making art

Run from `design/`: `python3 .claude/skills/image-gen/scripts/image_gen.py "<description>" <out_dir> --name <name> [--ref mascot/fox-v2.png] [--transparent] [--aspect 1:1]`. Read `.claude/skills/image-gen/SKILL.md` first. Look at every result before you use it. Never draw the fox with a flame tail or curled around a round shape.

Share images (Open Graph, Paper board "R3 · OG images"): `scripts/build-design-art.sh og` makes `laravel/public/images/og/<art>-<season>.jpg` from the built hero WebP files plus the wordmark tag `logo/og-wordmark-tag.png`. That tag is a 2x browser render of `logo/og-wordmark-tag.html`; render it again only when the wordmark changes. The step runs after all other art, because it reads their output.

## Keep this file true

When you add, remove or rename a Paper page, a key board, a folder or a source document, update this file in the same change.
