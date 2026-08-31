# Fixed-term decision article refresh

Refresh `/sahkosopimus/kannattaako-maaraaikainen` as a data-led editorial decision page.

## Requirements

- Remove the embedded individual-contract comparison widget and all copy that introduces it.
- Use only aggregate precomputed data for the page answer.
- Explain that contract duration and pricing method are separate choices. A fixed-term contract is not always fully fixed-price.
- Show a dated current market comparison for the published current fixed rate of open-ended products and fully fixed 6, 12, and 24 month contracts with p20, median, p80, and contract count. Use one common eligible date so the duration choice has a direct baseline.
- Show the current 5,000 kWh annual-cost distribution for open-ended fixed-price and 12-month fully fixed contracts when a same-date active-method comparison is available. This is the page's price-of-certainty result and includes monthly fees. The open-ended 12-month total is an estimate because the seller can change its price under the contract terms.
- Show historical p20, median, and p80 trends for fixed 6, 12, and 24 month contracts. Keep open-ended out of the long trend until historical classification is proven comparable across the observed/canonical transition.
- Show the latest eligible 30-day p20, median, and p80 fixed-term forecasts for 6, 12, and 24 months, including confidence and sample size.
- Use honest unavailable states. Do not fall back to old forecast models or the wrong pricing basis.
- Keep the page aggregate. Link to contract listing pages for individual offers.
- Correct unsupported or over-broad editorial claims about price locking, termination fees, moving, automatic continuation, and long-term market direction.
- Show separate market-data and editorial-review dates. Do not set Article `dateModified` to request time.
- Use precomputed statistics and forecast tables only. Cache prepared article data. Do not calculate contract prices during the request.
- Follow Voltikka design, accessibility, and Simplified Technical English rules.

## Verification

- Add focused feature tests for data eligibility, unavailable states, aggregate-only rendering, corrected copy, and structured-data dates.
- Run focused tests, production asset build, and `git diff --check`.
