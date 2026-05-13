# Decisions

- Made ConsumptionCalculator numeric public properties accept int|string|null so Livewire blank input payloads can hydrate instead of throwing before calculation.
- Added safe raw/int/string helper methods based on get_object_vars() to tolerate missing/uninitialized public properties from stale or partial Livewire snapshots.
- Replaced enum from() calls with tryFrom() plus defaults so blank/invalid select state falls back instead of raising ValueError.
- Added regression tests for blank numeric values and blank/invalid select values.
- Blank or too-small numeric inputs are written back to the component so the rendered form displays the enforced minimum: 20 m², 1 resident, and 0 for optional extras.
