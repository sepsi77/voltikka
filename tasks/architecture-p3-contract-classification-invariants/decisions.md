# Decisions

## Initial decisions

- Use tolerant fromSource or tryFrom behavior at import boundaries.
- Do not add strict Eloquent enum casts before unknown-value handling exists.
- Add database constraints incrementally.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current behavior

- `ElectricityContract` stores pricing model, contract type, target group, and metering as untyped strings. It has no strict enum casts.
- `MeteringType::fromString()` accepts `Seasonal` but maps every unknown value to `General`.
- `ContractImporter` copies upstream classification strings to relational columns. Immutable snapshots preserve the source payload.
- `ContractInterpretationPublisher` and multiple domain services repeat supported classification strings.
- The current local production-data snapshot contains only supported classification values and has no negative or inverted consumption ranges.
- The focused baseline passes: 187 tests and 687 assertions.

## Implementation design

- Add backed tolerant enums for pricing model, contract type, and target group. Keep an explicit `Unknown` case and normalize verified source aliases at import boundaries.
- Extend `MeteringType` with a tolerant nullable parser. Keep `fromString()` for the existing explicit General fallback where required.
- Do not add strict Eloquent enum casts. Add typed model accessors while keeping SQL and public wire formats scalar.
- Normalize new classification values during relational import while retaining exact upstream values in immutable snapshots.
- Derive interpretation publication allowlists and high-value domain comparisons from enums instead of repeated literals.
- Do not add canonical scalar projection columns without a measured query need.
- Add a preflighted database invariant for non-negative, ordered consumption limits. Use a MySQL check constraint and equivalent SQLite insert/update triggers; do not rebuild the SQLite contracts table.

## Implementation result

- Added tolerant `PricingModel`, `ContractType`, and `TargetGroup` backed enums with explicit `Unknown` cases and verified source aliases. `MeteringType` now also has a nullable tolerant source parser while its old General fallback remains available.
- `ElectricityContract` exposes typed classification accessors without changing scalar storage, SQL columns, Eloquent casts, cache payloads, or public wire formats.
- Relational import normalizes three classification fields and retains the exact upstream values in immutable source snapshots. Interpretation publication derives its allowlists from enums and rejects unknown values.
- High-value pricing, card, statistics, ranking, replacement, retail-premium, bill-comparison, listing, history, local-contract, and weekly-offer services use typed in-memory decisions or enum scalar values in SQL. Exact raw comparisons remain in replacement and historical-ancestor matching so two different unknown source values cannot become equivalent.
- The legacy low-rate Spot heuristic remains available only when the pricing model is absent or blank. An explicit unsupported model cannot become Spot from its numeric rate.
- Migration `2026_07_31_000001` preflights existing rows, adds an enforced MySQL check, and uses equivalent SQLite insert/update triggers without rebuilding the table.
- The current local production-data snapshot has 3,839 contracts, only supported classification combinations, and zero invalid consumption ranges. Railway production MySQL uses `mysql:9.4`, which enforces check constraints. These were read-only checks; no production state changed.
- The focused classification regression suite passes: 372 tests and 1,509 assertions. The fetch-command regression suite also passes: 32 tests and 150 assertions. Pint passes after formatting all classification files.
- The full Laravel suite at the classification checkpoint ran 1,809 tests. After updating the one fetch assertion to the intentional `Fixed` → `FixedTerm` normalization, 1,807 pass; the remaining two failures are the same unrelated duplicate monthly Spot-average setup and strict-float detail assertion.
- Classification/import/publication/storage guardrails are documented in `laravel/app/Services/AGENTS.md`, the ContractImport and ContractInterpretation context files, and `laravel/database/AGENTS.md`.
