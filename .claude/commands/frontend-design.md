---
name: frontend-design
description: Create distinctive, production-grade frontend interfaces with high design quality. Use this skill when the user asks to build web components, pages, artifacts, posters, or applications. Generates creative, polished code that avoids generic AI aesthetics.
---

This skill guides creation of distinctive, production-grade frontend interfaces. It follows an iterative workflow with checkpoints, emulating how professional designers work.

## Workflow Overview

The skill operates in phases. At key checkpoints, present assumptions and wait for user confirmation before proceeding. This prevents wasted effort and ensures alignment.

---

## Phase 1: Understand & Infer

Before any visual decisions, establish the foundation.

**Gather or infer:**
- **User & Context**: Who uses this? What device/situation? What's their expertise level?
- **Primary Action**: What's the ONE thing the user must do or notice?
- **Brand Constraints**: Existing colors, fonts, tone? Or greenfield?
- **Success Metric**: Conversion? Comprehension? Speed? Delight?

**Output a Design Brief** (5-7 bullets) summarizing:
- The problem being solved
- The target user and context
- Known constraints
- What success looks like
- Any assumptions made

### → CHECKPOINT 1
Present the Design Brief to the user:
> "Before I proceed, here's my understanding of what we're building. Please confirm or correct:"
> [Design Brief]
> "Does this capture your intent? Any constraints I'm missing?"

**Wait for confirmation before Phase 2.**

---

## Phase 2: Structure & Hierarchy

Design the information architecture before aesthetics.

**Define:**
- **Content Hierarchy**: What's primary → secondary → tertiary?
- **User Flow**: Entry point → primary action → next steps → escape hatches
- **Component Inventory**: What UI elements are needed? Which are standard vs. custom?
- **Responsive Priority**: Mobile-first or desktop-first? What changes across breakpoints?

**Output a Structure Map:**
- Hierarchy bullets (ordered by importance)
- Rough section layout with purpose annotations
- List of required states: default, loading, empty, error, success, hover/focus

### → CHECKPOINT 2 (for complex projects)
For larger builds, present the structure:
> "Here's the proposed information hierarchy and layout structure:"
> [Structure Map]
> "Does this priority order match your intent?"

For simple components, proceed to Phase 3.

---

## Phase 3: Explore Directions (Diverge)

Generate 2-3 distinct aesthetic directions. Don't commit to one immediately.

**For each direction, define:**
- **Mood** (3-5 keywords): e.g., "warm, editorial, refined" or "stark, technical, precise"
- **Typography Strategy**: Display font + body font pairing (from Google Fonts or system-safe)
- **Color Strategy**: Dominant color + accent + neutrals (with hex codes and semantic roles)
- **Layout Concept**: Grid approach, density, shape language, spatial rhythm
- **Motion Concept**: Where animation matters, what style (spring physics, staggered reveals, etc.)

**Direction Types:**
- **Direction A**: Safe/conversion-focused — clarity and usability prioritized
- **Direction B**: Bold/brand-forward — distinctive aesthetic, memorable impression
- **Direction C**: Wildcard — unexpected approach that still serves the goal

### → CHECKPOINT 3
Present all directions concisely:
> "I've developed three possible directions:"
>
> **A) [Name]** — [3 keywords]. [1-sentence description]
> **B) [Name]** — [3 keywords]. [1-sentence description]  
> **C) [Name]** — [3 keywords]. [1-sentence description]
>
> "Which direction resonates? Or should I blend elements?"

**Wait for selection before Phase 4.**

---

## Phase 4: Systematize (Design Tokens)

Before building, lock down the design system. This prevents inconsistency.

**Define tokens for:**
```css
:root {
  /* Spacing Scale (8px base) */
  --space-xs: 4px;
  --space-sm: 8px;
  --space-md: 16px;
  --space-lg: 24px;
  --space-xl: 32px;
  --space-2xl: 48px;
  --space-3xl: 64px;

  /* Typography Scale */
  --text-xs: 0.75rem;
  --text-sm: 0.875rem;
  --text-base: 1rem;
  --text-lg: 1.125rem;
  --text-xl: 1.25rem;
  --text-2xl: 1.5rem;
  --text-3xl: 2rem;
  --text-display: 3rem;

  /* Color Roles */
  --color-bg: #____;
  --color-surface: #____;
  --color-text: #____;
  --color-text-muted: #____;
  --color-accent: #____;
  --color-accent-hover: #____;
  --color-border: #____;
  --color-error: #____;
  --color-success: #____;

  /* Radii */
  --radius-sm: 4px;
  --radius-md: 8px;
  --radius-lg: 16px;
  --radius-full: 9999px;

  /* Shadows */
  --shadow-sm: ...;
  --shadow-md: ...;
  --shadow-lg: ...;
}
```

**Also define:**
- Font loading strategy (with fallback stack)
- Interaction states (hover, active, focus, disabled)
- Motion preferences (`prefers-reduced-motion` support)

---

## Phase 5: Build

Now implement working code with full production readiness.

### Aesthetic Execution Guidelines

**Typography**
- Choose distinctive, characterful fonts — avoid Inter, Roboto, Arial unless justified for the specific context (utility tools, localization, performance constraints)
- If using a common font, state why it's correct for this use case
- Pair a display font with a complementary body font

**Color & Theme**
- Commit fully to the chosen palette
- Use dominant colors with sharp accents — avoid timid, evenly-distributed palettes
- Reference tokens consistently (no magic numbers)

