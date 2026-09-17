# Premium public privacy correction — 2026-09-16

## Addendum: strict cached/raw premium boundary

Complete locally. `ContractPricingViewData` now rejects unknown or private premium fields, missing
public fields, unknown flags, malformed reference lists/records/dates, and uncontrolled provenance.
The exact public keys and flags are shared with `PremiumEstimate`; provenance uses its typed
family, proxy and VAT formatter. Nullable pricing dates remain valid. No keyword blacklist is used.
The common validator covers Supplier and Reset estimates, nested normal/actual projections and
any supplied premium even when its enclosing basis is changed. Valid payloads round-trip exactly;
invalid payloads throw rather than being sanitized. Financial code, Hybrid Spot timeline validation,
internal observation evidence, selection, schema versions and preflight tools are unchanged.

`PremiumPublicPrivacyTest` now checks raw private company/lineage fields, observation/publication
IDs, source quotes, unknown keys, missing keys, malformed metadata and basis-bypass attempts in
both top-level estimates and nested normal projections. All typed family/VAT/proxy combinations,
all public flags and nullable pricing dates pass exact reader round trips. Financial assertions
are unchanged. No existing fixture needed correction.

Final verification from `laravel/`:

- `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-premium-privacy-no-config php artisan test --filter=PremiumPublicPrivacyTest`:
  **2 passed, 2,632 assertions**.
- Same environment, `php artisan test --filter='PremiumPublicPrivacy|ForwardPremium|PremiumObservationProxy|ContractPricingReadModel|ContractApi|CalculationApi|SourceValidatedEnergyRules|EnergyRule|SourceEnergy|Historical|CurrentSupplier|CurrentReset|HybridSpotTransport'`:
  **511 passed, 8,934 assertions**, 38.73 seconds. Log: `/tmp/voltikka-premium-boundary-tests.log`.
- `vendor/bin/pint` and `vendor/bin/pint --test` on `PremiumEstimate.php`,
  `ContractPricingViewData.php` and `PremiumPublicPrivacyTest.php`: passed.
- `git diff --check` and context-pair checks: passed. Final diff and status reviewed.

No production, network, LLM, commit, build or preflight operation ran. The manager must run new
sealed full-outcome baseline/candidate checks. Old redacted audit rows were not repaired or used.
The earlier producer-only result below is retained as history.

## Result

Complete locally. No production or network operation, commit or push ran. No financial formula,
selection order, evidence acceptance rule, cache version or estimator DTO changed. Candidate
schema 19 has not been deployed and needs no further version increase for this correction.
Shared root and status documentation remains with the documentation agent.

## Public and private boundaries

`PremiumEstimate::toArray()` no longer publishes `source_companies` or `source_lineages`.
Each reference keeps the existing `provenance` key, but its value now comes only from the typed
family, the reference-period proxy boolean and the normalized VAT basis. Caller-supplied prose,
observation/publication IDs, donor names, energy signatures and lineage tokens cannot enter that
text. The existing `target_audience_vat_normalized_unknown_assumed` wording describes the audience
normalization convention. It does not assert that the original source supplied explicit tax facts.
The eight existing selector flags are permitted; arbitrary caller flags are omitted.

The public payload still has source category, bucket premiums, lineage/company/observation/variant
counts, confidence, evidence date bounds, reference trade dates, and each reference's pricing date,
delivery bounds and proxy fact. No new source certainty or tax fact is inferred.

Internal readonly properties are unchanged. Full source collections, observation provenance,
source/publication identities in that provenance, energy signatures and flags remain available
for private audit. The resolver still retains equal observations with different provenance without
adding independent weights. No loader or observation DTO change was needed.

Supplier and Reset serializers already call this boundary. Nested V5 normal projection transport
therefore receives the same data-minimal payload. This change does not sanitize arbitrary external
legacy payloads; it corrects the candidate's public serialization at its producer boundary.

## Regression coverage

`tests/Unit/CanonicalPricing/PremiumPublicPrivacyTest.php` checks all four families, both VAT bases,
exact/proxy references and all three source tiers. It checks private sentinel removal, internal
retention, unchanged source selection, two audit observations with one independent weight, premiums,
counts, dates, confidence and controlled flags. It also checks direct Supplier/Reset serialization,
real V5 calculated-cost normal projection output and the strict `ContractPricingViewData` round trip.
The projected normal total remains EUR 119 for 1,200 kWh with the supplied monthly offsets.

The existing CurrentSupplier integration assertion for `unknown_assumed` remains unchanged and passes.

## Verification

Commands run from `laravel/`:

- `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-premium-privacy-no-config php artisan test --filter=PremiumPublicPrivacyTest`
  initially had one test-only expected-total error (expected 121, actual 119). The fixture arithmetic
  was corrected to 9 + 11 × 10 = 119. No implementation change followed that failure.
- `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-premium-privacy-no-config php artisan test --filter='PremiumPublicPrivacyTest|ForwardPremium|PremiumObservationProxy|CurrentSupplier|CurrentReset|CurrentEnergyRuleFinancialIntegration|EnergyRulePublicOutput|EnergyRuleEstimateDisclosure|ContractApiCanonicalPricing|ContractApiTest|ContractPricingReadModel'`
  passed: **270 tests, 6,180 assertions**, 33.82 seconds. Log: `/tmp/voltikka-premium-privacy-tests.log`.
- `vendor/bin/pint app/Services/CanonicalPricing/ForwardPremium/PremiumEstimate.php tests/Unit/CanonicalPricing/PremiumPublicPrivacyTest.php`
  passed. The same file list with `vendor/bin/pint --test` passed.
- The dedicated test command above passed after Pint: **2 tests, 1,582 assertions**, 0.22 seconds.

No CSS or JS changed, so no asset build ran. No full-suite result is claimed for this bounded unit.
`git diff --check` passed. Final source changes and `git status --short` were reviewed; shared
work remains intact. `cmp` passed for the ForwardPremium AGENTS/CLAUDE pair; CLAUDE remains a
symlink to AGENTS.md.
