# Electricity futures services

Voltikka collects electricity futures end-of-day settlement prices from EEX so the site can build its own historical futures dataset.

Primary files:
- `EexFuturesService.php` — wraps the EEX public chart EOD endpoint and normalizes `settlPx`, `volume`, and `lotSize` series.
- `../../Console/Commands/FetchEexFutures.php` — Artisan command `futures:fetch-eex` for daily collection.
- `../../Console/Commands/BackfillEexFutures.php` — Artisan command `futures:backfill-eex` for fetching all history available from the public EEX API.
- `../../../config/eex_futures.php` — endpoint settings and the configured EEX instruments.
- `../../../database/migrations/2026_05_22_000001_create_electricity_futures_eod_prices_table.php` — storage table.

Important behavior:
- EEX requires the `Referer: https://www.eex.com/` header for browser-equivalent public chart requests.
- Public EEX API calls are throttled centrally in `EexFuturesService`: by default the first call runs immediately and later calls wait a random delay around 15 seconds (`request_delay_seconds` +/- `request_delay_jitter_seconds`). This intentionally makes backfills/daily fetches slow and polite.
- Out-of-bounds maturity values return HTTP 200 with empty payloads (`price-ticker.data = []`, chart series empty), not a 404/error. The command probes `price-ticker` using one representative instrument per tenor and stops at the first empty maturity after valid maturities, so max delivery dates are discovered dynamically without repeating discovery for every market.
- The public EEX chart endpoint returns only about 45 days of history, so `futures:backfill-eex` means "fetch all publicly available history" rather than true deep history. The fetch command caps the requested start date to the configured `history_window_days` and defaults to re-fetching that full rolling window. Rows are upserted by instrument, maturity, and trade date, so reruns are safe.
- Default collection stores EEX Nordic System Price and Nordic zonal **Base Month, Quarter, and Year** futures for DK1, DK2, FI, NO1-NO5, and SE1-SE4.
- EEX maturity strings are `YYYYMM`: monthly maturities use the delivery month, quarterly maturities use the quarter start month (`01`, `04`, `07`, `10`), and yearly maturities use January (`YYYY01`).
- By default the command scans candidate maturities matching the EEX delivery dropdown shape: previous month + current/next months (`months_back`, `months_ahead` scan ceiling), next quarters (`quarters_ahead` scan ceiling), and next calendar years (`years_ahead` scan ceiling). The actual fetched set is the dynamically discovered contiguous valid range from the representative instrument, then the same maturity values are applied to all configured markets of that tenor. As of 2026-05 examples this means monthly `202604` through `202611`, quarters through `202801`, and yearly `202701` through `203201`.
- The current EEX product-code file does not expose Baltic power year future short codes for this endpoint. Do not invent Baltic instruments; add them to `config/eex_futures.php` only after verifying working `area` + `shortCode` combinations from EEX.

Useful commands:
```bash
php artisan futures:fetch-eex --area=FI --tenor=month --maturity=202606 --start-date=2026-05-04 --end-date=2026-05-08
php artisan futures:fetch-eex --area=FI --tenor=quarter --maturity=202607 --start-date=2026-05-04 --end-date=2026-05-08
php artisan futures:fetch-eex --area=FI --tenor=year --maturity=202701 --start-date=2026-05-04 --end-date=2026-05-08
php artisan futures:fetch-eex --dry-run --months-back=0 --months-ahead=1 --quarters-ahead=1 --years-ahead=1
php artisan futures:backfill-eex
```
