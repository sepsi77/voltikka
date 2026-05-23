# Fixed-term price forecasting exploration

Local-only research scripts for the forecasting plan in `../price-forecasting-plan.md`.

Run with `uv` from the Laravel directory:

```bash
cd laravel
uv run data-investigation/price-forecasting/simple_fixed_term_forecast.py \
  --from-date 2026-01-01 \
  --to-date 2026-05-23
```

The script reads the local SQLite database and writes exploratory CSV/Markdown files to `outputs/`, including a latest median-price direction outlook (`simple_fixed_term_outlook.csv`) and a Markdown report. Generated outputs are intentionally ignored by git because they are derived from the local production-data sync.
