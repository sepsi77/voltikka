# Decisions

- Added a focused `ArticleContractPriceComparisonChart` Livewire component instead of embedding the full `/sahkosopimus/tilastot` page. The article needs the lead comparison graph only, while the full statistics page contains much more editorial/statistical content.
- The article chart reads `contract_price_daily_statistics` annual-cost aggregates and averages daily median values into weekly buckets, matching the core semantics of `ContractPriceStatistics`.
- The chart uses the existing `resources/js/contract-price-statistics.js` `data-line-chart` renderer to avoid duplicating chart rendering logic.
- Added an overlay loading state to `ContractTypeComparison` for mode, consumption, and contract selector updates because those interactions can take several seconds while data is recalculated.
- Simplified the article statistics chart to a fixed weekly / 5 000 kWh view. Removed period and consumption controls so the graph reads as editorial evidence instead of another interactive tool.
- Moved the statistics graph near the beginning of `/sahkosopimus/kannattaako-porssisahko` and wrapped it in explanatory article copy before and after the chart.
- Clarified in the article and chart copy that the graph shows weekly median annual costs, not the cheapest contract and not an arithmetic average.
- Added a visible legend below the article chart using the same line styles as `resources/js/contract-price-statistics.js`.

# Checks

- `cd laravel && php -l app/Livewire/ArticleContractPriceComparisonChart.php`
- `cd laravel && ./vendor/bin/pint --dirty`
- `cd laravel && php artisan test tests/Feature/ContractPriceStatisticsPageTest.php`
- `cd laravel && php artisan route:list --path=sahkosopimus/kannattaako-porssisahko`
- `cd laravel && php artisan view:cache && php artisan view:clear`
