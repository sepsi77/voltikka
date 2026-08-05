# Decisions

- Use browser localStorage for reuse. This keeps the first HTML response generic and safe for the existing shared edge cache.
- Keep national contracts visible after postcode selection. Add only regional contracts linked to the exact selected postcode.
- Treat `availability_is_national = null` as not proven national, so it is hidden by default. The production `where availability_is_national = true` condition provides this fail-closed behavior even though the current test schema is non-nullable.
- An explicit URL/Livewire postcode wins over browser storage. Store that value during selector initialization; restore browser storage only when the component has no selection.
- A city page can use an exact postcode for its regional tier only when the postcode belongs to the page municipality. Prefer municipal-code comparison and use the Finnish municipality name only when a code is unavailable. Nearby local-company contracts continue to use the visitor's actual postcode eligibility.
- Prepared listing cache namespaces moved from v2 to v3 because national-by-default eligibility changes cached membership without changing source-data fingerprints.
- Verification completed with the six requested test classes (155 tests, 388 assertions), PHP syntax checks, `git diff --check`, and one Impeccable detector pass over all changed Blade files.
- The layout pass must keep postcode eligibility and pricing-category controls visible, but can reduce repeated containers, labels, and vertical gaps above the first contract.
- The layout keeps consumption, bill comparison, pricing behavior, availability, optional filters, and results in a stable linear order. The bill disclosure is now one compact factual row. The postcode control uses one compact status-and-input row instead of a second full section.
- Clarified copy names actions and outcomes: "Vertaa nykyistä sähkölaskuasi", "Arvioi kulutus laskurilla", "Saatavuus: koko Suomi", and "Käytä numeroa". The redundant postcode database helper was removed.
- Both listing templates now use the same plain results divider. This removes one repeated bordered module from the base listing.
- Post-edit desktop and mobile screenshots show the first contract higher on the page and preserve the visible pricing and availability controls. The final layout detector returned no findings. The focused UI suite passed 93 tests with 279 assertions, and the production asset build passed.
