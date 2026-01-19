# Decisions Log

## 2026-01-19 - Design Update Session

### Decision: Use Alpine.js for Interactive Components
- **Context**: Need mobile hamburger menu toggle and collapsible filters
- **Decision**: Use Alpine.js CDN for lightweight interactivity
- **Rationale**:
  - Alpine.js is already commonly used with Laravel/Livewire
  - Lightweight and doesn't require a build step
  - Simple declarative syntax fits well with Blade templates
  - No need for heavy JavaScript framework for simple toggling

### Decision: Inline Tailwind Config for CDN Fallback
- **Context**: Layout checks for Vite manifest; falls back to Tailwind CDN
- **Decision**: Add inline `tailwind.config` object when using CDN fallback
- **Rationale**:
  - Ensures brand colors work in development without Vite build
  - Provides consistent experience whether using Vite or CDN
  - Prevents "missing color" issues when CDN is used

### Decision: Use SVG Icons Instead of Icon Library
- **Context**: Need icons for filter buttons and presets
- **Decision**: Use inline SVG paths from Heroicons instead of icon library
- **Rationale**:
  - No additional dependencies
  - Works with Tailwind classes for sizing/coloring
  - No build step or font loading required
  - Legacy app used Iconify but inline SVG is simpler for Laravel

### Decision: Use success-500 for Active Filter States
- **Context**: Legacy uses `text-green-500 border-green-600` for active filters
- **Decision**: Map to Voltikka's `success-500/600` colors
- **Rationale**:
  - Maintains semantic meaning (green = active/selected)
  - Uses brand color palette for consistency
  - success-500 (#84cc16) closely matches legacy green-500

### Decision: Remove Postcode and Metering Filters from Simplified View
- **Context**: Original contracts-list had postcode search and metering type filters
- **Decision**: Simplify to contract type and energy source filters only
- **Rationale**:
  - Matches legacy app's primary filter sections
  - Cleaner UI with fewer filters
  - Postcode can be added later if needed
  - Focus on most common filtering use cases
