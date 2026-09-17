# Inactive normal tariff episode evidence

## Result

Implemented locally. No current service, premium loader or public transport activation.
Defaults remain V4 and calculated-cost schema 18. No production operation, network request,
application LLM call, migration, history repair, commit or push ran.

## API and evidence rules

- `SupplierAdjustedCandidate` adds trailing `normalTariffEvidence=false`. This selects extraction,
  not economic identity. Full rates, metering, VAT and mechanism still define equality; fees do not.
- `SupplierAdjusted/CurrentNormalCandidateExtractor::candidate(id, data, context, comparisonDate)`
  requires already parsed and source-authorized data. It uses EnergyRulePlan baseline/normalSpans,
  the existing timeline, VAT copies and SupplierAdjustedEligibility weights/family guards.
- Each current normal bucket needs independent AdjustableTariff proof. Fixed-only actual maps,
  fixed normal guarantees, unknown rules, incomplete buckets, resets, packages, Spot and unknown
  billed charges cannot supply a normal donor. General, Time, Season and explicit base-Hybrid
  families retain their existing identity boundaries.
- The result is energy evidence. Its zero fee is only the existing snapshot-evidence convention.
  The next integration must supply real current fees separately; never bill this placeholder.
- The resolver uses the exact persisted profile registry. V5 requires schema-v5/prompt-v20/
  validator-v18, eligible status, source ownership, current publication/scoped-analysis identity,
  dated completion/publication and fresh full source validation. It builds validation input from
  the immutable snapshot at the observation date before parsing with energy rules.
- Both endpoints of an observation must prove the same normal map. Invalid or conflicting output
  cannot supply a favourable anchor. Source quotes remain private.
- Older compatible ordinary interpretations use the existing non-promotional ACTUAL candidate
  path. They do not gain V5 normal facts. Legacy energy promotions cannot become normal donors.
- Bare raw snapshots/components never prove normal semantics, regardless of their age. NORMAL
  mode does not reopen them after an immutable gap. An internal gap restarts normal continuity.
  A right gap keeps the original dated anchor only for the requested contract's still-current,
  matching, source-validated pointed observation whose normal map remains valid at asOf. It does
  not fabricate a later observation. Ended/non-current evidence stays closed. Default mode keeps
  its existing raw-history/gap-proxy behavior.
- Only observation chronology dates a normal-price episode. Phase starts, rule starts and
  guarantee expiry do not become anchors. Actual 4 / normal 9 dates the observed normal 9.

## Files

- Modified `SupplierAdjusted/DTO/SupplierAdjustedCandidate.php` (one trailing option).
- Added `SupplierAdjusted/CurrentNormalCandidateExtractor.php`.
- Extended `SupplierAdjusted/CurrentPriceEpisodeResolver.php` and its existing batch columns.
- Added `tests/Feature/CurrentNormalEpisodeEvidenceTest.php`.
- Updated `SupplierAdjusted/AGENTS.md`; its CLAUDE.md remains a symlink.

The calculator, EnergyRulePlan and kernel tests were not edited. The large prior change set was
preserved. Shared task JSON and root context were not edited by this unit.

## Verification

Run from `laravel` with SQLite `:memory:` from PHPUnit. New tests fake HTTP and prevent stray
requests. Direct extractor tests use explicit parsed fixture data; episode tests use persisted
immutable snapshots and full validator proof. No application LLM is involved.

Final commands:

```sh
DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter=CurrentNormalEpisodeEvidenceTest
DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='CurrentNormalEpisodeEvidenceTest|Episode|Supplier|Historical'
vendor/bin/pint app/Services/CanonicalPricing/SupplierAdjusted/DTO/SupplierAdjustedCandidate.php app/Services/CanonicalPricing/SupplierAdjusted/CurrentNormalCandidateExtractor.php app/Services/CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php tests/Feature/CurrentNormalEpisodeEvidenceTest.php
vendor/bin/pint --test app/Services/CanonicalPricing/SupplierAdjusted/DTO/SupplierAdjustedCandidate.php app/Services/CanonicalPricing/SupplierAdjusted/CurrentNormalCandidateExtractor.php app/Services/CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php tests/Feature/CurrentNormalEpisodeEvidenceTest.php
```

- Focused: 11 passed, 137 assertions, 1.46 seconds.
- Related: 266 passed, 4,329 assertions, 25.67 seconds.
- Batch check: exactly four resolver queries for 1, 8 and 32 normal candidates. No per-candidate
  source query. Full validation is CPU work on batch-loaded documents.
- Pint: passed on the four PHP files. The first format pass corrected formatting only.
- `git diff --check` from repository root: passed. Final diff and status reviewed.
- Initial focused failures were fixture errors: a fee had a non-null energy rule; changed normal
  15 needed a matching structured reduction; a fixed normal basis needed finite bounds. The
  fixtures were corrected and now assert full source validity before persistence.
- No full suite or asset build ran. There are no CSS or JavaScript changes.

## Manager review corrections

- `SupplierAdjustedEligibility` now has a default-off constructor option `currentNormalEvidence`.
  The proved normal-map extractor alone enables it. EstimateRequired is accepted without changing
  the source calculation status. Default/Historical eligibility and rejection of ordinary
  Incomplete/Unsupported remain intact. A full persisted V5 source/episode test covers the case.
- All fixed NORMAL spans in the supported plan window reject this bounded ordinary-adjustable
  donor, including future spans. A fixed ACTUAL promotion with adjustable NORMAL remains valid.
  The regression first proves that the kernel can build the map, then checks donor rejection.
- The resolver no longer introduces a one-day freshness cutoff. An unchanged pointed normal
  publication retains its original anchor after midnight when its normal map still applies at
  asOf. Internal gaps, ended/non-current evidence, conflicts and expired scope stay closed.
  The midnight regression checks both anchor retention and unchanged stored observation dates.
- No kernel, source prover, calculator or calculator test helper was edited.

Final review commands, from `laravel`:

```sh
DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter=CurrentNormalEpisodeEvidenceTest
DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config php artisan test --filter='CurrentNormalEpisodeEvidenceTest|Episode|Supplier|Historical'
vendor/bin/pint app/Services/CanonicalPricing/SupplierAdjusted/CurrentNormalCandidateExtractor.php app/Services/CanonicalPricing/SupplierAdjusted/SupplierAdjustedEligibility.php app/Services/CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php tests/Feature/CurrentNormalEpisodeEvidenceTest.php
vendor/bin/pint --test app/Services/CanonicalPricing/SupplierAdjusted/CurrentNormalCandidateExtractor.php app/Services/CanonicalPricing/SupplierAdjusted/SupplierAdjustedEligibility.php app/Services/CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php tests/Feature/CurrentNormalEpisodeEvidenceTest.php
```

Focused: **14 passed, 156 assertions**, 1.97 seconds. Related: **269 passed, 4,348 assertions**,
25.92 seconds. Pint and diff check passed. Initial review checks found a fixture missing parser
citations and an incompatible optional method argument in a test subclass. The fixture now has
citations; the option moved to the constructor, preserving the existing method signature and all
subclasses. No calculator test helper change was needed. Final diff and status were reviewed.
Service activation stays off; schema18/defaultV4 are unchanged. No network or production operation.

## Limits for the next unit

This is an inactive evidence boundary, not a pricing engine or a premium-selection integration.
Current service parser opt-in, current premium donors, real fee supply and public offer transport
remain separate work. No claim of deployment or full annualized-policy completion is made.
