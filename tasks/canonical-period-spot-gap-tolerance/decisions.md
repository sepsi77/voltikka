# Decisions

- Production evidence on 2026-08-01 showed completed-month hourly gaps: the one-day-old local production snapshot has 743/744 May hours and 717/720 June hours. The strict canonical coverage check makes an entire Spot-dependent bill period unavailable because of these small gaps.
- A period with zero matching realized Spot observations remains unavailable. This preserves the fail-closed boundary when there is no factual basis.
- A partial period uses same-Helsinki-day observed arithmetic mean for each missing hour. If a whole day has no observations, it uses the requested period's observed arithmetic mean. This keeps the fallback local when possible and deterministic when it is not.
- The outcome assumptions must distinguish a fully observed period from one with filled gaps.
- `CanonicalContractPriceCalculator::calculatePeriod()` now resolves actual and normal-price rates before completing one shared Spot map. This prevents a partial gap required only by the normal-price pass from restoring the old strict failure.
- Unit coverage fixes the same-day mean, whole-missing-day period mean, normal-price pass, and zero-history boundary. A focused feature regression proves both `BillComparisonService` and the contract-detail module keep a one-gap Spot period available.
- Focused canonical period and bill-comparison regression passed 46 tests with 243 assertions. The full Laravel suite passed 1,859 tests with 6,607 assertions. Pint and `git diff --check` passed.
- An exact local acceptance check with the production snapshot priced the reported Sähkötytöt June 2026 bill (200 kWh) successfully at about €12.10 and recorded the gap-fill assumption.