**Spatial Composition**
- Break predictable grids when it serves the design
- Use asymmetry, overlap, diagonal flow, or generous whitespace intentionally
- Match density to the aesthetic (maximalist = controlled density, minimalist = generous space)

**Motion & Interaction**
- Focus on high-impact moments: page load sequences, primary action feedback
- Use staggered reveals with `animation-delay` for orchestrated entrances
- Prefer CSS animations; use Motion/Framer for React when complex physics needed
- Hover states should feel tactile and responsive to the aesthetic

**Backgrounds & Atmosphere**
- Create depth — don't default to flat solid colors
- Consider: gradient meshes, subtle noise/grain, geometric patterns, layered transparencies, dramatic shadows
- Match texture to tone (organic = grain/texture, technical = clean/sharp)

### Production Requirements

**All States:**
- Default, hover, active, focus (visible!), disabled
- Loading (skeleton or spinner appropriate to aesthetic)
- Empty state (helpful, not just "No data")
- Error state (clear, actionable)
- Success state (confirming feedback)

**Accessibility:**
- Minimum AA contrast (4.5:1 body text, 3:1 large text)
- Visible focus indicators (never `outline: none` without replacement)
- Semantic HTML structure (proper heading hierarchy, landmarks)
- Touch targets ≥44px on interactive elements
- `prefers-reduced-motion` support

**Responsiveness:**
- Define behavior at key breakpoints
- Ensure touch-friendly on mobile
- Adapt typography and spacing scales

**Performance:**
- Prefer CSS/SVG over heavy images for effects
- Font loading strategy with fallbacks
- Avoid excessive JS-driven animation

---

## Phase 6: Critique & Iterate

After initial build, run a self-evaluation.

### Design QA Checklist

| Criterion | Pass? | Notes |
|-----------|-------|-------|
| Hierarchy clarity — is the primary action obvious? | | |
| Visual rhythm — consistent spacing and alignment? | | |
| Interaction clarity — do affordances communicate? | | |
| Readability — contrast and type sizing adequate? | | |
| Responsiveness — works across breakpoints? | | |
| Accessibility — focus states, contrast, semantics? | | |
| Distinctiveness — memorable without gimmicks? | | |
| Cohesion — does everything feel part of one system? | | |

### Output:
- Top 3 issues identified
- Proposed fixes for next iteration
- Optional: alternative approaches considered

### → CHECKPOINT 4 (on delivery)
Present the implementation with a brief rationale:
> "Here's the implementation. Key design decisions:"
> - [Decision 1]: [Why]
> - [Decision 2]: [Why]
>
> "Let me know if you'd like me to iterate on any aspect — I can adjust [specific elements] or explore a different approach to [specific area]."

---

## Anti-Patterns to Avoid

These patterns signal generic "AI slop" — avoid unless specifically justified:

**Visual:**
- Purple/blue gradients on white (the "AI default")
- Card shadows that are too dark or blurry
- Perfectly symmetrical 3-column feature grids
- Hero sections with centered title + single gradient button
- Generic placeholder text ("Feature 1, Feature 2, Feature 3")

**Typographic:**
- Inter, Roboto, or system fonts without justification
- Converging on "safe" choices (Space Grotesk, Poppins) across every generation
- Poor hierarchy (everything the same size/weight)

**Structural:**
- No empty/loading/error states
- Missing focus indicators
- Non-responsive designs
- Inconsistent spacing (magic numbers instead of tokens)

**If you use a common pattern, justify why it's correct for this specific context.**

---

## Output Contract

When generating a frontend interface, structure the response as:

1. **Design Brief** — The problem and constraints understood
2. **Direction Concepts** — 2-3 options explored (for non-trivial work)
3. **Chosen Direction** — Selection with rationale
4. **Design Tokens** — CSS variables or equivalent
5. **Implementation** — Full working code
6. **Design Notes** — Key decisions explained, known limitations, iteration suggestions

For quick components, phases can be compressed, but always establish context and justify aesthetic choices.

---

## Iteration Protocol

When receiving feedback:

1. **Clarify the level**: Is this about concept (wrong direction) or execution (right direction, wrong details)?
2. **Don't just tweak colors**: Evaluate if hierarchy, layout, or mental model needs to change
3. **Propose solutions with tradeoffs**: "I can do X, which gives you Y but trades off Z"
4. **Maintain design integrity**: Push back respectfully if feedback would break the system's coherence

---

## Context-Specific Patterns

### Marketing / Landing Pages
- Hero impact is everything — invest heavily here
- Clear visual path toward CTA
- Scroll narrative (story unfolds as user scrolls)
- Social proof and trust signal placement

### Dashboards / Applications  
- Information density balance
- Consistent, learnable navigation patterns
- State management visible to user
- Data visualization choices tied to the data's story

### E-commerce
- Product imagery treatment is primary
- Price and CTA prominence
- Trust signals throughout
- Friction-free path to purchase

### Content / Editorial
- Reading experience optimization (measure, line-height, contrast)
- Typography for sustained reading
- In-content navigation
- Minimal distraction from content

---

## Remember

Design is iterative. The checkpoints exist to ensure alignment before effort is invested. Bold maximalism and refined minimalism both work — the key is **intentionality**, not intensity. Every choice should trace back to the user's goal and the product's purpose.

Execute with confidence. Claude is capable of extraordinary creative work — don't default to safety.
