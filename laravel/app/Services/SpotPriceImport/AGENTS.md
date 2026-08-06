# Official Spot price import

`SpotPriceImporter` is the single source of truth for persisting official ENTSO-E Spot prices used by `spot:fetch` and `spot:backfill`.

Important semantics:
- normalize API records and select electricity VAT from the record's Helsinki local time
- store 15-minute records in `spot_prices_quarter`; group their arithmetic hourly means by region and UTC hour in `spot_prices_hour`
- store direct hourly records in `spot_prices_hour`
- preserve existing records with `insertOrIgnore()` in chunks of 500; reruns do not revise prices
- complete hourly coverage for a half-open UTC range requires every exact expected hourly timestamp for the selected region; a partial set or off-hour rows cannot satisfy the check

Commands keep responsibility for date ranges, ENTSO-E retries and errors, progress, delays, averages, output, and cache warming. Do not turn this service into a generic import framework or add a result DTO.

`EntsoeService` applies the `services.entsoe.connect_timeout` and `services.entsoe.timeout` limits before its retry policy. Their defaults are 5 and 30 seconds. The hourly `spot:fetch` schedule runs in Europe/Helsinki, uses one-server execution, and expires its overlap mutex after 60 minutes so an interrupted run cannot block imports for a full day.
