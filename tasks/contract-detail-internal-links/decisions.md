# Decisions

- Use existing broad SEO comparison pages for badge links instead of creating duration-specific pages. This improves internal linking without adding thin pages.
- Put URL mapping in `laravel/app/Support/ContractInternalLinks.php` rather than directly in Blade so the behavior can be tested and reused.
- General-metering badges link to `/sahkosopimus/yleissahko` only for fixed-price or legacy/null pricing-model contracts. Spot and hybrid contracts with general metering should point to their pricing-model pages instead, because `/sahkosopimus/yleissahko` filters fixed-price general-tariff contracts.
- Bumped contract-detail prepared view-data cache key to `v2` so cached payloads include the new hero link variables.
