# Typography

## Font Family

### Plus Jakarta Sans

**Source:** [Google Fonts](https://fonts.google.com/specimen/Plus+Jakarta+Sans)

A geometric sans-serif with a modern, friendly character. More distinctive than Inter while remaining highly readable.

```html
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
```

```css
font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
```

**Weights used:**
- 400 (Regular) — Body text
- 500 (Medium) — Navigation, labels
- 600 (Semibold) — Subheadings, emphasis
- 700 (Bold) — Headings, prices
- 800 (Extrabold) — Hero headlines, rank numbers

---

## Type Scale

| Token | Size | Line Height | Usage |
|-------|------|-------------|-------|
| `text-xs` | 12px | 1.5 | Labels, badges, fine print |
| `text-sm` | 14px | 1.5 | Secondary text, captions |
| `text-base` | 16px | 1.5 | Body text |
| `text-lg` | 18px | 1.5 | Lead paragraphs |
| `text-xl` | 20px | 1.25 | Price values |
| `text-2xl` | 24px | 1.25 | Card prices, section titles |
| `text-3xl` | 30px | 1.1 | — |
| `text-4xl` | 36px | 1.1 | Page headings |
| `text-5xl` | 48px | 1.1 | Hero headlines (mobile) |
| `text-6xl` | 60px | 1.1 | Hero headlines (desktop) |

---

## Text Styles

### Hero Headline

```css
.hero-headline {
  font-size: 3.75rem;      /* 60px */
  font-weight: 800;
  line-height: 1.1;
  letter-spacing: -0.025em;
  color: white;
}

/* Accent line */
.hero-headline span {
  color: #fb923c;          /* coral-400 */
}
```

**Example:**
```
Löydä paras
sähkösopimus     ← coral-400
```

---

### Page Heading (H1)

```css
.page-heading {
  font-size: 2.25rem;      /* 36px */
  font-weight: 800;
  line-height: 1.1;
  color: #0f172a;          /* slate-900 */
}
```

---

### Section Heading (H2)

```css
.section-heading {
  font-size: 1.125rem;     /* 18px */
  font-weight: 700;
  line-height: 1.5;
  color: #0f172a;          /* slate-900 */
}
```

---

### Contract Title

```css
.contract-title {
  font-size: 1.125rem;     /* 18px */
  font-weight: 700;
  line-height: 1.25;
  color: #0f172a;          /* slate-900 */
}
```

---

### Price Display

```css
/* Large price (annual cost) */
.price-large {
  font-size: 1.5rem;       /* 24px */
  font-weight: 800;
  line-height: 1;
  color: #0f172a;          /* slate-900 */
}

/* Featured price */
.price-featured {
  font-size: 1.5rem;
  font-weight: 800;
  color: #ea580c;          /* coral-600 */
}

/* Price unit */
.price-unit {
  font-size: 0.875rem;     /* 14px */
  font-weight: 400;
  color: #94a3b8;          /* slate-400 */
}
```

---

### Rank Number

```css
.rank-number {
  font-size: 2.5rem;       /* 40px */
  font-weight: 800;
  line-height: 1;
  color: #e2e8f0;          /* slate-200 — default */
}

.rank-number.featured {
  color: #f97316;          /* coral-500 */
}
```

---

### Label (Uppercase)

```css
.label {
  font-size: 0.75rem;      /* 12px */
  font-weight: 500;
  line-height: 1.5;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #94a3b8;          /* slate-400 */
}
```

---

### Badge Text

```css
.badge {
  font-size: 0.75rem;      /* 12px */
  font-weight: 600;        /* or 700 for emphasis */
  line-height: 1;
  letter-spacing: 0;
  text-transform: uppercase;
}
```

---

### Body Text

```css
.body {
  font-size: 1rem;         /* 16px */
  font-weight: 400;
  line-height: 1.5;
  color: #475569;          /* slate-600 */
}

.body-secondary {
  font-size: 0.875rem;     /* 14px */
  color: #64748b;          /* slate-500 */
}
```

---

## Numeric Typography

Prices and statistics use **tabular figures** for proper alignment:

```css
.numeric {
  font-variant-numeric: tabular-nums;
}
```

In Tailwind: `tabular-nums`

---

## Responsive Typography

| Element | Mobile | Desktop |
|---------|--------|---------|
| Hero headline | 36px | 60px |
| Page heading | 30px | 36px |
| Stat numbers | 30px | 36px |
| Body text | 16px | 16px |

```css
/* Hero headline responsive */
@media (min-width: 768px) {
  .hero-headline {
    font-size: 3rem;       /* 48px */
  }
}

@media (min-width: 1024px) {
  .hero-headline {
    font-size: 3.75rem;    /* 60px */
  }
}
```
