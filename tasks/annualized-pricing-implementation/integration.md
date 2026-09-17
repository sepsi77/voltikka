# Current supplier premium integration — finite slice complete

This result supersedes the earlier incomplete integration report. It completes the manager's smaller strict supplier-adjusted slice, not the whole approved pricing policy. Reset, broader multi-phase energy prices/promotions, and nonreset Hybrid projection remain separate work.

## Integrated behavior

Current ordinary eligible OpenEnded/FixedPrice General, Time, and Season contracts now use canonical peer premiums when the own historical month reference is missing but the current curve is complete and fresh. This works through single `evaluate()`, `metricsForContracts()`, multi-consumption statistics, and the period wrapper's annual side. Exact-period billing receives no premium adjustment.

The loader reads one active supported supplier universe per Helsinki date and orchestrator instance. It selects only required contract columns. Donors require current pointed source/publication proof, first observation and publication completion no later than the comparison date, parser-valid pricing, complete promotions, and the existing strict supplier eligibility. The loader review removed the accidental same-day last-observed gate: current pointed proof remains current across midnight. Provenance records the actual last-observed date bounded by as-of, not today. Exact source proof and no-lookahead remain; a regression covers malformed proven canonical peers. It reuses `CurrentSourcePromotionEvidence`, `PromotionTermsAssessment`, `CanonicalPricingParser`, and `CurrentPriceEpisodeResolver(asOf)`. No stored premium table or raw current-price components supply rates.

Donor exact energy buckets are normalized through the existing audience VAT rule. Unknown source VAT assumes the target basis and that assumption is recorded; it is never labelled explicit raw VAT. A valid observed energy anchor supplies its existing month reference at the prior vintage. Each premium is `normalized retail bucket - VAT-matched reference`. Missing references supply no observation. Finite negative premiums remain valid.

The pure selector retains own lineage, same company, then company-balanced market priority, one weight per independent energy variant, and clone/lineage deduplication. One usable peer is enough, at lower confidence. The estimator keeps its own valid historical reference first; comparable premiums are tried before seasonal/hold fallback.

For every tail month and tariff bucket, the offset is `beta * (current forward * market VAT multiplier + selected bucket premium - current bucket rate)`. The current month and all fees stay unchanged. Actual, normal, and structured-only passes receive the same bucket adjustments and zero floors. The plausibility guard uses the actual bill profile's bucket/month weights. A test rejects a Time projection whose old representative would fall inside the band while the actual costed equivalent exceeds it.

## Interfaces and provenance

- `ElectricityContract::getLineageIdentitiesByContractIds(array): array` returns each target's sorted `contract_ids`, sorted `root_ids`, and SHA-256 of roots joined with `|`. It uses the existing canonical lineage query plus one adjacency query. Cycles fall back to sorted members; numeric string `0` is preserved. `RetailPremiumObservationService::getLineageIdentity()` now delegates to it with unchanged keys.
- `CurrentPremiumEvidenceLoader::forCandidates(candidates, contracts, anchors, CarbonImmutable asOf): array` returns optional typed premiums by requested ID. Targets keep existing eligibility. Missing company identity cannot select comparable premiums. A usable own reference or an unavailable current curve prevents peer reads. `resetMemoization()` clears the local peer universe and root identities.
- `PremiumObservation` adds optional `pricingDate` and `referencePeriodProxy`. Defaults retain exact retail/reference interval validation. Proxy mode keeps real observed price bounds separate from the containing model month, requires a calendar-normalized pricing date inside that month, and requires an earlier trade date. The containing month is not a seller guarantee. Proxy evidence carries a lower-confidence flag.
- `PremiumEstimate::toArray()` serializes source, normalized bucket spreads, counts, confidence, source companies/lineages, evidence bounds, trade dates, model reference intervals, actual pricing dates, and provenance.
- `CanonicalContractPriceCalculator::calculate()` appends optional `PremiumEstimate $premium` after `ComparisonPolicy`. Historical ignores it. The supplier request adds `energyRates`, optional `premium`, and actual `bucketMonthWeights`. The estimate adds bucket offsets and `offsetForMonthKey(month, bucket = null)`; the old scalar path stays compatible.
- New basis `forward_premium`, method `supplier_adjusted_forward_premium`, and payload policy `supplier_adjusted_forward_premium_v1` identify this changed current financial calculation. The existing `supplier_adjusted_estimate` payload contains premium provenance and named fallback reasons. The strict view boundary validates the new basis, policy, source/counts/confidence, finite named buckets, current curve date, and reference/evidence dates. Public copy states that current futures were used despite missing own history. Seasonal/hold copy no longer falsely equates a missing defensible premium with unavailable futures.
- Configured annual v2 persistence is unchanged. Its current adapter can copy the new method/basis; explicit historical replay does not load today's peers. No historical row is relabelled or rebuilt.
- `CalculatedCostPayloadSchema::VERSION` is now **18**, bumped once from 17. Later slices must keep 18.

