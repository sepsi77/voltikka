# Source-context safety correction — 2026-09-16

## Result

Done locally. No activation or release is included.

Before the source change, two new tests called the public
`ContractInterpretationValidator::validate()` with the unchanged valid A output
from `EnergyRulesFixture` and additional source text:

- `Reliable energy is indexed to Nord Pool.`
- `Reliable energy has a consumption effect.`

Both calls returned an empty error list. Both regression tests failed, as expected.
This reproduced the unsafe acceptance without an application LLM call.

## Change

`EnergyRuleSourceProof::isNonPricing()` no longer accepts arbitrary suffixes after
marketing, service or contact prefixes. Marketing permits the bounded energy phrase
and optional `kotiisi` or `for your home`. Service text permits the existing bounded
verb and optional `arkisin`. Existing welcome, renewable-origin, ordering and
service-hours clauses remain valid.

Contact labels must have one complete email address, checked with
`FILTER_VALIDATE_EMAIL`, or a bounded phone form with 7–15 digits. No DNS or network
check runs. The existing `Contact our support team` category has its own whole-clause
form. Unknown appended text fails, whether it is in the same sentence or a separate
sentence. Tests also use unknown exchange wording without a keyword-screen match:
the closed clause grammar, not a larger blacklist alone, provides this protection.

Product-name screening now includes Nord Pool, index, Spot, Finnish exchange and
consumption-effect signals. A word-only `Nord Pool indexed` pricing name cannot
bypass the proof gate.

Regression tests call the public validator. They require a complete-source-proof
error for unsupported text, then verify that an explicit Unknown rule can pass with
the same source text unchanged. They do not remove conditions or accept qualified
guarantees. Existing cross-day adjustable bounds and conflict windows are unchanged.

## Files changed in this unit

- `laravel/app/Services/ContractInterpretation/EnergyRuleSourceProof.php`
- `laravel/tests/Unit/EnergyRuleSourceContextTest.php`
- `laravel/app/Services/ContractInterpretation/AGENTS.md`
- `tasks/source-validated-energy-rules/source-context-hardening.md`

The Interpretation `CLAUDE.md` remains a symlink to `AGENTS.md`.

## Verification

Commands ran from `laravel`, except the Git checks:

1. `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='EnergyRuleSourceContextTest::test_marketing_prefix'`
   - Before the source fix: **2 failed, 2 assertions**, 0.27 seconds. Both public
     validator calls incorrectly returned no errors.
2. `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='EnergyRuleSourceContextTest'`
   - After the fix: **15 passed, 139 assertions**, 0.39 seconds.
3. `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='EnergyRuleSourceContextTest|EnergyRuleSourceProofTest|EnergyRuleSourceReviewTest|SourceValidatedEnergyRulesTest|ContractInterpretationProfileTest'`
   - **57 passed, 457 assertions**, 1.07 seconds, exit 0.
   - Includes source proof, source review, profile isolation, publication and current
     batch proof, including cross-day A→B→A reuse without model work.
   - Log: `/tmp/energy-source-context-hardening-tests.log`.
4. `vendor/bin/pint --test app/Services/ContractInterpretation/EnergyRuleSourceProof.php tests/Unit/EnergyRuleSourceContextTest.php`
   - Passed, exit 0.
5. `git diff --check`
   - Passed, exit 0. Source diff and final Git status reviewed.

No network, production operation, real application LLM call, migration command,
commit or push ran. Database tests used isolated in-memory SQLite. No financial
kernel, current normal episode code, shared fixture, task JSON, default profile or
publication code was changed. The pre-existing working tree remains in place.
Broader language coverage and producer activation remain outside this unit.
