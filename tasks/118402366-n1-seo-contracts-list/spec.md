# N+1 in seo-contracts-list municipalities

Investigate and fix repeated `SELECT * FROM municipalities WHERE slug = ? LIMIT 1` lookups on `/sahkosopimus/paikkakunnat/{location}` in `SeoContractsList`.

Outcome: municipality slug lookups are memoized per component render, including missing slugs, and city/local-contract accessors now go through that memoized lookup path.