The earlier date/full-signature anchor memo fix remains: all four current service entry points pass their date; memo keys include exact rates, metering, VAT, mechanism, date, and source/publication IDs, not average plus fee. Retry clearing also clears the existing EEX request-local reads.

## Query contract

Targets with a usable own reference skip peer reads. Targets without a current curve make only the shared available-vintage preflight, not a peer-universe query. The unpointed API fixture therefore now uses exactly **10** queries for each batch of 1, 8, or 32: five list/Spot reads, four dated episode reads, and one available-vintage query. The extra preflight is necessary so a missing own anchor cannot suppress otherwise available futures. The test also proves no peer query and no raw current components in this case.

The real-source fake-curve integration fixture uses the same query count for 1 and 8 targets. It loads peers once; a second consumption uses the cached peer universe. Retry clearing removes that evidence and observes a removed donor. Curve data remains shared through the existing provider's vintage/curve memoization.

## Tests and results

These are this slice's run results, not final integrated acceptance. Later test-fixture and loader
boundary fixes have separate owners. The manager must confirm the final run before acceptance.

New tests:
- `CurrentSupplierPremiumIntegrationTest`: real SQLite canonical/source/observation/publication fixtures with a fake market provider. It covers all four entry points, same-company/market selection, own-reference priority, current month and exact period preservation, actual/normal/structured equality, incompatible family/VAT/metering, invalid/future/expired source evidence, incomplete campaign evidence, company unknown-VAT normalization, real peer clone/company weighting, negative premiums/floors, distinct Time/Season offsets, final billed-equivalent guards, missing curve/peer fallback, bounded queries, retries, strict payload round trips, and explicit Historical ignoring a supplied current premium.
- `PremiumObservationProxyTest`: separate observed/reference bounds, mid-month prior vintage, lower confidence, finite negative spread, exact-mode rejection, and same-day trade rejection.
- Extended lineage tests prove unchanged RetailPremium hashes, two batch reads, missing IDs, and numeric-zero roots.

The previously failing `MarketResetForwardShiftTest` fixture now discloses a one-month fee promotion and its normal continuation. It measures a real 2 EUR benefit, not an unbounded 24 EUR annual promotion. Its original shared-shift invariant remains. No promotion helper or episode resolver file was edited by this slice; those owners' concurrent changes are preserved.

Verification commands:

```sh
cd laravel
php artisan test --filter='CurrentSupplierPremiumIntegrationTest|PremiumObservationProxyTest|MarketResetForwardShiftTest|ElectricityContractLineagePriceHistoryTest|SupplierAdjustedPricingTest|ContractApiCanonicalPricingTest|CurrentAsOfAnnualCostParityTest|ForwardPremiumResolverTest|RetailPremiumCollectionTest|InferredRetailPremiumCollectionTest|RetailPremiumHistoryBackfillTest|AsOfAnnualCostCalculatorTest|CanonicalContractPriceCalculatorTest|BillComparisonCanonicalPricingTest|MarketResetEstimateSurfacesTest|CurrentEpisodePricingIntegrationTest|CurrentPromotionTermsTest|CurrentPriceEpisodeChronologyTest|CurrentPriceEpisodeResolverTest|HistoricalPriceEpisodeResolverTest'
php artisan test
```

