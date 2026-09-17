# Current energy-episode unit

## Result

Done with exceptions. The current resolver now uses trusted lineage identity and full canonical energy signatures where immutable evidence proves them. It does not infer Time or Season buckets from weighted averages. Historical AsOf resolution is unchanged.

Remaining evidence work is explicit:
- The narrow follow-up below adds pre-immutable Time/Season `price_components` evidence when a same-date observed household snapshot proves identity. Component-only dates, Company VAT0 history, and promotional energy rows remain unknown. Compatible General/FixedPrice snapshots still prove an exact singleton.
- Historical immutable extraction uses the existing strict ordinary single-phase eligibility. Multi-phase history, including redundant or fee-only phases, remains missing rather than inventing a signature. Phase count is not part of the candidate signature. The manager's broader phase resolver can later supply this evidence.
- The later supplier integration completed the dated orchestrator and memo work below. This episode unit itself did not edit cache or routing.

These are evidence-availability limits, not current numeric fallbacks. The follow-up adds one bounded historical `price_components` query for requested Time/Season lineages only. It does not add a current relational rate fallback.

## Narrow pre-immutable full-tariff follow-up

The public `resolve(array $candidates, ?CarbonInterface $asOf = null): array` interface is unchanged. Only the resolver, current chronology tests, and these notes changed in this follow-up. The integration agent owns candidate, core, premium-loader, model, and AGENTS context updates.

- One extra compact-projection query reads only requested Time/Season trusted lineages, no later than the explicit as-of date and strictly before each carrier's first immutable observation. The query joins same-date `observed_seller_data` snapshot identity. It does not load full Eloquent history or all-market prices. General-only resolution uses four queries and Time/Season uses five. After supplier integration, the General API uses ten, including the current-curve availability preflight.
- Observed statistics membership proves the household VAT-inclusive convention: the statistics collector admits only Household/Both/null targets. The same-date snapshot must also prove matching metering, FixedPrice, and OpenEnded identity. Missing identity or selected Company VAT0 remains unknown. Current explicit component VAT never supplies historical VAT facts or converts raw historical rates.
- DayTime/NightTime map to canonical day/night. SeasonalWinter and SeasonalWinterDay share the canonical winter bucket; SeasonalOther maps to the other-season bucket. Every bucket needs a finite nonnegative rate and a recognized cents/kWh unit. Null or unknown units, missing buckets, conflicting duplicates, and energy discount metadata remain unknown. Identical duplicates are order-independent. Monthly fees are not queried. Representative averages are never used to prove these rates.
- Historical energy promotion resolution remains conservative: any discount flag or residual value/window metadata rejects that dated signature. This follow-up does not resolve customer-relative promotional windows. It does not broaden current eligibility or alter contract guarantees.
- A February full-tariff run can continue through July canonical evidence without moving its observed start. The basis stays `observed_seller_snapshot_run`, with `observed_full_tariff_energy_evidence` and, when applicable, `canonical_source_energy_continuation`. Canonical-only starts keep their existing source basis. Gaps, including missing February 12, keep explicit uncertainty. A→B→A full-rate changes break the run even when both tariffs average 8.5.
- Raw rows cannot reopen an invalid, missing, or uncovered period after immutable chronology begins. Unknown earlier evidence followed by valid source evidence gives a later left-censored observed proxy with uncertainty flags, not a claimed repricing date. Current canonical rates remain unchanged.

Follow-up verification:
- `cd laravel && php artisan test --filter='CurrentPriceEpisodeResolverTest|CurrentPriceEpisodeChronologyTest|SupplierAdjustedPricingTest|HistoricalPriceEpisodeResolverTest'`: 38 tests passed, 212 assertions before the final extra edge tests.
- Final expanded run: `cd laravel && php artisan test --filter='CurrentPriceEpisodeResolverTest|CurrentPriceEpisodeChronologyTest|SupplierAdjustedPricingTest|HistoricalPriceEpisodeResolverTest|ContractApiCanonicalPricingTest'`: 52 tests passed, 313 assertions. This includes all three General API nine-query fixtures and the multi-lineage five-query resolver fixture.
- `cd laravel && php artisan test --filter='CurrentEpisodePricingIntegrationTest'`: 5 tests passed, 16 assertions.
- `cd laravel && vendor/bin/pint --test app/Services/CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php tests/Feature/CurrentPriceEpisodeChronologyTest.php`: passed. Pint previously changed only test import style.
- `git diff --check`: passed. Final diff and working-tree status reviewed. No production/network operation, migration command, durable data write, commit, or push occurred. Tests used isolated SQLite fixtures.

The later documentation integration updated `SupplierAdjusted/AGENTS.md` with these evidence limits. Verification counts above remain this unit's run history, not final integrated acceptance.

## Changed interfaces

Files are under `laravel/app/Services/CanonicalPricing/SupplierAdjusted/` unless stated otherwise.

`DTO/SupplierAdjustedCandidate` retains its three positional arguments, then adds:

