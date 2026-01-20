# Spacing & Layout

## Spacing Scale

Based on a 4px base unit, following Tailwind's default scale.

| Token | Value | Pixels | Usage |
|-------|-------|--------|-------|
| `space-0` | 0 | 0 | — |
| `space-1` | 0.25rem | 4px | Tight gaps, badge padding |
| `space-2` | 0.5rem | 8px | Small gaps, inline spacing |
| `space-3` | 0.75rem | 12px | Icon gaps, tight padding |
| `space-4` | 1rem | 16px | Standard padding, gaps |
| `space-5` | 1.25rem | 20px | — |
| `space-6` | 1.5rem | 24px | Card padding, section gaps |
| `space-8` | 2rem | 32px | Large gaps |
| `space-10` | 2.5rem | 40px | — |
| `space-12` | 3rem | 48px | Section spacing |
| `space-16` | 4rem | 64px | Large section spacing |
| `space-20` | 5rem | 80px | Hero padding |
| `space-24` | 6rem | 96px | — |

---

## Layout Widths

| Token | Value | Usage |
|-------|-------|-------|
| `max-width-content` | 80rem (1280px) | Main content container |
| `max-width-text` | 65ch | Readable text blocks |

**Container:**
```css
.container {
  max-width: 80rem;
  margin: 0 auto;
  padding-left: 1.5rem;
  padding-right: 1.5rem;
}
```

---

## Section Spacing

| Section | Vertical Padding |
|---------|------------------|
| Hero | 64px (py-16) |
| Consumption selector | 40px (py-10) |
| Filters | 24px (py-6) |
| Contract list | 32px (py-8) |
| Footer | 64px (py-16) |

---

## Component Spacing

### Contract Card

```
┌──────────────────────────────────────────────────────┐
│                      24px (p-6)                       │
│  ┌────┐  16px  ┌─────────────────────────────────┐   │
│  │Rank│  gap   │ Content                         │   │
│  │ 01 │ ────── │                                 │   │
│  └────┘        └─────────────────────────────────┘   │
│                                                       │
└──────────────────────────────────────────────────────┘
```

- Card padding: 24px (p-6)
- Gap between rank and content: 24px (gap-6)
- Gap between content sections: 24px (gap-6)
- Vertical gap on mobile stacking: 24px (gap-6)

### Consumption Selector Cards

- Card padding: 20px (p-5)
- Icon to text gap: 12px (mb-3)
- Grid gap: 16px (gap-4)

### Filter Section

- Filter group gap: 8px (gap-2)
- Section label margin: 12px (mb-3)
- Divider margin: 32px (mx-8 on lg)

---

## Grid Patterns

### Hero Stats (3-column)

```css
.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}
```

### Consumption Selector (5-column)

```css
.consumption-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);  /* Mobile */
  gap: 1rem;
}

@media (min-width: 768px) {
  .consumption-grid {
    grid-template-columns: repeat(5, 1fr);
  }
}
```

### Contract List

```css
.contract-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
```

---

## Responsive Breakpoints

| Name | Width | Usage |
|------|-------|-------|
| `sm` | 640px | Small tablets |
| `md` | 768px | Tablets |
| `lg` | 1024px | Small laptops |
| `xl` | 1280px | Desktops |
| `2xl` | 1536px | Large screens |

**Key responsive changes:**

| Element | Mobile | Desktop (lg+) |
|---------|--------|---------------|
| Hero | Stacked | 2-column grid |
| Consumption | 2-column | 5-column |
| Contract cards | Stacked content | Horizontal row |
| Filters | Stacked groups | Inline row |

---

## Border Radius

| Token | Value | Usage |
|-------|-------|-------|
| `radius-sm` | 4px | — |
| `radius-md` | 8px | Buttons, inputs, small elements |
| `radius-lg` | 12px | Cards, larger buttons |
| `radius-xl` | 16px | Large cards, modals |
| `radius-2xl` | 24px | **Contract cards**, hero stat cards |
| `radius-full` | 9999px | Pills, badges, avatars |

---

## Z-Index Layering

| Layer | Z-Index | Usage |
|-------|---------|-------|
| Base | 0 | Default content |
| Dropdown | 10 | Dropdown menus |
| Sticky | 20 | Sticky header |
| Fixed | 30 | Fixed elements |
| Modal backdrop | 40 | Modal overlay |
| Modal | 50 | Modal content |
| Popover | 60 | Tooltips, popovers |
| Toast | 70 | Notifications |

---

## Shadows

| Token | Value | Usage |
|-------|-------|-------|
| `shadow-sm` | `0 1px 2px 0 rgb(0 0 0 / 0.05)` | Subtle depth |
| `shadow-md` | `0 4px 6px -1px rgb(0 0 0 / 0.1)` | Cards, dropdowns |
| `shadow-lg` | `0 10px 15px -3px rgb(0 0 0 / 0.1)` | Elevated cards |
| `shadow-card-hover` | `0 12px 40px -12px rgb(0 0 0 / 0.15)` | Card hover state |
| `shadow-coral` | `0 10px 30px -10px rgb(249 115 22 / 0.3)` | Coral button glow |
