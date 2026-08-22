# Decisions

- Out-of-range pagination returns a real 404. It does not redirect to the last valid page.
- `ContractsList::abortIfPageIsOutOfRange()` checks the completed paginator in the shared normal and SEO listing view-data builders. This covers annual and bill-mode paginator branches while leaving the fixed one-page `CheapestContracts` compatibility surface unchanged.
- Empty, malformed, zero, and negative page values still normalize to page 1 before the range check.
- Valid location pagination after page 1 remains crawlable but passes exactly `noindex,follow` to the layout. Canonical, previous, and next links stay page-specific.
- The city view renders `LocalContractsSection` only when the main paginator is on page 1. `SeoContractsList` still calculates local/regional IDs and excludes them from the main paginator on every page.
- No `robots.txt` change is part of this work because crawlers must be able to read `noindex`.
- Regression coverage includes normal annual-list and bill-mode out-of-range handling, city out-of-range handling, page-specific city robots and pagination links, page-1 indexability, and page-1-only local blocks.
