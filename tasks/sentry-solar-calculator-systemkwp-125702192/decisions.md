# Decisions

- `systemKwp` is now `float|string|null` tolerant. The Sentry payload showed Mobile Safari sending `systemKwp: ""`; Livewire passed `null` to `updatedSystemKwp` and the previous non-nullable `float` property could be unset before the hook read `$this->systemKwp`, causing `PropertyNotFoundException`.
- `updatedSystemKwp($value)` normalizes from the hook argument, clamps blank/non-numeric input to `0.5` kWp, and keeps the existing range notice.
- Added `normalizedSystemKwp()` and use it for PVGIS requests, static example scaling, and analytics so blank/stale snapshots cannot leak into calculations.
- Added a Livewire regression test for setting `systemKwp` to an empty string.

## Follow-up: heat pump calculator hardening

- Applied the same blank-number-input hardening to `HeatPumpCalculator` without creating a separate task, per user request.
- Non-nullable numeric Livewire properties were changed to accept temporary `string|null` states, and `normalizeNumericInputs()` now runs before validation/DTO construction.
- Blank model/advanced numeric inputs normalize to safe minimum/default values; blank bill-based consumption inputs normalize to `null` and continue to show the existing validation message instead of throwing.
- Investment array values are normalized too because advanced settings bind directly to `investments.{key}`.
