---
name: Voltikka
description: Finnish electricity contract comparison — calm, data-forward, coral-accented.
colors:
  coral-50: "#fff7ed"
  coral-100: "#ffedd5"
  coral-200: "#fed7aa"
  coral-300: "#fdba74"
  coral-400: "#fb923c"
  coral-500: "#f97316"
  coral-600: "#ea580c"
  coral-700: "#c2410c"
  slate-50: "#f8fafc"
  slate-100: "#f1f5f9"
  slate-200: "#e2e8f0"
  slate-300: "#cbd5e1"
  slate-400: "#94a3b8"
  slate-500: "#64748b"
  slate-600: "#475569"
  slate-700: "#334155"
  slate-800: "#1e293b"
  slate-900: "#0f172a"
  slate-950: "#0f1419"
  emissions-low: "#22c55e"
  emissions-medium: "#f59e0b"
  emissions-high: "#ef4444"
  badge-green-bg: "#dcfce7"
  badge-green-text: "#15803d"
typography:
  display:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "clamp(2.25rem, 5vw, 3.75rem)"
    fontWeight: 800
    lineHeight: 1.1
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "2.25rem"
    fontWeight: 800
    lineHeight: 1.1
    letterSpacing: "-0.015em"
  title:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "normal"
  price:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 800
    lineHeight: 1
    letterSpacing: "-0.01em"
    fontFeature: "tnum"
  body:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  label:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.5
    letterSpacing: "0.05em"
rounded:
  sm: "4px"
  md: "8px"
  lg: "12px"
  xl: "16px"
  "2xl": "24px"
  full: "9999px"
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
  "20": "80px"
components:
  button-primary:
    backgroundColor: "{colors.coral-500}"
    textColor: "#ffffff"
    typography: "{typography.title}"
    rounded: "{rounded.lg}"
    padding: "14px 24px"
  button-primary-hover:
    backgroundColor: "{colors.coral-400}"
    textColor: "#ffffff"
  button-secondary:
    backgroundColor: "{colors.slate-900}"
    textColor: "#ffffff"
    typography: "{typography.title}"
    rounded: "{rounded.lg}"
    padding: "14px 24px"
  button-secondary-hover:
    backgroundColor: "{colors.slate-800}"
    textColor: "#ffffff"
  button-outline:
    backgroundColor: "transparent"
    textColor: "{colors.slate-900}"
    typography: "{typography.title}"
    rounded: "{rounded.full}"
    padding: "12px 32px"
  contract-card:
    backgroundColor: "#ffffff"
    textColor: "{colors.slate-900}"
    rounded: "{rounded.2xl}"
    padding: "24px"
  contract-card-featured:
    backgroundColor: "#ffffff"
    textColor: "{colors.slate-900}"
    rounded: "{rounded.2xl}"
    padding: "24px"
  consumption-card:
    backgroundColor: "#ffffff"
    textColor: "{colors.slate-900}"
    rounded: "{rounded.xl}"
    padding: "20px"
  consumption-card-active:
    backgroundColor: "{colors.coral-500}"
    textColor: "#ffffff"
    rounded: "{rounded.xl}"
    padding: "20px"
  filter-pill:
    backgroundColor: "{colors.slate-50}"
    textColor: "{colors.slate-600}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  filter-pill-active:
    backgroundColor: "{colors.slate-950}"
    textColor: "#ffffff"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  badge-energy:
    backgroundColor: "{colors.badge-green-bg}"
    textColor: "{colors.badge-green-text}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "6px 12px"
  badge-neutral:
    backgroundColor: "{colors.slate-100}"
    textColor: "{colors.slate-600}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "6px 12px"
  spot-indicator:
    backgroundColor: "{colors.coral-50}"
    textColor: "{colors.slate-900}"
    typography: "{typography.label}"
    rounded: "{rounded.lg}"
    padding: "8px 16px"
  input-field:
    backgroundColor: "#ffffff"
    textColor: "{colors.slate-900}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "12px 16px"
---

# Design System: Voltikka

## 1. Overview

