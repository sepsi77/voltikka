# Contract history observation clarity

Show first and latest observation dates and the distinct observed calendar-day count for each exact contract version. Use loaded relational components without extra queries. Keep price selection and history arithmetic unchanged. Use `last_seen_on_sale_date` (all rows, including zeros) for the displayed latest observation. Do not use `latest_price_date`, which belongs to the latest-positive price selection. Explain that timeline prices are the latest selected observations, not a promise of unchanged prices across the range.

Label actual seasonal representative-energy charts as weighted seasonal energy prices. Explain the 5 winter / 7 other month weighting and separate it from a consumption-specific bill or annual forecast. Keep General > Time > Season precedence, Spot copy, missing-reference notes, and short-history suppression.

Bump the prepared detail cache from v19 to v20. Add focused tests, run relevant PHP tests, asset build, and diff checks. No production changes, commits, pushes, or edits to other tasks.
