# Decisions

- 2026-05-27: Removed the absolute cheapest/minimum annual-cost metric from the `/sahkosopimus/tilastot` consumption range table and its prepared view-data payload. The page now presents p20/median/p80 instead, avoiding misleading single-row/import anomalies.
- 2026-05-27: Bumped the prepared view-data cache namespace from `v7` to `v8` so cached payloads with the old `min` field/table structure are not reused.
