# Decisions Log

## 2026-01-25: Implementation Decisions

### 1. Discount Detection Logic

**Decision**: Use both `pricing_has_discounts` contract flag AND price component discount info.

**Rationale**: The contract may have the `pricing_has_discounts` flag set to true as a quick indicator, but the detailed discount information (percentage, duration, expiry) is stored in individual price components. Both sources should be checked to ensure no discounts are missed.

### 2. Active Discount Filtering in Database Query

**Decision**: Filter promotions at the database level using subqueries rather than loading all contracts and filtering in PHP.

**Rationale**: Memory optimization - avoids loading all contracts and their price components into memory when only a subset have active discounts. The database can efficiently filter using EXISTS subqueries.

### 3. Badge Display Logic

**Decision**: Show "X kk tarjous" format when `n_first_months` is available, otherwise just "Tarjous".

**Rationale**: Provides more specific information to users when available. The duration of the promotion is valuable information that helps users make decisions.

### 4. JSON-LD Schema Enrichment

**Decision**: Add `priceValidUntil` and discount description to the Offer schema for promoted contracts.

**Rationale**:
- `priceValidUntil` is a standard schema.org property that search engines understand
- Including discount details in the description helps with rich snippets
- Only added when actual discount info is available to avoid empty fields

### 5. Route Placement

**Decision**: Place the sahkotarjous route after company contracts but before the city catch-all route.

**Rationale**: The city route uses a catch-all pattern `{city}` that would match "sahkotarjous" if placed before it. The route must come before the catch-all to work correctly.

### 6. Sitemap Priority

**Decision**: Set priority to 0.85 with daily changefreq.

**Rationale**:
- Promotions change frequently as discounts expire and new ones appear
- Priority 0.85 is slightly lower than main pricing type pages (0.9) but higher than housing type pages (0.8)
- Daily updates ensure search engines see current promotion data
