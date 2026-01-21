# Decisions Log

## Decision 1: Keep homepage and spot-price at root level

**Date:** 2026-01-21
**Decision:** Keep `/` and `/spot-price` at their current locations, move all other contract-related pages under `/sahkosopimus/`

**Rationale:**
- Homepage is the main entry point and should remain at the root
- `/spot-price` is a general information page, not specifically about comparing contracts
- All contract comparison, detail, and location pages belong under the `/sahkosopimus/` umbrella for SEO clarity

---

## Decision 2: Use proper `<a href>` links instead of Livewire wire:click

**Date:** 2026-01-21
**Decision:** Replace `wire:click` with proper `<a href>` links for municipality selection in LocationsList

**Rationale:**
- `wire:click` is not crawlable by search engines
- Each municipality page should be directly accessible via URL
- This allows Google to index each city-specific contract page
- Better user experience (browser back button works, can be bookmarked)

---

## Decision 3: Add municipality pages to sitemap

**Date:** 2026-01-21
**Decision:** Added a new `getMunicipalityUrls()` method to include all `/sahkosopimus/paikkakunnat/{slug}` pages in the sitemap

**Rationale:**
- Municipality pages were not previously in the sitemap (only city SEO pages)
- These pages are now crawlable with proper links
- Including them in the sitemap ensures Google discovers all location-specific pages
- Priority set to 0.5 (lower than main pages and SEO pages) to signal relative importance

---

## Decision 4: Use named routes in navigation

**Date:** 2026-01-21
**Decision:** Changed hardcoded URLs to `{{ route('name') }}` syntax in navigation and internal links

**Rationale:**
- If routes change in the future, links automatically update
- More maintainable codebase
- Follows Laravel best practices

---

## Decision 5: Include calculator in sitemap

**Date:** 2026-01-21
**Decision:** Added `/sahkosopimus/laskuri` to the sitemap with monthly changefreq and 0.6 priority

**Rationale:**
- The calculator is a useful tool that should be discoverable
- Monthly changefreq is appropriate as calculator logic doesn't change frequently
- Medium priority (0.6) reflects its utility but secondary importance to contract listings
