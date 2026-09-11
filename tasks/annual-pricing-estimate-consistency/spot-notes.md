# Spot timestamp and evidence corrections

## Result
Done. The rolling-reader and AsOf annual blockers are closed. Current and historical futures-only pricing passes integration tests with the core owner's current hook. No production calls, migrations, history rewrites, commits, or deployments were made.

## Rules and implementation
- SpotPriceAverageService parses raw stored timestamps as UTC before Helsinki hour classification. Rolling 30/365 windows are half-open local date windows converted to UTC; the rolling target is Helsinki today. New types distinguish corrected windows without rewriting old rows.
- SpotPriceAverage owns PERIOD_ROLLING_30D_LOCAL / PERIOD_ROLLING_365D_LOCAL and the legacy+local type lists. Producer constants remain aliases. Existing latestRolling30Days/latestRolling365Days helpers accept both types and use the shared latestRollingEvidence scope: DATE(period_end) descending, local type first on a same-date tie, then ID descending. A newer legacy row remains usable. DATE ordering also treats SQLite date-only and midnight-datetime forms as the same date.
- CanonicalContractPricingService uses the model helper again. Its assumptions retain actual coverage and label legacy windows instead of inventing exact local dates. Estimate memo keys include coverage.
- SpotAssumptions optional fields are actualHours, expectedHours, and windowSemantics. SpotEstimate carries them under shape, together with coverage_ratio. Optional fields preserve direct-constructor compatibility.
- A complete futures strip stays usable with missing, sparse, stale, or legacy historical shape. It uses zero day/night offsets, confidence=lower, higher_confidence=false, and zero_intraday_shape_fallback plus the reason. At least 98% verified shape coverage permits normal offsets; accepted partial coverage remains explicit. Incomplete/stale curves still reject the whole strip. Usable partial rolling levels remain the fallback; no market level is invented.
- AsOfSpotAssumptionsProvider uses the same local window. It prefers corrected stored rows. For legacy rows it tries raw local reconstruction before retaining a labelled legacy level. Valid partial raw day/night evidence stays usable. Missing day/night evidence, duplicate timestamps, off-hour timestamps, and non-finite evidence do not become verified historical shape.
- AsOfAnnualCostCalculator now resolves an estimate whenever contracts need Spot, independently of historical shape availability. Missing shape carries actual/expected hours from the unavailable evidence result into the estimator. Annual availability requires finite non-null estimate day/night equivalents, not historical availability. Provenance retains unavailable source/reason and actual coverage, plus the estimate confidence. Both canonical and relational futures-only annual paths pass tests.
- ContractPriceStatisticsService's direct rolling reader accepts both versions and selects period_end <= target, with local tie preference. It retains coverage metadata. Its raw fallback now uses the same 365-date local window and raw UTC classification. Day/night partitioning avoids Eloquent collection diff on rows whose IDs were not selected.
- ContractPriceStatistics's daily page fingerprint includes daily and both versions of rolling 30/365 rows. Hour and overall-price sums detect a same-second refresh. ContractPageCacheVersion already read all types; it now also detects same-date updates through updated time and hour/overall/day/night sums.
- ContractPricingViewData::validateSpotEstimate accepts lower-confidence forward output without historical shape dates. Existing payloads round-trip unchanged.
- Local SpotForward, ContractStatistics, Caching, and Livewire context files were updated. Shared root/canonical docs and tasks.json remain manager-owned. The manager should also retain the lower-confidence hydration rule in the shared ContractPricing documentation reconciliation.

## Core hook: exact condition
Before contractual Spot rate resolution, after the Company-only forward exclusion, the core calculator must handle:

`! $spot->isAvailable() && $spotEstimate?->basis === SpotEstimateBasis::ForwardCurve`

It can use the estimate's current-month day/night wholesale values only as internal rate-resolution inputs. It must retain the original SpotEstimate payload and historical coverage provenance. This hook is already present in the core owner's working-tree change and passes the current+AsOf no-history integration test. This executor did not edit the core calculator.

## Tests and fixtures
- Updated AsOfAnnualCostCalculatorTest and ContractPriceStatisticsCanonicalSourceTest fixtures to use rolling_365d_local when they intend verified local windows; period_start remains the target identity.
- Added SpotRollingReaderTest for both latest helpers, local ties, newer legacy fallback, direct statistics-reader coverage, raw UTC classification, excluded next-local-date hours, and same-date cache fingerprint updates.
- Added annual tests for relational and canonical AsOf futures-only estimates, equality with current canonical pricing without history, unavailable-source provenance, and sparse lower-confidence baseload.
- Existing focused tests cover both runtime timezones, a 8759-hour DST window, producer/raw equality, sparse/no history, >=98% partial shape, rolling fallback, and payload hydration.

## Verification
All commands ran in laravel unless stated otherwise.

1. `APP_TIMEZONE=UTC php artisan test --filter='SpotRollingReaderTest|SpotForwardPriceEstimatorTest|AsOfSpotAssumptionsProviderTest|AsOfAnnualCostCalculatorTest|ContractPricingReadModelTest|ContractPriceStatisticsCanonicalSourceTest'`: **95 passed, 422 assertions**. Log: `/tmp/voltikka-spot-focused-utc.log`.
2. Same command with `APP_TIMEZONE=Europe/Helsinki`: **95 passed, 422 assertions**. Log: `/tmp/voltikka-spot-focused-helsinki.log`.
3. `php artisan test --filter='Spot|CanonicalContractPricingServiceTest|ContractRequestMemoizationTest|AsOfAnnualCostCalculatorTest|ContractPricingReadModelTest|ContractPriceStatisticsServiceTest|ContractPriceStatisticsPageTest'`: intermediate result **409 passed, 9 failed**, 1487 assertions. Log: `/tmp/voltikka-spot-tests-v2.log`. One failure was the local-window fixture in ContractPriceStatisticsCanonicalSourceTest; fixed. The other eight are test annual-method selection, not rolling types: four ArticleSpotElectricityStatisticsQueryTest cases and four CompanyDetailSectionsTest cases assume legacy annual fixtures but the local configured active method differs.
4. `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_legacy_v1 php artisan test --filter='ArticleSpotElectricityStatisticsQueryTest|CompanyDetailSectionsTest'`: **45 passed, 242 assertions**. This isolates the eight configuration-dependent failures. No changes were made to those tests or their pricing policy. Log: `/tmp/voltikka-spot-unrelated-check.log`.
5. `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_legacy_v1 php artisan test --filter='Spot|CanonicalContractPricingServiceTest|ContractRequestMemoizationTest|AsOfAnnualCostCalculatorTest|ContractPricingReadModelTest|ContractPriceStatisticsServiceTest|ContractPriceStatisticsPageTest'`: **418 passed, 1580 assertions**. Log: `/tmp/voltikka-spot-tests-v3.log`.
6. Root `php /tmp/voltikka-spot-audit.php` from the first pass: sparse evidence selects forward_curve/lower and day=night=5; stored/raw both return 8760 hours and overall/night=10; both runtime timezones return identical day=10 and night=1. The audit's second sparse timestamp belongs to the next Helsinki date, so corrected coverage is one hour, not two. Vendor PDO deprecation notices only.
7. `git diff --check`: passed. Owned diffs and final git status reviewed. Search found no remaining app reader that filters only a legacy rolling type.

No CSS/JS change; no asset build. Full suite remains the manager's integration check. Other agents have active changes; they were not overwritten.
