# SEO optimize `/sahkosopimus/tilastot` for "sähkösopimusten hintakehitys"

## Goal

Make the contract price statistics page rank for the keyword **"sähkösopimusten hintakehitys"** in addition to the existing "sähkön hintatilastot" cluster. The page already shows daily contract-price trend data — the change is purely on-page SEO copy (title, meta, H1, intro, JSON-LD) so the target phrase appears in all the ranking-relevant slots.

## Scope

On-page editorial changes only. No data model, route, layout, or interaction changes.

## Files touched

- `laravel/app/Livewire/ContractPriceStatistics.php`
  - `render()` — new `title` and `metaDescription`
  - `getJsonLdProperty()` — new `description`, expanded `keywords`
- `laravel/resources/views/livewire/contract-price-statistics.blade.php`
  - H1 reworded so the exact target phrase opens the heading
  - Intro paragraph reworded to lead with the exact phrase
  - Lead chart caption gets a short prefix mentioning the target phrase
- `laravel/tests/Feature/ContractPriceStatisticsPageTest.php`
  - Two `assertSee` lines updated to the new H1

## Out of scope

- Citation label `'Sähkön hintatilastot'` in `getCitationsProperty()` is intentionally kept — users may have already copied citations using that label.
- No new H2 sections, no template restructure, no new components.
- No sitemap or routing changes.
