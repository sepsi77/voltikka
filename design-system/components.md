# Component Patterns

## Overview

This document defines the key UI components used in Voltikka, their variants, and specifications.

---

## Buttons

### Primary Button (CTA)

Used for main actions like "Katso sopimus", "Aloita vertailu".

```css
.btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.875rem 1.5rem;        /* py-3.5 px-6 */
  background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
  color: white;
  font-weight: 700;
  font-size: 1rem;
  border-radius: 0.75rem;          /* rounded-xl */
  transition: all 200ms ease;
  box-shadow: 0 10px 30px -10px rgb(249 115 22 / 0.3);
}

.btn-primary:hover {
  background: linear-gradient(135deg, #fb923c 0%, #f97316 100%);
}
```

**Tailwind:**
```html
<button class="inline-flex items-center gap-2 bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold px-6 py-3.5 rounded-xl transition shadow-lg shadow-coral-500/20">
  Katso sopimus →
</button>
```

---

### Secondary Button

Used for secondary actions like "Näytä lisää".

```css
.btn-secondary {
  padding: 0.875rem 1.5rem;
  background: #0f172a;             /* slate-900 */
  color: white;
  font-weight: 700;
  border-radius: 0.75rem;
  transition: background 200ms ease;
}

.btn-secondary:hover {
  background: #1e293b;             /* slate-800 */
}
```

---

### Outline Button

Used for tertiary actions.

```css
.btn-outline {
  padding: 0.75rem 2rem;
  background: transparent;
  border: 2px solid #0f172a;
  color: #0f172a;
  font-weight: 600;
  border-radius: 9999px;           /* rounded-full */
  transition: all 200ms ease;
}

.btn-outline:hover {
  background: #0f172a;
  color: white;
}
```

---

## Cards

### Contract Card

The primary content display unit.

**Structure:**
```
┌─────────────────────────────────────────────────────────────┐
│ ▌ 01  [Logo] Company Name        Margin    Basic    Annual  │
│ ▌      Company Subtitle          0,39c/kWh 3,99€/kk  847€   │
│ ▌      [TUULI] [PÖRSSI]                              [CTA]  │
│ ▌                                          42kg CO₂/v       │
└─────────────────────────────────────────────────────────────┘
```

**Specifications:**
- Background: White
- Border: 1px solid slate-100
- Border radius: 24px (rounded-2xl)
- Padding: 24px (p-6)
- Left accent: 4px colored border (green/amber/red based on emissions)
- Featured: 6px coral gradient border, coral-200 border color

**Left Border Colors:**
| Emissions | Color | Width |
|-----------|-------|-------|
| Low (0-50kg) | `#22c55e` | 4px |
| Medium (50-200kg) | `#f59e0b` | 4px |
| High (200kg+) | `#ef4444` | 4px |
| Featured | Coral gradient | 6px |

**Hover State:**
```css
.contract-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 40px -12px rgb(0 0 0 / 0.15);
}
```

---

### Featured Card

Same as contract card with enhancements:

```css
.contract-card.featured {
  border-color: #fed7aa;           /* coral-200 */
}

.contract-card.featured::before {
  background: linear-gradient(180deg, #f97316 0%, #ea580c 100%);
  width: 6px;
}

/* Corner badge */
.featured-badge {
  position: absolute;
  top: 0;
  right: 0;
  background: linear-gradient(135deg, #f97316, #ea580c);
  color: white;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 0.375rem 1rem;
  border-bottom-left-radius: 0.75rem;
}
```

---

### Stat Card (Hero)

Used in the hero section for displaying key metrics.

```css
.stat-card {
  background: rgba(255, 255, 255, 0.05);
  backdrop-filter: blur(4px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 1rem;
  padding: 1.5rem;
  text-align: center;
}

/* Highlighted variant */
.stat-card.highlight {
  background: rgba(249, 115, 22, 0.2);
  border-color: rgba(249, 115, 22, 0.3);
}
```

---

## Consumption Selector

Interactive buttons for selecting annual electricity usage.

