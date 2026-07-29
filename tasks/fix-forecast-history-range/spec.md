# Fix forecast history range

Investigate and correct the "Mediaanihinta viime kuukausina" section on `/sahkosopimus/sahkon-hintaennuste`.

The public page currently says that data was collected only from 27.7.2026 to 29.7.2026 and shows three measurements. Voltikka has collected the underlying fixed-term median price history for longer.

## Requirements

- Use the correct stored historical price source for the median-price history section.
- Do not weaken the forecast page rules for current model version and current canonical pricing basis.
- Keep current canonical prices separate from historical observed seller evidence.
- Add or update focused tests.
- Update relevant context documentation if behavior changes.

## Outcome

- The current forecast and teaser still use only public-display-eligible forecast rows.
- The offered-price section now reads the complete fixed-term 6/12/24-month `energy_price` median timeline from `contract_price_daily_statistics`.
- Older points keep `observed_seller_data` provenance. Canonical daily calculations continue the series after rollout, so the newest value does not freeze on the last observed-basis date.
- Forecast-run dates, model versions, and futures coverage do not enter or truncate the history. If two bases exist for one segment and date, the canonical row supplies the one public point.
