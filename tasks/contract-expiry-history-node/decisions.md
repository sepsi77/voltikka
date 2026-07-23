# Decisions

- Use the rendered contract's maximum `price_components.price_date` as the last date Voltikka observed it on sale.
- Label this explicitly as a last observation, not as an expiry/removal date, because `active_contracts` is a current snapshot and no availability transition timestamp is persisted.
- Add a synthetic newest status node only for the rendered inactive contract; do not add nodes for every inactive predecessor shown on an active replacement page.
- Keep the version count as a count of actual contract versions, excluding the synthetic status node.
- Use a slate status marker rather than a new warning accent so the timeline stays within Voltikka's restrained color system; coral remains reserved for the actively sold current version.

# Verification

- `php artisan test --filter='ContractDetailPageTest'`: 54 tests passed, 118 assertions.
- `git diff --check`: passed.
- Targeted `pint --test` reports pre-existing style rules (`concat_space` and related rules) elsewhere in the two touched files; no automatic broad reformat was applied.
