# Supplier price episode chronology

## Result
Done. The focused resolver tests pass. A wider supplier test has one failure in the annual-equivalent calculation that the core agent owns.

## Changes and decisions
- `CurrentPriceEpisodeResolver.php` now reads all snapshot bases in date order. An old observed matching rate cannot hide a newer canonical price change or a later return to the same rate.
- Same-date evidence counts as one day. Known observed energy rates take priority only within that date. Null energy rates cannot override known rates. Conflicting selected evidence breaks the run. The result does not depend on row insertion order.
- Keep strict daily continuity. Even one missing day starts a new run; no earlier history is invented.
- A latest nonmatching or unknown date clears the old matching run. The unchanged batched source fallback requires the current observation and published interpretation to share the exact source snapshot.
- Evidence basis describes only the selected run. A mixed run has the canonical basis.
- Added `tests/Feature/CurrentPriceEpisodeChronologyTest.php`, with isolated SQLite in-memory tables, duplicate evidence in both insertion orders, A-B-A history, one-day gaps, source fallback, missing evidence, and one/two-query batch assertions.
- Updated only the episode section in `SupplierAdjusted/AGENTS.md`. Its `CLAUDE.md` remains a symlink.

## Verification
- `cd laravel && php artisan test --filter='CurrentPriceEpisode'`: 7 passed, 42 assertions. Repeated after formatting with the same result.
- `php /tmp/voltikka-episode-review.php`: selected `2026-08-01`, as expected.
- `cd laravel && php artisan test --filter='SupplierAdjusted|CurrentPriceEpisode'`: 17 passed, 1 failed, 134 assertions. Failure: `SupplierAdjustedPricingTest::test_time_and_season_offsets_are_additive_and_exact_rates_stay_unchanged`, line 234. Expected annual equivalent `11.037037037037036`; actual `10.999999999999996`. That test passes explicit anchors and concerns the concurrent calculator/annual-equivalent change, not resolver chronology. Do not repair it in this unit.
- Focused `vendor/bin/pint --test` initially reported resolver formatting. Ran Pint on the resolver and new test only; the repeated check passed.
- `git diff --check`: passed. Reviewed the owned diff and final working-tree status. Other agents have concurrent changes; these were not edited.

## Limits
No production reads or writes, history rewrite, migration, commit, push, or deployment. No CSS/JS changes, so no asset build. The manager owns the full suite, shared cache-schema update, shared context, and task status.
