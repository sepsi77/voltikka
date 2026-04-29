# Contract price statistics page — redesign

Redesigns `/sahkosopimus/tilastot` (`App\Livewire\ContractPriceStatistics`) from a generic SaaS-style dashboard (dark hero, three hero-metric cards, three identical stacked tables, coral-gradient trend bars) into a citation-grade editorial data page.

The underlying data model (`contract_price_snapshots`, `contract_price_daily_statistics`, `ContractPriceStatisticsService`) and artisan commands stay as-is. This task only changes the page UI, the chart layer, the SEO surface, and a few user-facing copy strings.

## Why

The page exists as an SEO link-acquisition play. Target audience is journalists, Reddit/HS commenters, and data-curious laypeople; target queries include `sähkön hinta tilastot`. Voltikka's USP versus the many spot-price sites is that these numbers come from real contract data, so the page must communicate "what Finns actually pay" and present numbers in a form that is screenshot-able, quotable, and easy to cite.

The current page does the opposite: marketing-styled chrome, no clear editorial answer, no citation affordance, no real chart, and several patterns explicitly banned by `DESIGN.md` (hero-metric template, coral-gradient bars, dark slate hero used as default theme, identical card grids).

## Scope

- Production-ready rebuild of the Livewire view (`resources/views/livewire/contract-price-statistics.blade.php`) and any supporting Livewire properties needed for the new layout.
- Add a real chart library: **uPlot** (40 KB, MIT, no gradient defaults). Wire via Vite. Inline SVG is used for sparklines and other small visuals.
- New `Dataset` + `DataDownload` JSON-LD on the page.
- CSV download endpoint (license: CC-BY 4.0 with attribution to Voltikka, methodology link, includes VAT note).
- Copy-paste citation block (plain text, Markdown, HTML variants).
- Deep-linkable query params: `?kulutus=`, `?jakso=`.
- Honest empty / sparse / error states. Public page must not leak `php artisan ...` instructions (current empty state does).
- Mobile is a real target (search traffic is mobile-heavy).
- Remove the dark slate-950 hero on this page. Page lives on light surfaces, in keeping with `DESIGN.md`'s rule that the dark hero is "a focused moment, not a default theme."

Out of scope:
- Changes to the snapshot / daily-statistic data model.
- Changes to artisan commands (`contracts:calculate-price-statistics`, `contracts:backfill-price-statistics`).
- New segments or new metrics.
- Authentication. Page stays public.

## Audience and primary action

- Audience: data-curious users, journalists, Reddit/social posters; SEO traffic.
- Primary action: read one chart and one editorial sentence and walk away with a quotable claim. Everything else (segment table, consumption breakdown, methodology, CSV) serves the long tail of the same audience after they're hooked.

## Design direction

