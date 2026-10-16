# Redesign implementation in Laravel

## Goal

Implement the Voltikka picture-book redesign (`design/`) in the Laravel app. Take exact values from the Paper file "Voltikka" (`01M50B1D4JDT66PK35AAW7BSE4`) with `get_jsx` / `get_computed_styles`, not from screenshots.

## Sources of truth

- `design/voltikka-design-system.md` (rules), `design/voltikka-tokens.css` (tokens).
- Paper page "Direction · first boards": `/sahkosopimus` board (`2J3-0`), `/spot-price` board (`11H-0`).
- Paper page "Seasons": the four season variants of the listing hero, the spot hero and the footer.

## Scope of the first slice

1. Foundation: tokens in Tailwind 3 (`theme.extend` from CSS variables), Bricolage Grotesque + Figtree, season selection (`Europe/Helsinki` month), optimized WebP art in `public/images/art/`.
2. Shared shell: top bar on the hero art (dark and light variants), footer with the season scene, Preferred Sources strip (round 3: notice-board art and a paper-note button, see decisions.md).
3. The `/sahkosopimus` listing (and all `SeoContractsList` pages): hero with the energy price index on the sky, breadcrumbs, compact controls block, results heading, ledger entries (replace the contract cards), pagination, villager interlude, "Katso myös".
4. Then: `/spot-price`.

Pages that are not yet redesigned keep their old body, but get the new top bar (on a night band) and footer.

## Constraints

- Do not change pricing, ranking, caching or data logic. Presentation only.
- Livewire form rules (`wire:model.blur`, numeric `min`) stay.
- No commit or push without user approval. Branch: `design/voltikka-redesign`.
- Verify each step in the local browser (user, 2026-10-16).
