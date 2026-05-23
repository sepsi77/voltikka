# AGENTS.md

Local-only research scripts for the fixed-term contract price forecasting plan.

Scope:
- Keep this subtree outside production Laravel code: no migrations, Eloquent models, app services, routes, or scheduled commands here.
- Scripts may read the local SQLite database and write CSV/Markdown diagnostics under `outputs/`.
- Outputs are exploratory artifacts, not product forecasts, and are ignored by git because they are derived from the local production-data sync.
- Run Python through `uv`; see `README.md` for the current command.

Current files:
- `README.md` — local run instructions.
- `simple_fixed_term_forecast.py` — `uv`/PEP 723 script that builds fixed-term retail target series, FI futures-implied hedge costs, spot risk features, an EWMA premium/gap backtest, and a latest median-price direction outlook.

Important modeling conventions:
- Retail targets come from `contract_price_daily_statistics` where `metric_key = 'energy_price'` and segments are `fixed_term_6`, `fixed_term_12`, `fixed_term_24`.
- Futures are aligned with `max(trade_date) < retail stat_date` to avoid same-day settlement leakage.
- Futures settlement prices are converted from EUR/MWh to c/kWh including VAT via `settlement_price / 10 * 1.255`.
- Delivery windows start at the next full calendar month after the observation date.
- The MVP model is intentionally simple: EWMA retail premium plus conservative gap closure. Treat results as diagnostics until there is materially more futures history.
- Consumer direction labels use the median index only by default. With the current script settings, expected 30-day moves smaller than 0.15 c/kWh are `slightly_*`/neutral rather than advice to lock or wait.
