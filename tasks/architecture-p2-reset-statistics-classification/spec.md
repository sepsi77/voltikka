# Consolidate reset and statistics classification

**Priority:** P2

## Goal

Prevent card categories, forward statistics, and public comparisons from classifying the same market-reset contract differently.

## Scope

- Define a basis-aware reset classification service or rule set.
- Use canonical cadence for forward canonical statistics.
- Keep text-based observed classification for historical rows.
- Align company statistics and price-history overlays with the shared segment rule.

## Acceptance criteria

- A forward canonical reset contract has a statistics segment compatible with its card category.
- Historical rows do not receive current canonical classification retroactively.
- Quarterly and other reset patterns are not copied across components.
- Tests cover monthly, quarterly, seasonal, other, and historical observed cases.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
