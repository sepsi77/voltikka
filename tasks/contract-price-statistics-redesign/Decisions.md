# Decisions / open questions

## Audience and goal (2026-04-29)

The page is an SEO link-acquisition play, not a tool for contract buyers. Primary audience is data-curious users, journalists, and Reddit/HS commenters. The point is to attract inbound links and screenshot citations from media and social. Target queries: `sähkön hinta tilastot` and similar.

This reframes the design completely: it must read as an editorial data artifact, not a product dashboard. Numbers must be quotable; charts must be screenshot-clean; a copy-paste citation block and CSV download are first-class features, not nice-to-haves.

## Why we are redesigning, not iterating

The current page violates several explicit `DESIGN.md` bans:

- The hero-metric template (three identical stat cards under the hero).
- Identical card grids stacked endlessly (three near-identical wide tables for 2000/5000/18000 kWh).
- Coral-gradient trend bars used as decoration.
- The dark slate-950 hero used as default chrome on a non-hero page (`DESIGN.md` explicitly says it must be "a focused moment, not a default theme").
- A pill badge ("Sähkösopimusten hintakehitys") restating the H1 below it.

Past those bans, the page also fails its actual job: it shows "the latest period" as a snapshot when the page exists to communicate change over time, and offers no citation affordance for journalists.

## Color and theme

