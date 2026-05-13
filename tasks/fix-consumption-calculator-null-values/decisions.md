# Decisions

- Made ConsumptionCalculator numeric public properties accept int|string|null so Livewire blank input payloads can hydrate instead of throwing before calculation.
- Added safe raw/int/string helper methods based on get_object_vars() to tolerate missing/uninitialized public properties from stale or partial Livewire snapshots.
- Replaced enum from() calls with tryFrom() plus defaults so blank/invalid select state falls back instead of raising ValueError.
- Added regression tests for blank numeric values and blank/invalid select values.
