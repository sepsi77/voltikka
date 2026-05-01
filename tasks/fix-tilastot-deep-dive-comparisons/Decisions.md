# Decisions

## Comparison basis

Use `annual_cost` at the selected `kulutus` level for non-spot deep-dive quotables comparing a segment against pörssisähkö.

Reason: `spot_total_energy_price` is current/day-period spot average plus margin, while fixed-term/open-ended/hybrid energy prices are offered forward contract prices. Comparing those directly is mathematically valid but misleading as a contract-type comparison. Spot `annual_cost` already uses trailing-365-day spot average plus margin, matching the intended "what a spot customer would practically pay over a year" comparison.

The deep-dive c/kWh chart/stat strip remains unchanged because it describes current energy price trends, not the relative yearly cost claim.

## Implementation

`ContractPriceStatistics::getDeepDivePayloadsProperty()` now loads the latest `annual_cost` median for spot and for each deep-dive segment at the selected `kulutus` level. `buildQuotableForSegment()` keeps spot's own quote as a c/kWh trend since data start, but formats non-spot comparisons as annual-cost claims, e.g. “5 000 kWh vuosikulutuksella … (352 €/v vs. 348 €/v)”.

Regression coverage: `ContractPriceStatisticsPageTest::test_deep_dive_spot_comparisons_use_annual_cost_not_current_cents_per_kwh` asserts the page shows `€/v vs.` and no longer emits `c/kWh vs.` comparisons.

## Lead chart caption consistency

The lead chart caption must also use the same `annual_cost` series as the chart. The previous caption came from `callouts`, which are c/kWh spot/current-price metrics, so it could say “pörssisähkö has gotten 70% cheaper” while the annual-cost chart line rose slightly. `ContractPriceStatistics::getCaptionProperty()` now reads `leadChartPayload` directly and describes the annual-cost trend.

Regression coverage: `ContractPriceStatisticsPageTest::test_lead_chart_caption_uses_annual_cost_trend_not_current_spot_cents` seeds a mismatch where current spot c/kWh falls but spot annual cost rises, and asserts the caption follows the annual-cost trend.

## Period-switch loading state

The `Kuukausi / Viikko / Päivä` control now disables period buttons while `setPeriod` is in flight, shows an inline “Päivitetään jaksoa” status, overlays the lead chart with a small loading pill, and dims/labels the deep-dive section while it rerenders. This prevents the UI from feeling stuck while Livewire rebuilds chart payloads.

The `Vuosikulutus` control follows the same pattern for `setConsumption`: buttons are disabled while the request is in flight, an inline “Päivitetään kulutusta” status is shown, and shared chart/deep-dive overlays also target `setConsumption`.

## Chart tooltip readability

The line-chart tooltip now mirrors the actual line style for each series using a short SVG swatch with stroke color, stroke width, and dash pattern. Rows are sorted by y-value at the hovered point, matching the vertical order of the lines on the chart. This makes it easier to connect each tooltip value to its corresponding graph line.

## Sparse table rows

The segment price table and consumption range table now omit rows where the latest relevant statistic has fewer than 10 contracts. This keeps one-off or very sparse categories from looking as robust as broader market segments. Explanatory table copy mentions the cutoff.

Contract counts can legitimately differ between the segment price table and consumption range table: the former counts contracts with an energy-price observation, while the latter counts contracts whose annual cost can be calculated at the selected consumption level. A contract may be excluded from the latter if its consumption range does not include the selected kWh level or an annual-cost input is missing. The consumption table copy explains this.
