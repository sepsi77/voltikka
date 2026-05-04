# Decisions

- Do not attempt Finnish genitive/adessive inflection for arbitrary company names. Use neutral templates like "yhtiöltä {company}".
- Active contract meta descriptions now prefer Voltikka comparison facts: contract type phrase, rank/total, 5 000 kWh annual cost, and cheapest-alternative difference when available.
- Price-history-aware meta descriptions take priority when the `General` component has at least two tracked dates and an absolute change of 3% or more. They include current c/kWh, monthly fee when available, change direction/percentage, and rank.
- Spot contracts describe the `General` component as margin; other contracts describe it as energy price.
- Product JSON-LD description uses the generated meta description, not provider `short_description` / `long_description`, to reduce the chance that provider copy becomes Google's snippet.
- Contract detail prepared view-data cache key was bumped from v2 to v3 for the initial metadata change, to v4 for the price-history meta template, and to v5 for compact title templates.
- The hero verdict label `Yksi halvimmista` is limited to absolute rank <= 25. Rank 26+ must not get that label even if it falls in the cheapest 10% by percentile.
- Active ranked title tags should lead with Voltikka-specific data when a compact price phrase is available: price/margin, optional history change, rank, then contract name. Do not include base fee in title price phrases; keep them around 20 characters so contract names remain visible.
