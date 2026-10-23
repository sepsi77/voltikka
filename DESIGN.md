---
name: Voltikka
description: Finnish electricity comparison drawn as a seasonal picture book. Exact, plain prices on snow-coloured paper.
colors:
  ground: "#F5F6F1"
  snow-white: "#FFFFFF"
  edge: "#D9DFDC"
  edge-strong: "#B9C2C0"
  field-edge: "#838E89"
  ink: "#16202E"
  ink-body: "#3A4656"
  ink-muted: "#5E6876"
  night: "#14213A"
  night-lead: "#C9D3E3"
  fox: "#C4501C"
  fox-deep: "#9E3C10"
  aurora: "#1C7A5E"
  lake: "#2B5F8C"
  violet: "#6A4C9C"
  granite: "#535D68"
  cat-spot: "#1C7A5E"
  cat-periodic: "#2B5F8C"
  cat-usage: "#6A4C9C"
  cat-fixed: "#535D68"
  price-high: "#B3261E"
  price-low: "#1C7A5E"
  saving: "#1C7A5E"
  warning-ink: "#6B4A00"
  forecast: "#B7C6D9"
  star: "#E0A21B"
  season-ground-talvi: "#E4EAF0"
  season-ground-kevat: "#DFE6D0"
  season-ground-kesa: "#D5E5C4"
  season-ground-syksy: "#EEDCC2"
typography:
  hero-number:
    fontFamily: "Bricolage Grotesque, system-ui, sans-serif"
    fontSize: "64px"
    fontWeight: 800
    lineHeight: "66px"
    letterSpacing: "-0.03em"
  display:
    fontFamily: "Bricolage Grotesque, system-ui, sans-serif"
    fontSize: "52px"
    fontWeight: 800
    lineHeight: "54px"
    letterSpacing: "-0.035em"
  price:
    fontFamily: "Bricolage Grotesque, system-ui, sans-serif"
    fontSize: "52px"
    fontWeight: 800
    lineHeight: "54px"
    letterSpacing: "-0.04em"
  rank:
    fontFamily: "Bricolage Grotesque, system-ui, sans-serif"
    fontSize: "36px"
    fontWeight: 800
    lineHeight: "34px"
    letterSpacing: "-0.03em"
  headline:
    fontFamily: "Bricolage Grotesque, system-ui, sans-serif"
    fontSize: "34px"
    fontWeight: 800
    lineHeight: "38px"
    letterSpacing: "-0.02em"
  number:
    fontFamily: "Bricolage Grotesque, system-ui, sans-serif"
    fontSize: "32px"
    fontWeight: 800
    lineHeight: "36px"
  title:
    fontFamily: "Bricolage Grotesque, system-ui, sans-serif"
    fontSize: "22px"
    fontWeight: 800
    lineHeight: "26px"
    letterSpacing: "-0.01em"
  action:
    fontFamily: "Bricolage Grotesque, system-ui, sans-serif"
    fontSize: "18px"
    fontWeight: 800
    lineHeight: "22px"
  lead:
    fontFamily: "Figtree, system-ui, sans-serif"
    fontSize: "18px"
    fontWeight: 400
    lineHeight: "28px"
  body:
    fontFamily: "Figtree, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: "25px"
  ui:
    fontFamily: "Figtree, system-ui, sans-serif"
    fontSize: "15px"
    fontWeight: 700
    lineHeight: "20px"
  label:
    fontFamily: "Figtree, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: "18px"
  caption:
    fontFamily: "Figtree, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 400
    lineHeight: "16px"
  caption-caps:
    fontFamily: "Figtree, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 800
    lineHeight: "16px"
    letterSpacing: "0.1em"
rounded:
  swatch: "3px"
  control: "5px"
  button: "12px"
spacing:
  "1": "4px"
  "2": "8px"
  "3": "12px"
  "4": "16px"
  "5": "20px"
  "6": "24px"
  "8": "32px"
  "10": "40px"
  "12": "48px"
  "16": "64px"
  section: "88px"
  gutter: "104px"
