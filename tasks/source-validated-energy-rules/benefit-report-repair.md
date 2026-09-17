# Local benefit report repair

## Result

The local report now compares monetary benefits at displayed EUR-cent precision. No application financial data changed.

- `preflight/report.php`: `displayedBenefitAmount()` rounds numeric savings to two decimals, as the total display comparison does. It preserves null and other nonnumeric values. Annual-equivalent savings and real-term savings remain separate comparisons. `includes_discounts` still uses strict comparison, independent of savings rounding.
- `preflight/smoke.php`: 14 synthetic cases cover both savings scopes. Floating-point noise does not change the benefit count. One-cent changes and subcent changes across a displayed-cent boundary do. Null-to-zero, zero-to-null, missing-to-zero and eligibility changes still count. Raw `changed_fields` and the separate term metadata count are checked.

Raw savings, raw changed-field diagnostics, calculator output, total comparisons and ranking logic are unchanged. There is no sampling threshold.

## Verification

All commands passed:

```sh
sandbox-exec -p '(version 1)(allow default)(deny network*)' php tasks/source-validated-energy-rules/preflight/smoke.php
laravel/vendor/bin/pint --test tasks/source-validated-energy-rules/preflight/report.php tasks/source-validated-energy-rules/preflight/smoke.php
php -l tasks/source-validated-energy-rules/preflight/report.php
php -l tasks/source-validated-energy-rules/preflight/smoke.php
git diff --check
```

The smoke test also passed the existing manifest, count, exact-total, exclusion, read-only, source-privacy, cold/warm and report checks. Its new private synthetic directory is `/var/folders/8t/9jkmd7qj66x4_wkg__jqdnlr0000gr/T/voltikka-compare-smoke-d67f9356e8df4497`.

No sealed replay report was changed or regenerated. No network, production operation, migration, application command, commit or push ran. No application suite or asset build was needed for this report-only repair.

## Manager follow-up

After review, generate a new final report from the sealed inputs. This smoke test does not establish the new real-data benefit counts. The shared documentation owner must record in `preflight/AGENTS.md` that monetary benefit comparisons use displayed EUR cents, while null/missing versus zero, eligibility and raw diagnostics remain distinct. Shared status and task files were left to their assigned owner.