**Creative North Star: "The Calm Coral Brief"**

Voltikka is what a competent Finnish energy advisor would build if they cared more about the reader than the click. The system is calm and plain-spoken in voice, but never beige: a warm coral accent runs through it like a single highlighter mark across an otherwise quiet brief. Slate neutrals hold the data; the coral appears only where the user needs to act, decide, or be reassured. The dark slate-950 hero is a moment of focus, not a "premium" texture — when the page goes quiet and dark, the user is being asked to commit to something (consumption, contract, comparison).

The product's whole reason for existing is that the comparison niche is full of fake-neutral ranking pages and commission-first lead funnels. The visual system has to actively reject that posture. That means: no exaggerated savings, no aggressive CTAs, no urgency theatre, no decorative colour competing with the price. Data is the protagonist. The chrome stays out of the way.

The system is also explicitly not bank-like, not utility-company corporate, not crypto/neon, and not generic SaaS. It can be modern and energetic, but never hype-driven. Coral is the emotional note that keeps the data warm; everything else is restrained.

**Key Characteristics:**
- Single accent (coral), used sparingly — never as background mass
- Slate neutrals do almost all the work; pure black is forbidden
- Data typography is tabular and prominent; chrome is muted
- Dark slate hero used as a focused moment, not as default theme
- Emissions-tier left-stripe on contract cards is informational, not decorative
- Plus Jakarta Sans across the system, weights 400–800

## 2. Colors

A two-axis palette: warm coral as the single voice of action, cool slate as the substrate. Emissions colours are semantic only and never decorative.

### Primary

- **Voltikka Coral** (`#f97316`, `coral-500`): The single accent. CTAs, the active state on consumption selectors, "featured" contract treatment, the spot-price indicator dot, accent text against dark slate. Never used as a large background fill — it is rarity, not saturation.
- **Coral Hover** (`#fb923c`, `coral-400`): Lifts the primary button on hover. Also used as the accent text colour on the dark slate hero so coral remains legible on slate-950.
- **Coral Pressed** (`#ea580c`, `coral-600`): Pressed/active state and the bottom of the gradient pair `from-coral-500 to-coral-600`.
- **Coral Tints** (`coral-50` `#fff7ed`, `coral-100` `#ffedd5`, `coral-200` `#fed7aa`): Soft surfaces only — the spot-price indicator background, featured-card border, hover hint on the consumption selector.

### Neutral

- **Page Ink** (`#0f172a`, `slate-900`): Headings, contract titles, primary body emphasis. The default colour for serious numbers.
- **Body** (`#475569`, `slate-600`): Body copy.
- **Secondary Text** (`#64748b`, `slate-500`): Captions, supporting copy.
- **Muted** (`#94a3b8`, `slate-400`): Labels, placeholders, price units (`/kWh`, `/v`).
- **Borders & Dividers** (`#e2e8f0`, `slate-200`): Default 1px borders. (`#f1f5f9`, `slate-100`): even quieter dividers and card backgrounds in calm sections.
- **Page Surfaces** (`#f8fafc`, `slate-50`): Page background tint, filter pill default.
- **Focus Surface** (`#0f1419`, `slate-950`): Hero and footer background. The room-goes-dark moment.

### Semantic — Emissions Status

These colours mean *one thing*: a CO₂ tier on a contract. They never appear as decorative accents and never substitute for coral.

- **Emissions Low / Zero** (`#22c55e`): 0–50 kg CO₂/v. Also drives the green energy-source badges (`#dcfce7` bg, `#15803d` text).
- **Emissions Medium** (`#f59e0b`): 50–200 kg CO₂/v.
- **Emissions High** (`#ef4444`): 200+ kg CO₂/v.

### Named Rules

**The One Voice Rule.** Coral appears on at most ~10% of any given screen. Its rarity is what makes it readable as an action signal. If you find yourself using coral as a section background or reaching for a secondary accent colour, the design is already wrong.

