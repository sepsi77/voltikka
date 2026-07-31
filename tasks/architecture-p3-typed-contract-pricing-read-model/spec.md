# Add a typed contract-pricing read model

**Priority:** P3

## Goal

Keep calculated pricing typed between canonical or legacy calculation and presentation consumers.

## Scope

- Define a small ContractPricingViewData or ContractMetric DTO.
- Hydrate cached arrays into the DTO at one boundary.
- Use typed access in card, detail, company, ranking, weekly-offer, and API preparation services.
- Serialize to arrays only for cache and HTTP boundaries.

## Acceptance criteria

- Presenters do not depend on broad array keys with silent null fallbacks for required fields.
- Canonical-only, excluded, package, Hybrid, and short-term outcomes remain explicit.
- Cache and API payload shapes remain intentional and versioned.
- Tests fail when required pricing fields are missing or invalid.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
