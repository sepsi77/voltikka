# Decisions

- `/sahkosopimus/kiintea-hinta` is restored as a real pricing-type SEO page for `pricing_model = FixedPrice`, not a redirect.
- `/sahkosopimus/yleissahko` remains the narrower general-metering fixed-price page (`pricing_model = FixedPrice` + `metering = General`).
- `/sahkosopimus/paikkakunnat/{location}` only renders when `{location}` exists in `municipalities.slug`; unknown slugs 404 instead of fabricating city names.
- The legacy `/sahkosopimus/{city}` redirect only redirects real municipality slugs; unknown slugs 404 so reserved/invalid SEO words do not become fake location URLs.
- Targeted tests passed: `cd laravel && php artisan test --filter='SeoCityRoutesTest|SeoContractsListTest|SitemapTest'`.
