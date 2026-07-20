# Decisions and guardrails

- Zero is a semantic baseline, not a minimum-height positive bar.
- Signed direction must be encoded geometrically. Numeric labels alone are insufficient.
- Hourly day strips should remain comparable; derive their geometry from a shared signed domain that includes zero and the displayed 30-day average.
- Minimum visible bar sizing is allowed only for non-zero values and must preserve sign.
- The design must work for asymmetric ranges and all-negative datasets, not only a symmetric mixed-sign example.
- Do not change imported or calculated prices to solve a presentation problem.
- Preserve actual versus forecast provenance and current-hour emphasis.

## Implemented

- `SpotPrice` computes one shared signed domain for hourly actual/forecast strips, including zero and any 30-day average, with headroom on non-zero bounds.
- Each hourly value receives direction, bottom, height, and endpoint metadata. Exact zero has zero height; only non-zero values receive the 4% minimum visible size.
- The Blade chart renders a calculated zero baseline, signed bounds, bars in both directions, and positions the average and cheapest marker from signed metadata.
- Quarter-hour data receives server-computed diverging geometry and a per-row zero reference, so Alpine only renders the supplied values.
- Focused tests cover positive-only shared scales, mixed signs, all-negative values, all-zero values, tiny values, zero averages, quarter-hour divergence, and rendered signed markup.
