# Price forecasting production backend

Implement the first production backend for fixed-term electricity contract price forecasts.

Scope:
- No frontend/UI.
- Add persistence so forecasts can be stored and later compared with realized retail prices.
- Port the simple local EWMA retail-premium / futures hedge-cost direction model from `laravel/data-investigation/price-forecasting/` to PHP services.
- Forecast fixed-term 6, 12 and 24 month market-level prices, primarily median with p20/p80 stored too when available.
- Add Artisan commands to run forecasts and evaluate matured forecasts against actual daily statistics.
