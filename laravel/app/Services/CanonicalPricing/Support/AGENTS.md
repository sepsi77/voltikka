# Annual timeline and consumption support

Read `../AGENTS.md` for the pricing policy.

`EnergyRuleOfferTerms::build(CanonicalContractData $data, EnergyRulePlan $plan, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array` returns `list<OfferTermData>` for an admitted plan. It uses resolved energy-rule spans and the original governing fee timeline. It does not bill, select projections or recompute savings. Unavailable normal comparison gives no terms. Only genuine fixed/formula reductions and supported monthly-fee reductions qualify. Explicit normal fee metadata is primary; an independently Introductory fee can use its exact typed Normal/Continuation fee. Unknown, duplicate or discontinuous changed fee terms suppress all offer copy rather than publish a partial claim; ordinary guarantees, equal metadata and zero operators do not. A positive percentage remains genuine at normal zero. Unsupported changed fee components suppress the whole offer rather than publish a partial claim. Real-window dates clip term descriptions, and duplicate identical guarantees do not duplicate copy.

- `PhaseTimelineBuilder` uses no-overflow anniversaries for after-months dates, the annual end, and all 12 display-bin boundaries. It also keeps calendar-month and known phase boundaries.
- `WindowSegment::monthFraction()` is the factual calendar-month fraction. Exact-period pricing and package allowances use it.
- `annualMonthFraction()` normalizes the annual usage fraction of each calendar month. This conserves the annual profile when February occurs at both ends of a leap-crossing window. It does not alter exact-period fractions.
- `billingMonthFraction()` prorates ordinary annual fees within the no-overflow contract month. Thus N complete contract months have N monthly fees. A split segment must retain both its annual scale and billing-month day count.
- `MonthlyUsageProfileBuilder` uses the same default flat consumer profile for General, Time, Season, and Spot. Explicit heating and cooling keep their monthly shape. Only then are tariff buckets split. The old seasonal factor method remains for legacy callers; the shared profile does not use it.
- A phase boundary with unchanged prices must not alter the 12 monthly output bins. Do not restore the old elapsed-month-at-calendar-slice-start grouping.
