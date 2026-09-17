# Annualized pricing policy documentation

## Scope

Record the user-approved annualized comparison policy dated 2026-09-15. The authoritative section belongs after the introduction in `laravel/app/Services/CanonicalPricing/AGENTS.md`. Add a short root summary and status links at the top of the SupplierAdjusted and MarketReset context files.

The policy defines intended future implementation. It does not describe a completed pricing change or authorize production mutations. Retain current and historical implementation notes, billing, VAT, usage, evidence, and cache safety rules. Preserve the four `CLAUDE.md` symlinks. Do not change the completed `tasks/annual-pricing-estimate-consistency` task.

## Required policy

- Annualized comparison, with six-month term costs annualized regardless of continuation or Hybrid classification; no new 24-month policy.
- One coherent non-fixed-term forecast policy using known prices first and current FI futures for unknown months.
- Retail premium hierarchy: reliable own lineage, comparable company, comparable market; retain usable futures when an own historical anchor is missing.
- Exclude incomplete promotional terms from price calculations, using structured and prose evidence without an intent test.
- One trusted replacement-lineage identity resolver; energy episodes use full tariff rates and stay separate from fee changes.
- Monthly future projections remain accepted approximations for quarterly and discretionary repricing, with estimate disclosure.
- Add a future implementation regression checklist.

## Limits and acceptance

Documentation only. No PHP, tests, configuration, data, pricing behavior, or deployment changes. No commit or push. Check `git diff --check`, inspect the final diff, verify symlinks, and verify only the four intended context files and this task folder changed. PHP tests and asset builds are not required.