```php
?array $energyRates = null, // canonical component-type keys, normalized float c/kWh
?string $metering = null,
bool $includesVat = true,
string $pricingMechanism = 'FixedPrice',
```

`normalizedEnergyRates(): ?array` returns sorted rates. A legacy three-argument caller can only imply a General singleton. Explicit Time/Season without exact rates returns null. `hasSameEnergySignature(self $other): bool` compares sorted bucket keys, every finite rate (0.0001 tolerance), metering, VAT, and mechanism. It ignores ID, representative average, and fee. These read-only helpers and the resolver accept the same candidate input for later peer evidence work.

`SupplierAdjustedEligibility::candidate()` now passes its existing exact rates and context metering/VAT/mechanism to the DTO. No eligibility condition was broadened. The calculator's existing normalization remains authoritative.

`CurrentPriceEpisodeResolver::resolve(array $candidates, ?CarbonInterface $asOf = null): array` now uses the shared model batch lineage API. The optional date uses Helsinki calendar dates and defaults to today. Four batch queries load lineage IDs, selected snapshot fields, source-observation intervals joined to source/publication identity, and interpretations completed by the explicit date. Empty input makes zero queries. No per-contract DB query or full Eloquent price history load was added.

`PriceEpisodeEvidenceBasis::CanonicalSourceObservationRun` adds the value `canonical_source_observation_run`. `PriceEpisodeAnchor` itself is unchanged.

## Evidence rules

- IDs and fees cannot move the energy anchor. Full tariff changes, including 10/6 to 9.4/7 with the same 8.5 representative, do move it. A→B→A does not merge.
- Immutable source identity, observation-scoped analysis, published/superseded state, validation errors, current publication pointers, parser checks, and VAT normalization remain required. Publication-pointer equality alone no longer supplies an anchor.
- Snapshot fallback stays closed from a carrier's first immutable source observation onward, including unknown gaps and invalid or conflicting canonical evidence. Missing source payloads also stay closed.
- Legacy same-date observed singleton evidence takes precedence only on that date. Multi-rate snapshot averages are never exact singleton evidence.
- Source intervals keep their actual observed coverage. Matching observations across missing dates can share an observed start, with `price_episode_observation_gap`; later unobserved time has `price_episode_right_observation_gap`. Every proxy has `price_episode_left_censored` and `price_episode_observed_proxy`. None proves a hedge date or actual repricing day.
- Unknown/conflicting evidence clears the run. Flags distinguish different observed signatures, conflicting signatures, and unknown/possibly conflicting evidence. Missing anchors carry `missing_price_episode_anchor` and `full_energy_signature_not_proven`.
- Source observations, snapshots, and interpretation completion after the explicit date are excluded. Source coverage is capped at that date.

## Manager integration contract (now wired locally)

The later supplier slice completed this wiring; retain the contract below. Final integrated
verification is still subject to manager acceptance.

The request memoization key must contain contract ID, sorted exact energy rates, metering, VAT basis, pricing mechanism, and explicit Helsinki as-of date. Retain current source-observation/publication identity fencing and retry-local clearing. Average plus fee is no longer sufficient: 10/6 and 9.4/7 collide at 8.5. Fees do not belong to energy identity, though keeping them as an extra cache discriminator only causes extra reads.

Pass the pricing request date into `resolve()` instead of silently using today's default for a dated calculation. The DTO has no new source-pointer fields; obtain pointer identity from the existing contract/current-evidence boundary. Cache-schema integration remains with the manager. Do not change the separate `HistoricalPriceEpisodeResolver` path.

## Verification

- Initial focused run: `cd laravel && php artisan test --filter='CurrentPriceEpisodeResolverTest|CurrentPriceEpisodeChronologyTest|SupplierAdjustedPricingTest'` passed: 25 tests, 151 assertions.
- Expanded focused run: `cd laravel && php artisan test --filter='CurrentPriceEpisodeResolverTest|CurrentPriceEpisodeChronologyTest|SupplierAdjustedPricingTest|HistoricalPriceEpisodeResolverTest'` passed: 33 tests, 178 assertions.
- Pint initially reported spacing/docblock style in the resolver. The resolver-only formatter then passed. Final repeat of the expanded focused test command passed: 33 tests, 178 assertions. Final `vendor/bin/pint --test` over the six changed PHP files passed. `git diff --check` passed, final diff/status review completed, and the SupplierAdjusted CLAUDE symlink check passed. `git diff --numstat -- laravel/app/Services/ContractStatistics/HistoricalPriceEpisodeResolver.php` was empty.
- Tests cover trusted branches/cycles/self-links/missing roots, constant query count, fee and ID continuity, full Time changes and recurrence, incomplete multi-rate evidence, gaps, source conflicts, pointer mismatch, invalid and future interpretations, future observations/snapshots, VAT conversion, and malformed output.
- Closest SupplierAdjusted `AGENTS.md` records implementation and remaining limits. Its `CLAUDE.md` remains a symlink. Root and shared task context files are not edited by this unit.
- No production or network operation, commit, push, migration, dependency, or durable application-data write occurred. Test fixtures used isolated SQLite databases only.
