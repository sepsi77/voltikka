# Isolated historical-change replay

## Scope and safety

This is an export-time retrospective check of the NEW expanding equal-weight production variant. It does not reproduce the frozen p20 research training identities or its scores. It does not prove archived-vintage accuracy or future predictive superiority.

Source: complete `tasks/supplier-premium-coverage/export-20260913T094138Z/`, April 8–September 13, 2026. Manifest SHA-256: `6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6`. The script verifies each manifest query's complete count, expected count, bytes and SHA-256 before use. All 164 prior research/export files pinned in the frozen p20 task remain unchanged. `FixedTermHedgeCostService.php` also matches its prior hash. Old application source pins are deliberately not rewritten after the approved model replacement.

Bootstrap isolates configuration before providers and does not load the normal `.env`. Sentry is disabled, logging is null, cache is array, and HTTP stray requests are blocked. Before migrations/load, actual PDO and `PRAGMA database_list` prove: testing environment, SQLite driver, configured `:memory:`, main database file empty. Only needed unit statistics and forecast schema are loaded, including the new nullable migration. No futures table exists. The script checks generation queries for futures access.

Original exported forecast rows stay byte-identical across the nullable migration, generation preview, staged new-model evaluation and no-op down. Generation dry run, evaluation dry run and stored report preserve their respective database snapshots. New rows are staged only in memory. No normal or production database is opened.

## September 13 issue, October 13 target

All nine service forecasts match an independent raw-array pair mean. Each fit has **98 unique start days/pairs: 80 observed + 18 canonical**. Canonical presence starts July 27. Start bounds: April 8–August 13. Target bounds: May 8–September 12. These bounds are not a claim that every intervening pair is eligible: cross-basis seams and missing dates stay excluded. All current rows use canonical unit statistics. Confidence is **low**, based only on 18 current-basis pairs.

| Term | Quantile | Current c/kWh | Mean change c/kWh | Forecast c/kWh | Outlook |
|---|---|---:|---:|---:|---|
| 6 | p20 | 14.3193 | 1.17401633 | 15.4933 | Rise expected |
| 6 | median | 14.5294 | 1.28023265 | 15.8096 | Rise expected |
| 6 | p80 | 15.9000 | 1.19433061 | 17.0943 | Rise expected |
| 12 | p20 | 11.3000 | 0.28180714 | 11.5818 | Rise expected |
| 12 | median | 11.9409 | 0.28090510 | 12.2218 | Rise expected |
| 12 | p80 | 12.7900 | 0.29478776 | 13.0848 | Rise expected |
| 24 | p20 | 10.0280 | 0.23617347 | 10.2642 | Rise expected |
| 24 | median | 10.5600 | 0.19888776 | 10.7589 | Rise expected |
| 24 | p80 | 11.1000 | 0.20686429 | 11.3069 | Rise expected |

No September 13 quantiles cross. Synthetic tests cover crossings without sorting or relabelling. No financial/futures diagnostics or uncertainty intervals are invented.

## Chronological matched evaluation

Generate canonical issues July 27–August 14, then evaluate exact canonical targets August 26–September 13. Every fit uses only completed targets strictly before its own issue. All nine September 13 fits plus 171 historical fits are independently checked: **180 fits**. All **171** historical predictions have exact targets; zero missing actuals and zero unsupported provenance. Each term/quantile has 19 matched issue days. Compare the new prediction with unchanged current price on the SAME rows.

| Term | Quantile | n | Model MAE | Unchanged MAE | Direction correct | Unchanged direction correct |
|---|---|---:|---:|---:|---:|---:|
| 6 | p20 | 19 | 0.5939 | 1.6539 | 19 | 0 |
| 6 | median | 19 | 0.3526 | 1.5286 | 19 | 0 |
| 6 | p80 | 19 | 0.4800 | 1.2161 | 18 | 1 |
| 12 | p20 | 19 | 0.1806 | 0.4201 | 19 | 0 |
| 12 | median | 19 | 0.2229 | 0.3831 | 16 | 3 |
| 12 | p80 | 19 | 0.1460 | 0.3460 | 16 | 3 |
| 24 | p20 | 19 | 0.1140 | 0.2607 | 14 | 5 |
| 24 | median | 19 | 0.1207 | 0.1962 | 9 | 10 |
| 24 | p80 | 19 | 0.1458 | 0.2177 | 10 | 9 |

MAE units are c/kWh. The 24-month median direction result is worse than unchanged direction despite lower price error. This small, already-inspected, rising-market period is not an independent test. Overlapping windows, term rows and quantiles are not independent samples. Composition changes and export revisions are uncontrolled. Do not generalize these results or attach frozen research scores to this variant.

## Reproduce

From repository root, with existing dependencies only:

```bash
php -d memory_limit=512M tasks/historical-change-forecast-release/replay.php
```

Two runs produced byte-identical `replay-results.json` (SHA-256 `ab0eb03682086b5759764bfaca8d0f866b5f1ad4ab04f944fcdb4427d4084855`). The JSON contains all nine forecasts, all 180 fits' pair hashes/counts/bounds/means, all matched evaluations, export hashes and snapshot-preservation facts. Pair identities can be regenerated from the script and pinned raw arrays; no large export is copied into this task.

The offline replay uses a 512 MB PHP limit for export snapshots and verification artifacts, not an application memory/configuration change. An initial 128 MB replay exhausted memory; it did not touch an external database. Existing PHP 8.5 vendor PDO deprecation warnings are unrelated and were not repaired. Production may have newer inputs: inspect the exact intended issue date with a separate read-only dry run before any approved first generation.
