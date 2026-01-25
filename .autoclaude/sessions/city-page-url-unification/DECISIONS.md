# Decisions Log

## 2026-01-25 - URL Structure Decision

### Decision: Unified City Pages Under /paikkakunnat/

**Context:** City pages were previously accessible at two different URL patterns:
- `/sahkosopimus/{city}` (catch-all at end of route definitions)
- `/sahkosopimus/paikkakunnat/{location}` (via LocationsList component)

This caused potential confusion and duplicate content issues.

**Decision:** Unify all city-specific contract pages under `/sahkosopimus/paikkakunnat/{location}`.

**Rationale:**
1. **Single canonical URL** - Each city has one definitive URL
2. **Clear URL hierarchy** - "paikkakunnat" (locations) is a clear parent category
3. **SEO benefits** - No duplicate content, clearer site structure for search engines
4. **Consistent with other SEO pages** - Housing types, energy sources remain at `/sahkosopimus/{type}`

**Implementation:**
- Old URLs get 301 redirects to preserve SEO value
- SeoContractsList handles city pages with full local flavor functionality
- LocationsList simplified to just browsing municipalities

### Decision: Split LocationsList and SeoContractsList Responsibilities

**Context:** LocationsList was handling both municipality browsing and individual city contract displays.

**Decision:**
- LocationsList: Only handles municipality browser (searchable list)
- SeoContractsList: Handles all city-specific contract listings

**Rationale:**
1. **Single responsibility** - Each component has one clear job
2. **Consistent city page experience** - All city pages use SeoContractsList with its full feature set (local companies, regional contracts, solar estimates)
3. **Simpler component code** - LocationsList no longer needs contract filtering logic

### Decision: Route Parameter Name Change

**Context:** The route uses `{location}` parameter but SeoContractsList internally uses `$city`.

**Decision:** Accept `$location` in mount() and map to `$city` internally.

**Rationale:**
1. **Minimal code changes** - All internal references to `$city` remain unchanged
2. **Semantic URL** - "location" is more generic and matches the "paikkakunnat" concept
3. **Backward compatibility** - Internal logic continues to work as before
