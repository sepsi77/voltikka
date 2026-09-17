# Current financial service integration

## Current audit follow-up

The NULL-success reader repair, estimate disclosures, sealed replay and SQLite diagnosis are
complete locally. Current schema is 19, versus deployed 17. See `audit-fixes.md` for the manager's
2766-test gate and all replay deltas. The replay is intermediate: review proved legacy no-discount and overlap-end anchor defects.
Both repairs pass focused/related tests. The final manager gate passes 2773 tests / 18253 assertions
and Laravel/Remotion builds; the final sealed replay resolves broken anchors. Material premium and
unknown-restart amount acceptance remains pending; restored
forecasts are not automatically improvements. Fee metadata and clamp flags changed without financial math
changes; absent actual projection provenance remains null. Historical intentionally retains its
narrower identity/reference behavior. The reset enable flag is not a global estimator switch.

## Result

Implemented locally. This is not producer activation or deployment. Default V4, Historical parser/assets/hashes, annual-v2 and beta are unchanged. The later release-boundary unit bumps the shared calculated-cost schema once from 18 to 19. The manager's fresh combined LOCAL PHP acceptance gate passed; see `final-verification.md`. Remotion toolchain checks now pass. Bounded synthetic video repair/QA now passes; broader video limits and production approvals remain separate release work. Final repaired-tree verification and replay are complete, not economic acceptance; see `release-plan.md`.

## Service and evidence boundary

- All four current service paths opt into typed rules only with exact batched `energy_rules_valid` proof.
- The same batch now returns `energy_rules_required` for a V5 publication or known-rule JSON. Failed required proof excludes the offer and peer; it cannot become legacy fixed billed-price math. A local shape discriminator rejects unpointed known-rule JSON without another query. Ordinary V4/Unknown behavior stays unchanged.
- `periodEvaluationsForContracts()` uses today's publication cutoff for today's available offers. Its hypothetical past bill start still governs relative bill terms and dated annual evidence; it is not a publication cutoff. Factual period pricing never receives annual projections.
- Periods build a separate unprojected rule plan. Own energy bounds remain valid across fee phases; published normal rates and source operators enter the existing hourly-consumption and days/30 fee calculation. Actual-only periods keep null normal totals and zero measured savings. Period assumptions are local: an annual clipping or continuation marker cannot describe a period where it did not occur. Legacy V4 period billing is unchanged.
- Episode memo identities contain actual/normal evidence mode, full tariff rates, context/date and source/publication identity. Normal-rule identities also contain the canonical evidence. Ordinary fee-only memo behavior stays unchanged.

## Financial integration

- `normalEnergyTargetData()` prepares estimator input from the plan's independently proved current normal map. It supplies the actual current fee, not the energy extractor's zero-fee placeholder. Original fee phases remain in billing.
- Target eligibility is broader than donor eligibility. A fixed normal span or a finite ordinary non-promotional guarantee can need forecasting after its end, but does not prove an ordinary monthly own-reference date. These targets use a missing own anchor, then existing compatible premiums, seasonal shape or explicit hold.
- Supplier and Reset calculations reuse the existing estimators, reference provider and premium selector. Own reference remains first. Transferred evidence keeps trusted lineage, company and company-balanced market order, tariff/VAT/cadence/family compatibility and economic deduplication. Peer reads remain lazy.
- Source-authorized peers contribute normal9, not promotional4. The private loader retains original source/publication provenance. No relational price fallback or stored premium write is added.
- Reset normal repricing follows its recurring period/cadence, not the actual promotional lock. Actual4 can stay fixed across a normal reset. Reset scalar checks use the customer's usage weights; the projection retains the complete normal bucket map. Zero current normal is a valid rate.
- The paired kernel applies normal offsets once before the source operator and source floor. It retains the existing nonnegative model floor, VAT normalization, consumption profile, fees and real-term clipping. Complete actual-only locks need no forecast query.
- Estimated formula clipping without a source floor adds the public `energy_rule_nonnegative_model_floor_applied` assumption. It never inserts `floor_amount=0`. Tests distinguish model clipping, an explicit source floor and an observed zero.
- Source-rule phase rows emit boolean `energy_price_guaranteed`. Only one unchanged, explicitly fixed actual billed rate across the complete span can make it true. Different Time/Season rates, formula or normal-price certainty, an exact overall total and base-only Hybrid cannot make it true.
- Estimated actual results use `SourceEnergyRules`. Exact actual retains None, TermPriceAnnualized or HybridBaseOnly even when normal savings are estimated. Normal forecasts are not attached as actual Supplier/Reset estimates. The comparison records billed energy euros × 100 / costed kWh. Source-rule results omit the legacy blanket hold-current assumption; typed normal projection and explicit hold provenance state the actual basis.
- `Support\EnergyRuleOfferTerms::build()` is connected in `costWindow`. Signed net benefit, normal availability and genuine terms remain the public eligibility rule. Public DTO/copy implementation belongs to the parallel public unit.

