# NVE reservoir statistics (Magasinstatistikk)

Collects Norwegian weekly hydro reservoir filling for Norway and the elspot zones NO1–NO5, with the
min/median/max of the same ISO week over twenty years, for the monthly market review. Nothing
public reads these rows yet. Task context: `../../../../tasks/monthly-market-review/` (G10).

## Files

- `NveReservoirService.php` – read-only client for
  `https://biapi.nve.no/magasinstatistikk/api/Magasinstatistikk/` (no key; Swagger at
  `/magasinstatistikk/swagger/v1/swagger.json`):
  - `HentOffentligData` – full weekly history from 1995 (about 4 MB, ~15,000 rows for 9 areas).
  - `HentOffentligDataMinMaxMedian` – min/median/max per area and ISO week 1–53, "for the last
    twenty years", without the years.
  - Not used: `HentOffentligDataSisteUke` (latest week only), `HentOmråder` (area names).
- `NveReservoirImporter.php` – upserts `nve_reservoir_weeks` and `nve_reservoir_week_references`;
  proves the reference period.
- `../../Console/Commands/FetchNveReservoirs.php` – `php artisan nve:fetch-reservoirs`.
- `../../../config/nve.php` – areas and limits.

## Rules

- Areas: `omrType` NO (`omrnr` 0, Norway total) and EL (`omrnr` 1–5 = NO1–NO5). VASS (three
  watercourse areas) exists but is not stored; add it to `nve.area_types` if needed.
- Filling is a ratio 0..1 (`filling_ratio`), as NVE publishes it. `week_end_date` is NVE `dato_Id`,
  the Sunday that ends the ISO week. NVE publishes on Wednesday 13:00 Norwegian time.
- Every run reads the full history (one request), because the reference proof needs it. By default
  only rows from the previous ISO year are upserted; `--from-year` / `--all` store more.
- **Reference period proof.** NVE does not say which twenty years it uses. The importer accepts a
  period (ending at the current ISO year − 1, − 2 or the current year) only when min, median and
  max of every configured area for weeks 1–52 equal the published values (tolerance 1e-5). On
  2026-11-01 the period was 2006–2025 (312 of 312 area-weeks matched; 2005–2024 and 2007–2026 did
  not). If no period matches, no reference row is stored and the run exits 1. Do not label a
  reference with assumed years. The period is part of the key, so each year's reference stays.
- Week 53 reference uses only the years that have a week 53 (2009, 2015, 2020 in 2006–2025).
- Live check, week 43 of 2026: Norway 69.5 % (60.8 TWh) vs median 82.6 % (min 64.2, max 92.9);
  NO2 53.6 % vs median 83.9 % and min 53.8 %, so NO2 was below its 20-year minimum. Some press
  reports quote another median (about 84.6 %); cite NVE's own figure and its period.
- A failed weekly fetch also fails the reference unit (no proof possible); the run exits 1. Logs
  contain the unit and exception class only.

## Licence (open)

The API is public without a key. Neither NVE's magasinstatistikk page nor the data.norge.no entry
names a licence (checked 2026-11-01; data.norge.no shows "Not provided"). Other NVE datasets use
NLOD. Credit "NVE magasinstatistikk" and confirm the terms with NVE before publication.

## Commands

```bash
php artisan nve:fetch-reservoirs                 # previous + current ISO year, and the reference
php artisan nve:fetch-reservoirs --all           # full history from 1995
php artisan nve:fetch-reservoirs --from-year=2015
```
