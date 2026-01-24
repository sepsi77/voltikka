# Decisions Log

## 2026-01-24 - Session: spot-price-clarity-improvements

### Decision 1: Threshold Values for Day Verdict
**Decision**: Use ±10% threshold for day verdict (Edullinen/Normaali/Kallis päivä)
**Rationale**:
- ±10% provides a meaningful distinction between normal and exceptional days
- Smaller thresholds (e.g., ±5%) would flag too many days as cheap/expensive
- Aligns with user expectations for "today is a cheap/expensive day" messaging

### Decision 2: Threshold Values for Hourly Badges
**Decision**: Use ±5% threshold for hourly badges
**Rationale**:
- Consistent with existing color gradient thresholds (already using ±5% as boundary between green/yellow and yellow/orange)
- At hourly granularity, ±5% is a meaningful price difference for timing energy usage
- Matches the clock chart's color coding logic

### Decision 3: Badge Visibility on Mobile
**Decision**: Hide badges on small screens (hidden sm:inline-flex)
**Rationale**:
- Hourly rows already contain: time, bar chart, price value, and expand icon
- Adding badges would make rows too crowded on mobile
- Color coding in the bar provides the same information visually

### Decision 4: Info Tooltip Implementation
**Decision**: Use click-to-toggle (not hover) for tooltip on mobile compatibility
**Rationale**:
- Hover doesn't work on mobile/touch devices
- Click-to-toggle with click-outside-to-close is mobile-friendly
- Alpine.js x-data and @click.outside provide clean implementation

### Decision 5: VAT Rate in Tooltip
**Decision**: Explicitly mention "ALV 25,5%" in the tooltip
**Rationale**:
- Users should know the exact VAT rate included
- This is the current Finnish VAT rate as of September 2024
- Helps users understand the final price composition
