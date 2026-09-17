# Source-language completion

## Result

The bounded source-language unit is implemented. This is not producer activation or a claim that every seller sentence is supported. Default V4 and Historical assets/hash behavior are unchanged.

## Implementation

- `EnergyRuleSourceProof` recognizes an exact-input-product-prefixed combined fixed energy + monthly fee clause. The shared first-month period fixes energy for one month only. Company/product IDs are not hardcoded. A fee-only period cannot prove energy terms.
- Complete bounded Cheap-style continuation context retains the separate announced energy/fee rate, valid lock end and quarterly notification schedule. No missing start or normal observation is invented. Unsupported additions and detached conditions still close known proof.
- An adjacent explicit dated energy amount/start and lock-end statement can prove a fixed period only with one General scope, valid dates and both full citations. The analysis date does not supply its start.
- Explicit whole-term guarantee prose can link to an exact undiscounted General monetary component and stated FixedTerm duration. Rule evidence must cite contract type, duration, price, has_discount=false, type and unit. Numeric product names remain context, not guarantee proof.
- Explicit actual or normal adjustable prose can link to a current undiscounted structured amount with its own exact amount/discount identity and scope citations. An active discount's post-offer amount alone cannot supply this current quote.
- Prompt-v20 documents the forms and qualifications. No schema/financial DTO change was needed.

## Fixtures and measured results

Six new JSON fixtures retain complete public source snapshots, read from the existing SQLite file through Python `mode=ro`. The input builder supplies all source input fields. Tests never query that file.

Cheap passes source proof, full validation and locked publication with its one-month fixed energy rule. Oomi, Voima, Iin, Tyyni and Hehku remain Unknown with source text unchanged. Their genuine limits are described in `real-source-wording-review.md`.

A separate faithful, explicitly synthetic full-context 24-month source passes full validation and publication. It includes product/pricing names, whole-term prose, term duration, renewable context, contact/service context and complete structured price metadata. It is not a rewritten real qualified offer.

Negative tests cover changed price, mismatched and adversarial product names, changed tariff scope, missing normal proof, absent term guarantee, missing discount-identity citation, active discount, changed term duration, negation, conditions and invalid lock dates. Changed locked snapshot prices reject publication before a valid unchanged source publishes. Existing recurrence tests prove reuse without model calls.

## Verification

All Artisan tests used `DB_URL='' APP_CONFIG_CACHE=/tmp/voltikka-energy-no-config` and PHPUnit's isolated in-memory SQLite configuration. HTTP requests are prohibited in the new publication test.

- Initial new test run: 4 failures (one test enum error and one PHP namespace escape error). Corrected.
- First broader run: 5 failures and 134 passes. A broad numeric-full-stop protection also joined phone/date-ending sentences. Replaced it with the narrow Finnish ordinal notification lookahead. No sentence is discarded.
- Final command: `php artisan test --filter='EnergyRuleFullSourceLanguageTest|EnergyRuleSourceContextTest|EnergyRuleSourceProofTest|ContractInterpretationProfileTest|SourceValidatedEnergyRulesTest|EnergyRuleSourceReviewTest|ContractInterpretationPipelineTest|ContractSourceObservation|AnalyzeContractSourceSnapshot|HistoricalInterpretation'`
- Final result: **189 passed, 1065 assertions**, 3.56 seconds. Log: `/tmp/energy-language-final.log`.
- `vendor/bin/pint --test app/Services/ContractInterpretation/EnergyRuleSourceProof.php tests/Support/FullSourceEnergyFixture.php tests/Feature/EnergyRuleFullSourceLanguageTest.php`: passed. Formatter was run on these same three files first.
- `git diff --check`: passed. Final diff/status reviewed. No CSS/JS change, so no asset build.

## Remaining genuine limits

- Cheap's later lock has no explicit start. Its 9.95 announcement is not a current normal quote. Neither can become an unconditional normal guarantee or premium anchor.
- Oomi's cap and Voima's exception are not representable by the current unconditional energy-rule schema. They remain Unknown. Iin's eligibility/timing, Tyyni's missing lock, and Hehku's incomplete energy terms remain unresolved.
- New implicit structured-rate bindings are General-only. Existing explicitly named Time/Season clauses remain supported; the new forms do not guess scope across multiple energy slots.
- The whole-source grammar stays bounded. It does not accept arbitrary marketing suffixes or unresolved pricing/qualifications.

No network, production action, application LLM call, application migration, commit or push occurred. No calculator, pricing service, financial DTO, normal episode resolver, public transport or shared `EnergyRulesFixture` was edited. The large unrelated worktree remains in place.
