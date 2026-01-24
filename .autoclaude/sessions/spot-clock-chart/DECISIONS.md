# Decisions Log

## 2026-01-24

### Color Scale Design
- Chose a 7-tier color scale based on percentage difference from the 30-day average
- Colors range from dark green (≤-30%) through yellow (neutral, ±5%) to dark red (>+30%)
- This provides good visual distinction between cheap and expensive hours

### Clock Layout
- Used SVG for the clock visualization rather than Canvas for better accessibility and styling
- Segments start at the top (midnight) and go clockwise
- Inner radius 105px, outer radius 175px provides good visual balance
- Center circle (85px radius) displays the 30-day average

### Animation Strategy
- Staggered segment reveal animation (40ms apart) creates an engaging visual effect
- Center circle appears first with a "pop" animation
- Hour markers and legend fade in after segments are revealed

### Conditional Rendering
- Clock only renders when ALL 24 hourly prices are available for today
- This prevents partial/misleading visualizations
- If no data is available, the chart simply doesn't appear (graceful degradation)

### VAT Handling
- Applied 25.5% VAT (1.255 multiplier) to both the 30-day average and hourly prices
- This ensures consistent comparison (all prices include VAT)
