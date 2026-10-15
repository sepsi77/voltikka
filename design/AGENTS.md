# design/ — Voltikka redesign

This folder owns the visual redesign of Voltikka: the brand guide, the design system spec, the art (fox, scenes, illustrations) and the link to the Paper design file. It does **not** own app code (`laravel/`). Read the root `AGENTS.md` first. Its rules apply here too: reply and write in ASD-STE100 Simplified Technical English, the simplicity rule, and no production changes without approval.

## Read first

1. `voltikka-design-system.md`: **the source of truth for the visual design** (tokens, type, layout rules, theme rule, open items and user decisions).
2. `voltikka-brand-guide.md`: the name, the fox-fire story, the fox rules and the voice.

If the two disagree, the design system spec wins. Report the conflict to the user and fix the other file.

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
| `illustrations/{sauna,laundry,ev-charging}-v1.png` | "Kannattaako nyt?" illustrations |
| `scenes/interlude-villagers-*.png` | Transparent villager interlude strips for pauses between page blocks (walk = on the listing board; noticeboard = alternative) |
| `emblems/emblem-*.png` | Rejected as pricing category signs (meaning unclear); use the inked price-shape glyphs in the spec instead |
| `.claude/skills/image-gen/` | Image generation skill (ported from Fetchalot; Codex by default, OpenRouter fallback reads `laravel/.env`) |

All art is made with the image-gen skill and is production art (user, 2026-10-16): no illustrator redraw. New fox poses are image-gen variations of `mascot/fox-v2.png`. Check every result for consistency with the reference.

## The Paper file

- File **"Voltikka"**, id `01M50B1D4JDT66PK35AAW7BSE4`. Call `get_guide({topic: "paper-mcp-instructions"})` once per session before other Paper tools.
- The file has design tokens that match the spec §2–§4. Use them as `var(--token)`. Never hard-code a color that has a token. When you add a token, add it in Paper and in the spec in the same change.
- Page **"Direction · first boards"** (`p-1-0`), desktop 1440. **Reference for the main rule (design at the side, no cards):** "Pörssisähkö — /spot-price v4 picture book". v3 (white rounded panels) is rejected. "Sähkösopimukset — /sahkosopimus v4 picture book" is the current listing board (compact controls, ledger entries). The v1 boards and "/spot-price v2 BOLD" are rejected options kept for comparison; delete them when the user approves the direction.
- Insert local images with `paper-asset:///Users/seppo/code/voltikka/design/…`.
- Known Paper limits: `margin` and CSS `transform` are ignored (use padding/gap; flip art in the image itself). Screenshots can render late; take a second one before you decide that an image is missing.

## Making art

Run from `design/`: `python3 .claude/skills/image-gen/scripts/image_gen.py "<description>" <out_dir> --name <name> [--ref mascot/fox-v2.png] [--transparent] [--aspect 1:1]`. Read `.claude/skills/image-gen/SKILL.md` first. Look at every result before you use it. Never draw the fox with a flame tail or curled around a round shape.

## Keep this file true

When you add, remove or rename a Paper page, a key board, a folder or a source document, update this file in the same change.
