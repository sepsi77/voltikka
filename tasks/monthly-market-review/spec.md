# Monthly electricity market review and outlook

## Goal

Publish one Finnish electricity market review and outlook each month. The review must:

- be a citable source for media (stable numbers, stable URL, clear method, named sources);
- give material for a short video (a few strong numbers and chart-ready series);
- use Voltikka's own data first (contract prices, spot prices, futures), and explain the
  price with a small set of physical and market drivers.

## Phase 1 (this step): data basis

Before we design the review, we define which data we need, how often we collect it, and
where it comes from. Output: `data-inventory.md`.

Inputs studied:

- `research/electricity-price-drivers/` (three deep-research reports, 2026-11-01);
- the open-source spot forecast `vividfog/nordpool-predict-fi`, whose `prediction.json`
  Voltikka already shows;
- Voltikka's current collectors (`laravel/routes/console.php`, `config/eex_futures.php`,
  `EntsoeService`, contract statistics, forecasts).

## Later phases (not started)

2. Decide the review contents and the frozen monthly metric set (user decision). Shape decided 2026-11-01 and reshaped the same day for two audiences (consumers on top, journalists below; see decisions.md); metric set follows from the September 2026 prototype.
3. Build the missing collectors (each one is a separate, small task).
4. Build the monthly snapshot (frozen numbers + method version) and the public page/CSV.
5. Video template.
6. Own short- and medium-term spot price forecast models (user goal 2026-11-01), to replace the third-party forecast.

## Constraints

- Simplest solution: reuse `EntsoeService`, the EEX collector and the existing statistics
  tables where possible. One collector per source, no parallel systems.
- Do not mention Energiavirasto in public copy (data lineage is internal).
- The retail premium dataset is private. It can inform the text, but it is not published
  as a series unless the user decides otherwise.
- Check each source's licence before we publish its numbers. Fingrid and FMI open data are
  CC BY 4.0. EEX and Nord Pool redistribution terms are not yet checked.
- Month boundaries use Europe/Helsinki. State the VAT basis of every price.
