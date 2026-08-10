# Spec

Create indexable contract listing pages for the Finnish search intents “6 kk määräaikainen sähkösopimus”, “12 kk määräaikainen sähkösopimus”, and “24 kk määräaikainen sähkösopimus”.

Each page must:
- show active household fixed-term contracts for the exact requested duration segment
- use the existing `SeoContractsList` architecture and shared listing controls/cards
- have unique Finnish title, meta description, H1, introduction, canonical URL, breadcrumbs, and JSON-LD
- separate the ranked results under an exact duration-specific H2: `Halvin 6 kk sähkösopimus`, `Halvin 12 kk sähkösopimus`, or `Halvin 24 kk sähkösopimus`
- show the matching precomputed offered-price trend and eligible fixed-term price forecast in the hero area
- keep insight data informational only; it must not change listing rank
- handle unavailable trend or forecast data honestly
- be added to the sitemap and suitable internal links
- include feature tests for routing, filtering, SEO metadata, and matching insight selection
