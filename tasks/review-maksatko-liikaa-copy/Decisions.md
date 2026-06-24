# Decisions

- Preserve page behavior and only adjust wording/grammar unless a source issue clearly requires otherwise.
- Reworded the bill total field from generic “Maksettu yhteensä” to “Sähkösopimuksen hinta” so it matches the feature rule: enter only the electricity contract/energy portion, not electricity transfer.
- Kept savings labels as estimates where annualized values are shown, but removed awkward wording such as “top 3:ssa” and “€/kWh-luku”.
- Hide the user's own c/kWh value from the ranking table. It is only an implied bill average (`bill total / kWh`), not the user's actual energy price, and showing it beside market-contract c/kWh can mislead users.