**The No Pure Black Rule.** Never `#000`. Use `slate-950` (`#0f1419`) for the deepest dark and `slate-900` (`#0f172a`) for ink. White is acceptable as text on dark slate; everywhere else, prefer slate-50 over white-on-white surfaces.

**The Emissions-Are-Data Rule.** Green/amber/red appear only where they communicate a measured CO₂ tier or a verified clean-energy source. They are never used to "add colour" to a layout. If a designer reaches for green for visual interest, they should reach for coral instead — or for nothing.

## 3. Typography

**Display / Body / Label Font:** Plus Jakarta Sans (Google Fonts), with `system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif` fallbacks. Weights 400, 500, 600, 700, 800.

**Character:** A geometric sans with a friendly, modern cut — more distinctive than Inter, calmer than Manrope. It carries Finnish diacritics cleanly and reads as competent rather than corporate. One family across the whole system; hierarchy comes entirely from weight and scale.

### Hierarchy

- **Display** (800, `clamp(36px, 5vw, 60px)`, line-height 1.1, tracking -0.025em): Hero headline only. The accent half of the headline (e.g. *"sähkösopimus"*) is set in `coral-400`, never gradient-clipped.
- **Headline** (800, 36px, line-height 1.1): Page H1 in slate-900. Mobile drops to 30px.
- **Title** (700, 18px, line-height 1.25): Section heads, contract titles, button labels.
- **Price** (800, 24px, line-height 1, `tabular-nums`): Annual cost, contract price. Featured contracts may render the price in `coral-600`; default is `slate-900`. Line-height 1 so the number sits flush.
- **Body** (400, 16px, line-height 1.5, `slate-600`): Paragraph copy. Cap line length at 65–75ch.
- **Body Secondary** (400, 14px, `slate-500`): Card subtitles, supporting copy.
- **Label** (500, 12px, line-height 1.5, tracking 0.05em, UPPERCASE, `slate-400`): Filter labels, badge text, micro-labels above values.

### Named Rules

**The Tabular Numbers Rule.** Every price, kWh value, percentage, and CO₂ figure uses `font-variant-numeric: tabular-nums`. Numbers stack vertically across rows so users can compare at a glance. Proportional figures in a comparison column are forbidden.

**The No Gradient Text Rule.** Coloured emphasis is solid. The hero pattern is *one solid colour change mid-headline* (e.g. white → coral-400), never `background-clip: text` over a gradient.

**The Single Family Rule.** One typeface (Plus Jakarta Sans). No serif display, no monospace alternates, no second family for "personality." Hierarchy is weight + scale + colour.

## 4. Elevation

The system is mostly flat. Surfaces sit on the page with 1px slate-200 borders rather than ambient shadows. Shadows appear only in two places: as a *colored glow* under coral CTAs (the warmth, made physical) and as a soft lift on contract-card hover. The dark hero uses subtle `bg-white/5 + backdrop-blur-sm` glass cards for stat callouts — the only sanctioned use of glassmorphism in the system, and only against the dark slate-950 background.

### Shadow Vocabulary

- **shadow-sm** (`0 1px 2px 0 rgb(0 0 0 / 0.05)`): Faint lift on quarter-price cards on hover. Almost imperceptible — that's the point.
- **shadow-md** (`0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)`): Dropdowns, popovers.
- **shadow-lg** (`0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)`): Elevated cards in modal contexts.
- **shadow-coral** (`0 10px 30px -10px rgb(249 115 22 / 0.3)`): The signature glow under primary CTAs and active consumption selectors. Coloured ambient light, not a drop-shadow.
- **shadow-coral-lg** (`0 20px 40px -15px rgb(249 115 22 / 0.4)`): Reserved for the largest CTAs (homepage primary action).
- **shadow-card-hover** (`0 12px 40px -12px rgb(0 0 0 / 0.15)`): Contract-card hover state, paired with `translateY(-2px)`.

### Named Rules

**The Flat-By-Default Rule.** Surfaces are flat at rest. Shadows appear only as response to state (hover, active, the coral CTA at rest). A page should not look "lifted"; it should look composed.

