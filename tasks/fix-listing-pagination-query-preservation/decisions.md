# Decisions

- Root cause: all listing result sets use manually constructed `LengthAwarePaginator` instances. Their options set only `path` and `pageName`, so generated anchor URLs contain only `page` and discard the URL-bound `consumption` and `hintatyyppi` state.
- Fix: pass one shared `paginationQueryParameters()` result into each paginated listing constructor, including normal SEO listings and bill mode.
- The helper includes non-default `consumption` and non-empty raw `hintatyyppi`. It keeps the comma-separated multi-select value intact. It omits default values so canonical unfiltered pagination stays `?page=N`.
- Regression tests reproduce the reported `/sahkosopimus?page=1&consumption=2000&hintatyyppi=kulutusvaikutus` state and cover a multi-select `hintatyyppi=kulutusvaikutus,kiintea` value.
- No production operation was used.

## Follow-up: consumption preset hydration

- Root cause: `consumption` is URL-bound, but `selectedPreset` and
  `directConsumption` are separate component state. Livewire hydrates the URL
  value before `mount()`, and no mount logic reconciled those fields, so the
  default `large_apartment` selection could remain active while calculations used
  another consumption value.
- Fix: initial mount now reconciles only an explicitly present `consumption`
  query. An exact value selects the matching current preset; a custom value clears
  the preset and fills the direct input. It does not run during later Livewire
  interactions.
- SEO route defaults are applied only when the explicit query is absent. This
  preserves housing, consumption-level, and business defaults while allowing a
  visitor's URL value to win when both are present.
- Regression tests cover the reported 10,000 kWh URL state, a custom 7,500 kWh
  query value, and an explicit query overriding a housing-page default. Existing
  tests cover preset/direct interactions and route defaults without a query.
