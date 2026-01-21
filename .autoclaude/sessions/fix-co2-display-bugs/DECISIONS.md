# Decisions Log

## 2026-01-21: CSS Rebuild vs Code Changes

**Context:** The CO2 badge on the contracts list was rendering incorrectly - appearing as a large box instead of a compact inline badge.

**Decision:** Run `npm run build` to regenerate CSS instead of modifying HTML/CSS.

**Rationale:** After investigation, the HTML/Blade template code was already correct with proper Tailwind classes (`w-3.5`, `h-3.5`, `inline-flex`, etc.). The issue was that these classes were not included in the compiled CSS bundle because Tailwind's JIT compiler hadn't processed the template when the CSS was last built.

**Outcome:** CSS rebuild fixed the badge display without requiring any code changes.

---

## 2026-01-21: Replace Semi-circular Gauge with Horizontal Bar

**Context:** The task requested removing the "broken" semi-circular gauge and replacing it with a simpler horizontal bar. After CSS rebuild, the gauge was actually working, but the task explicitly requested simplification.

**Decision:** Replace the semi-circular gauge with a simple horizontal progress bar as requested.

**Rationale:**
1. The horizontal bar is simpler and more reliable than the complex conic-gradient gauge
2. Having both a gauge AND a comparison bar was redundant
3. The new design reduces visual clutter
4. Horizontal bars are easier to read and understand at a glance

**Outcome:** New "Päästökerroin" horizontal bar with gradient colors and position marker, keeping the hero number and comparison bar.

---

## 2026-01-21: Always Show Electricity Source Section

**Context:** The empty electricity source section was conditionally rendered only when `$contract->electricitySource` existed. However, the section was still empty when the model existed but all percentages were 0.

**Decision:** Always show the "Sähkön alkuperä" section, but display an informative message when no data is available.

**Rationale:**
1. Users should always see where the electricity source information would be
2. Hiding the section entirely might make users think it's missing functionality
3. The informative message explains why data is missing and what fallback is used
4. Consistent with the CO2 section which explains residual mix usage

**Outcome:** Section always displays with either source data (when available) or a helpful message explaining that Finland's residual mix is used for calculations.
