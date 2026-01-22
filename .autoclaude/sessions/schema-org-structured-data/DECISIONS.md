# Decisions Log

## 2026-01-22

### Decision: Use Product schema instead of Service for contracts
**Context:** Electricity contracts could be modeled as either Product or Service in schema.org.

**Decision:** Used Product schema because:
- Google's rich results documentation focuses on Product for item listings
- Product supports offers with UnitPriceSpecification which fits our pricing model
- Product.additionalProperty allows structured custom attributes (energy sources, emissions)
- ItemList with Product items is well-supported for listing pages

### Decision: WebSite schema only on main listing page
**Context:** WebSite schema should appear once per site, typically on homepage.

**Decision:** Only render WebSite schema on ContractsList when:
- On page 1 (not paginated)
- No filters are active

This ensures it appears on the canonical main entry point without duplication.

### Decision: Use SearchAction targeting postcode filter
**Context:** WebSite schema supports SearchAction for sitelinks search box.

**Decision:** Configured SearchAction to use `?postcodeFilter={postcode}` as the search target because:
- Postcode is the primary search mechanism for filtering contracts
- This matches how users discover available contracts for their location
- It enables potential sitelinks search box in Google results

### Decision: Reusable Blade component for schema output
**Context:** Multiple pages need JSON-LD schema output.

**Decision:** Created `x-schema-markup` component that:
- Accepts array of schema objects
- Loops through and outputs each as separate script tag
- Handles empty schemas gracefully (skips them)
- Uses consistent JSON encoding flags

This centralizes the output logic and makes it easy to add multiple schemas per page.

### Decision: Separate BreadcrumbList schema per page
**Context:** Each page has its own navigation context.

**Decision:** Generate BreadcrumbList in each Livewire component rather than in layout because:
- Each page has unique breadcrumb structure
- Contract detail includes company in path
- List pages include active filters in breadcrumbs
- Company detail has different hierarchy

### Decision: Include calculated annual cost in ItemList offers
**Context:** ItemList items can include basic offer information.

**Decision:** Include the calculated total annual cost in each list item because:
- Provides price context to search engines
- Enables potential price display in search results
- Uses the same consumption-based calculation shown to users
