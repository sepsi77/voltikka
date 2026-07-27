# Decisions

- Compare Spot margin and monthly base fee because these are supplier-controlled charges. The Nord Pool market price is common to Spot contracts and is not a useful seller competitiveness measure.
- Use market statistics from the same date and pricing basis as the current company comparison. Do not show Spot fee benchmarks from the dated historical fallback beside current contract terms.
- This task preserved the page title and H1 at the time. The later `company-detail-approved-seo-copy` task changes the title and keeps the H1.
- `CompanyMarketComparisonService` exposes `spot_benchmarks` only on a non-historical payload. Each `spot_margin` or `monthly_fee` metric must have a numeric median and at least 10 contracts on the exact date and pricing basis selected for the company annual-price comparison.
- The Spot table compares the currently displayed calculated margin and monthly fee with those medians. A missing contract fact or missing benchmark produces no comparison statement.
- Equal means that the difference is below 0.005 in the displayed unit, which agrees with the two-decimal table display.
- The Spot FAQ states the lowest available margin and monthly fee separately. It mentions market medians only when at least one safe benchmark exists.
