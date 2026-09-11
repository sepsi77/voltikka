# Decisions

- Work is local only. Historical stored statistics stay unchanged. A cache version change must invalidate old calculated outcomes.
- Identify a fee-only transition by a changed resolved monthly/one-off fee and unchanged resolved energy buckets, mechanism, and Spot margin. Use the same inheritance and duplicate handling as billing. Compare each energy bucket, not the weighted average or labels. Skip only the final slice of such a phase; intermediate calendar slices cannot become energy boundaries.
- Keep explicit recurring period boundaries, finite coverage before a gap, unchanged-fee finite phases, unresolved rates/packages, and real energy changes conservative. The feature-off path is unchanged.
- Tests model both September 11 Aalto shapes at 5,000 kWh, including an inherited intro energy rate. The synthetic forward is 4 c/kWh and correct reference is 6; the incorrect later reference would be 14 and trigger the floor. Correct annual energy equivalents are 5.801111 and 7.091111 c/kWh. Normal totals are EUR 361.455556 and 425.955556; offer totals are EUR 355.569534 and 420.069534. The only saving is EUR 5.886022 under the existing calendar-month fee fractions, not exactly EUR 5.95 (20/30 September plus 10/31 October).
- Additional tests cover inherited Time/Season buckets, a genuine one-bucket change, explicit recurring boundaries, real finite energy promotions, annual/monthly arithmetic, copy fallbacks, and cache v15. Existing negative-projection floor tests still pass.
- Finnish reset copy now states the next 12 months, including the current known period and later estimated periods. Receipt label: `12 kk keskihinta, arvio`. Calculated-cost schema moved once from 14 to 15.
- Historical stored annual rows and daily aggregates stay unchanged. After release, new calculations can change statistics and rankings. Cache invalidation does not repair historical rows. A rebuild requires a separate reviewed plan and explicit write approval.

## Existing limits, not changed

- Reset offsets are calendar-month keyed. A real mid-month energy boundary can still apply the offset to that calendar month's earlier slices. The genuine-energy regression pins the current behavior instead of adding a new day-level rule.
- Monthly output groups each timeline slice by its start's elapsed month. Inserting a fee boundary can move part of October between display bins. The energy forecast and annual energy sum remain identical; the tests check the exact existing bin arithmetic for each shape. This separate display-bin issue is outside this repair.

## Verification

- Final targeted command: `cd laravel && php artisan test --filter='MarketResetForwardShiftTest|MarketResetEstimateSurfacesTest|ContractCardPresenterTest|ContractDetailPresenterTest|ContractDetailPageTest|CompanyListPageTest|ContractRequestMemoizationTest|ContractRankingTypedMetricsTest'`: **243 passed, 1,205 assertions**. Log: `/tmp/market-reset-targeted-tests.log`.
- `cd laravel && php artisan test`: **2,151 passed, 24 failed, 9,147 assertions**. Log: `/tmp/market-reset-full-tests.log`.
- Baseline proof: exported unchanged HEAD to an isolated `/tmp` directory, copied local installed dependencies and build assets, supplied a generated test-only APP_KEY plus `APP_DEBUG=true` and `CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_as_of_v1`, and ran the six failing feature classes. **132 passed, the same 24 failed**. Failure-name comparison with the full changed-tree run is identical. Log: `/tmp/market-reset-baseline-tests.log`. The failures are 4 article-statistics tests, 16 company-detail tests, and one each in listing eligibility, contracts list, home trend, and pricing-bucket filters. Without the AsOf environment setting, only the existing three Livewire/query-state failures remain. No unrelated repair was made.
- `cd laravel && npm run build`: **passed**, Vite built 60 modules. Warning: Browserslist data is 9 months old; no dependency update was made.
- `git diff --check`: **passed**.
- `cd laravel && vendor/bin/pint --test --dirty`: existing import/style failures in `ContractCardPresenterTest.php` and `ContractDetailPageTest.php`. Running Pint on the same unchanged HEAD files proves the same failures. No unrelated import rewrite was made.
- Earlier test iterations exposed two incorrect test expectations (quarter label and elapsed-month bins) and hard-coded cache-v14 expectations. These were corrected; final targeted tests pass.
- No production operation, stored-row rewrite, commit, or push was done. Root context did not change because the architecture and release rules are unchanged. All edited local CLAUDE mirrors are existing symlinks.
