# Strict transport release check — 2026-09-16

## Result and limits

The bounded consumer repair and local preflight guard are complete. This is not a release approval. The manager must make new private baseline and candidate runs. No real export, network access, production operation, LLM call, deployment, commit or history rewrite was part of this unit. Existing artifacts remain unchanged.

The reported Kerava same-phase fixed energy plus Spot margin is **not** made valid by this repair. Its Current financial exclusion belongs to the separate calculator unit. This unit does not change the calculator or SourcePremium DTO fields.

## Consumer invariants

`ContractPricingViewData` now accepts `base_only_hybrid` with `forward_curve_spot` or `rolling_365_spot` only when:

- `is_estimate` is true and the existing consumption-effect rule passes. An optional effect record must say `present=true`; unknown effect values stay unknown.
- The existing strict Spot validator accepts the complete estimate. The method must match its forward or rolling-fallback basis.
- The resolved phase records contain both fixed-base and Spot usage. Inclusive windows do not overlap. A Spot phase has a margin and no fixed energy display rate. A fixed phase has no Spot margin. No package is admitted by this extension.

The check sorts only a local copy to compare windows. It does not change the payload, its labels, dates, amounts, totals or order. Missing estimates, `none`, wrong basis and conflicting phase usage still fail. This is transport validation, not a second financial calculator.

`HybridSpotTransportTest` calculates a synthetic six-month fixed-base to six-month Spot timeline under Current policy for both methods, then checks exact round-trip hydration. Negative tests cover missing and wrong-basis estimates in both directions, missing estimate certainty, absent effect, same-phase energy, overlapping inclusive dates, and missing distinct base/Spot usage.

## Preflight release gate

`compare.php` validates **every full raw outcome**, including excluded outcomes, with the selected app tree's `ContractPricingViewData::fromArray()` before the unchanged `withoutProse` audit projection. Reflection proof includes the selected reader; complete selected-tree loading and file hashes remain in force.

Each phase has `transport_validation`:

- `checked_count`, `valid_count`, `error_count` count all outcomes.
- `errors` retains at most 100 records with contract ID, closed `read_model_rejected` reason, and `InvalidArgumentException` or generic `Throwable` class. No exception message, quote, input value or stack is recorded.
- `error_detail_limit=100` states the bound. The error count is not truncated.

A reader failure does not erase an outcome, change financial counts or stop baseline diagnostics. The report retains separate before/after validation records. Missing old validation is reported as null, never treated as success. Validation time is outside the existing engine metric and inside overall wall time.

**The manager gate must require `candidate_transport_ready=true` in addition to all prior gates.** Use:

```sh
php tasks/source-validated-energy-rules/preflight/report.php \
  --baseline="$PRIVATE/new-baseline.json" \
  --candidate="$PRIVATE/new-candidate.json" \
  --output="$PRIVATE/new-comparison.json" --require-candidate-transport
```

The option writes the new diagnostic report first, then exits 2 if any required candidate phase has errors, missing validation or incomplete counts. A failed baseline alone does not block a valid candidate transport check. Exit 0 is only transport/report acceptance, not economic or deployment approval. Existing input identity, manifest, cold/warm, SQL, read-only and exclusive-output guards remain. Old prose-stripped audit arrays are still not full public payloads; do not remove phase labels to claim acceptance.

## Verification

- `cd laravel && php artisan test --filter='HybridSpotTransportTest|ContractPricingReadModelTest|ContractApiCanonicalPricingTest|CalculationApiTest|CanonicalContractPriceCalculatorTest|SpotForwardPriceEstimatorTest'`: **159 passed, 903 assertions**.
- `sandbox-exec -p '(version 1) (allow default) (deny network*)' php tasks/source-validated-energy-rules/preflight/smoke.php`: PASS. It checks raw valid and invalid transport, all four phase counts including exclusions, 105 errors with only 100 details, separate baseline/candidate readiness, missing old validation, and unchanged financial counts. All existing cent-rounding, manifest, privacy, SQL, isolation, read-only and cold/warm checks pass.
- A network-denied synthetic CLI check gave exit 0 for invalid baseline/valid candidate and exit 2 for invalid candidate. Both wrote separate new diagnostic reports. Private fixtures: `/var/folders/8t/9jkmd7qj66x4_wkg__jqdnlr0000gr/T/voltikka-transport-cli-dec183b2dee64451`.
- `laravel/vendor/bin/pint --test` on the reader, new test and three preflight PHP files: PASS. Pint first formatted only the new test and smoke changes.
- `git diff --check`: PASS.

Initial fixture corrections: the new calculator test first used an unsupported effect scope (`energy`) instead of `base_contract`; that produced comparable-estimate rather than base-only output. After the fixture correction all tests pass. The first CLI test reused an input filename as an output filename; the exclusive-create guard correctly stopped it without overwriting the input. A new private directory and distinct filenames then passed both CLI checks.
