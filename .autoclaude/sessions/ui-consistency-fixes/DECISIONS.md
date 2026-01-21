# Decisions Log

## 2026-01-21

### Decision: Pricing Type URL Slugs

**Context:** Need to choose URL slugs for spot and fixed price contract pages.

**Options considered:**
1. `/sahkosopimus/spot` and `/sahkosopimus/fixed-price` (English)
2. `/sahkosopimus/porssisahko` and `/sahkosopimus/kiintea-hinta` (Finnish)

**Decision:** Use Finnish slugs (`porssisahko` and `kiintea-hinta`)

**Rationale:**
- Consistent with other SEO pages (tuulisahko, vihrea-sahko, etc.)
- Better for Finnish SEO targeting
- More user-friendly for Finnish audience

---

### Decision: Hero Section Consistency

**Context:** SEO pages had a different hero styling than the homepage.

**Decision:** Make SEO page heroes match homepage styling exactly.

**Rationale:**
- Visual consistency improves brand identity
- Users navigating between pages see a cohesive experience
- Single source of design patterns to maintain

---

### Decision: Sitemap Priority for Pricing Type Pages

**Context:** New pricing type pages need sitemap entries.

**Decision:** Set priority to 0.9 (second only to homepage at 1.0) with daily changefreq.

**Rationale:**
- Pricing type pages are high-value landing pages for common searches
- "Pörssisähkö" and "kiinteä hinta" are popular search terms
- Higher than housing types (0.8) because pricing model is often the primary decision factor
