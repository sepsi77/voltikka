# Add contract classification enums and invariants

**Priority:** P3

## Goal

Reduce uncontrolled string comparisons while preserving safe handling of unknown upstream values.

## Scope

- Add tolerant value enums for pricing model, contract type, and target group.
- Use existing MeteringType consistently at domain-service boundaries.
- Add database checks for stable values only after data cleanup.
- Add scalar projections for query-critical canonical facts where justified.

## Acceptance criteria

- Domain services do not repeat raw classification strings for supported values.
- Unknown upstream values have an explicit safe fallback.
- Invalid consumption ranges cannot be stored after migration.
- Database constraints are introduced only after existing data is verified.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
