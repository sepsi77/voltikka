# Default-page caching

Implement data-aware caching for high-traffic contract listing and detail pages. Prioritize default/canonical GET states that receive search traffic: `/sahkosopimus`, SEO contract listing pages, and `/sahkosopimus/sopimus/{id}`.

Use a caching strategy similar to `ContractPriceStatistics`: cache prepared view data with a source-data fingerprint and expiry at tomorrow. Avoid caching arbitrary filter/query combinations initially.