- Color strategy: **Restrained**, with coral capped at ≤ 5% (intentionally below `DESIGN.md`'s 10% ceiling). On a citation page, neutrality is the brand.
- Theme: light. The dark slate-950 hero is removed from this page. Scene sentence: *"A reporter at HS or Yle on a 27-inch monitor at 14:30, scanning the page once for a number to quote in tomorrow's article, and a graph clean enough to screenshot without cropping."*

## Lead-chart strategy

- One chart, four lines, coral on the editorially-leading segment (default: `Pörssi yhteensä`).
- Direct end-labels rather than a legend block (FT/NYT pattern). Easier to screenshot, easier to read.
- Editorial caption beneath the chart, generated from the data, in plain Finnish. The caption is the screenshot.
- No data-point dots until hover. Single crosshair tooltip with all four values, tabular nums.

## Why uPlot for charts

The repo currently has no chart library. uPlot wins on this surface because:

- Small (≈ 40 KB minified, ≈ 15 KB gzipped). Page can stay under the 200 KB JS budget.
- Sober defaults: no gradient fills, no drop shadows, no animation choreography. We do not have to fight the library to honor `DESIGN.md`.
- Fast at large series counts; future-proof as the data window grows.
- MIT licensed.

ApexCharts and Chart.js were rejected because their defaults push gradients, animations, and SaaS-y tooltips that we would have to override extensively. ECharts is overkill for ~12 series. Recharts requires React (we are on Livewire/Blade).

If the user later prefers zero JS deps, the lead chart can be reimplemented in inline SVG; the rest of the page already uses inline SVG sparklines.

## Honest data-window framing

Real contract data has been collected since January 2026. As of redesign start (2026-04-29) the window is roughly four months. We do not zero-pad earlier dates and we do not pretend a 12-month trend exists.

The meta row in the header explicitly states the window. The dek mentions *"Aineisto kasvaa kuukausittain, näytämme sen mitä on kerätty."* As more data accumulates, the same components scale automatically; nothing in the layout assumes a particular horizon.

## Interpreting the original spec's "show all tables together" rule

`tasks/contract-price-statistics/Decisions.md` requires that the page does not hide metrics behind dropdowns. We honor the spirit, not the letter:

- All main data is visible without a dropdown.
- The original "three identical stacked tables for 2000/5000/18000 kWh" is replaced by one table whose consumption level is selected via compact pills.
- The unselected consumption levels remain crawlable via `?kulutus=` query params and are linked from the section H2 ("Katso myös: 2 000 kWh · 18 000 kWh"). SEO and UX both win.
- The original "energy prices, margins, monthly fees" table and the "trend cards" block are merged into a single segment table with sparkline column. Same data, fewer parallel views.

Time aggregation (`Kuukausi · Viikko · Päivä`) stays as compact pills, per the original spec.

## Citation block as a first-class feature

A pre-formatted citation with a one-click copy is the SEO-link play's actual weapon. It should offer at least three formats: plain text, Markdown, HTML. This is what gets a journalist or Reddit poster to actually link us instead of paraphrasing. Treat it as load-bearing, not chrome.

## CSV license

Proposing CC-BY 4.0 with attribution to Voltikka. This is the standard journalists and researchers expect; making it explicit lowers the friction for citation. Confirm with user before shipping.

## VAT

The methodology block must state whether the stored prices include the 25.5 % Finnish VAT. This is a citation-page non-negotiable. Read `ContractPriceStatisticsService` and the snapshot writer during build to confirm before drafting the methodology copy.

## SEO additions on this surface

- `Dataset` + `DataDownload` JSON-LD on the page (SearchEngine-recognized for stats pages).
- Canonical stays at `/sahkosopimus/tilastot` to preserve any link equity already accrued.
- Verify the route is included in `SitemapService`; add if missing.

## Open items (resolve during build)

1. VAT inclusion in stored snapshots: confirm in code, then write the methodology line.
2. CSV license: confirm CC-BY 4.0 with user before exposing the download.
3. Default lead-chart segments: tentative is `Pörssi yhteensä`, `12 kk määräaikainen`, `Toistaiseksi voimassa oleva`, `Joustosähkö`. Re-evaluate once the new data is plotted, in case one of the four is too sparse to plot at the current data window.

## Resolutions taken at build time (2026-04-29)

1. **VAT.** `SpotPriceHour` and `SpotPriceAverage` expose `price_with_tax` / `avg_price_with_tax` and the snapshot service feeds those into the `spot_total_energy_price` calculation. Contract price components are stored as the providers publish them, which on Voltikka's source is VAT-included. The page therefore states "Hinnat sisältävät arvonlisäveron 25,5 %" in the meta strip, methodology block, and CSV header.
2. **CSV license.** Shipped as **CC BY 4.0** with attribution to Voltikka. Header lines in the CSV state the license, source URL, and VAT inclusion. Footer of the page also surfaces the license alongside the cite block.
3. **Lead-chart segments.** Shipped with the proposed four: `spot` (rendered using the spot-total energy-price-derived `annual_cost` series), `fixed_term_12`, `open_ended`, `hybrid`. Coral on `spot` (index 0). Order is tracked in `$primarySegments` on the Livewire component.
4. **Default period.** Shipped as `weekly` (not `monthly`) since the current data window is short and weekly aggregation gives the chart visible signal. Period URL param defaults to "weekly" and is omitted from the URL when active (`Url(except: 'weekly')`).
5. **Default consumption.** Shipped as `5000` kWh/v. Param is `kulutus`, omitted when default.
6. **Chart library.** uPlot v1.6.32 added as a dependency (`laravel/package.json`). Total page JS budget after build: ~25.8 KB gzipped for the chart bundle, well under the 200 KB target.
7. **Citation copy block.** Implemented with three formats (`plain`, `markdown`, `html`) and an Alpine-driven copy button that falls back to `document.execCommand('copy')` when the Clipboard API is unavailable.
8. **Dataset JSON-LD.** Emitted via the existing `<x-schema-markup>` component; includes `creator`, `temporalCoverage` derived from the data window, `license`, and a single `DataDownload` distribution pointing at the CSV endpoint.
9. **End-label de-overlap.** Direct end-labels are drawn only when the chart container is ≥ 560 px wide; on narrower viewports the Blade-rendered legend strip below the chart takes over. End-labels are vertically de-overlapped with a 20 px minimum gap.
10. **Y-axis unit handling.** Per-tick unit suffixes were clipping at narrow widths. Switched to a single corner unit badge (top-left, `€/v` or `c/kWh`) with unit-free tick values. Cleaner and screenshot-safe.
