# Unify legacy exact-period pricing

**Priority:** P2

## Goal

Make all feature-off period calculations use one discount-aware legacy calculator.

## Scope

- Add an exact-period entry point to ContractPriceCalculator or a closely related legacy pricing service.
- Move discount timing, time-of-use, seasonal, Spot, and fee rules behind that entry point.
- Make BillComparisonService delegate instead of calculating raw rates itself.
- Align UntilDate inclusivity with the canonical timeline policy.

## Acceptance criteria

- Feature-off period and annual prices use consistent discount semantics.
- A mid-month UntilDate discount has correct partial coverage.
- BillComparisonService contains no duplicate component discount arithmetic.
- Regression tests cover General, time, seasonal, Spot, and monthly-fee discounts.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
