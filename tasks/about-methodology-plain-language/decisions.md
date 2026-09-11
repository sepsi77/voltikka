# Decisions

- The user wants useful estimates and transparent assumptions, not automatic exclusions or a generic disclaimer.
- The page currently contains calculation details but lacks a prominent, plain explanation of unequal certainty. Some claims are too strong: all misleading offers detected, all savings are first-year, current data always refreshed. Simplify without changing publisher/funding claims.
- Replace internal language such as peruskuormahinta, luottamustaso, hintavaihe and vertailutaso with everyday explanations. Explain futures once as market prices agreed now for electricity delivered later, not promises about a seller's future retail price.
- Do not describe equal estimated day/night prices as equal customer hourly consumption: the shape fallback changes price assumptions, not the customer's usage profile.
- Keep the method anchor and current content structure. Put the key caution in visible normal-size text near the heading, not only in small print.
- Calculation-code changes and broad design-system updates are outside this request.

## Implementation and verification

- Updated the existing methodology blocks with everyday Finnish. The visible note follows the heading and distinguishes known fixed arithmetic from uncertain annual estimates. Kept anchors, layout, action strip, publisher and funding facts, and optional historical Spot value.
- Added scoped 85/15 Time-tariff guidance, retail-versus-wholesale change assumptions, an illustrative short-term example, Hybrid exclusion, package timing, real-term savings, VAT assumptions, and the seller/Arvio next step. No pricing code changed. Existing annual-pricing work remains intact.
- Updated `laravel/AGENTS.md` only in its Public methodology paragraph; its CLAUDE symlink remains intact.
- `cd laravel && php artisan test --filter='AboutPage|AnnualEstimateCopyConsistency|PageActionStrip'`: 16 passed, 158 assertions.
- `cd laravel && php artisan test --filter='PreferredSourceActionStripTest'`: 8 passed, 48 assertions.
- `cd laravel && npm run build`: passed, 60 modules. Existing Browserslist data is nine months old; no dependency updates made.
- `node /Users/seppo/.agents/skills/impeccable/scripts/detect.mjs --json laravel/resources/views/livewire/about-page.blade.php`: returned `[]`; no findings.
- `git diff --check`: passed. Reviewed the scoped diff and final working-tree status.
- Browser check attempted against the existing local listener at `http://127.0.0.1:8000/tietoa#menetelma`. It returned Not Found. The desktop capture at `/tmp/about-method-desktop.png` is only the error page, not UI evidence. The selector check failed on the absent page, so mobile capture did not run. Closed the dedicated `about-copy` browser session. Did not start or repair a server or database process.
- Parent reviewed the full text. Final copy corrections make short-term annualization conditional (full 12-month known coverage need not use real-term annualization), shorten the open-ended heading, and improve VAT and source wording. Focused assertions cover the conditional wording and reject the former universal statement.
- Final verification: `cd laravel && php artisan test --filter='AboutPage|AnnualEstimateCopyConsistency|PageActionStrip|PreferredSourceActionStripTest'`: 24 passed, 210 assertions. No Tailwind classes changed, so the previous successful build remains valid. No further browser or detector run was needed.
- Parent review and task are complete. No commit, deployment, production mutation, or new dependency.
