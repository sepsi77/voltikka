# Company detail page structure

## Goal

Improve company detail pages for household and business visitors and make the pricing content easier to verify.

## Requirements

- Keep household contracts in the existing main contract section.
- Move business-eligible contracts to a separate section at the bottom of the page. Contracts targeted to both audiences appear in both sections.
- Title the business section `{company name} sähkösopimukset yrityksille`.
- Add `Päivitetty {date}` near the top of the page. The date must come from stored page data, not from the request time.
- Remove the unexplained price rank from the page title and H1. Resolve the metadata toward the company price search cluster without changing factual meaning.
- Do not show a delivery-area section.
- Do not show a company FAQ section or emit FAQPage structured data for hidden content.
- Match the annual-consumption selector to the compact selector on the main `/sahkosopimus` page, including presets, direct input, responsive collapse, and calculator action.
- Keep canonical pricing as the current pricing source when it is enabled. Keep feature-off behavior.
- Update tests and nearby documentation.
