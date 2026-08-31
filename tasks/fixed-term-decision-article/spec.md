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
- Lead with a plain-language conclusion that answers the household decision. Every data section must state its answer or unavailable verdict before amounts, method, and caveats. Explain the trade-off between 6, 12, and 24 months instead of presenting equal-weight data sections.
- Translate every important statistic into meaning. State whether a difference is small or material, what the trend implies, and which uncertainty limits the conclusion.
- Keep technical distribution details available, but place p20, p80, sample counts, model details, and full weekly tables after the plain-language interpretation or inside disclosures.
- Every chart must have a visible labelled y-axis in c/kWh, readable tick values on desktop and mobile, a clear legend, dates, and a short sentence that states the observed change.
- Avoid internal phrases such as active method, pricing basis, eligible distribution, and point-in-time comparison in primary copy.
- Answer the broad title by comparing fully fixed-term contracts with Spot, open-ended fixed-price, periodically market-reset, quarterly, and consumption-effect contracts in separate explanatory sections.
- Show price and price risk together in one accessible illustration. Explain that accepting more price variation can create a saving opportunity but does not guarantee a cheaper contract. When a consumption effect is unknown, use the stored base-price aggregate and state that the effect is assumed to be zero.
- Use the fully fixed 12-month annual cost as the clean one-year benchmark. Keep 6- and 24-month fixed-term evidence as duration context and explain their annual-comparison limits.
- For this article only, compare Hybrid and affected quarterly/reset aggregates from their stored base-price totals. Label every such number as excluding the consumption effect, and state that the actual cost can be higher or lower. Do not change contract-list ranking or canonical calculator output.
- Replace custom SVG charts with the existing Chart.js article convention: responsive canvas, Finnish labels and tooltips, destroy/recreate lifecycle, a visible takeaway, and an accessible disclosure table.

## Verification

- Add focused feature tests for data eligibility, unavailable states, aggregate-only rendering, corrected copy, and structured-data dates.
- Run focused tests, production asset build, and `git diff --check`.
