# Decisions

- Root cause: `CitySolarEstimate` lazy Livewire hydration can call `CitySolarService`, which performs a PVGIS HTTP request on cache miss. `PvgisService` did not set explicit HTTP timeouts, so a slow PVGIS response could sit in Guzzle `curl_exec()` until PHP's 30s max execution time killed the request.
- Fix: `PvgisService` now sets explicit connect/request timeouts for PVGIS calls.
- Adjustment: PVGIS timeouts are configurable through `services.pvgis.connect_timeout` and `services.pvgis.timeout`, backed by `PVGIS_CONNECT_TIMEOUT` and `PVGIS_TIMEOUT`. Defaults are 3s connect timeout and 12s total request timeout, which leaves headroom under PHP's 30s limit while being less aggressive for real users than the initial 8s request timeout.
- Fix: crawler user agents hydrating `CitySolarEstimate` now read only cached city solar estimates. If no cached estimate exists, the widget renders empty instead of making an external PVGIS call. This specifically protects Googlebot-triggered Livewire lazy update requests.
- Human visitors can still populate the city solar cache through the lazy widget; failures continue to be caught/logged by `CitySolarService` and render no widget.
- Verification: ran `php artisan test --filter='city_solar_estimate_googlebot|city_page_does_not_fetch_solar_estimate|PvgisServiceTest'` and after timeout adjustment `php artisan test --filter='PvgisServiceTest|city_solar_estimate_googlebot'`.