- **Color strategy: Restrained.** Slate substrate, coral as the accent for the primary line in the lead chart and the live-data dot only. ≤5% coral on this surface (intentionally below `DESIGN.md`'s 10% ceiling — citation pages should look neutral).
- **Light theme.** No dark slate-950 hero. Editorial-archive feel.
- **Anchor references:** Statistics Finland (StatFin), FT/NYT data-journalism explainers, Trading Economics country pages.
- **Typography:** Plus Jakarta Sans across the system, per `DESIGN.md`. `tabular-nums` on every number on the page.
- **No em dashes** anywhere in copy.

## Layout (top → bottom)

1. **Editorial header strip.** H1 + 1-line dek + meta row (`Aineisto: <from>–<to>` · `Päivitetty <date>` · `<n> sopimusta` · `[Lataa CSV]` · `[Viittaa tähän]`). No pill badge, no dark hero.
2. **Lead chart.** Single line chart, four segments overlaid (`Pörssi yhteensä`, `12 kk määräaikainen`, `Toistaiseksi voimassa oleva`, `Joustosähkö`), 5000 kWh/v annual cost on the y-axis, time on the x-axis. Coral on the editorially-leading line (default: `Pörssi yhteensä`); other lines slate-400/500 with direct end-labels (no legend below). Editorial caption beneath the chart, generated from data (e.g. *"Pörssipohjaiset sopimukset ovat halventuneet 8 % tammikuusta. 12 kk määräaikaiset ovat kallistuneet 3 %."*). Period pills (`Kuukausi · Viikko · Päivä`) sit next to the chart's heading and scope the chart + sparklines.
3. **Three editorial callouts.** Plain-text leads, no big numbers, no card strokes. Format: *"Pörssisähkö nyt 6,40 c/kWh, +12 % tammikuusta."* Three columns desktop, stacked mobile.
4. **Period-over-period segment table — "Hinnat sopimustyypeittäin".** One wide table, ~12 rows. Columns: `Sopimustyyppi · Sopimuksia · Energiahinta nyt · Δ 30 pv · Δ tammikuusta · Sparkline (60×20 inline SVG)`. Tabular nums. Featured (lead-chart) row bolded but no fill.
5. **Consumption section — "Vuosikustannus kulutuksen mukaan".** Pill switcher (`2 000 · 5 000 · 18 000` kWh, default 5000) above one comparison table showing min / p20 / avg / p80 across segments at the selected level. Unselected levels remain crawlable via `?kulutus=` deep links and are linked from the H2's "Katso myös" line.
6. **Spot deep-dive.** Two compact charts side by side: spot margin distribution and spot total energy price trend. This is where Voltikka's USP lands hardest.
7. **Methodology + cite block.** Two columns: left, plain-language methodology (source, date range, contract count, VAT inclusion, why some days may be missing); right, copy-paste citation block (plain / Markdown / HTML toggle) and CSV download with license note.

## Key states

- **Default (data present):** as above.
- **Empty (`contract_price_daily_statistics` empty):** editorial header renders; chart area shows *"Tilastoja ei ole vielä saatavilla. Aineiston keruu on käynnissä."* No `php artisan` leakage.
- **Sparse data (current ~4 months):** chart x-axis honestly shows whatever range exists; meta row says so. Dek mentions *"Aineisto kasvaa kuukausittain, näytämme sen mitä on kerätty."* No zero-padding.
- **Loading:** skeleton on chart only; tables render server-side. No spinners.
- **Per-row missing data:** row renders with `–`, sparkline shows the gap, no row removal.
- **Period switch (`viikko` / `päivä`):** chart and sparklines re-render via Livewire; tables refresh; no full reload.
- **Print / screenshot:** lead chart + caption + meta row must fit cleanly in a 1200 × 700 screenshot.

## Interaction model

- Period pills: instant Livewire update, ≤ 200 ms.
- Consumption pills: same.
- Chart hover: single crosshair, all four lines' values in a slate tooltip with tabular nums. No data-point dots until hover.
- Sparkline hover: small popover with the data-window range, current, low, high.
- "Viittaa tähän": copies pre-formatted citation, shows inline *"Kopioitu"* confirmation.
- "Lataa CSV": straight download, no modal.
- Deep-linkable: `?kulutus=…&jakso=…` are real query params.

## Content requirements

- **H1:** *Sähkön hintatilastot — mitä suomalaiset oikeasti maksavat*
- **Dek:** *Voltikka seuraa sähkösopimusten todellista hintakehitystä: pörssi-, määräaikaisia, joustosähkö- ja toistaiseksi voimassa olevia sopimuksia.*
- **Lead chart caption:** dynamic, two sentences, plain Finnish, no jargon.
- **Section H2s:** *Hinnat sopimustyypeittäin*, *Vuosikustannus kulutuksen mukaan*, *Pörssisähkön marginaalit ja kokonaishinta*, *Mistä luvut tulevat*.
- **Methodology:** 4–6 short sentences. Source, date range, contract count, what counts as active, VAT note, why some days may be missing.
- **Citation (default plain text):** *Lähde: Voltikka — Sähkön hintatilastot, päivitetty <date>. https://voltikka.fi/sahkosopimus/tilastot*

## Constraints

- Tech: Laravel 11 + Livewire 3 + Tailwind 3 + Vite 6.
- Chart library: uPlot (add as npm dep). No coral gradient fills, no shadow defaults.
- Mobile: critical. All interactions and the lead chart must be usable at 360 px width.
- Accessibility: WCAG 2.1 AA. Chart must be keyboard-navigable and have an accessible text fallback (visually-hidden table of the same data).
- VAT: confirm whether stored prices include the 25.5% VAT. Methodology block must state this either way.
- Performance: page should ship under 200 KB JS gzipped including uPlot.
- SEO: keep canonical at `/sahkosopimus/tilastot`. Add `Dataset` + `DataDownload` JSON-LD. Update sitemap if not already included.

## Anti-goals

- Anything that reads as marketing or affiliate (no urgency, no exaggerated savings, no aggressive CTAs, no commission framing).
- The hero-metric template, identical card grids, coral-gradient trend bars, dark slate hero as default theme, glassmorphism on light surfaces, gradient text. All explicitly banned by `DESIGN.md`.
- Hiding metrics behind dropdowns. Per `Decisions.md` of the original task, all main data must remain visible on the page; only time aggregation is controlled by compact buttons.
- Pretending the data window is longer than it is. The 4-month limitation must be stated honestly.

## Open questions resolved by user (2026-04-29)

- Audience: data-curious + journalists, not contract buyers. SEO link play.
- Primary message: trends, including period-over-period.
- Time horizon: limited to data window (Jan 2026 onwards), grows over time.
- Chart library: free choice, JS deps OK. → uPlot.

## Open questions still to resolve during build

- VAT inclusion in stored prices (read snapshot service to confirm; methodology block depends on this).
- CSV license: proposing CC-BY 4.0 with attribution to Voltikka. Confirm before shipping.
- Default lead-chart segments: proposing `Pörssi yhteensä`, `12 kk määräaikainen`, `Toistaiseksi voimassa oleva`, `Joustosähkö`. Confirm.