## Changed price regimes and actual-only terms

- A later ordinary known11 wins on its own period. Unknown months after it hold11; they cannot resume old9 or its old offsets. The phase start never becomes an observation date. The outcome records `energy_rule_latest_known_price_continuation` when it uses this estimate.
- Fully known timelines remain exact. Disjoint normal9 → normal12 guarantees retain their own dates and costs. A later applicable normal lock takes priority over an older adjustable quote; it cannot become an earlier anchor. Unknown tails hold the latest map unless the current map has valid forecast evidence.
- Dated expired ordinary and normal announcements remain available on later comparison dates. Old9 cannot return after11 expires. A known return to9 after11 also cannot restore the original9 observation or offsets. Superseded normal maps are excluded from current-normal fields, forecast targets and donors. A newly dated normal quote can replace an expired lock without that past lock blocking current donor eligibility.
- An ongoing non-promotional current quote remains a forecast target after its separate absolute guarantee expires. The expired guarantee is not a current guarantee or monthly reference.
- Contradictory supplied normal projections and overlapping conflicting guarantees remain excluded.
- The full-source Cheap fixture proves actual7.49 for one month but not current-normal9.95 or a dated later lock. The actual-only plan costs that month, then uses the scoped announced9.95 as an explicit estimate. It does not fill an earlier gap, invent a normal premium anchor, or expose a measured benefit. Normal totals, projection and current-normal rates stay null. No actual forward projection is used in this fallback, so `actualProjection` stays null.
- Missing actual continuation after an unidentified promotion remains excluded. The rule plan does not add a new forecasting engine or arbitrary age/sample thresholds.

## Verification

Tests use isolated SQLite, local fake futures and prohibited stray HTTP. No network, production operation, real application LLM call, migration/history repair, commit or push was performed.

Commands ran from `laravel/` with `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config`.