- Final focused run: **320 passed, 2668 assertions** (4.04 seconds).
- Separate current/historical episode plus premium run: **52 passed, 208 assertions**.
- Full application run: **2480 passed, 6 failed, 12884 assertions** (238.66 seconds). It ran before the final small guard/test additions. Failures remain outside the smaller premium wiring slice:
  1. `VatIntegrationTest:66`: unbounded discount fixture expected 416.06; excluded/null.
  2. `CanonicalOfferSurfacesTest:65`: untyped offer expected positive savings; got zero.
  3. `CanonicalPricingListingTest:194`: expected three listed contracts; got two.
  4. `ContractPriceStatisticsCanonicalSourceTest:100`: unknown promotional continuation expected one snapshot; got zero.
  5. `ContractPriceStatisticsCanonicalSourceTest:273`: unbounded normal-amount offer expected a snapshot; none exists.
  6. `WeeklyOffersCanonicalPricingTest:131`: old seven-query bound. The full run observed eight dated-episode/list reads before the added current-curve preflight; this needs the same explicit bounded-query review as the API test.
- Initial new tests exposed a zero-filled unused profile bucket lookup; the estimator now uses only the tariff's actual buckets. Test fixture corrections also accounted for inclusive 31-day period fee proration and clearing injected Spot assumptions on retry. These failures were fixed and focused tests rerun; none is hidden by weakening price guards.
- Final scoped `vendor/bin/pint --test` over all files owned by this slice passed. `git diff --check` passed. Final source diffs and `git status --short` were reviewed; concurrent promotion/episode changes remain intact. All edited context directories retain `CLAUDE.md -> AGENTS.md`. No CSS/JS build was needed.

## Safety and remaining scope

No production, network tool, LLM, migration, new flag, commit, push, deployment, stored premium write, or stored history rewrite was made. Application writes occurred only in isolated test fixtures. Root/shared task status files were not edited by this unit. Closest ForwardPremium, SupplierAdjusted, ContractPricing, RetailPremium, and ContractReplacement context files record the new boundaries; their CLAUDE links remain intact.

Strict single-phase supplier eligibility is deliberately unchanged. Multi-phase fee and energy promotions, later known energy spans, reset estimator integration, Hybrid base projection, and package-safe broader forecasts remain the next slices. Do not treat this result as the whole approved policy or as a verified release while the six full-suite regressions remain.

## Manager review fixes: current premium donor safety

- Corrected the loader import to `CanonicalPricing\Exceptions\CanonicalPricingParseException`. A malformed canonical donor with matching source and publication pointers is now excluded. A valid alternative still supplies the target premium.
- Removed the unapproved same-day `last_observed_at` floor. Active, exactly pointed published evidence remains eligible across midnight and import delays. First observation and publication completion must still be no later than the explicit comparison date. The current curve guard remains unchanged.
- The loader selects the actual last observation timestamp. Strict UTC timestamp parsing rejects malformed values. Its Helsinki calendar date, capped at the comparison date, supplies both `observedAt` and `pricePeriodEnd`. An anchor after that observed end is rejected. Pricing-date proxy, reference dates, VAT and uncertainty provenance remain unchanged.
- Added tests for yesterday's pointed evidence, older evidence without a new age floor, Helsinki midnight, comparison-date capping, malformed/reversed observation bounds, and future first observations. Existing future-publication coverage still passes.
- Verification: `cd laravel && php artisan test --filter='CurrentSupplierPremiumIntegrationTest|ForwardPremiumResolverTest|PremiumObservationProxyTest'` passed: **68 tests, 338 assertions**. The first run exposed a test fixture using `type` instead of `component_type`; corrected it and reran successfully. Scoped Pint initially found style issues; applied Pint only to the two owned PHP files, then `vendor/bin/pint --test` passed. Tests passed again after formatting.
- Only the two assigned PHP files and this append were changed by this unit. No core, other tests, network, production, commit, or push operation occurred. Full-suite verification remains with the assigned manager/agent. The manager must update ForwardPremium/AGENTS.md: replace its same-date source-coverage description with the retained pointed-evidence and real observed-date rules above. That context file is outside this unit's permitted ownership.
