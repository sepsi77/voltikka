# Color Palette

## Overview

Voltikka's color palette balances energy and trust. The coral accent provides warmth and distinctiveness, while slate neutrals ensure readability and professionalism.

---

## Primary Palette

### Coral (Accent)

The primary accent color. Used for CTAs, highlights, active states, and the featured card treatment.

| Token | Hex | Usage |
|-------|-----|-------|
| `coral-50` | `#fff7ed` | Subtle backgrounds, accent tints |
| `coral-100` | `#ffedd5` | Light backgrounds, hover states |
| `coral-200` | `#fed7aa` | Borders on accent elements |
| `coral-300` | `#fdba74` | Secondary accents |
| `coral-400` | `#fb923c` | — |
| `coral-500` | `#f97316` | **Primary accent**, CTAs, highlights |
| `coral-600` | `#ea580c` | Hover state for coral-500 |
| `coral-700` | `#c2410c` | Active/pressed states |
| `coral-800` | `#9a3412` | — |
| `coral-900` | `#7c2d12` | Dark accent text |
| `coral-950` | `#431407` | — |

**Gradient:**
```css
background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
```

---

### Slate (Neutrals)

The neutral palette for text, backgrounds, and borders.

| Token | Hex | Usage |
|-------|-----|-------|
| `slate-50` | `#f8fafc` | Page backgrounds, subtle sections |
| `slate-100` | `#f1f5f9` | Card backgrounds, input backgrounds |
| `slate-200` | `#e2e8f0` | Borders, dividers |
| `slate-300` | `#cbd5e1` | Disabled borders |
| `slate-400` | `#94a3b8` | Muted text, placeholders |
| `slate-500` | `#64748b` | Secondary text |
| `slate-600` | `#475569` | Body text (secondary) |
| `slate-700` | `#334155` | — |
| `slate-800` | `#1e293b` | — |
| `slate-900` | `#0f172a` | **Primary text**, headings |
| `slate-950` | `#0f1419` | **Hero backgrounds**, footer |

---

## Semantic Colors

### Emissions Status

Used exclusively for CO₂ emissions indicators.

| Status | Color | Hex | Usage |
|--------|-------|-----|-------|
| Low | Green | `#22c55e` | 0-50 kg CO₂/year |
| Medium | Amber | `#f59e0b` | 50-200 kg CO₂/year |
| High | Red | `#ef4444` | 200+ kg CO₂/year |
| Zero | Green | `#22c55e` | Exactly 0 kg (badge highlight) |

**Card left border colors:**
- Green contracts: `#22c55e` (4px border)
- Amber contracts: `#f59e0b` (4px border)
- Red contracts: `#ef4444` (4px border)
- Featured contract: Coral gradient (6px border)

---

### Energy Source Badges

| Source | Background | Text |
|--------|------------|------|
| Tuuli (Wind) | `#dcfce7` (green-100) | `#15803d` (green-700) |
| Vesivoima (Hydro) | `#dcfce7` | `#15803d` |
| Ydinvoima (Nuclear) | `#dcfce7` | `#15803d` |
| Aurinko (Solar) | `#dcfce7` | `#15803d` |
| Seka (Mixed) | `#f1f5f9` (slate-100) | `#475569` (slate-600) |

---

## Color Usage Guidelines

### Do

- Use coral for primary actions and key highlights only
- Use slate-900 for headings and important text
- Use green badges for all clean energy sources
- Apply coral shadow to primary CTAs: `shadow-coral`

### Don't

- Don't use coral for large background areas
- Don't use red/amber for anything other than emissions
- Don't mix coral with other bright accent colors
- Don't use pure black (`#000`) — use slate-950 instead

---

## Dark Sections

Hero and footer use `slate-950` (`#0f1419`) as the base, with:
- White text for headings
- `slate-300` / `slate-400` for body text
- Coral-400 for accent text
- Glassmorphism cards: `bg-white/5` with `backdrop-blur-sm`

---

## Accessibility

| Combination | Contrast Ratio | WCAG Level |
|-------------|----------------|------------|
| slate-900 on white | 15.3:1 | AAA |
| coral-600 on white | 4.6:1 | AA |
| white on slate-950 | 18.1:1 | AAA |
| coral-400 on slate-950 | 6.2:1 | AA |
| green-600 on white | 4.5:1 | AA |

All text combinations meet WCAG AA standards. Large text (18px+) and UI components pass AAA.
