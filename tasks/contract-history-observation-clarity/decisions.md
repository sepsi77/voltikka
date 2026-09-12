# Decisions

- The reported 234 unchanged observation days do not prove a backfill defect. This task changes display only.
- Count unique calendar dates per exact version, not component rows or lineage totals.
- Keep existing latest-positive component selection and latest_price_date semantics. Review correction: the timeline latest-observation condition and time use `last_seen_on_sale_date`, which includes zero rows. The rendered regression shows 20 March while the selected positive price date remains 10 January.
- Determine seasonal copy from resolved historical components, not contract metadata. Require every energy-bearing observation to resolve to Season; mixed histories keep the ordinary energy label.
- Updated the nearest history context. Its CLAUDE.md remains a symlink to AGENTS.md.

## Verification

- `cd laravel && php artisan test --filter='ContractHistoryPresenterTest|ContractDetailPriceDevelopmentTest|ElectricityContractLineagePriceHistoryTest'`: 27 passed, 149 assertions. Includes the unchanged short-history suppression and bounded-query tests.
- `cd laravel && php artisan test --filter='ContractDetailPageTest|ContractDetailPresenterTest'`: 109 passed, 427 assertions.
- `cd laravel && npm run build`: passed; Vite built 60 modules. Non-blocking warning: caniuse-lite is 9 months old. No dependency update was made.
- Targeted `vendor/bin/pint --test` over the two history presenters and two changed test files failed on style rules (fully qualified types, imports, not/unary spacing, braces, empty bodies). No broad style rewrite was made; existing local style was kept.
- `git diff --check`: passed. Final diff and status reviewed. Other dirty task folders were not changed. No production action, commit, or push.

## Review correction verification

- `cd laravel && php artisan test --filter='ContractHistoryPresenterTest|ContractDetailPriceDevelopmentTest|ContractDetailPageTest'`: 116 passed, 459 assertions. Includes rendered March 20 latest observation, unchanged January 10 positive-price selection, first date, count, and retained positive price.
- Ran `laravel/vendor/bin/pint` on temporary copies of both history presenters and both changed test files, then compared each copy to its source. All resulting differences were existing fully qualified PHPDoc types outside added lines. No added-block style violation required a source change; legacy source was not reformatted.
- `git diff --check`: passed after the correction. The AGENTS/CLAUDE mirror is unchanged and equal. No CSS or JS changed in this correction; the earlier successful build remains applicable.

