# Decisions

- The statistics page already reads precomputed `contract_price_daily_statistics`; the request-time cost is repeated aggregation/render payload building over the full daily-statistics table.
- Cache the component's prepared view data per `period` + `consumption` and per cheap database version fingerprint instead of caching raw Eloquent collections or doing full response caching. This preserves Livewire controls while avoiding the expensive collection scans on repeated page loads.
- Expire cached payloads daily and include table update fingerprints so same-day imports/backfills can invalidate without manual cache clearing.
- The cache key includes the daily-statistics row count as well as latest dates/update timestamps so inserted backfill/import rows bust stale cached payloads even if test/dev timestamps share a second.
- Added feature coverage to verify the second request does not reload every daily-statistics row and that cached payloads invalidate when statistics data changes.
