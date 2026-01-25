# Decisions Log

## Art Direction Mandate

**Date:** 2026-01-25

**Decision:** Agents working on Remotion video tasks should **lead with video art direction and Remotion expertise**.

**Rationale:** Video content requires careful consideration of:
- Motion design principles (anticipation, follow-through, easing curves)
- Visual hierarchy and information density for mobile screens (1080x1920)
- Color contrast and readability
- Temporal flow between scenes
- Frame 0 static state for thumbnail generation

**Guidance for agents:**

1. **Before writing code, consider:**
   - What is the visual story being told?
   - What is the focal point of each scene?
   - How do elements enter, rest, and exit?
   - What spring configs (SNAP vs FLOW) fit the motion intent?

2. **Reference DailySpotPrice for established patterns:**
   - Brand colors: coral #f97316, slate-50 #f8fafc, slate-900 #0f172a
   - Spring configs: SNAP (damping=25, stiffness=300), FLOW (damping=20, stiffness=150)
   - Staggered animations with frame offsets
   - Scale + opacity + translateY combinations

3. **For OfferCard specifically:**
   - Discount badge must be the visual focal point
   - Savings section uses green (#22c55e) for positive emphasis
   - Pricing table needs clear visual separation between columns
   - Company logo provides brand recognition (64px minimum)

4. **Animation timing considerations:**
   - Each card gets ~2.4s with 5 offers (12s total for carousel)
   - Allow 0.3-0.5s for card entry animation
   - Content should be readable for at least 1.5s before exit

---

## Video Specifications

**Date:** 2026-01-25

**Decision:** WeeklyOffers video uses same specs as DailySpotPrice for brand consistency.

| Property | Value |
|----------|-------|
| Dimensions | 1080x1920 (9:16 vertical) |
| Duration | 16.5 seconds (495 frames at 30fps) |
| Font | Plus Jakarta Sans |
| Primary color | Coral #f97316 |
| Background | Slate-50 #f8fafc |
| Dark background | Slate-900 #0f172a |

---

## Scene Structure

**Date:** 2026-01-25

**Decision:** Three-scene structure matching DailySpotPrice pattern.

| Scene | Timing | Purpose |
|-------|--------|---------|
| TitleScene | 0-2.5s | "Viikon sähkötarjoukset" + week dates |
| OffersCarousel | 2.5-14.5s | 1-5 contract cards with discounts |
| SignOffScene | 14.5-16.5s | Voltikka.fi branding (reused from DailySpotPrice) |

---

## Implementation Decisions (2026-01-25)

### Discount Calculation Approach

**Decision:** Calculate savings by comparing costs with and without discount.

**Rationale:** The savings displayed in the video should accurately reflect how much a user saves with the discounted offer vs. the regular price. We:
1. Calculate annual cost WITH discount at each consumption level
2. Calculate annual cost WITHOUT discount (reverse the discount to get original price)
3. Subtract to get savings

For percentage discounts: `original_price = discounted_price / (1 - discount_percentage)`
For absolute discounts: `original_price = discounted_price + discount_value`

### Card Progress Indicator

**Decision:** Added progress dots to OfferCard instead of carousel level.

**Rationale:** Each card is a full-screen view, so the progress indicator should be part of the card component to ensure it's always visible during the card's display. Placing it at carousel level would require complex z-index management.

### SignOffScene Duplication

**Decision:** Created separate SignOffScene in WeeklyOffers directory instead of extracting to shared.

**Rationale:** While DRY principles suggest sharing, for video components:
1. Each scene may need slight customization
2. Direct imports are simpler for Remotion bundling
3. SignOffScene is lightweight (~100 lines)

Future consideration: Extract to `remotion/src/shared/` if more video compositions are added.

---
