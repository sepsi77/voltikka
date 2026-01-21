# Decisions Log

## 2026-01-21

### Architecture Decisions

#### 1. Component Inheritance Pattern
**Decision**: CheapestContracts and SahkosopimusIndex both extend SeoContractsList rather than creating entirely new components.

**Rationale**: This follows the existing pattern in the codebase where SeoContractsList extends ContractsList. This allows reuse of all the filtering, calculation, and rendering logic while only overriding the SEO-specific methods.

#### 2. Featured Contract Caching
**Decision**: The CheapestContracts component caches all contracts in a private property `$allContractsCache` to avoid recalculating when accessing both `featuredContract` and `contracts` properties.

**Rationale**: The parent's `getContractsProperty()` does expensive calculations (price calculations, sorting). Calling it twice (once for featured, once for remaining) would be inefficient. Caching ensures the calculation happens only once per request.

#### 3. Route Ordering
**Decision**: New routes (`/sahkosopimus/halvin-sahkosopimus` and `/sahkosopimus`) were added BEFORE the city catch-all route (`/sahkosopimus/{city}`).

**Rationale**: Laravel matches routes in order. If the city catch-all came first, it would incorrectly match "halvin-sahkosopimus" as a city name. Placing specific routes first ensures they are matched correctly.

#### 4. Housing Type Preset Mapping
**Decision**: Added explicit mapping between housing types and consumption presets rather than relying on consumption values alone.

**Rationale**: The UI should show the correct preset selected (highlighted) when viewing housing-type-specific pages. Just setting the consumption value wouldn't highlight the correct preset card.

Mapping:
- omakotitalo → large_house_electric (18000 kWh)
- kerrostalo → large_apartment (5000 kWh)
- rivitalo → large_house_ground_pump (12000 kWh)

#### 5. Featured Contract Card Design
**Decision**: Created a separate `featured-contract-card` component rather than using conditional styling in the existing `contract-card`.

**Rationale**: The featured card has significantly different styling (coral gradient background, larger elements, badge) that would make the conditional logic in the base component too complex. A separate component is cleaner and more maintainable.

#### 6. Sitemap Priority Values
**Decision**:
- `/sahkosopimus` priority: 0.95 (just below homepage at 1.0)
- `/sahkosopimus/halvin-sahkosopimus` priority: 0.9 (equal to spot-price page)

**Rationale**: These are high-value SEO pages that should rank highly in the sitemap. The main comparison page is slightly more important as it's the primary entry point, while the cheapest contracts page is a specialized view.

### Test Observations

The test suite has pre-existing failures in `SpotPriceComponentTest` related to:
- Best consecutive hours calculation
- Potential savings calculation
- View assertions for spot price data

These failures are unrelated to this implementation and appear to be due to changes in the spot price component or test data that happened before this session.