**The Coral Glow Rule.** Coloured shadow is reserved for coral elements. A blue glow, a green glow, or a slate-tinted glow is forbidden — it makes the system look like a generic SaaS product. Black/transparent ambient shadows are fine.

**The Glass Only In The Dark Rule.** `backdrop-filter: blur(...)` appears exclusively on the dark slate-950 hero, on stat-cards over a dark gradient. Glassmorphism on light surfaces is forbidden.

## 5. Components

### Buttons

- **Shape:** 12px radius (`rounded-xl`) for primary/secondary; full-pill (`rounded-full`) for outline tertiary.
- **Primary:** Linear gradient `from-coral-500 to-coral-600` (135deg), white text, weight 700, padding 14px 24px, `shadow-coral` at rest. Hover lifts to `from-coral-400 to-coral-500`. The CTA is the warmest object on the page — always.
- **Secondary:** Solid `slate-900`, white text, same shape and padding. Hover to `slate-800`. Used when the primary action is taken or when there is no single hero action.
- **Outline (Tertiary):** Transparent, 2px slate-900 border, slate-900 text, weight 600, full-pill radius, padding 12px 32px. Hover inverts: slate-900 fill, white text.
- **Forbidden:** ghost-text-only buttons, multi-colour gradients beyond the coral pair, drop-shadow on secondary or outline variants.

### Filter Pills

- **Default:** `slate-50` background, 1px `slate-200` border, label-styled text in `slate-600`, 8px radius. Hover deepens border to `slate-300`.
- **Active:** `slate-950` background, white text, no border. The active filter is the darkest object in its row — visually obvious without colour.
- **Energy-source variant:** Same shape with a 8px coloured dot prefix (`emissions-low` for clean energy, otherwise omitted).

### Contract Cards

The signature component of the system. Each card encodes a contract row in horizontal layout (rank → identity → metrics → CTA), with a coloured left edge encoding the CO₂ tier.

- **Shape:** 24px radius (`rounded-2xl`), white background, 1px `slate-100` border, 24px padding.
- **Left edge (informational, not decorative):** 4px coloured stripe (`emissions-low` / `medium` / `high`) bound to the CO₂ tier of the contract. This is the one place a coloured side-stripe is sanctioned, because it carries a measured value the user uses to scan the list. It is never used decoratively elsewhere in the system.
- **Featured variant:** 6px coral-gradient left stripe (`coral-500 → coral-600`), `coral-200` border, optional corner badge in coral gradient.
- **Hover:** `translateY(-2px)` + `shadow-card-hover`. Transition 150–200ms ease-out.
- **Rank number:** 40px, weight 800, `slate-200` (or `coral-500` on featured). Visually quiet but spatially anchoring.
- **Inside the card:** company logo (WebP), title in slate-900, subtitle in slate-500, energy-source badges, then a horizontal row of metrics (margin, basic fee, annual cost) in tabular-nums. Annual cost is the largest number — that is the answer the user came for.

### Consumption Selector

A 5-column grid (2-column on mobile) of large cards used for selecting annual kWh consumption.

- **Default:** White, 2px `slate-200` border, 16px radius, 20px padding. Icon container is a 48×48 `slate-100` rounded square.
- **Hover:** Border deepens to `coral-400`.
- **Active:** Coral gradient fill (`coral-500 → coral-600`), white text and icon, `shadow-coral`. The active card is the only coloured surface on the page.

### Inputs / Fields

- **Style:** White background, 1px `slate-200` border, 12px radius, 12px 16px padding, body type in `slate-900`.
- **Focus:** Border shifts to `coral-500`; no glow, no double-ring. Quiet focus is on-brand.
- **Error:** Border to `emissions-high` (`#ef4444`); error message in 12px label style, same colour, below the field.
- **Disabled:** Background `slate-50`, text `slate-400`.

### Badges

- **Energy badge (clean):** `#dcfce7` background, `#15803d` text, 12px label style, 8px radius. Used for Tuuli / Vesivoima / Ydinvoima / Aurinko.
- **Neutral badge:** `slate-100` background, `slate-600` text. Used for "Seka" or any non-clean source.
- **Featured badge:** Coral gradient on white text, anchored to the top-right corner of a featured card with bottom-left radius only.

