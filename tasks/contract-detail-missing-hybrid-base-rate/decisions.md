# Decisions

- Production QA on 2026-08-01 found a repeatable HTTP 500 on contract `fd2vhf-vare-oy-maaraaikainen-valkky-sahko-6kk`.
- Local reproduction against the production snapshot gives `TypeError: CardReceiptLines::amount(): Argument #1 ($value) must be of type float, null given` from the consumption-effect receipt branch.
- The contract is inactive, classified as Hybrid, and has no canonical pricing/calculation. In canonical mode the missing base rate must be omitted. Relational current-price fallback would violate the canonical source-of-truth rule.
- The production snapshot contains 896 inactive Hybrid contracts with null `canonical_pricing` and zero active Hybrids in that state. The fix is therefore a broad historical-detail safety rule, not a slug-specific exception.
- `CardReceiptLines` resolves the consumption-effect base rate once, adds `Perushinta` only for a non-null rate, and always keeps the `Kulutusvaikutus` mechanism row. The strict `amount(float)` contract remains unchanged because omission belongs at the optional fact boundary.
- Regression coverage keeps the normal Hybrid receipt and adds both the missing-rate presenter case and an HTTP-rendered inactive/noindex historical detail case with no canonical payload.
- The exact failing production slug returns HTTP 200 with no exception against the local production snapshot after the fix; its historical page remains `noindex, follow` and keeps the consumption-effect fact.
- Focused relevant regressions passed 165 tests with 535 assertions. The final full Laravel suite with both production hotfixes passed 1,861 tests with 6,616 assertions. Pint and `git diff --check` passed.
