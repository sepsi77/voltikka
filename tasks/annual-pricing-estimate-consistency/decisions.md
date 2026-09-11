# Decisions

- User explicitly rejected broad exclusion requirements for unknown future prices and consumption effects. Estimation is the purpose of this system. Repair arithmetic and consistency without requiring certainty.
- Use known prices for covered periods and explicit reasonable assumptions for unknown periods. Do not treat missing coverage as zero, or override a disclosed later price with the signup price.
- Review reproductions are in /tmp/voltikka-annual-review.php, /tmp/voltikka-spot-audit.php, /tmp/voltikka-reset-review.php, /tmp/voltikka-reset-review-extra.php, /tmp/voltikka-episode-review.php, and /tmp/voltikka-parser-review.php. They are synthetic examples, not proof of current production incidence.
- Parallel implementation has exclusive file ownership. Each executor writes progress to its own notes file here. The manager owns tasks.json, this file, and final root/canonical context reconciliation.

## Implemented policy
- Cost gaps in chronological order from the latest applicable price or its disclosed normal amount. Keep later known phases. Do not count assumed continuation as a measured promotion saving. Retain detail warnings without inventing a future rise or euro impact.
- Cost the whole short term, including disclosed assumptions, before annualizing it. Hybrid estimates include the known base-price phases but not the unknown consumption effect.
- Use one flat default calendar-month consumption profile for all tariffs. Explicit heating and cooling still change that profile. Preserve the entered annual total, no-overflow anniversaries, ordinary contract-month fees, calendar-month package allowances, and original one-time charge identity.
- Derive reset and supplier annual equivalent rates from billed energy euros and the same consumed kWh. Keep a known partial-month reset period exact.
- Use verified Helsinki-date rolling Spot evidence for intraday shape. Sparse or absent shape does not stop a complete futures estimate: use equal day/night baseload prices, lower confidence, and an explicit assumption. Keep legacy rows as separate evidence; do not rewrite history.
- Normalize components and market inputs to one contract basis: Household/Both/null inclusive, Company excluded. Unknown component VAT and package amounts assume that target basis. Annual historical Spot normalization is a current-rate estimate; exact periods use actual hourly ex-VAT evidence. Company listing copy states the different tax bases rather than claiming all rows include VAT.
- Add the Helsinki calculation date to list/company/ranking cache keys and memos. Bump the shared calculated-cost schema once to v16. Stored interpretations and historical statistics are not rewritten.

## Verification and boundaries
- Fixed the old pagination test fixtures so page 2 exists before testing filter or consumption behavior. No Livewire handler or snapshot validation was weakened. Replaced hardcoded cache schema assertions with the shared schema dependency.
- Pin the normal legacy annual-statistics method in phpunit.xml, as already done for pricing feature flags. Canonical/AsOf tests opt in through config; production configuration is unchanged.
- Final independent manager verification: `cd laravel && php artisan test` passed all 2,236 tests with 10,019 assertions in 92.99 seconds (`/tmp/annual-pricing-final-tests.log`). This includes the final realized Spot VAT service test. `npm run build` passed (`/tmp/annual-pricing-build-final.log`); only the existing outdated Browserslist database warning remains. `git diff --check` and byte checks for every changed AGENTS/CLAUDE pair passed. Actual calculator, VAT, Spot reader, integrity, fixture, and documentation diffs were reviewed before completion.
- Synthetic regression cases prove the corrected behavior, not the prevalence of the original defects in production. No production calls, commits, pushes, migrations, data refresh, or historical rewrites were made.
- A broad economic-validator redesign, unrelated API sorting, historical VAT reconstruction for annual averages, and forecast coefficient calibration are outside this task. Unknown future prices alone remain no reason to exclude an otherwise useful estimate.
