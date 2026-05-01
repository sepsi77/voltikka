# Decisions

- The slow page is an editorial article that embeds multiple data-heavy Livewire widgets: market trend chart, seasonality chart, win-rate chart, volatility chart, and the interactive contract type comparison calculator.
- Expensive article/widget data derived from precomputed market tables is cached with short TTLs (mostly 6 hours; monthly comparison spot averages 1 day). This keeps data fresh enough while avoiding repeated aggregate queries and hourly spot-price processing on every request.
- No contract prices are recalculated in the article chart components; they continue to read precomputed statistics rows.
- The pörssisähkö article comparison calculator uses `comparisonContext="spot_article"` so both tabs keep pörssisähkö as the anchor: pörssisähkö vs kiinteähintainen and pörssisähkö vs määräaikainen.
- Livewire lazy loading was removed from the article widgets because their chart JavaScript is pushed by the child component render. With lazy hydration, markup could appear without the pushed chart initializers running, leaving blank chart areas. Caching remains the primary performance improvement for these widgets.
