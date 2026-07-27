# Preserve contract listing filters during pagination

## Problem

On Laravel Livewire electricity contract listings, advancing to another page can remove the current `consumption` and `hintatyyppi` query parameters. This includes comma-separated multi-select `hintatyyppi` values.

Reproduction URL:

`/sahkosopimus?page=1&consumption=2000&hintatyyppi=kulutusvaikutus`

## Goal

Pagination must preserve the current URL-bound consumption and pricing-bucket filter values while it changes only the page value.

## Acceptance

- Advancing from the reproduction URL keeps `consumption=2000` and `hintatyyppi=kulutusvaikutus`.
- Comma-separated multi-select `hintatyyppi` values remain intact.
- Existing tolerant handling of malformed `page` values remains unchanged.
- A focused regression test covers the behavior.
- No production changes are made.

## Follow-up: consumption preset hydration

An initial listing URL can hydrate `consumption` without updating the independent
preset UI state. For example,
`/sahkosopimus?consumption=10000&hintatyyppi=kulutusvaikutus&page=2` calculates
with 10,000 kWh but previously marked the 5,000 kWh preset active.

Acceptance:

- An explicit query value that equals a preset selects that preset on initial load.
- A custom query value clears the preset selection and fills the direct input.
- SEO housing, consumption-level, and business defaults stay unchanged when no
  explicit consumption query conflicts with them.
- Existing Livewire preset, direct-input, and calculator interactions stay unchanged.
