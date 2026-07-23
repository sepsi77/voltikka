# Expired contract history node

Add an explicit status node to the contract-detail history timeline when the rendered contract is no longer active/on sale.

## Requirements

- Inactive contract detail pages show a newest timeline node stating that the contract is no longer on sale.
- Do not claim an exact removal date: current data only records the last date the contract was observed in an import through `price_components.price_date`.
- Show that last-observed date when available, with an honest fallback when unavailable.
- Active contract pages keep the existing current-version treatment and do not show the inactive node.
- Inactive single-version contracts still render the history section.
- An inactive version must not be labelled `Nykyinen` as if it were currently sold.
- Add regression tests and update nearby agent documentation.
