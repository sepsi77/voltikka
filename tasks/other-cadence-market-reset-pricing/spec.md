# Other-cadence market-reset pricing

## Problem

Active contracts can have a validated recurring market-reset schedule with `cadence = other`, for example Lumme Energia Perussähkö, whose price is reviewed 2–4 times per year. The pricing category correctly calls these contracts market-price contracts, but canonical annual pricing does not treat `other` as an active reset. It excludes the contract as `excluded_unknown_future` even when the current energy price and monthly fee are complete.

## Requirements

- Treat a validated present `other` recurring schedule as a legitimate market reset for annual comparability and integrity handling.
- Keep the result as an estimate, not an exact contractual annual price.
- Use the quarterly reset proxy for forward-curve annualisation because these products reset several times per year but do not publish exact calendar periods.
- Keep genuinely incomplete pricing excluded.
- Add regression tests for list inclusion and the quarterly proxy.
- Update nearby context documentation.
