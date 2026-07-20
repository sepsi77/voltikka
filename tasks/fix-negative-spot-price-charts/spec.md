# Render negative values correctly in spot-price bar charts

## Problem

The hourly day strips use a positive-only scale with zero fixed at the bottom. Bar height is clamped to at least 4%, so negative, zero, and sufficiently small positive prices look nearly identical. The expanded 15-minute bars repeat the issue by turning every non-positive value into a short rightward bar.

## Scope

- Introduce signed chart domains that always include zero.
- Draw positive values above/right of zero and negative values below/left of zero.
- Keep day strips visually comparable by using one shared signed domain where appropriate.
- Support positive-only, mixed-sign, all-negative, exactly-zero, and missing-value datasets.
- Position the rolling 30-day average baseline correctly even if it is zero or negative.
- Apply equivalent signed behavior to expanded 15-minute bars.
- Preserve current/actual/forecast styling, accessible labels, tooltips, minimum-price markers, and responsive behavior.
- Add regression tests for signed geometry and rendering metadata.

## Acceptance criteria

1. Mixed-sign hourly data renders around a visible zero baseline; negative bars extend below it and positive bars above it.
2. An all-negative day remains legible and does not collapse every value into the same minimum-height bar.
3. Exactly zero renders at the zero baseline without being presented as a positive bar.
4. Small non-zero values may receive a minimum visible size, but the minimum must preserve their direction and must not misrepresent zero.
5. Shared scaling includes the global minimum, global maximum, zero, and any displayed 30-day-average baseline with reasonable headroom.
6. Y-axis labels include meaningful signed bounds and zero at its calculated position rather than always at the bottom.
7. Expanded quarter-hour bars use a signed/diverging layout with a visible zero reference.
8. Numeric labels, titles, detail values, min/average/max statistics, and accessible labels retain the actual signed price.
9. Existing forecast/current-hour colors and cheapest-hour marker remain functional.
10. Automated tests cover positive-only, mixed-sign, all-negative, all-zero, and small-magnitude values for hourly and quarter-hour chart behavior.
11. The `/spot-price` page remains usable on mobile and desktop and focused SpotPrice tests pass.

## Primary files

- `laravel/app/Livewire/SpotPrice.php`
- `laravel/resources/views/livewire/spot-price.blade.php`
- `laravel/tests/Feature/SpotPriceComponentTest.php`