components:
  inked-action:
    textColor: "{colors.fox-deep}"
    typography: "{typography.action}"
  inked-action-hover:
    textColor: "{colors.ink}"
  field:
    textColor: "{colors.ink}"
    typography: "{typography.ui}"
    rounded: "0px"
    padding: "6px 2px"
  checkbox:
    backgroundColor: "{colors.snow-white}"
    rounded: "{rounded.control}"
    size: "20px"
  checkbox-checked:
    backgroundColor: "{colors.fox}"
    textColor: "{colors.snow-white}"
    rounded: "{rounded.control}"
    size: "20px"
  strip-action:
    textColor: "{colors.fox-deep}"
    typography: "{typography.action}"
    height: "44px"
  strip-action-hover:
    textColor: "{colors.ink}"
  nav-item:
    textColor: "{colors.ink-body}"
    typography: "{typography.ui}"
    padding: "6px 0"
  pager-current:
    textColor: "{colors.fox-deep}"
    typography: "{typography.action}"
---

# Design System: Voltikka

If this file and `design/voltikka-design-system.md` disagree, the spec wins; report the conflict. This file is the Impeccable-format record of the shipped redesign. The spec, the brand guide (`design/voltikka-brand-guide.md`) and the Paper file "Voltikka" own the design. `laravel/resources/css/tokens.css` must stay identical to `design/voltikka-tokens.css`.

## Overview

**Creative North Star: "The Seasonal Picture Book"**

Voltikka is a picture book about Finnish evenings and electricity. A painted scene opens every page: a village, a lake, a sky. The fox stands in that scene once. The art follows the current Europe/Helsinki season: talvi (December to February), kevät (March to May), kesä (June to August) and syksy (September to November). The composition, the fox and its green scarf stay the same in every season. Only the weather, the light and the villagers' activities change.

The pages are bold, not timid. Prices are exact and plain, and big numbers carry each page. The design stays at the side: the art frames the content but never carries data or controls. Content sits directly on the snow-coloured ground. Structure comes from display headings with a hand-inked fox underline, inked hairlines, dotted leaders, paw-print dividers and small inked marks. Nothing sits in a card.

The user rejected these directions, and they stay rejected: standard SaaS cards and white rounded panels, pills and badges, shadows, gradients and glass behind content, watercolour washes behind text or data, data-as-landscape charts, per-contract mini charts, and painted category emblems.

