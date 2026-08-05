# National contracts and postcode eligibility

## Requirements

- Contract comparison listings show only nationally available contracts until the visitor selects a valid Finnish postcode.
- After postcode selection, listings include national contracts and regional contracts linked to that exact postcode.
- The selected postcode is reused on other listing pages in the same browser.
- The selector is visible outside the collapsed advanced filters.
- Invalid or stale stored postcodes do not expose regional contracts.
- City-page regional sections do not use a saved postcode from another municipality.
- The listing setup keeps the primary path clear: consumption, pricing behavior, availability, optional filters, then results. Supporting copy states each fact once and the first contract stays close to the controls.

## Status

Completed and verified. The selector, listing pipeline, local city tiers, prepared-cache namespaces, regression tests, and context documentation are current.
