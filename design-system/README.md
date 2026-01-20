# Voltikka Design System

This document defines the visual language for Voltikka, a Finnish electricity contract comparison platform targeting environmentally-conscious consumers.

## Design Direction

**Name:** Fresh Coral
**Keywords:** Modern, energetic, bold, trustworthy, accessible

### Design Principles

1. **Data-forward** — Prices and emissions are the hero, not decorative elements
2. **Clear hierarchy** — Users should instantly see what matters (rankings, prices, green badges)
3. **Approachable authority** — Professional enough for financial decisions, warm enough for everyday Finns
4. **Environmental clarity** — Green energy options are visually distinct without being preachy

### Visual Identity

- **Hero treatment**: Dark slate backgrounds create premium, focused feel
- **Accent color**: Coral/orange provides energy and stands out from typical utility green
- **Cards with ranking**: Numbered cards with colored left borders guide the eye
- **Featured highlights**: Best options get special treatment (borders, badges, accent backgrounds)

## Files in This System

| File | Purpose |
|------|---------|
| `tokens.css` | CSS custom properties for all design tokens |
| `tokens.json` | JSON format for build tools/Tailwind config |
| `colors.md` | Color palette documentation and usage guidelines |
| `typography.md` | Font choices, scale, and usage patterns |
| `components.md` | Component patterns and specifications |
| `spacing.md` | Spacing scale and layout guidelines |

## Quick Reference

### Primary Colors
- **Coral 500**: `#f97316` — Primary accent, CTAs, highlights
- **Slate 900**: `#0f172a` — Primary text
- **Slate 950**: `#0f1419` — Hero backgrounds, footer

### Key Decisions
- **Font**: Plus Jakarta Sans (Google Fonts)
- **Border radius**: 12-16px for cards, 8-12px for buttons
- **Shadows**: Subtle, with colored shadows on accent elements
- **Icons**: Heroicons (outline style, 1.5px stroke)

## Implementation Notes

### For Tailwind CSS
The `tokens.json` file can be imported into your `tailwind.config.js` to extend the default theme with Voltikka's custom colors and spacing.

### For Plain CSS
Import `tokens.css` at the root of your stylesheet to access all design tokens as CSS custom properties.

---

*Last updated: January 2026*
