# Official Spot price import

`SpotPriceImporter` is the single source of truth for persisting official ENTSO-E Spot prices used by `spot:fetch` and `spot:backfill`.

Important semantics:
- normalize API records and select electricity VAT from the record's Helsinki local time
- store 15-minute records in `spot_prices_quarter`; group their arithmetic hourly means by region and UTC hour in `spot_prices_hour`
- store direct hourly records in `spot_prices_hour`
- preserve existing records with `insertOrIgnore()` in chunks of 500; reruns do not revise prices
- complete hourly coverage for a half-open UTC range requires every exact expected hourly timestamp for the selected region; a partial set or off-hour rows cannot satisfy the check

ENTSO-E sends curve type A03 for FI and its neighbours: a point is omitted when the price does not change, and it is valid until the next point (the last point until the end of the time interval). `EntsoeService` expands A03 to every slot. Before 2026-11-01 it did not, so stored FI history has missing hours (46 in the local copy from 2025-09-30 to 2026-10-24) and hourly means calculated from 3 of 4 quarters (766 hours; October 2026 monthly mean 4.205 instead of 4.184 c/kWh VAT0). Insert-only reruns do not repair those stored values.

An older defect also exists: in 2022–2024 every second month (Feb, Apr, Jun, Aug, Oct, Dec) holds only its first day, so about 4,250 FI hours per year are missing (an earlier backfill of those months failed). Stored monthly means are therefore very wrong there (local copy: 2022-12 38.79 instead of 24.63 c/kWh VAT0; 2024-02 0.08 instead of 5.16).

`SpotPriceHistoryRepair` + `php artisan spot:repair-history --from=YYYY-MM --to=YYYY-MM [--region=FI] [--apply]` repair this. It is a dry run by default. It fetches one Helsinki month per ENTSO-E request, builds rows through `SpotPriceImporter::prepare()` (the same VAT and hourly-mean rules as `import()`), inserts missing quarter/hour rows, updates rows that differ by more than 0.0001 c/kWh (hours; `price_without_tax` is a MySQL single-precision FLOAT) or 0.00005 (quarters, DECIMAL(10,4)), and never deletes a row. After an FI apply it recalculates daily/monthly/yearly averages; the hourly `spot:fetch` would also do that, because `calculateAllAverages()` recalculates all of them. Past rolling 30/365-day rows are deliberately NOT recalculated: stored contract statistics used them as dated evidence, and `calculateAllAverages()` writes only today's rolling rows. Local dry run 2022-01..2026-10 (copy of 2026-10-28): 13,110 missing hours, 663 changed hours, 1,961 missing quarters; 46 of 58 monthly means move by at least 0.01 c/kWh. A production apply is a production data write: it needs explicit approval and a fresh dry run on production first. A01 series are parsed as before. When a zone publishes several sequences (DE-LU), only `classificationSequence` position 1 is kept: it is the coupled SDAC price (matched SMARD/Energy-Charts exactly on 2026-10-31).

Neighbour zones (`services.entsoe.bidding_zones`: SE1, SE3, EE, DE-LU) are stored in the same tables with their own `region` code by `spot:fetch-neighbours` (hourly at minute 25) and `spot:backfill --region=`. They get `vat_rate` 0, because Finnish VAT applies only to FI; their `price_with_tax` equals the VAT0 price. Every public reader filters `region = 'FI'` (checked 2026-11-01); keep that filter in any new reader. Neighbour runs never calculate averages or warm caches.

Commands keep responsibility for date ranges, ENTSO-E retries and errors, progress, delays, averages, output, and cache warming. Do not turn this service into a generic import framework or add a result DTO.

`EntsoeService` applies the `services.entsoe.connect_timeout` and `services.entsoe.timeout` limits before its retry policy. Their defaults are 5 and 30 seconds. The hourly `spot:fetch` schedule runs in Europe/Helsinki, uses one-server execution, and expires its overlap mutex after 60 minutes so an interrupted run cannot block imports for a full day.
