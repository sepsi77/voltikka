# Decisions

## Initial decisions

- Migrate tests incrementally. Do not rewrite the complete test suite at once.
- Factories must not hide domain facts that affect pricing or eligibility.
- Keep intentionally malformed fixtures explicit and local to their tests.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current behavior

- `ElectricityContract` has no factory integration. `UserFactory` is the only application factory.
- Listing, canonical-pricing, API, detail, bill-comparison, and statistics tests repeat contract defaults and canonical JSON builders.
- The focused baseline ran 130 tests: 129 passed. The one failure is the existing strict-float mismatch in `ContractDetailPresenterTest` (`30.00000000000003` versus `30.0`), which is independent of fixture creation.

## Implementation design

- Add `HasFactory` to `ElectricityContract` and one `ElectricityContractFactory` with valid, production-like household fixed canonical pricing as its default.
- Require an explicit existing company through `forCompany()`; the factory must not hide company creation or foreign-key facts.
- Keep activation, relational prices, legacy mode, consumption limits, and malformed canonical data explicit.
- Add focused canonical fixture builders for valid fixed, Spot, Hybrid, reset, package, and canonical-only states. Do not add permissive deep merging or malformed builders.
- Characterize each factory state and prove that valid canonical states parse before migrating tests.
- Migrate the high-value listing, pricing, API, and detail fixture helpers incrementally. Keep intentionally malformed literals local.
- Defer Finnish slug consolidation. It is not needed to establish shared contract fixtures.

## Implementation result

- `ElectricityContract` now uses `HasFactory`.
- `ElectricityContractFactory` requires an explicit existing company and keeps activation, relational prices, legacy nulls, consumption limits, and invalid states explicit.
- Named states cover household, Spot, fixed-term, Hybrid, reset, package, canonical-only, and legacy contracts.
- `CanonicalPricingFixture` provides typed, parser-valid boundaries, components, phases, packages, schedules, effects, and scenario attributes. Malformed payloads stay local to the tests that need them.
- `ElectricityContractFactoryTest` characterizes factory defaults and every required state. `PricingBucketFilterTest`, `CanonicalPricingListingTest`, `ContractApiCanonicalPricingTest`, and `ContractDetailPresenterTest` now delegate their common setup to the shared fixtures.
- The focused fixture regression set ran 149 tests: 148 passed. The only failure is the pre-existing strict-float mismatch in `ContractDetailPresenterTest`.
- The full Laravel suite at this checkpoint ran 1,759 tests: 1,757 passed. Its two failures are the same unrelated current-tree failures: duplicate monthly Spot-average setup on 31 July and the strict-float detail assertion.
- Pint passes for the factory, fixture builder, and migrated tests. Factory guardrails are documented in `laravel/database/AGENTS.md`.
