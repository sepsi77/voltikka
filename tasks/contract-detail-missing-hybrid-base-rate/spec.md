# Contract detail missing Hybrid base rate

## Problem

Production returns HTTP 500 for the inactive historical contract `/sahkosopimus/sopimus/fd2vhf-vare-oy-maaraaikainen-valkky-sahko-6kk`. Canonical pricing is enabled, but this historical Hybrid has no canonical calculation or canonical pricing. `CardReceiptLines` classifies the mechanism as consumption effect and passes a null base rate to `amount(float)`, causing a TypeError.

## Requirements

- A missing canonical base rate must be omitted, never formatted as zero and never passed to a non-null formatter.
- Keep the consumption-effect mechanism row when applicable.
- Preserve canonical-only current receipt behavior. Do not fall back to relational current prices.
- Add regression coverage for the exact missing-base-rate state and the contract detail HTTP response.
- Update nearby documentation.
- Do not deploy or mutate production without explicit confirmation.
