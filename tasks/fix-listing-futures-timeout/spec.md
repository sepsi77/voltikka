# Fix contract-list futures timeout

Investigate and fix Sentry issue VOLTIKKA-21 (issue ID 143155060): a production Livewire update for `SahkosopimusIndex::togglePricingBucket('kiintea')` exceeded the 30-second PHP execution limit while repeatedly selecting the latest eligible electricity-futures trade date.

## Requirements

- Remove repeated futures-date database work from one contract-list calculation request.
- Preserve canonical pricing and futures-vintage semantics.
- Add a regression test that detects the repeated-query or repeated-resolution defect.
- Run focused tests and relevant static checks.
- Do not change production state or deploy without explicit user confirmation.
