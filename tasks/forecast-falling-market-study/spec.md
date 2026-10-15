# Falling-market forecast study (pre-declared)

## Problem

From 2026-09-14 to 2026-10-15 the 6/12/24-month fixed-term medians fell (12 m 12.50 → 11.69 c/kWh), but every stored `fixed_term_futures_adjusted_v1` forecast said `rising`. Cause: the unrestricted expanding mean of completed retail changes (+0.66/+0.34/+0.26 c/kWh on 2026-10-15) dominates; the seven-day futures contribution is usually ±0.1. The mean barely moves because it averages every change since January, most of them rises.

## Goal

Decide, with evidence that was not used to choose the models, which production change to make: keep current, remove the drift term, a normal-premium model, a blend, or publish no direction.

## Frozen inputs

- `export.py` extracts from the local SQLite copy synced from production on 2026-10-16 (read-only sync): unit_statistics_v1 `energy_price` rows with null consumption for `fixed_term_6/12/24` (all bases), and all FI Base month/quarter/year futures rows. Output CSVs and SHA-256 hashes are pinned in `inputs.json` before results.
- Snapshot cutoff: **2026-10-15** (last retail statistic date in the snapshot).

## Series and pair rules (same as production)

Per term: latest ID owns each basis/date before finite validation; observed prefix before first canonical presence, canonical continuation after it. A pair (s, s+h) needs both endpoints present, finite, same basis. Training uses only pairs with target strictly before issue. Minimum 20 training pairs (and 20 feature-complete pairs for any model with a fitted feature).

Futures basket: same as `FixedTermHedgeCostService` batch mode. Latest trade strictly before the valuation date, delivery basket of `term` months starting next full month after issue, month → quarter → year fallback, calendar-day weights, complete strip only, EUR/MWh / 10 × 1.255.

## Models (fixed list; no additions, windows or tuning after results)

Rows are evaluable only where every model can predict (one matched cohort per horizon/quantile).

- **M0 unchanged**: delta 0.
- **M1 current**: unrestricted expanding mean + ridge-1 seven-day fixed-basket futures contribution (production formula).
- **M2 futures-only**: M1 without the mean.
- **M3 rolling-60 drift**: mean of the last 60 training pairs + the M1 futures contribution.
- **M4 premium return**: premium p(d) = retail(d) − basket(d). Gap g(s) = mean of all premiums on dates < s (≥ 20 values) − p(s). Delta = b·g, b = no-intercept least squares on training pairs, floored at 0.
- **M5 premium return + futures**: M4 + ridge-1 futures contribution fitted on M4 training residuals.
- **M6 half-gap**: 0.5·g (fixed coefficient, no fit).
- **M7 blend**: mean of M2 and M4 deltas.

Rounding: delta half-up to four decimals; direction inclusive ±0.15 c/kWh, else flat. Actual direction uses four-decimal target − current.

## Horizons and quantiles

Primary: median, h = 30. Secondary: median h = 14 and h = 7; p20 and p80 at h = 30. Secondary results never replace the primary decision.

## Evaluation windows

- **Retrospective (descriptive only)**: issue dates whose target is ≤ 2026-10-15. Models M0–M6 (as variants) were already inspected on this data on 2026-10-16, so this window is not evidence for selection. Report it split by issue < 2026-08-15 / ≥ 2026-08-15, by term and by actual up/flat/down.
- **Holdout (decision evidence)**: issue dates ≤ 2026-10-15 whose target is > 2026-10-15. No target value exists in the frozen snapshot. Evaluate after a fresh production sync on or after **2026-11-15** (30-day targets of 2026-10-15 issues). Training stays walk-forward, so new data cannot leak into earlier fits. The rerun must report every retail row dated ≤ 2026-10-15 whose value differs from the frozen export; revised rows are listed, not silently used.

## Metrics

Per model/window/term/all: N, unique issue days, MAE, bias, correct/missed/false/wrong-way counts, actual up/flat/down counts, paired absolute-error improvement versus M0 and M1. Windows overlap heavily; do not report significance tests.

## Pre-declared decision rule (primary holdout, pooled terms)

1. A candidate (M2–M7) **qualifies** if on the holdout its MAE < M1 and ≤ M0, its wrong-way count ≤ M1, AND on the full retrospective window its MAE ≤ M0 + 0.10 c/kWh.
2. If one or more candidates qualify, recommend the qualifying candidate with the lowest holdout MAE; ties go to the simpler model (order M2, M6, M4, M7, M5, M3).
3. If none qualifies and M1 holdout MAE ≤ M0, recommend keeping M1.
4. Otherwise recommend publishing no rising/falling direction (show current prices and history) until a model meets rule 1 on later evidence.

The recommendation is advice to the user; any production change needs separate approval.

## Limits

Overlapping windows, one falling episode, small 6-month segment (9–10 contracts), assumed observed/canonical continuity, possible production revisions of statistic rows. No claim of general accuracy.
