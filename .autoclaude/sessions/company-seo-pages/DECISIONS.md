# Decisions Log

## 2026-01-21

### Decision: Combined Task 1 and Task 2 into single implementation
**Context:** Tasks 1 (CompanyList component) and 2 (CompanyList view) were listed separately but are tightly coupled.
**Decision:** Implemented both the component and view together since they're interdependent.
**Rationale:** A Livewire component requires its view to function, and testing requires both to exist.

### Decision: Added routes early (partially completing Task 5)
**Context:** Task 5 (update routes) depends on Tasks 1 and 3, but routes for CompanyList are needed for testing.
**Decision:** Added routes for `/sahkosopimus/sahkoyhtiot` (list) and `/sahkosopimus/sahkoyhtiot/{companySlug}` (detail) now.
**Rationale:** Tests require routes to exist. The 301 redirect portion will be completed later when Task 3 is done.

### Decision: Ranking metrics use company's best/lowest values
**Context:** Companies can have multiple contracts with different prices/emissions.
**Decision:**
- "Cheapest" uses the company's lowest-priced contract
- "Greenest" uses average renewable % across all contracts
- "Cleanest emissions" uses lowest emission factor
- "Spot margins" uses lowest margin among spot contracts
- "Monthly fees" uses lowest monthly fee
**Rationale:** This gives consumers the most useful view - what's the best deal each company offers.

### Decision: Used Finnish title "Sähköyhtiöt Suomessa"
**Context:** The task description had "Sahkoyhtiot Suomessa" (without umlauts).
**Decision:** Used proper Finnish spelling "Sähköyhtiöt Suomessa" for the page title.
**Rationale:** Better for SEO and user experience to use correct Finnish characters.

### Decision: Company detail page links use new URL structure
**Context:** Old URL was `/sahkosopimus/yritys/{slug}`, new URL is `/sahkosopimus/sahkoyhtiot/{slug}`.
**Decision:** All links in CompanyList point to new URL structure immediately.
**Rationale:** Ensures consistency. 301 redirect will be added for old URLs to maintain SEO.