**Default State:**
```css
.consumption-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.25rem;
  background: white;
  border: 2px solid #e2e8f0;
  border-radius: 1rem;
  transition: all 150ms ease;
}

.consumption-card:hover {
  border-color: #fb923c;
}
```

**Active State:**
```css
.consumption-card.active {
  background: linear-gradient(135deg, #f97316, #ea580c);
  border-color: #f97316;
  color: white;
  box-shadow: 0 10px 30px -10px rgb(249 115 22 / 0.3);
}
```

**Icon container:**
```css
.consumption-icon {
  width: 3rem;
  height: 3rem;
  background: #f1f5f9;
  border-radius: 0.75rem;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 0.75rem;
}

.consumption-card.active .consumption-icon {
  background: rgba(255, 255, 255, 0.2);
}
```

---

## Filter Pills

### Type Filter (Primary)

```css
.filter-btn {
  padding: 0.5rem 1rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #475569;
  transition: all 150ms ease;
}

.filter-btn:hover {
  border-color: #cbd5e1;
}

.filter-btn.active {
  background: #0f1419;
  border-color: #0f1419;
  color: white;
}
```

### Energy Source Filter

Same as type filter but with colored dot indicator:

```html
<button class="filter-btn">
  <span class="w-2 h-2 bg-green-500 rounded-full"></span>
  Päästötön
</button>
```

---

## Badges

### Energy Source Badge (Green)

```css
.badge-green {
  display: inline-block;
  padding: 0.375rem 0.75rem;
  background: #dcfce7;
  color: #15803d;
  font-size: 0.75rem;
  font-weight: 700;
  border-radius: 0.5rem;
  text-transform: uppercase;
}
```

### Neutral Badge

```css
.badge-neutral {
  display: inline-block;
  padding: 0.375rem 0.75rem;
  background: #f1f5f9;
  color: #475569;
  font-size: 0.75rem;
  font-weight: 500;
  border-radius: 0.5rem;
  text-transform: uppercase;
}
```

---

## Rank Number

Large numbered indicator for contract position.

```css
.rank {
  font-size: 2.5rem;
  font-weight: 800;
  line-height: 1;
  color: #e2e8f0;
}

.contract-card.featured .rank {
  color: #f97316;
}
```

---

## Header

### Navigation

```css
.nav-link {
  padding: 0.5rem 1rem;
  border-radius: 0.5rem;
  font-weight: 500;
  color: #64748b;
  transition: color 150ms ease;
}

.nav-link:hover {
  color: #0f172a;
}

.nav-link.active {
  background: #f1f5f9;
  color: #0f172a;
  font-weight: 600;
}
```

### Spot Price Indicator

```css
.spot-indicator {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-radius: 0.75rem;
}

.spot-dot {
  width: 0.5rem;
  height: 0.5rem;
  background: #f97316;
  border-radius: 50%;
  animation: pulse 2s infinite;
}
```

---

## Emissions Indicator

### Dot + Text Pattern

```html
<div class="flex items-center gap-2">
  <span class="w-3 h-3 bg-green-500 rounded-full"></span>
  <span class="text-sm">
    <strong class="text-green-600">42 kg</strong>
    <span class="text-slate-500">CO₂/v</span>
  </span>
</div>
```

**Color mapping:**
- 0-50 kg: `bg-green-500`, `text-green-600`
- 50-200 kg: `bg-amber-500`, `text-amber-600`
- 200+ kg: `bg-red-500`, `text-red-600`

---

## Icons

Use [Heroicons](https://heroicons.com/) (outline style, 1.5px stroke weight).

Common icons:
- Lightning bolt: Brand logo, energy-related
- Home: Housing types
- Building: Apartments
- Chart: Spot price indicator
- Check circle: Verified/clean energy
- Arrow right: CTAs
- Chevron down: Dropdowns, expand

**Size guidelines:**
- Navigation/small UI: 20px (w-5 h-5)
- Card icons: 24px (w-6 h-6)
- Hero decorative: 20px (w-5 h-5)
