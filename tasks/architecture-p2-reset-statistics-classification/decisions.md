# Decisions

## Implemented design

- `ContractStatisticsSegmentClassifier` is the only statistics segment classifier and owns the only segment label map.
- Classification is explicitly basis-aware.
  - `canonical_calculation` reuses `PricingCategoryResolver` and `PricingBucket::fromFacts()`: Spot maps to `spot`, MarketReset to `market_reset`, ConsumptionEffect to `hybrid`, and Fixed to the structural term segment. It never falls back to quarterly text.
  - `observed_seller_data` keeps the old order: Spot, Hybrid, shared text-quarterly match, fixed-term bucket, open-ended, other.
- Canonical Spot precedence and reset-over-Hybrid precedence come from the shared card facts. No reset cadence list or quarterly phrase list was copied.
- `market_reset` has the generic label `Jaksoittain vaihtuva hinta`. The shared map retains `quarterly => Kvartaalisähkö` for stored history and CSV output.
- Old rows are not migrated, rewritten, or translated. A canonical reset chart starts when rows are actually persisted under `market_reset`; observed `quarterly` and `open_ended` points do not enter it.
- Detail-page median overlays use the expected current basis. Older dates can retain their stored basis, but the latest expected-basis date accepts only that basis and newer opposite-basis dates are excluded.
- Company comparison keeps identical-key history only. Its cache schema is v6.
- The public statistics deep dive is cadence-neutral and uses `market_reset`; its prepared payload cache schema is v11.
- The contract detail prepared payload cache schema is v18 because the overlay segment can change.
- The Quarterly SEO listing keeps its existing observed text filter. Its statistics teaser reads `market_reset` in canonical mode and `quarterly` in feature-off mode. A `market_reset` trend waits for a 30-day-old canonical point instead of treating differently keyed observed history as the same segment.
- Listing prepared-payload cache schemas are v2 because their source fingerprint cannot invalidate a code-only insight membership change.
- The consumption calculator includes the current `market_reset` annual-cost segment. Fixed/Spot-only editorial charts were not changed.
- `ContractPriceStatisticsCanonicalSourceTest` freezes time to its fixed fixture date and resets it in teardown. The setup also resets scoped pricing services after changing canonical config, which makes the class deterministic.

## Constraints retained

- No migration and no old-row rewrite.
- No production or Railway operation.
- No duplicate reset cadence list, quarterly phrase list, or segment label map.
- Current canonical facts are never projected backward onto observed seller history.

## Validation

- Baseline focused suite: 105 tests passed and one pre-existing time-sensitive canonical-statistics test failed. Freezing that fixture class to its declared date made it deterministic.
- Final focused regression suite: 279 tests passed with 893 assertions.
- Independent review found no blocking issue after the basis-aware Quarterly insight, listing outer-cache schema bumps, and canonical no-text-fallback guard were added. Its final focused check passed 199 tests with 628 assertions.
- Full Laravel suite: 1,676 tests passed and 2 unrelated tests failed with 5,961 assertions. The known failures are the duplicate monthly Spot-average fixture in `ContractDetailPageTest` and the strict floating-point identity assertion in `ContractDetailPresenterTest`.
- Pint passed for all 17 changed PHP files. Task JSON validation and `git diff --check` passed.
