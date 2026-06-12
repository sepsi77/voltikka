# Decisions

- Root cause: Livewire's URL attribute hydrates `?page=` as an empty string before `SeoContractsList::mount()`. The strict `int` type on inherited `ContractsList::$page` rejected that assignment.
- Fix: make `$page` `int|string` and normalize empty, malformed, and negative values to page 1 via `normalizePageProperty()` before render/cache/SEO pagination and after interactive updates.
- Added regression tests for empty `page` on `/sahkosopimus`, the reported city SEO route, and malformed page values.
- Updated the obsolete `SeoCityRoutesTest::test_city_page_shows_contracts_count` expectation from the old word `löytyi` to the current results credibility-bar wording (`sopimusta` / `vertailussa`).
