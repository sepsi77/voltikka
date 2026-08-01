# Standardize contract-list consumption selector

Use one shared compact annual-consumption selector on all contract listing views, including `/sahkosopimus/halvin-sahkosopimus`.

Requirements:
- Keep the compact selector from `seo-contracts-list.blade.php` as the visual and behavioral source of truth.
- Reuse one Blade partial from the SEO listing, cheapest listing, and base contract listing views.
- Preserve preset selection, direct consumption input, calculator mode, mobile collapse behavior, and the blur input boundary.
- Keep the cheapest page ranking and featured-card layout unchanged.
- Add a regression test that proves the cheapest page uses the compact shared selector and does not render the old selector heading/tab UI.
