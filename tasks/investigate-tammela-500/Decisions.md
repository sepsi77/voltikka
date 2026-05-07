# Findings

- Reproduced production HTTP 500 for `/sahkosopimus/paikkakunnat/tammela` with `curl`.
- Production municipality row exists for slug `tammela`; this is not a missing city/route issue.
- Reproduced inside the production container with a synthetic Laravel request.
- With normal memory, request exhausts memory while building/logging the response path.
- With 1GB memory, the underlying exception is visible: Laravel database cache insert fails with `SQLSTATE[22001]: Data too long for column 'value' at row 1` in `SeoContractsList::seoContractsViewData()` / `Cache::remember()`.
- The cached payload for this city SEO page is too large for the `cache.value` column (`mediumtext`) and is also very memory-heavy (~368 MB peak in the repro).
- User-provided Railway logs are MySQL redo-log pressure warnings and SIGKILL notices; they indicate DB/write pressure and process kills, but the direct page failure is the app trying to cache an oversized SEO page payload.
- Later Railway MySQL logs showed `No space left on device` and `The table 'cache' is full`; diagnosis found the database cache table was ~4 GB.

# Changes

- Reduced city-page local/regional contract payloads in `app/Services/LocalContractsService.php`.
- Removed eager loading of full `priceComponents` history for local and regional city contract sections.
- Local contract pricing still uses `getLatestPriceComponentsForCalculation()` for discount-aware calculation.
- Attached only synthetic latest price-component models back onto each local/regional contract so `<x-contract-card>` can still show current component prices without retaining historical rows in the cached SEO payload.
- Updated `app/Services/AGENTS.md` with the city/local contract price-component caching guardrail.
- Updated `contracts:fetch` so after a successful import it clears stale application caches before bumping/warming fresh caches.
- For the database cache driver, the import cache clear uses `TRUNCATE TABLE cache` rather than Laravel's database-store `DELETE`, because expired/old rows otherwise leave large InnoDB table files allocated.

# Production cleanup

- Truncated the production `cache` table after MySQL volume was increased/restarted.
- Row count went to 0.
- Direct MySQL service filesystem check showed `/var/lib/mysql/railway/cache.ibd` shrank to 147,456 bytes and `/var/lib/mysql` had ~18 GB free.

# Local validation

- `php -l app/Services/LocalContractsService.php` passed.
- `php -l app/Console/Commands/FetchContracts.php` passed.
- `php artisan test --filter=ContractsFilterTest` passed.
- Synthetic local Laravel GET request to `/sahkosopimus/paikkakunnat/tammela` with `memory_limit=128M` returned HTTP 200 after clearing cache.
- Repeated synthetic request returned HTTP 200 from warm cache.
- With local `CACHE_STORE=database`, cold request returned HTTP 200, warm request returned HTTP 200, and the cached database payload was about 13.4 MB, under MySQL `MEDIUMTEXT` capacity.
