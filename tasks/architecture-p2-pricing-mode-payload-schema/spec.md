# Centralize pricing mode and payload schema

**Priority:** P2

## Goal

Make canonical state, reset-shift state, expected statistics basis, and shared calculated-cost payload version one explicit dependency.

## Scope

- Add an immutable PricingMode value object.
- Inject it into pricing consumers and cache-key builders.
- Require the reset estimator dependency and use disabled settings for hold-flat behavior.
- Create one shared calculated-cost payload schema version.

## Acceptance criteria

- Container and direct construction cannot silently use different reset behavior.
- Statistics basis and cache mode markers come from one value.
- All calculated-cost cache keys include one shared schema version.
- Service-specific cache wrappers can keep separate outer-payload versions.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
