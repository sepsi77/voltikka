# Current source-read correction

## Result

Done locally. No activation or production change.

`CurrentSourcePromotionEvidence` now compares both the fresh database canonical
columns and caller arrays with the exact published output through a recursive,
type-sensitive comparison. Object-key order and integer/float representation do
not change equality. Lists keep their order. Boolean, numeric-string and null
changes cannot equal numeric amounts. Full validation still receives the exact
published output and immutable source; the comparison does not repair either.
The existing source canonicalizer is not suitable here: it changes whitespace,
removes shared source data and preserves integer/float representation.

The same batch query uses CASE for output and the three canonical JSON columns.
Only the exact schema-v5/prompt-v20/validator-v18 tuple returns those documents.
Legacy rows still return source prose for campaign rates. No second query,
cache, flag, schema, default or Helsinki date-boundary change was added.

## Regression coverage

`laravel/tests/Feature/SourceValidatedEnergyRulesTest.php` now checks:

- Caller-only and database-only changes: true instead of 4, null instead of 0,
  and numeric strings instead of 4 or 0. Each actual batch uses one query and
  rejects energy-rule proof without changing the published output or source.
- Reordered object keys and integer/float equivalents in caller and database
  copies retain proof. These batches also use one query.
- V4 campaign validity and the 4 c/kWh rate stay compatible. The actual SQL
  result has null output and null canonical proof columns for V4.
- Unpointed input still uses zero queries.

## Verification

Commands ran from `laravel`, with `DB_URL=''` and
`APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config` for tests. PHPUnit uses isolated
SQLite memory storage. HTTP tests use fakes; no network or model call ran.

- `php artisan test --filter=SourceValidatedEnergyRulesTest`: 16 passed,
  129 assertions.
- `php artisan test --filter='SourceValidatedEnergyRulesTest|EnergyRuleSourceProofTest|EnergyRuleSourceContextTest|CurrentPromotionTermsTest|ContractInterpretationProfileTest'`:
  52 passed, 389 assertions.
- `vendor/bin/pint --test app/Services/CanonicalPricing/CurrentSourcePromotionEvidence.php tests/Feature/SourceValidatedEnergyRulesTest.php`: passed.
- Root `git diff --check`: passed.

No full suite, migrations, history writes, commit or push ran. Shared context and
task JSON remain manager-owned and unchanged by this unit. The manager can copy
this implementation note into the shared CanonicalPricing context after review.
