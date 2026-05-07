# Decisions

- Moved city-page PVGIS/solar potential lookup out of `SeoContractsList` and into a dedicated `CitySolarEstimate` Livewire child component.
- Rendered that child with Livewire `lazy` on `/sahkosopimus/paikkakunnat/{city}` pages so initial HTML can be returned without waiting on `CitySolarService` / PVGIS cache misses.
- Kept a lightweight placeholder in the lazy child to preserve layout while the async Livewire request fetches the solar estimate.
- Added a feature test that binds `CitySolarService` to a throwing fake and verifies the initial city page load still succeeds, proving the service is not called in the initial request.
