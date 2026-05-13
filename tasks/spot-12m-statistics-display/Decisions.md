# Decisions

- The contract-type energy-price table now treats spot differently from fixed-style offers: the visible spot c/kWh is a trailing 12-month realized daily spot average plus the latest typical spot margin, not the latest collection day's spot price.
- The spot row shows the p20–p80 range directly under the energy-price value to avoid adding another table column. The range is calculated from daily spot averages over the same trailing window plus the typical margin, but the UI labels it only as a normal vaihteluväli/range.
- If stored daily spot averages are unavailable in local/test data, the page falls back to existing daily `spot_total_energy_price` medians for the same window so the UI remains populated.
- The “Hinnat sopimustyypeittäin” sparkline now tracks the displayed median energy-price basis. For spot this means the 12-month rolling daily spot average + typical margin; for non-spot rows this means the segment's stored median `energy_price`.
- The annual-cost sparkline moved to the “Hintahaarukka” table, where it matches the table's annual total-price context for the selected consumption.
- The “Tarkemmin sopimustyypeittäin” spot deep-dive chart now uses the same 12-month rolling spot average + typical margin for the median line. Its shaded band uses the same p20–p80 daily-price variation basis as the upper spot row, not latest-day provider min/max.
- The prepared view-data cache key was bumped and now includes daily spot-average fingerprints because the page reads `spot_price_averages` directly.
