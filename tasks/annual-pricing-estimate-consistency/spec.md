# Annual pricing estimate consistency

## Goal
Keep annual estimates useful when future rates or consumption effects are unknown. Known prices must stay exact. Unknown periods must use an explicit reasonable assumption, never zero costs or an expired introductory price when a later price is disclosed.

## Scope
- Correct annual phase coverage, Hybrid continuation, one-time charges, month boundaries and monthly output; preserve ordinary estimating rather than add broad exclusion gates.
- Use one customer consumption distribution independently of tariff classification.
- Correct Spot UTC/local-hour handling and give incomplete shape evidence a useful, labelled fallback rather than reject usable futures.
- Reconcile detailed API consumption and invalidate date-dependent annual caches daily.
- Resolve supplier-price episode chronology correctly.
- Align displayed annual equivalent rates with billed totals and correct VAT basis.
- Add focused regression tests, run the full suite, and document the estimation policy.

## Constraints
No production changes, deployment, database refresh, or historical-statistics rewrite. Do not add broad purity requirements or a new forecasting framework. Retain explicit warnings and distinguish exact prices, estimates, and Hybrid base-only comparisons. Do not implement speculative validator redesign or unrelated API sorting changes in this task.

## Acceptance
Previously reproduced pricing defects have regression tests. Unknown future prices remain estimable through documented assumptions. Known disclosed phases always take priority. Consumption totals and displayed equivalents reconcile with calculations. Relevant tests, full PHP suite, asset build, and git diff checks pass, or remaining failures are explicitly recorded.
