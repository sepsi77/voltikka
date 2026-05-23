# Spot price external predictions MVP

Import Finnish spot price predictions from the public `vividfog/nordpool-predict-fi` deploy feed and show them on Voltikka's spot price page as clearly labelled third-party forecasts.

Requirements:
- Do not build Voltikka's own model yet.
- Store imported forecast points with source attribution and model/feed metadata where practical.
- Official ENTSO-E prices remain the source of truth for actual/today/tomorrow prices.
- Forecast data must be visually and textually separated from official spot prices.
- Cite the source near any displayed third-party forecast data: `nordpool-predict-fi` by vividfog, GitHub URL.
- Add a manual Artisan import command; scheduling can be added if the import is safe and lightweight.
- Keep the implementation easy to replace with Voltikka-owned forecasts later.