- Targeted financial/source regression: `php artisan test --filter='CurrentEnergyRuleFinancialIntegrationTest|EnergyRuleKernelTest|CurrentSupplierPremiumIntegrationTest|CurrentResetPremiumIntegrationTest|CurrentEpisodePricingIntegrationTest|CompareCanonicalPricingCommandTest|SourceValidatedEnergyRulesTest'`: **199 passed, 4153 assertions**, 36.23 seconds. Log: `/tmp/energy-financial-targeted-final.log`.
- New real-service coverage includes persisted V5 proof; A/B fixed versus formula pricing; all four service paths; a newly published offer against a past bill; own and transferred Supplier/Reset premiums; exact actual versus estimated normal; real fees; trusted ID/fee continuity; expiry; zero/percentage/floors; General/Time/Season/VAT; six-month Hybrid; normal reset versus actual guarantee end; later11 and gaps; malformed/unpointed rules; full-source Cheap; finite ordinary guarantees; and no forecast reads for full fixed terms.
- Batch measurement at **1 / 8 / 32** targets: **5 / 5 / 5 SQL queries** with own references and three consumptions; **13 / 13 / 13** with transferred normal premiums and two consumptions. The tests assert these counts. Log: `/tmp/energy-financial-query-counts.log`.
- First full suite: 2737 passed, 2 failed, 17693 assertions. The failures were the new discriminator's malformed-phase iteration and an overly broad ordinary anchor memo signature. Both were corrected and their focused tests pass.
- A later focused run also found a parallel public test's unsupported `eur_once` fixture unit. The manager was told; this unit did not edit that public test.
- Full suite after the two corrections: `php artisan test`: **2746 passed, 17762 assertions**, 138.76 seconds. Log: `/tmp/energy-financial-full-final.log`.
- Final focused run after the explicit latest-known continuation assumption and incomplete-future target coverage: `php artisan test --filter='CurrentEnergyRuleFinancialIntegrationTest|EnergyRuleKernelTest|CurrentEpisodePricingIntegrationTest|CompareCanonicalPricingCommandTest'`: **54 passed, 624 assertions**, 10.07 seconds. Log: `/tmp/energy-financial-last-targeted.log`.
- Earlier full suite before the final public hooks and date cases: `php artisan test`: **2748 passed, 17781 assertions**, 136.33 seconds. Log: `/tmp/energy-financial-release-gate.log`. This also includes the parallel public/source work present at test time.
- Final wide focused regression: `php artisan test --filter='CurrentEnergyRuleFinancialIntegrationTest|EnergyRuleKernelTest|CurrentSupplierPremiumIntegrationTest|CurrentResetPremiumIntegrationTest|CurrentEpisodePricingIntegrationTest|CompareCanonicalPricingCommandTest|SourceValidatedEnergyRulesTest|EnergyRulePublicOutputTest|CurrentNormalEpisodeEvidenceTest'`: **232 passed, 4520 assertions**, 35.66 seconds. Log: `/tmp/energy-financial-final-focused.log`.
- Full suite with the public hooks, model-floor marker, expired ordinary/current guarantees and changed normal-map cases: **2752 passed, 17881 assertions**, 136.80 seconds. Log: `/tmp/energy-financial-complete-full.log`.
- A final new dated-normal unit test first failed because it used an incorrect parser argument name. The test now uses `withEnergyRules`; no parser behavior changed. Its focused group then passed: **82 tests, 970 assertions**. Full suite after that change: **2753 passed, 17883 assertions**, 135.16 seconds (`/tmp/energy-financial-final-complete.log`).
- The period boundary regression first proved that actual-only Cheap had an unjustified `normalPeriodTotal=12.39`. It now retains actual7.49, null normal and zero measured savings. Formula periods do not inherit annual clipping flags. A fixed/formula fee-split unit test proves separate energy bounds, unchanged hourly consumption/fee proration and no annual forecast in period costs.
- Latest focused period/financial/public/normal/bill regression: `php artisan test --filter='CurrentEnergyRuleFinancialIntegrationTest|EnergyRuleKernelTest|CanonicalPeriod|CurrentNormalEpisodeEvidenceTest|EnergyRulePublicOutputTest|BillComparison'`: **131 passed, 1250 assertions**, 14.35 seconds. Log: `/tmp/energy-financial-final-period-focused.log`.
- Full suite after period integration: **2754 passed, 17907 assertions**, 136.00 seconds (`/tmp/energy-financial-period-final-full.log`). A subsequent edit removed the incorrect legacy blanket hold-current assumption from source-rule annual results.
- Final full suite after all code changes: `php artisan test`: **2754 passed, 17909 assertions**, 135.45 seconds. Log: `/tmp/energy-financial-finished-full.log`. This includes parallel work present at test time; the manager still owns the combined release gate.
- Formatter and final `vendor/bin/pint --test` passed on all eight PHP files in this unit: calculator, service, current source evidence, private premium loader, EnergyRulePlan, CurrentNormalCandidateExtractor, the financial integration test and EnergyRuleKernelTest.
- `git diff --check` passed. Final diff and worktree status were reviewed. All edited AGENTS/CLAUDE pairs are symlinks. Unrelated work remains in place. No CSS/JS changed in this unit, so no asset build was run.

## Genuine source limits

This unit does not infer unconditional guarantees from qualified or incomplete source wording. Oomi's cap, Voima's exception, Iin's unresolved eligibility/timing, Tyyni's missing lock and Hehku's incomplete energy terms retain the source unit's Unknown behavior. Cheap's later announcement is not a proved current normal quote or independently dated lock. See `source-language-completion.md`. These are source/semantic limits, not disconnected financial wiring.
