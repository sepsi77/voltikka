# Add shared contract test fixtures

**Priority:** P3

## Goal

Make tests create consistent production-like contracts and canonical pricing states.

## Scope

- Add ElectricityContractFactory with common contract states.
- Add reusable canonical pricing fixture builders.
- Replace duplicated createContract helpers in high-value test groups first.
- Centralize Finnish slug generation and add shared contract tests if included in the same change set.

## Acceptance criteria

- Listing, pricing, API, and detail tests use consistent contract defaults.
- Factories provide household, Spot, fixed-term, Hybrid, reset, package, and canonical-only states.
- Legacy or intentionally invalid states require explicit factory states.
- Shared slug behavior has one table-driven test if slug consolidation is performed.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
