# Decisions

- Limited visible listing contract `priceComponents` to latest calculation components instead of full history to reduce query, serialization, and cache payload size.
- Wrapped default listing prepared-data cache writes in a short cache lock; if the lock is busy, render uncached to avoid request timeouts during cold-cache stampedes.
- Disabled prepared view-data caching for city SEO pages because long-tail city URLs plus local/regional sections create large DB-cache payloads; city pages still reuse shared contract metric caches.
