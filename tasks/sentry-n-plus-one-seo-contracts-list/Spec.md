# Spec

Fix Sentry Issue 118164833: N+1 queries in `SeoContractsList` component on SEO contract listing pages, especially repeated `price_components` and `electricity_sources` queries on location pages such as `/sahkosopimus/paikkakunnat/tampere`.

Goals:
- Identify per-contract relation queries in the component/view/schema code.
- Eager-load or batch-load only the relation data needed by the rendered list/calculations.
- Preserve discount-aware latest price component calculation behavior.
- Add or run focused tests where practical.
