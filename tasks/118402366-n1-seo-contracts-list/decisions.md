# Decisions

- The repeated `municipalities where slug = ? limit 1` queries came from `SeoContractsList` reading city data in several accessors during one render. The old request-scoped cache only cached found municipalities; a missing slug left `$municipality` as `null`, so every city-data call queried again.
- Added an explicit loaded flag and loaded slug so both found and not-found municipality lookups are memoized per component instance.
- Added a small city-data array cache and made local-contract/view-data paths use `getMunicipality()` instead of directly reading the protected property, keeping one source for the lookup.
- Added a feature test asserting an unknown city page performs only one slug lookup.
