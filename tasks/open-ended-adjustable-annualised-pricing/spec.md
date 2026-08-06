# Open-ended adjustable annualised pricing

## Problem

Open-ended `FixedPrice` contracts without a contractual price guarantee are currently costed by holding the current energy price flat for the full next 12 months. Canonical pricing treats a current phase with `ends: none` as fully covered and can classify the result as `comparable_exact`.

This creates seasonal snapshot bias. A supplier can change an open-ended price with notice, and observed contract histories show prices falling after winter and potentially rising again when wholesale electricity becomes more expensive. A summer price held flat for one year can therefore make these contracts look artificially cheap.

Vimpelin Voima Oy Sulaketariffi is the initial example. Its current price is 7.40 c/kWh, while Voltikka observed 9.40 c/kWh before the change effective 1 June 2026. At 5,000 kWh, the current flat-price calculation is €420.40/year.

## Goal

Give adjustable open-ended fixed-price contracts an honest, market-aware 12-month estimate while keeping the current published price separate and exact.

## Requirements

- Apply the new behavior consistently to eligible open-ended fixed-price contracts, not only to contracts with a known observed change. General, Time, and Season metering use the same model when their current canonical tariff is complete.
- Do not apply it to fixed-term contracts, Spot contracts, Hybrid contracts, disclosed recurring market-reset contracts, or contracts with a supported explicit price-guarantee horizon that covers the comparison window.
- Keep the current published energy price and monthly fee as current-price facts.
- Change the annual outcome from exact to an estimate for eligible contracts.
- Reuse the established FI forward-curve and fallback infrastructure where its semantics are valid, but do not misclassify these contracts as disclosed recurring market-reset products.
- Anchor the wholesale reference to the start of the current observed price episode when reliable evidence exists.
- Keep a typed estimate method and basis payload through calculation, caches, API, cards, contract detail, ranking, and statistics.
- Rank by the estimated 12-month total.
- Public copy must clearly separate the current price from the estimated 12-month equivalent and must not claim a contractual future price or a disclosed reset cadence.
- Do not add a deceptive-pricing warning only because the price is adjustable.
- Fail safely when market data or price-period evidence is missing. Record and explain the fallback.
- Add focused unit and feature tests for eligibility, arithmetic, fallbacks, cache/schema boundaries, API output, and public copy.
- Update the nearest `AGENTS.md` files and root architecture documentation for the final behavior.

## Initial delivery approach

Start with the smallest defensible implementation. Use the existing market-reference curve and shape-shift arithmetic through a separate adjustable-open-ended pricing path. Keep the model and public language explicit about its lower-confidence, inferred adjustment behavior. Do not add per-company calibration before the available observations support it.

## Acceptance examples

- Sulaketariffi no longer returns `comparable_exact` with `estimate_method = none` for its annual outcome.
- Q-Valo remains eligible when its current phase start is unknown. Parikkalan Valo Time and Season variants remain eligible and keep their exact current tariff rates.
- Its current receipt still shows 7.40 c/kWh and €4.20/month.
- Its annual estimate uses market-aware future prices when the required FI curve and price-episode reference are available.
- A guaranteed fixed-term contract remains unchanged.
- A disclosed monthly or quarterly market-reset contract continues to use the existing market-reset method.
