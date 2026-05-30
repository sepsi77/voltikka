# Decisions

- 2026-05-30: Keep `sähkönkulutuslaskuri` as the primary page concept but expand the page with a real price-estimate calculator using Voltikka statistics so `sähkön hinta laskuri` intent is earned by functionality rather than keyword stuffing.
- 2026-05-30: Contract-type estimates use latest `contract_price_daily_statistics`. Non-spot rows calculate annual cost as `kWh × c/kWh / 100 + monthly fee × 12`. Spot rows prefer interpolated `annual_cost` statistics because those use the trailing-365-day spot average and are more comparable with fixed-price contracts.
- 2026-05-30: The public title is `Sähkönkulutuslaskuri – laske kulutus ja sähkön hinta | Voltikka`.
- 2026-05-30: Changed the living-area field from live debounce updates to blur updates. Immediate normalization of values below 20 made it hard to type numbers like `120` because the first `1` was quickly replaced with `20`; blur still enforces the minimum after editing.