**Key Characteristics:**
- One painted hero per page type, four seasonal variants of each, with a light or dark text tone that follows the brightness of the art.
- Snow-coloured ground (#F5F6F1) under all content; no containers.
- Bricolage Grotesque 800 for headings and numbers; Figtree for everything else.
- Hand-drawn lines: wavy fox underline, 1.5px hairline, 2px dotted leader, inked chart baseline.
- One brand accent (fox) and four category colours that always come with a glyph.
- Real controls: underlined fields, 20px checkboxes and radios, disclosure triggers that state their value; actions are inked text.

## Colors

Cool snow paper, navy ink and one warm fox orange, with four map colours that belong to the pricing categories.

### Primary
- **Fox Orange** (fox): Inked underlines under headings, the selected checkbox and radio, the "nyt" bar, the loop around the cheapest price, focus outlines. It has 4.3:1 on ground, so use it for marks and for text of 24px and up only.
- **Deep Fox** (fox-deep): Fox-coloured text. Inked actions, rank 1, the cheapest price, the current page, status caps on a ledger entry (6.2:1).

### Secondary
- **Aurora Green** (aurora, alias cat-spot, price-low, saving): Pörssisähkö, cheap hours, savings.
- **Lake Blue** (lake, alias cat-periodic): Jaksoittain vaihtuva hinta. Night series in day/night charts that show no categories.
- **Twilight Violet** (violet, alias cat-usage): Kulutusvaikutus.
- **Granite** (granite, alias cat-fixed): Kiinteä hinta; actual (past) spot bars.

### Tertiary
- **Warning Red** (price-high): Expensive hours, price up, warning lines on ledger entries, invalid field underline (6.0:1).
- **Caution Ochre** (warning-ink): Caution text without alarm (7.4:1).
- **Forecast Mist** (forecast): Third-party forecast bars only, always with a legend.
- **Star Gold** (star): Fill of the inked star on the cheapest hour. Never text.
- **Season grounds** (season-ground-talvi, -kevat, -kesa, -syksy): The painted ground line under villager vignettes. Decoration only.

### Neutral
- **Snow Ground** (ground): The page ground. It is also the snow of the art, so cutouts sit on the page with no frame.
- **Snow White** (snow-white): Text on night and dusk heroes; checkbox fill. Never a card.
- **Ink** (ink): Headings, numbers, values (15.1:1).
- **Body Ink** (ink-body): Body text (8.8:1).
- **Muted Ink** (ink-muted): Captions and secondary lines (5.2:1). Not below 13px.
- **Night** (night): Night sky band, mobile menu panel, footer ground.
- **Night Lead** (night-lead): Lead and link text on night (10.6:1).
- **Field Edge** (field-edge): Checkbox, radio and field underline (3.1:1).
- **Edge** (edge) and **Strong Edge** (edge-strong): Hairlines between rows, and dotted leaders. Decoration; no contrast need.

### Named Rules
**The Glyph Before Colour Rule.** A category colour appears only on that category's glyph, name, checked state or chart series. The glyph shape names the category, so the colour never works alone.

**The Fox Is Not Small Text Rule.** Fox orange is a mark colour. Text in fox colour under 24px uses fox-deep.

**The Tone Follows The Art Rule.** Bright art (most spring and summer heroes) gets the light tone: ink text on a ground-colour shade. Dark art (winter, rainy autumn) gets the dark tone: white text on a navy shade. `App\Support\Season::heroTone()` decides per hero, not per season name.

## Typography

**Display Font:** Bricolage Grotesque 800 (with system-ui, sans-serif)
**Body Font:** Figtree 400, 700, 800 (with system-ui, sans-serif)

**Character:** A heavy, slightly quirky grotesque for every name and number, so prices read from across the room. A friendly, clean sans for reading and for controls.

### Hierarchy
- **Hero number** (800, 64/66, -0.03em): The page's poster number on the sky, for example the current spot price.
- **Display** (800, 52/54, -0.035em; 34/38 on a phone): H1 on the hero. Long Finnish words break, never clip.
- **Price** (800, 52/54, -0.04em; decimals 30/36, unit Figtree 16): The €/kk price on a ledger entry.
- **Rank** (800, 36/34, -0.03em): Rank number on a ledger entry.
- **Headline** (800, 34/38, -0.02em; 28/32 on a phone): Section heading with the wavy fox underline.
- **Number** (800, 32/36): Key figure in a row.
- **Title** (800, 22/26, -0.01em): Contract or item name.
- **Action** (800, 18/22): Inked actions and page numbers.
- **Lead** (400, 18/28, max 720px): Hero and page lead.
- **Body** (400, 16/25, max 680px): Running text.
- **UI** (700 to 800, 15/20): Controls, navigation, links.
- **Label** (400 label, 800 value, 14/18): Rows of label and value, price parts.
- **Caption** (400, 13/16): Breadcrumbs, notes, small print. **Caption caps** (800, +0.1em, uppercase) mark a status, in the status colour.

### Named Rules
**The One Big Number Rule.** Each page has one number that reads from across the room. Numbers use tabular figures.

**The Two Families Rule.** Bricolage for names and numbers, Figtree for words and controls. No third family, and no system display face.

## Layout

The page column is 1232px wide (`--container-content`) with a 104px gutter at 1440. It keeps 32px of free width at the sides on a phone (16px each side) and 64px from 1024px. Reading text stops at 680px; hero text at 720px. Major sections are 88px apart (`--spacing-section`). The spacing scale is 4, 8, 12, 16, 20, 24, 32, 40, 48, 64.

The main layout switch is at 1024px: the top bar becomes the desktop navigation, the hero plate takes the Paper crop. The spec breakpoints are 390, 768, 1024 and 1440. The ledger also switches at 640px (two rows) and 1200px (five lanes: rank 90, name, price parts, price, action 120).

Every page opens with its painted hero (listing heroes about 460px; contract and company bands 360 to 460px; hub and data heroes 600 to 700px). The Preferred Sources strip follows directly as a low strip (about 84px: a small notice-board picture on desktop, a display title, an inked action). Listing pages put the contracts high (round 4, "calm top"): the breadcrumb sits in the hero above the H1, then the low strip, one controls block (the consumption row, then one row of disclosure triggers: Hinnoittelu, Saatavuus, Rajaa hakua; a two-column grid on a phone), then the results heading with its ranking promise and, at its right, the bill comparison as an inked action that opens the torn-paper bill of /maksatko-liikaa. On a 1440 x 900 screen the first contract starts inside the first screen. A villager interlude strip (about 1000px wide, centred, no frame) can pause between page blocks, never next to a contract, seller or price. The footer is a painted night village with the fox asleep and the full link columns on the night sky.

Long reads use a numbered section list at the left (260 to 300px) and a 700px body column at 18/30.

## Elevation & Depth

The system is flat. There are no shadows, no blur and no glass. Depth comes only from the art: the hero plate, cutouts and the footer scene. A painted hero gets a navy or ground-colour shade at its text side so text sits on calm sky at 4.5:1 or more. That shade is the one sanctioned gradient. It belongs to the art, never to content.

### Named Rules
**The Ink Not Chrome Rule.** Marks are drawn, not boxed. A field focus thickens its underline with a 1px fox line; that is a line, not an elevation.

## Shapes

The form language is hand-drawn line on open paper. There are no card, panel, pill or tag radii. The only radii are swatch (3px: legend swatches, bar tops), control (5px: checkbox, focus area of text choices) and button (12px: a rare filled submit button). Fields have no box and a 0 radius.

Lines are slightly uneven SVG paths with non-scaling strokes:
- **Wavy underline**: 3.5px fox under a section heading (200px wide), 3px under a sub-heading or the current page, 2.6px under an inked action.
- **Hairline**: 1.5px between ledger entries and FAQ rows.
- **Dotted leader**: 2px dotted edge-strong between a label and its value.
- **Inked baseline**: 1.6px ink under charts and glyphs.
- **Paw-print divider**: a short track of fox paw prints between major sections.


## Components

### Buttons
Character: almost no buttons. Actions are inked text.
- **Inked action** ("Katso →", "Lataa CSV"): Bricolage 18/22 800 in fox-deep with a 2.6px wavy underline. Hover turns it ink.
- **Small inked link**: Figtree 14/18 800 fox-deep, 1px underline at 3px offset.
- **Preferred Sources action**: an inked action (Bricolage 18/22 800 fox-deep with a 2.6px fox wave; 15/20 below 1024), 44px tap height. Hover: ink. Loading and fallback states keep the same box. The strip beside it (round 4, option A, user 2026-10-23) has a small notice-board picture on desktop and a display title; the round 3 pinned paper note is retired.
- **Focus**: 2px fox outline with a 2 to 3px offset on every interactive element.

### Cards / Containers
There are none. A ledger entry, a FAQ row and a section are separated by hairlines and space only.

### Inputs / Fields
- **Style:** no box. A 2px field-edge underline, value in ink 16/22 700, unit in ink-muted caption caps, placeholder ink-muted 400.
- **Focus:** the underline turns fox and gets one more 1px fox line under it.
- **Error / Disabled:** invalid underline in price-high with an accessible notice in price-high 14/700; disabled at 50% opacity.
- **Checkbox and radio:** real inputs, 20px, 2px field-edge, snow-white fill, 5px radius on the checkbox. Checked: fox fill with a white check.
- **Choice row:** checkbox or radio, a 44px glyph or home picture, the name and a one-line meaning. It looks like a control, never like a tile or a pill.
- **Disclosure trigger:** ink 14/800 text plus a chevron that turns 180° in 150ms. The label states the current value ("Saatavuus: koko Suomi").

### Navigation
- **Top bar:** on the hero art, transparent, in the hero tone. Wordmark in Bricolage 28/34 (23/28 on a phone), nav items Figtree 15 700 in the soft tone, the active item 800 in the strong tone with a 2px fox underline. The current spot price is plain text with a sparkle mark, never a pill.
- **Dropdown:** a ground-colour panel with a 1.5px ink edge and no shadow. Links are ink-body 15 700; hover and active get a 2px fox underline.
- **Mobile menu:** a night panel with a 3px fox bottom edge and night-lead links.
- **Pagination:** page numbers in Bricolage 18 800 ink, the current page fox-deep with a wavy underline, "‹ Edellinen" and "Seuraava ›" in Figtree 16 800.
- **Breadcrumbs:** one line of 13px caption text with "/" separators.

### Ledger contract entry (signature)
One row per contract, no card, no hover lift. A category line (34px glyph, category name and meaning, the "Arvio ⓘ" popover trigger in ink-muted with a dotted underline). A main row with rank above the company logo (logo in multiply blend, or the name in Bricolage), the contract name, price parts with dotted leaders, the €/kk price and the inked action. A tag line with sparkle tags and "!" warnings in price-high. The cheapest entry gets a fox-deep price and rank and a hand-drawn fox loop around the price. An entry over the consumption limit steps back to ink-muted and 50% opacity.

### Category glyphs
Inked price-shape pictures: uneven hourly bars (spot), a step line with period marks (periodic), a flat line in a dashed band (usage), a flat line with a padlock (fixed). An ink outline sits over a slightly offset flat category colour, with light hatching at 45% opacity. Sizes: 44px in choice rows, 34px in entries.

### Section heading
Bricolage headline with a wavy fox underline. A 96px vignette can sit at its left; actions sit at its right on the same baseline.

### Charts
Bar or line charts with an inked baseline, a dashed average line, a legend above, and every hour or day labelled. Forecast bars use forecast mist. Multi-series lines use the category colours; open-ended contracts are a dashed ink-muted line. Range bars show p20 to p80 with a median mark.

### FAQ row
Question in ink 18/800 with a chevron; answer in body text, max 680px; 1.5px edge hairlines between rows.

### Motion
Motion is small and functional: 120ms colour on nav items, 150ms chevron turns and opacity changes, a 300ms `cubic-bezier(0.16, 1, 0.3, 1)` move of the price ruler on the contract detail, and one loading spinner. Every new transition and animation gets a `prefers-reduced-motion: reduce` rule that removes it.

## Do's and Don'ts

### Do:
- **Do** put content directly on the ground (#F5F6F1) and build structure with headings, wavy underlines, hairlines and space.
- **Do** keep the fox once per page, in the hero, in the current season, far from any contract name, price, logo or seller.
- **Do** give every painted hero a light or dark text tone and a shade so hero text has 4.5:1 or more.
- **Do** show a category only with its glyph and name together; the colour is the third signal.
- **Do** use real controls: underlined fields, 20px checkboxes and radios, 2px fox focus outlines.
- **Do** use fox-deep for fox-coloured text under 24px and keep fox orange for marks.
- **Do** mark a status with a sparkle and caption caps in the status colour, or with an inked underline.
- **Do** take exact values from the Paper file and the tokens; map every new colour to a token.

### Don't:
- **Don't** use cards, white rounded panels, hard boxy frames, or any radius other than 3px, 5px and 12px.
- **Don't** use pills or badges, not even for short labels or the top-bar price.
- **Don't** use shadows, gradients behind content, blur or glass.
- **Don't** put watercolour washes behind text or data.
- **Don't** draw data as a landscape or add mini charts to ledger entries.
- **Don't** place villagers next to a contract, a seller or a seller price, or let a vignette float in open space.
- **Don't** draw a snow activity without snow, or a flame tail on the fox.
