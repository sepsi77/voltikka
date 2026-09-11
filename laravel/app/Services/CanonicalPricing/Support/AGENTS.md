# Annual timeline and consumption support

Read `../AGENTS.md` for the pricing policy.

- `PhaseTimelineBuilder` uses no-overflow anniversaries for after-months dates, the annual end, and all 12 display-bin boundaries. It also keeps calendar-month and known phase boundaries.
- `WindowSegment::monthFraction()` is the factual calendar-month fraction. Exact-period pricing and package allowances use it.
- `annualMonthFraction()` normalizes the annual usage fraction of each calendar month. This conserves the annual profile when February occurs at both ends of a leap-crossing window. It does not alter exact-period fractions.
- `billingMonthFraction()` prorates ordinary annual fees within the no-overflow contract month. Thus N complete contract months have N monthly fees. A split segment must retain both its annual scale and billing-month day count.
- `MonthlyUsageProfileBuilder` uses the same default flat consumer profile for General, Time, Season, and Spot. Explicit heating and cooling keep their monthly shape. Only then are tariff buckets split. The old seasonal factor method remains for legacy callers; the shared profile does not use it.
- A phase boundary with unchanged prices must not alter the 12 monthly output bins. Do not restore the old elapsed-month-at-calendar-slice-start grouping.
