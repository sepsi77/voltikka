# Syke hydrology

Collects two Syke (Finnish Environment Institute) areal hydrology series for the northern hydro
catchments as slow input features for an own spot price model. Nothing public reads these rows.
Task context: `../../../../tasks/own-forecast-model-data/`.

## Source and licence

- API: Hydrologiarajapinta OData, `https://rajapinnat.ymparisto.fi/api/Hydrologiarajapinta/1.2/odata`,
  no key. Version 1.2 (2026-05-11) is current; 1.1 (2019) is listed as a previous version and gave
  the same rows on 2026-11-01. Source: https://www.syke.fi/en-US/Open_information/Open_web_services/Environmental_data_API
- Licence: Creative Commons Attribution 4.0 International. The credit must name the licensor and the
  data: "Source: Finnish Environment Institute (Syke), Hydrologiarajapinta" (Finnish:
  "Lähde: Suomen ympäristökeskus (Syke), Hydrologiarajapinta"). Some Syke data has own restrictions
  in its metadata; none was found for these series. Source (checked 2026-11-01):
  https://www.syke.fi/en/environmental-data/use-license-and-responsibilities
- No published rate limit. The client waits `syke.request_delay_seconds` (1 s) between requests.

## Files

- `SykeHydrologyService.php` – read-only client: one `$filter` per series with all its places and
  the date range, `$orderby=Paikka_Id,Aika`, follows `odata.nextLink` (the server returns at most
  500 rows per page; `$top` above 500 is also paged). It follows a nextLink only when it starts
  with the configured entity URL. Retries HTTP 429, 5xx and connection errors.
- `SykeHydrologyImporter.php` – upserts into `syke_hydrology_values` on (series, place_id, date).
- `../../Console/Commands/FetchSykeHydrology.php` – `php artisan syke:fetch`.
- `../../../config/syke.php` – series, places with names, window and limits.

## Series and date semantics (verified 2026-11-01)

| series | entity | unit | dates | meaning of `date` |
|---|---|---|---|---|
| `precipitation_5d` | `SadantaAlue`, `Jakso_Id eq 2` | mm (Suure 5 PA, integer) | 1st, 6th, 11th, 16th, 21st, 26th | **first day** of the period: D..D+4; the 26th covers the 26th to the month end (3–6 days) |
| `snow_water_equivalent` | `LumiAlue` | mm (Suure 7 SA, `Arvo` is a decimal string) | 1st and 16th, October–June only | state on date D (not a sum) |

- Units: `Suure` and `TauluKuvaus` entities ("Aluesadanta PA [mm]", "Lumen aluevesiarvo SA [mm]").
- Period evidence: for place 848, the monthly value (`Jakso_Id` 3, dated the 1st) equals the sum of
  the six 5-day values dated in the same month for all 22 months 2024-12..2026-09. The opposite
  reading (value at D covers D-4..D) matched 1 month in 22. Also, on 2026-11-01 the latest 5-day
  value is dated 2026-10-21 and no October monthly value exists yet, which fits a start-day date.
- Do not use a 5-day value before its period has ended (D+5, or the 1st of the next month for the
  26th): a model feature at hour H must use only periods with an end before H, and only values
  that existed then. Rows are upserted, so the fetch time of an older row is the latest fetch.
- Lag: on 2026-11-01 the latest 5-day value was 2026-10-21 (period ended 10-25) and the latest
  snow value was 2026-10-16. Snow has no values on the 1st/16th of July, August and September.
- Place pairs (precipitation / snow, same areas): 810/159 Oulujoki Jokivarsi, 811/160 Oulujärvi,
  834/183 Jokijärvi–Niskakoski and 837/185 Koitijärvi (Iijoki), 848/196 Sinettäjärvi-Luusua and
  852/200 Raudanjoki (Kemijoki), 879/226 Raanujärvi and 881/228 Vietonen (Tornionjoki),
  885/232 Inari and 886/233 Juutuanjoki (Paatsjoki). The list comes from nordpool-predict-fi.
- History: 5-day values from 1974, snow from 1965. Since 2023-01-01: 275 five-day values and
  68 snow values per place (3,430 rows in all on 2026-11-01). About 900 rows per year.

## Rules

- Rows are upserted, because Syke corrects data. A frozen figure must be copied when published.
- The importer skips a row with a null or non-numeric value, an unknown place or another Jakso.
  It does not fill gaps or carry values forward; a model does that itself.
- Logs contain the series key and the exception class only (no URL).

## Commands

```bash
php artisan syke:fetch                                     # daily window: last 90 days through today
php artisan syke:fetch --series=snow_water_equivalent      # one series
php artisan syke:fetch --from=2023-01-01                   # backfill (earlier dates are allowed)
php artisan syke:fetch --to=2024-12-31                     # backfill from syke.backfill_start (2023-01-01)
```

Dates are Finnish calendar dates; `--to` is inclusive. A failed series does not stop the other;
the run then exits 1, and the scheduler failure listener logs it.
