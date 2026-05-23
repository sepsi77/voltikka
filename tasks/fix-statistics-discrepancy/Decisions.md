# Decisions

- The discrepancy came from mixed spot bases on `/sahkosopimus/tilastot`: the top editorial callout used raw `spot_total_energy_price` from the contract-statistics table, while the segment table and deep-dive spot section used the intended trailing-12-month realized spot average plus typical margin.
- Kept the yearly/trailing-12-month spot basis. Updated the top spot callout to use `spotEnergyPriceAggregatedSeries()` so its current value and deltas match the deep-dive/segment spot basis.
- Bumped the prepared view-data cache namespace from v6 to v7 so stale cached callout payloads are not reused.
- Added a regression test where raw spot stats fall sharply while yearly spot averages rise, verifying the callout no longer reports the raw fall.
