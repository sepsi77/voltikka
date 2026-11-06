# GIE AGSI+ gas storage

Collects daily EU and German gas storage levels from GIE AGSI+ (https://agsi.gie.eu) for the
monthly market review (data inventory G9). Nothing public reads these rows yet. Task context:
`../../../../market-review/`.

## Files

- `GieAgsiService.php` – read-only API client. Key in the `x-key` header, `size` 300 (API
  maximum), follows `last_page`. One request per `gas_storage.request_delay_seconds` (1.5 s);
  retries HTTP 429, 5xx and connection errors.
- `GasStorageImporter.php` – upserts one row per area and gas day into `gas_storage_daily_values`.
- `../../Console/Commands/FetchGasStorage.php` – `php artisan gas-storage:fetch`.
- `../../../config/gas_storage.php` – areas (`eu` → `type=eu`, `de` → `country=de`) and limits.
  The key is `GIE_API_KEY`.
- Migration `2026_11_01_000401_create_gas_storage_daily_values_table.php`.

## Rules

- **Attribution is mandatory.** GIE data is free to use and republish "in any way, shape or
  format", on the condition that GIE / AGSI is named as the source (for example "Lähde: GIE AGSI")
  under the figure. GIE can withdraw the API key without the credit. Source: the "Data Usage
  Rules & Source Referral" page, https://agsi.gie.eu/data-usage (checked 2026-11-01).
- The key is free: register at https://agsi.gie.eu/account (name, email, company, consent to the
  credit rule). The key shows on the account page. It does not expire (GIE manual v008).
- AGSI answers an invalid or missing key with **HTTP 200** and `{"error":"access denied",...}`.
  The client therefore checks the `error` field, not only the status. The command fails before
  any request when `GIE_API_KEY` is empty.
- GIE allows 60 calls per minute per IP; more puts the IP in a 60-second queue and repeated abuse
  gets a ban. A normal run is one call per area.
- Data is published at 19:30 and 23:00 CET for the previous gas day, and estimated days (`E`)
  become confirmed (`C`) later. The daily run refetches 14 days and upserts on
  (area_code, gas_day). Copy a frozen monthly figure into the review when it is published.
- Units: `gas_in_storage_twh` and `working_gas_volume_twh` in TWh; `full_pct` = storage / WGV in %;
  `trend_pct` = (injection − withdrawal) / WGV in %; `injection_gwh`, `withdrawal_gwh`,
  `net_withdrawal_gwh` in GWh/d. AGSI sends numbers as strings; a non-numeric value is stored as
  null. `gas_day` is the AGSI `gasDayStart` date text (a gas day starts at 06:00 CET).
- Logs contain the area and exception class only (no URL or key). A failed area does not stop
  the others; the run then exits 1.

## Commands

```bash
php artisan gas-storage:fetch                                   # last 14 gas days
php artisan gas-storage:fetch --area=de
php artisan gas-storage:fetch --from=2020-01-01 --to=2026-10-31 # backfill (paginated)
```