### Header

- **Navigation:** Plain links, weight 500, `slate-500` default, `slate-900` on hover. Active page is `slate-100` background pill with `slate-900` weight-600 text.
- **Spot price indicator:** `coral-50` background, `coral-200` border, 12px radius, 8px 16px padding. A 8×8 `coral-500` dot pulses (2s ease) next to the live €/kWh price. The only animated element in the chrome — it represents data that is live.

### Hero (Stat Cards)

The dark slate-950 hero is the only place glassmorphism is sanctioned.

- **Stat card:** `rgba(255,255,255,0.05)` background, 1px `rgba(255,255,255,0.1)` border, `backdrop-filter: blur(4px)`, 16px radius, 24px padding, centred numeric content in white with label in `slate-300`.
- **Highlighted variant:** `rgba(249,115,22,0.2)` fill, `rgba(249,115,22,0.3)` border. Used on at most one stat per hero.

## 6. Do's and Don'ts

### Do:

- **Do** treat the price as the largest, boldest object in any contract row — 24px / weight 800 / `tabular-nums` / `slate-900` (or `coral-600` only when featured).
- **Do** use coral on ≤10% of any screen. CTAs, active states, the spot-indicator dot, the featured-card stripe — and that is it.
- **Do** state assumptions, sources, and ranking logic visibly near the data they affect; the visual system should make uncertainty look honest, not hidden.
- **Do** lean on slate-50/100/200 borders and quiet surfaces — the chrome should feel finished but invisible.
- **Do** use `shadow-coral` only under coral elements; black/transparent ambient shadows everywhere else.
- **Do** use `tabular-nums` on every price, kWh value, percentage, and CO₂ figure.
- **Do** keep the dark slate-950 hero as a focused moment, not a default theme — most pages live on light surfaces.
- **Do** keep the emissions left-stripe on contract cards because it encodes a CO₂ tier; document any new use of coloured side-stripes in this file before shipping.

### Don't:

- **Don't** make Voltikka feel like a *scammy affiliate comparison site*, a *commission-first lead-generation funnel*, or a *fake-neutral ranking page*. Visual decisions inherit those anti-references directly.
- **Don't** ship *exaggerated savings claims*, *overly aggressive CTAs*, *vague ranking logic*, or *dark-pattern urgency*. No countdown timers, no "only N left," no red-shouting price deltas.
- **Don't** create *promotional layouts where the highest-paying provider appears to be the best by default* — ranking visuals must reflect ranking logic, not commercial weight.
- **Don't** make it feel *bank-like and cold*, *utility-company corporate*, *crypto/neon*, or *generic SaaS*. If a design could ship as-is for any of those, it has lost the brand.
- **Don't** make it *visually loud in a way that competes with the data*. Chrome stays out of the way; numbers carry the page.
- **Don't** use `#000` or `#fff` — slate-950 / slate-900 / white-on-dark instead.
- **Don't** use coloured side-stripes (>1px) on cards or list items for any reason *other* than the emissions-tier encoding on contract cards. No left-bar callouts, no left-bar alerts, no left-bar "info" panels.
- **Don't** use gradient-clipped text (`background-clip: text`). Coloured emphasis is one solid colour.
- **Don't** use glassmorphism on light surfaces. It is sanctioned only on the dark slate-950 hero.
- **Don't** introduce a second accent colour. If the design feels like it needs one, it needs less coral, not more colours.
- **Don't** use red/amber/green for decoration. Those colours mean a measured CO₂ tier or a verified clean-energy source — nothing else.
- **Don't** add bouncy or elastic motion. Easing is exponential ease-out; durations 150–300ms; CSS layout properties are never animated.
- **Don't** wrap everything in a card. Most layout doesn't need a container, and nested cards are forbidden.
- **Don't** use em dashes (—) in product copy. Use commas, colons, semicolons, periods, or parentheses.
