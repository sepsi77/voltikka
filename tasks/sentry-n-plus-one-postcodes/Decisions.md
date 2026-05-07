# Decisions

- The Sentry report is actionable but low-risk/low-urgency: it was triggered by an Amazonbot crawl of a city SEO page, not a user-facing error.
- Root cause found in `LocalContractsService::findNearbyCompanies()`: it loaded all companies and then called `Postcode::find($company->postal_code)` inside a loop.
- Fixed by bulk-loading all relevant company postcodes with one `whereIn` query and keying them by postcode before distance calculation.
- Added a focused regression test to ensure nearby-company postcodes are loaded in bulk and per-company primary-key postcode queries do not return.
