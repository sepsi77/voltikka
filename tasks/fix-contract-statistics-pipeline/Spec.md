# Fix contract statistics daily pipeline

## Problem
Production `/sahkosopimus/tilastot` stopped at 29.4.2026 even though `price_components` had rows for 30.4 and 1.5.

Production logs showed `contracts:fetch` imported prices, then crashed during `contracts:calculate-percentiles` before reaching `contracts:calculate-price-statistics`.

## Goal
Make the daily statistics update resilient so imported prices produce `/sahkosopimus/tilastot` aggregate rows even if percentile badge recalculation is slow or fails.

## Scope
- Reorder `contracts:fetch` post-import work so daily contract-price statistics run before percentile badge thresholds.
- Reduce memory usage in `contracts:calculate-percentiles` by not eager-loading full price-component history for all active contracts.
