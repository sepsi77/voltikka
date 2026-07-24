# Decisions

- Scope expanded from candidate-only description-phase detection to versioned LLM interpretation of every imported contract.
- Deterministic text/anomaly rules no longer gate analysis. They remain validation signals, prioritization features, and benchmark detectors.
- Keep immutable source snapshots and versioned automated interpretations. After automatic validation, compatible canonical classification fields publish into the existing `electricity_contracts` read model; raw model output never publishes without validation.
- Parse contract semantics on independent axes (term, pricing mechanism, metering/component schedule, cadence, and phases) instead of relying on one overloaded pricing-model enum.
- Retain a compatible derived `Spot` / `FixedPrice` / `Hybrid` recommendation for existing queries, but treat the richer mechanism taxonomy as the future source of behavior.
- Compute a canonical semantic `source_fingerprint` and a separate `analysis_fingerprint` that includes schema/prompt/provider/model versions. Re-run only when one of those inputs changes or an operator explicitly requests it.
- Persist the full interpretation input during import because current database rows are stale for many existing fields and cannot reliably reconstruct the source payload later.
- Dispatch idempotent analysis jobs after the import transaction commits; never block `contracts:fetch` on the LLM. There is no human review or approval workflow.
- Use strict JSON Schema, cited evidence, per-field confidence, and deterministic verification of every extracted number/date/unit before activation.
- Keep LLM-derived pricing phases in the versioned interpretation JSON for now. Existing `price_components` remains the efficient relational current-price history until phase-aware calculation is implemented.
- Valid results publish automatically. High-confidence, internally consistent category corrections update the existing canonical contract row; uncertain values keep the source fallback.
- Keep the existing relational tables as the public read model instead of adding a source resolver or separate effective-profile system.
- Prefer factual integrity reasons and specific price-change warnings over automatically accusing a provider of deceptive conduct.
- Production investigation was read-only and targeted active contracts in Railway project `Voltikka`, environment `production`, MySQL service.
- Deterministic matches are pricing-schedule candidates, not deceptive contracts. Schedule classification and integrity comparison are separate stages.
- Legitimate recurring monthly/quarterly resets and fixed-term continuations must not receive deceptive-promotion warnings merely because prices change over time.
- A recurring-reset classification is not a contract-wide exemption: a quarterly product can also layer a description-only introductory promotion over its ordinary quarterly price.
- The initial combined rule routed 24/434 active contracts; a broader rule routes approximately 34/434. Manual review found the broader set useful for schedule extraction, but it intentionally contains legitimate schedules and correctly structured promotions.
- Do not skip contracts whose API says they have discounts. Production examples show one component can be correctly structured while another disclosed component phase is omitted.
- Refresh or version source descriptions before extraction. Active production rows contain stale campaign dates because existing contract text is not generally refreshed by the importer.
- Text routing has unknown recall without labeled deceptive-contract ground truth. Analyze every contract; use deterministic source-model consistency and evidence checks to validate results.
- Segment anomaly baselines by pricing model, target/VAT population, contract type/duration, metering, and component. Spot General prices are margins and must never be compared with fixed energy prices.
- In the household open-ended FixedPrice segment, a joint energy+monthly rule below 80% of peer medians found both clear description-only future increases plus one legitimate monthly-reset product. Schedule classification correctly separates the legitimate case.
- Treat low price as a prioritization feature, not proof. Energy-only thresholds produced package tariffs, legitimate reset products, and genuine competitive offers as false positives.
- The initial manual benchmark samples the 100 active household-eligible contracts with the lowest latest 5,000 kWh snapshot cost. Four subagent batches labeled all rows and a separate subagent adjudicated every initial positive/ambiguous result.
- The focused positive label requires a persisted description announcing that current structured pricing is period-limited while corresponding promotion/future-phase metadata is absent. Missing descriptions are negative/no-signal, not a warning or review trigger.
- Focused benchmark counts: 4 target mismatches and 96 negatives. Broad text routing found all 4 with 15 routing false positives; this is too small and selection-biased to claim general 100% recall.
- Track pricing-model mismatches and unsupported consumption effects as separate `issue_types`, not deceptive-promotion positives.
- Production text matching consumption-effect terms found 42 active contracts: 38 Hybrid, one FixedPrice, and three Spot/optional-fixing products. Structured Hybrid provides a strong detector but not enough calculation semantics.
- `ContractPriceCalculator` currently treats Hybrid as an ordinary fixed rate and implicitly assumes zero consumption effect. Future Hybrid handling should expose the assumption/range and use extracted expected/typical/capped effects when available.
- The initial prompt/model experiments selected `openai/gpt-5.6-luna`, `system-prompt-v5.md`, `schema-v2.json`, and low reasoning effort; prompt v6/schema v3 later superseded those assets after production-validator testing.
- The final shadow run returned valid structured output for 434/434 active contracts at a reported total cost of about $5.22; model/provider pricing can change.
- Do not trust the model's headline integrity judgment directly. Derive promotion mismatch deterministically from validated component direction and touching phase boundaries; this made the four-positive top-100 focused benchmark stable across different runs.
- Publish LLM category corrections only when strict schema validation passes, the classification equals the recommended value, mismatch evidence exists, and classification confidence is high. Otherwise retain the source category automatically; there is no second call or manual review.
- Retail metering is determined by component schedule, not hourly/quarter-hourly Spot measurement. General Spot remains `metering=General`; only day/night components imply Time and seasonal components imply Season.
- Continuation at `after_months=12` is outside the first-12-month calculation horizon. Missing post-year normal pricing is metadata incompleteness, not a first-year understatement.
- The first source-snapshot implementation is intentionally small: it stores the complete original upstream contract payload plus a canonical SHA-256 fingerprint, `first_observed_at`, and `last_observed_at`. It does not yet refresh existing relational contract fields, queue LLM work, or affect public behavior.
- Canonical source fingerprints normalize object-key order and harmless string whitespace but preserve list order because it can carry meaning. They exclude shared `Details.SpotFutures` market data because it changes independently of contract meaning. The original payload remains unchanged in the snapshot for evidence. An unchanged fingerprint updates only `last_observed_at`; a changed fingerprint creates a new immutable snapshot inside the import transaction.
- Existing `electricity_contracts` source fields now refresh from every current API payload instead of updating only the discount flag and filling missing category fields. The local contract ID and `replaced_by_contract_id` remain untouched. Optional legacy `ShortDescription` / `LongDescription` fields update only when those API keys exist, so omission does not erase old values.
- For fetched contracts, `price_components` rows on the current import date are replaced from the complete latest payload before upsert. This handles changed, removed, and newly identified same-day components, while source snapshots preserve each complete payload version.
- Contract postcode and DSO pivots are replaced from the current payload for fetched contracts. They are no longer additive, so removed availability does not remain stale.
- The Azure Consumer API product-list contract object is treated as a complete record rather than a partial patch. Missing ordinary source fields therefore use the importer's documented null/default values; only optional legacy short/long descriptions preserve old values when their keys are absent.
- The minimal production interpretation model uses one `contract_interpretations` table with `pending`, `processing`, `published`, `failed`, and race-protection `superseded` states. It stores strict output JSON, automatic validation errors, model/prompt/schema provenance, usage, latency, and execution timestamps. It has no reviewer or override fields.
- `electricity_contracts.published_interpretation_id` identifies the current canonical version, and interpretation `published_fields` records exact ownership. `canonical_pricing`, `canonical_source_consistency`, and `canonical_calculation` materialize the current validated rich JSON; versioned interpretation output is its history.
- Interpretation analysis uses one reusable strict-output OpenRouter client with GPT-5.6 Luna, prompt v14, schema v3, validator v10, and medium reasoning by default. Jobs are fingerprint-idempotent by source/schema/prompt/validator/provider/model/reasoning, run after import commit, retry automatically, and reject stale job results when a newer source snapshot exists.
- Automatic validation currently covers the complete strict JSON shape, enums/ranges/dates, contract identity, exact source evidence values, numeric component evidence, structured component presence, basic boundary order, source/recommendation consistency, and text evidence for category corrections.
- The production prompt and schema are byte-identical to experiment `system-prompt-v5.md` and `schema-v2.json`. The first live calls failed because the earlier experiment scorer did not run the production validator and the production input shape had drifted from the experiment input.
- A five-case local replay produced 5/5 schema-valid outputs but 0/5 production-validator passes. Do not continue the production backfill until local experiments use the production input builder and validator. Evidence paths, exact-field quote matching, HTML normalization, and deterministic validation of derived discount amounts must be aligned first.
- Prompt v6 and schema v3 supersede prompt v5/schema v2 for production. They use one flat prompt input and one scalar source path per evidence item. Descriptions are normalized once before the model call while immutable snapshots retain the raw HTML.
- The production validator independently recomputes structured discounted amounts when all operands and the applicable phase limit have separate valid evidence. It does not waive numeric provenance for generic derived values.
- One interpretation can make one initial model call and at most two model correction calls. Each correction receives the same source, the previous complete output, and exact deterministic errors. Every attempt is retained; output publishes only after the full validator passes.
- The aligned five-case prompt v6 smoke run passed production validation 5/5 without correction. A separate old-failure replay corrected 13 errors and passed on the first correction call.
- On all 22 gold cases, 17 initial outputs passed production validation and the remaining five all passed after one correction call. No case needed the permitted second correction call. This is sufficient for a bounded retry rollout, but it is not proof that all 434 active contracts will pass.
- The first prompt v6 production smoke test queued five representative active contracts. All five published with zero final validation errors; four passed on the initial call and Cheap Kvartaalisähkö passed after one correction. Reported total cost was $0.0651, all five remained active, and the queue drained.
- Do not start the full production backfill until the taxonomy consistency validator is deployed. The smoke test exposed taxonomy output that passed the first validator but violated prompt intent: two Spot products included `fixed` because of fixed supplier adders/monthly fees, and ordinary fees were classified as `flat_fee_or_package`.
- Validator v2 rejects `fixed` on Spot unless a fixed energy-price component exists, requires `flat_fee_or_package` to match a `flat_fee` component, and keeps seasonal, time-of-use, and consumption-effect mechanisms consistent with extracted pricing. Validator version is persisted and included in the analysis fingerprint so stricter semantics create a new interpretation instead of reusing an older publication.
- A read-only replay of validator v2 found the expected taxonomy errors in three of the five production smoke outputs. One local correction call per affected output fixed all errors: the two Spot products returned only `spot`, and Cheap Kvartaalisähkö returned `fixed` plus `periodic_market_reset`. Reported correction-test cost was $0.0290. These correction calls did not write to production.
- The deployed validator v2 production rerun published all five representative contracts with zero final errors. Hehku Spot and Sähkötytöt each used one correction and ended with only `spot`; Cheap Kvartaalisähkö passed initially with `fixed` plus `periodic_market_reset`; seasonal and Hybrid cases passed initially. Seven total calls cost $0.0660, all five contracts remained active, and the queue drained. Automatic import-time interpretation remained disabled pending an explicit full-backfill decision.
- A second validator v2 production smoke test covered five different contracts. All five published with zero final errors; Vattenfall Optimi needed one correction and the other four passed initially. Six calls cost $0.0556. Final mechanisms covered quarterly reset, discounted and standard Spot, fixed-term consumption effect, and another verified quarterly market-reset product. All five remained active and the queue drained while automatic queueing stayed disabled.
- A third validator v2 production smoke test covered 10 current active contracts. All 10 published with zero final errors after 16 calls costing $0.1479; one needed both repairs, four needed one repair, and five passed initially. All remained active and the queue drained.
- Do not start the full backfill until validator v3 is deployed. The third smoke test exposed a semantic omission that internal consistency checks did not catch: Keravan Energia Kvartaalisähkö (aika) explicitly says its price resets four times per year, but the published output omitted `periodic_market_reset`, set recurring cadence to `none`, and called the calculation exact.
- Validator v3 requires internal consistency between `periodic_market_reset`, recurring-schedule presence, classification/schedule cadence, and an unknown recurring future price. It also detects strong source language for quarterly pricing: `Kvartaalisähkö`, four price changes per year, and explicit three-month price periods. A replay against all 20 validator v2 smoke publications flagged only the intended Keravan omission. One local correction call then produced `fixed` + `time_of_use` + `periodic_market_reset`, quarterly cadence, a present schedule, unknown future price, and `estimate_required`; it passed validator v3 and cost $0.0077 without writing to production.
- Prompt v7 adds explicit invariants and compact examples for primary models, recurring reset, time-of-use, seasonal pricing, flat-fee packages, optional fixing, promotions, and negative cases. Its 22-case gold run had 16 initial validator-v3 passes and 22/22 final passes after bounded correction; one case used both repairs. A fresh initial call on the production Keravan quarterly input passed validator v3 with the complete expected quarterly taxonomy. These test calls did not write to production.
- After deployment, the production prompt-v7/validator-v3 rerun for Keravan Energia Kvartaalisähkö (aika) published after one correction call. The final output contains `fixed` + `time_of_use` + `periodic_market_reset`, Time metering, matching quarterly cadence fields, recurring schedule presence, `recurring_market_reset`, unknown future prices, and `estimate_required`. It cost $0.0162, left the contract active, and drained the queue. Relational pricing stayed unpublished because validator v3 still treated recurring future prices as incomplete.
- Prompt v8/validator v4 correct that semantic mistake. Unknown future monthly/quarterly market prices are normal and affect annual calculability, not completeness of otherwise valid current structured components. A recurring-only limitation now requires complete-or-not-assessable current pricing rather than incomplete, uncertain first-year direction, and `estimate_required`; this permits current relational components to publish. Known omitted current prices, discounts, known future amounts, and non-recurring continuations remain incomplete.
- The prompt-v8 22-case gold-v4 run had 17 initial passes and 22/22 final passes after bounded correction; one case used both repairs. A fresh initial call on the production Keravan input returned `structured_pricing_status=complete`, uncertain first-year direction, and `estimate_required`, passed validator v4, and cost $0.0131 without writing to production.
- After prompt v8/validator v4 deployment, the production Keravan Kvartaalisähkö rerun passed on its initial call for $0.0073. Interpretation 71 published complete current structured pricing, uncertain future direction, quarterly recurring reset, and `estimate_required`. Relational publication succeeded with the current day, night, and monthly components; the contract stayed active and the queue drained.
- A 10-contract prompt-v8/validator-v4 production smoke test published all 10 with zero final errors after 17 calls costing $0.1600. Four passed initially, five needed one correction, and one needed both corrections. All stayed active and the queue drained.
- Manual smoke review found that interpretation 78 for Aalto Kuukausihinta treated an energy promotion ending 2026-03-31 as an introductory/current comparison phase even though analysis_date was 2026-07-23. It then reported a missing introductory energy amount and blocked relational publication. Interpretation 81 contained a similar expired phase, and interpretation 77 used uncertain only for a general open-ended price-change right.
- Prompt v9/validator v5 resolve these findings. Absolute-date phases ending before analysis_date are historical and cannot affect current pricing, missing facts, or issue codes. Complete/exact pricing without a directional issue must use not_detected instead of uncertain. A replay of interpretations 72-81 flagged only the intended three records; one local correction fixed each. Fresh initial prompt-v9 calls produced the corrected semantics for all three, with Huoleton requiring only an unrelated evidence correction. The 22-case gold run reached 22/22 final validator passes within the two-repair limit.
- Production reruns of Fortum Kesto, Aalto Kuukausihinta, and Huoleton all passed prompt v9 initially and replaced their prompt-v8 publications with corrected interpretations 82-84. All three published relational pricing. Ten additional contracts then published with zero final errors after 14 calls costing $0.1188; six passed initially and four needed one correction. Six published relational pricing, all stayed active, and the queue drained.
- The smoke test exposed a package-pricing taxonomy gap in Helen Helpposähkö L. Source facts show a €55.90 monthly package, 0 c/kWh structured energy, a 3,600 kWh annual limit, and the word `paketti`, but interpretation 94 emitted ordinary `fixed` + `monthly_fee` and no `flat_fee_or_package`/`flat_fee`. It safely marked calculation incomplete and did not publish relational pricing.
- The same smoke test exposed a conservative-fallback gap for Helen Välkkysähkö. The source model is Hybrid, while sparse text does not describe its consumption effect. Interpretation 89 recommended FixedPrice from ordinary structured components alone. The publisher correctly kept the relational contract model Hybrid because correction confidence was only medium.
- Prompt v10/validator v6 resolve both gaps. A package pattern requires package wording, a positive monthly charge, zero unit energy price, and a positive consumption limit; the Monthly source component maps only to `flat_fee`, zero included energy is not emitted as `energy_general`, and `fixed` requires a separate positive energy rate. Source Hybrid remains Hybrid unless explicit text disproves it. Replay flagged only interpretations 89 and 94; one local correction made Välkkysähkö Hybrid/unsupported and Helpposähkö L a flat-fee package/incomplete. The 22-case gold run reached 22/22 final passes within one correction per failed case.
- Production prompt-v10 reruns published Välkkysähkö as interpretation 95 and Helpposähkö L as interpretation 96 on their initial calls. Their richer canonical outputs are now selected, while relational pricing stayed safely unpublished because Välkkysähkö is unsupported and package excess-use terms are incomplete.
- A new mixed 10-contract prompt-v10 smoke test covered FixedPrice, Hybrid, Spot, General, Time, Season, recurring quarterly pricing, and a spot promotion. Nine published after 16 calls costing $0.1439; five passed initially, four passed after correction, and five published relational pricing. Interpretation 102 for Kosken käyttöWoima 24 kk failed safely after all three calls because numeric evidence validation reads Finnish symmetric `+/-1,5` and `+/-5` notation as negative values only, then incorrectly reports that the positive maxima lack evidence. The output otherwise cited the exact source values and used the expected Hybrid + spot + consumption_effect + seasonal taxonomy.
- Validator v7 accepts explicit `+/-`, `±`, and `+−` notation as numeric evidence for both symmetric bounds while keeping ordinary signed values directional. This is a deterministic parser correction; prompt v10 and low reasoning stay unchanged. A validator-version bump creates a new fingerprint so interpretation 102 and its three attempts remain immutable when the contract is rerun.
- After validator v7 deployment, Kosken käyttöWoima 24 kk published as interpretation 107 on its initial low-reasoning call for $0.0090. It retained Hybrid + spot + consumption_effect + seasonal, extracted typical bounds -1.5/+1.5 and hard bounds -5/+5, used incomplete/unsupported safety states, and did not publish conflicting relational pricing. Interpretation 102 remains unchanged for audit.
- A fresh mixed 10-contract validator-v7 production smoke test published 10/10 after 11 calls costing $0.0951. It covered package, monthly reset, Time, Season, Hybrid, Spot promotion, and optional fixing cases. Manual review found two semantic gaps despite validator passes: interpretation 108 added a null flat_fee placeholder from package included energy, and interpretation 117 used uncertain for a complete ordinary Spot estimate with optional fixing excluded from the base price.
- Prompt v11/validator v8 reject unknown package flat_fee placeholders and require not_detected whenever complete pricing has no directional issue, independent of ordinary Spot estimation. Validator-v8 replay flagged only interpretations 108 and 117. One local correction fixed each while low reasoning remained sufficient.
- After prompt v11/validator v8 deployment, corrected interpretations 118 and 119 published on their initial calls. A second fresh mixed 10-contract smoke test then published 10/10 after 13 calls costing $0.1032. Manual review found two overly conservative results: interpretation 122 marked complete fixed Time components not_assessable, and interpretation 129 did the same for complete Spot margin/monthly components, solely because descriptions were empty.
- Prompt v12/validator v9 make recognized non-discounted structured-only FixedPrice/Spot data complete and assessable. They require source-model match, not_detected, no description-only insufficient_evidence issue, and exact FixedPrice or estimate_required Spot calculation. Validator-v9 replay flagged only interpretations 122 and 129; one local correction fixed each. The validator also recognizes source SeasonalWinterDay as canonical winter energy.
- After prompt v12/validator v9 deployment, corrected interpretations 130 and 131 published initially. A third fresh mixed 10-contract smoke test then published 10/10 after 12 calls costing $0.1058. Manual review found that interpretation 142 inferred a six-month phase from stale discount_n_first_months=6 even though has_discount=false. Its conflicting/incomplete status blocked relational pricing, but the canonical schedule was still invented.
- Prompt v13/validator v10 ignore discount timing/value/type metadata unless has_discount=true. Validator-v10 replay flagged only interpretation 142, and one local correction removed the invented phase while retaining the duplicate current monthly components as conflicting facts.
- After prompt v13/validator v10 deployment, corrected interpretation 152 published initially. A fourth fresh mixed 10-contract smoke test produced nine publications after 15 calls costing $0.1337 for the 10 fresh cases. Interpretation 161 failed safely because all three low-reasoning attempts changed opaque contract ID `cxdwul-...-kesakampanja` to the Finnish display-name spelling `...-kesäkampanja`; no invalid output published.
- Prompt v14 explicitly requires byte-for-byte opaque contract-ID copying and gives the exact ASCII/Finnish counterexample. Production reasoning changes from low to medium, and reasoning effort joins the analysis fingerprint so this change does not reuse or erase prior attempts. One fresh local medium call for interpretation 161 used the exact ID and passed validator v10 initially for $0.0210.
- After prompt v14 and medium reasoning deployment, corrected interpretation 163 copied the ASCII ID exactly and published on its initial call. A sixth fresh mixed 10-contract smoke test then published 10/10 on initial calls for $0.1223; five published relational pricing. It covered FixedPrice General/Season/Time, three Hybrid variants, a second ASCII `kesakampanja` ID, optional fixing, dual margin/monthly discounts, and a market-price product. Manual review found no unsupported phases, classifications, warnings, or relational publications. This is the first final configuration batch with zero corrections and no manual semantic gaps; prompt v14/schema v3/validator v10/medium is accepted for continued use.
- New contracts remain inactive until first validation. New raw prices for an interpreted contract wait until their source version validates. The durable `relational_pricing_published` flag prevents unsafe incomplete/conflicting structured prices from replacing relational components or activating a new contract on this or later imports. Phase-aware calculations are still pending.
- The 2026-07-24 active backfill started after the 06:00 contract fetch completed and the production queue was empty. The fetch observed 425 active contracts and created 28 snapshots. Nine current prompt-v14 fingerprints already existed; the backfill created 416 interpretations, published 414, safely failed two, made 468 model calls for $4.9846, and published relational pricing for 305. The queue drained at 07:54 Europe/Helsinki.
- The first backfill invocation inherited local `QUEUE_CONNECTION=sync` because production did not define the variable and the local `.env` supplied it. It processed 25 contracts serially before the command timeout. The idempotent rerun used explicit `QUEUE_CONNECTION=database` and queued the remaining 391. Interpretation 198 was left processing after the killed local process, but its stored attempt had zero validator errors; a fresh validator replay passed and the normal atomic publisher completed it without a duplicate LLM call. Production backfill commands run from a local checkout must always set `QUEUE_CONNECTION=database` explicitly.
- The two final backfill failures were deterministic edge cases, not unsafe publications. Validator v11 accepts `after_months=0` as contract-start evidence for first-N-month discount arithmetic. Prompt v15/validator v11 add an excess-use package pattern when text says a monthly fee includes package energy and excess use is billed separately, with positive Monthly and General components. These map to `flat_fee` plus `energy_general` and mechanisms `flat_fee_or_package` plus `fixed`; absent package allowances remain incomplete and require a non-recurring issue code. A fresh local Louna Helppo XS call passed initially, while the stored Huoleton output passes validator v11 directly.
- Prompt v15/validator v11 deployed as Railway deployment `e62a1b2e-0f51-43da-b18a-fb79b4065e5c`. Fresh fingerprinted production interpretations 590 and 591 both published on their initial calls for $0.0312 combined. Huoleton published complete current quarterly pricing and relational components. Louna Helppo XS published the correct `flat_fee_or_package` + `fixed` + `periodic_market_reset` taxonomy, stayed incomplete because the included package amount is absent, and correctly did not publish relational pricing. Final active coverage is 425/425 current snapshots with a published interpretation; the two failed v14 rows remain immutable audit records. The complete production operation used 470 model calls and cost $5.0158, excluding the $0.0203 local validation call.
- After the successful backfill, production enabled `CONTRACT_INTERPRETATION_ENABLED=true` on the `voltikka` service. Railway deployment `ab71908c-1d2a-4e3a-96df-8dec56fa8920` applied the variable and completed successfully. Future `contracts:fetch` runs now queue only new analysis fingerprints after commit; unchanged source snapshots remain idempotent and do not create LLM calls.
- A consistent read-only production snapshot of the contract domain was copied to the ignored local SQLite development database on 2026-07-24. The sync included 3,680 contracts, 425 active rows, 195,569 historical price components, 462 immutable source snapshots, 591 versioned interpretations, contract relationships, percentiles, price history/statistics, and fixed-contract forecasts. All 425 active contracts have selected canonical pricing locally; 311 selected interpretations approved relational source pricing. SQLite foreign-key validation passed. Spot, user, queue, cache, and session tables were not replaced.

## Canonical phase-aware pricing + deceptive-pricing labels (2026-07-24 session)

The validated `canonical_*` JSON is now consumed for public pricing, behind
`CANONICAL_PRICING_ENABLED` (default off). New domain layer: `laravel/app/Services/CanonicalPricing/`.

Product decisions (user-confirmed this session):
- Promo + KNOWN later price → rank by the true 12-month cost across phases from the signup date
  (e.g. Aalto "Tyyni Vakiohinta" is ranked on 13,65 snt/kWh, labelled "Hinta nousee 1.8.2026").
- Open-ended promo + UNKNOWN later price → hidden from listings/rankings; detail page reachable with a
  warning. Genuinely broken `incomplete` pricing (ambiguous fees, packages missing allowance) is also hidden.
- Short fixed terms (< 12 mo) whose only gap is post-term pricing → kept, term price annualized, labelled
  "{N} kk sopimus – hinta sen jälkeen ei tiedossa".
- Hybrids (all `unsupported`) → kept, ranked base-only, disclosure "Ei sisällä kulutusvaikutusta".
- Tiered label: soft card pill + detailed detail-page notice; only `misleading_first_12_months = detected`
  gets a label; `uncertain`/`not_assessable` never do; the raw LLM `summary` is never rendered.

Engineering decisions:
- Reuse-by-extraction: `MonthlyUsageProfileBuilder` was extracted from `ContractPriceCalculator` (which now
  delegates and keeps its constants as aliases) so both calculators share identical usage math. The legacy
  22-test suite pins parity. Rejected: calling legacy `calculate()` per phase segment (its Jan-anchored
  seasonal indexing cannot express partial months) and full reimplementation.
- Fail closed: an unknown enum affecting costing, a missing canonical object, or a conflicting VAT basis for
  one component excludes the contract rather than costing it on data the calculator does not understand.
  Unknown issue codes (which do not affect costing) are dropped. Worst case is over-hiding, never
  promo-flattering.
- An **unknown phase start with a resolvable end is the already-running current price** and covers from the
  window start. This was a real bug found in the first full-DB comparison run: 9 legitimate Fixed12/Fixed24
  contracts (`starts.kind = unknown`, `ends = after_months 12/24`) were wrongly excluded; the fix moved them
  to `comparable_exact`. Regression test `test_14_unknown_start_current_price_covers_the_window`.
- VAT amounts are used as-is (structured prices are VAT-inclusive; description prices share the contract's
  VAT basis; business contracts stay ex-VAT). A component type with both included and excluded VAT in one
  contract excludes it.
- Detail-page discount-hero component display stays on relational metadata; only totals/ranking/labels move to
  canonical.
- Caches carry a `c1`/`c0` basis marker (`ContractListCacheService`, `ContractRankingService`,
  `ContractPageCacheVersion`) so toggling the flag busts them immediately.
- Statistics is forward-only: `calculateForDate(..., useCanonical)` defaults to the flag; the backfill command
  always passes `false` (today's interpretation must never be applied to a historical date).

First full local-DB comparison (`contracts:compare-canonical-pricing --start-date=2026-07-24`, 425 active
contracts, after the unknown-start fix): 192 comparable_exact, 125 comparable_estimate, 58 base_only_hybrid,
24 term_price_only, 24 excluded_incomplete, 2 excluded_unknown_future; 15 integrity-labelled (incl. Tyyni
Vakiohinta "Hinta nousee 1.8.2026"). Notable product impact to review on the production run before enabling:
the 24 `excluded_incomplete` contracts (Helen Helpposähkö tiers, Vattenfall Täysvesi/Täystuuli, some seasonal/
fuse tariffs) drop out of listings; confirm this is acceptable with the user before setting the flag.

Deferred follow-ups (tracked in tasks.json): operational metrics/retry controls for canonical pricing, and
integrating phase-resolved period rates into the bill-comparison *period* cost (currently only the annualized
estimate and exclusion go through canonical there).

Zero test regressions: the full Unit suite (310 tests, incl. 51 new canonical tests) is green; the 13 Feature
failures are all pre-existing (verified identical on clean `main`: CompanyDetail JSON-LD ordering,
SpotPriceComponent data, ConsumptionCalculator, ContractsListPagination).

### Local-review bug fixes (same session)

Local dev review at 5000 kWh surfaced contracts showing 0,0 €/kk. Two calculator bugs, both fixed
with regression tests (`test_15_*`, `test_16_*`):
1. A recurring/current phase with **both** boundaries `unknown` (a market product with no disclosed
   dates, e.g. Keravan Kvartaalisähkö, Vaasan Perussähkö yösähkö) resolved to no dated range, so no
   segment covered the window start and the estimate-fill had no phase to hold → total 0.
   `resolveCurrentPhaseIndex()` now falls back to the first phase that carries pricing (the described
   current price) when no segment covers `S`.
2. A phase carrying a **duplicate component** (a spurious `energy_general 0` alongside the real rate,
   e.g. Vaasan Kiinteä Vaikuttaja) let the placeholder 0 overwrite the real amount. `resolvePhaseRates()`
   now prefers the first non-zero amount per component type, mirroring the relational loader.

After the fixes: 0 of 400 listed contracts have a zero total (`getCachedMetrics(5000)` sweep).

### Post-review refinements from domain feedback (same session)

Owner reviewed the excluded/labelled contracts and gave domain corrections. Applied as
calculator/integrity rules (all with regression tests); several remaining gaps are LLM-layer and
noted below. Excluded dropped 24 → 12; card-level deceptive labels dropped to exactly one
(Tyyni Vakiohinta "Hinta nousee 1.8.2026").

1. **Recurring market products (monthly/quarterly/seasonal) are estimates, not deceptive.**
   Cheap Kvartaalisähkö and Kokkola Tyyni were `excluded_unknown_future` (flagged `detected` with a
   1-month intro). They behave like Spot: current period known, future periods reset with the market.
   Now: an active recurring reset is never excluded for `detected`; the uncovered tail holds the most
   recent disclosed (recurring) price, not the intro; and the integrity service suppresses the promo
   label for active recurring resets. Fill uses `lastCoveredPhaseIndex` (the ongoing price) instead of
   the phase-at-signup.
2. **Spot "toimitusmaksu" = margin (Porvoo SPOT ×2).** Their canonical pricing is complete
   (`spot_margin` + `monthly_fee`); the LLM marked them `incomplete` only over description wording. A
   Spot contract with a costable disclosed margin now lists as a spot estimate regardless of
   `incomplete` (`isCostableSpot`).
3. **Ambiguous duplicate monthly fee → take the higher (Vattenfall Täysvesi/Täystuuli ×8).** A
   fully-covered contract whose only gap is two `monthly_fee` components now lists, resolving the fee
   to the higher value (`isResolvableDuplicateFee`; `resolvePhaseRates` takes `max` of duplicate
   monthly fees, with `flat_fee` package charges additive on top).
4. **Materiality gate on the deceptive label.** A listed promo is only labelled when the structured
   price materially understates the first year (impact ≥ 30 € AND structured ≤ 80 % of true). Tyyni
   understates by 434 € (42 % of true) → labelled; Hehku KIINTEÄ 6 kk / Cheap Määräaikainen 6 kk
   (6-month fixed continuing at a similar spot price, ~50 € / ~10 %) → no longer labelled.

**Spot dev-data note (not a bug):** the synced dev DB's rolling-365 spot average is 7,54/5,57 c/kWh
from data ending 2026-05-23 (~2 months stale, low spring prices), so spot contracts look cheap. It is
the same basis the legacy calculator uses; production has current averages. Run `php artisan
spot:averages` locally for fresher dev numbers.

**Test isolation fix:** `phpunit.xml` now pins `CANONICAL_PRICING_ENABLED=false` so a local `.env`
override (used to demo the dev server) cannot leak the flag into the suite; canonical tests opt in via
`config()->set()`.

### Remaining exclusions (12) and the LLM-prompt follow-ups they need

Still excluded at 5 000 kWh, correctly, but some warrant interpretation-layer fixes:
- **Helen Helpposähkö XS/S/M/L, Turku Louna Helppo XS/S/M (7)** — flat capped tiers; the price for
  consumption above the included cap is genuinely absent. Uncomputable at 5 000 kWh (above every cap).
  Future option: a consumption-dependent policy that lists them where consumption fits the cap.
- **Vimpelin Voima Päivä ja yötariffi / Vuodenaikatariffi ×2 / Sulaketariffi (4)** — a discount is
  disclosed but the normal price after the promo (within 12 months) is not in the data. Owner: fine to
  skip (we can't assume users know the pre-promo price).
- **Vattenfall Ilmasto Vakio (1)** — consumption-limit rule internally inconsistent (monthly limits
  sum to 1 605 kWh but the annual limit says 1 500). Genuinely broken; needs source/LLM clarification.

### Spot margin misclassified as fixed energy (Spot Valo, Kosken markkinaWoima) — calculator guard

After the dev spot-price refresh (rolling-365 ending today), three products still showed absurdly low
totals (~57–73 €/yr): Parikkalan Valo **Spot Valo** (consumer + business, Time and Season variants) and
Paneliankosken Voima **Kosken markkinaWoima**. All are `pricing_model = Spot`, but their supplier margin
was interpreted as a small fixed `energy_day`/`energy_night`/`energy_seasonal_*` rate (0.26–0.5 c/kWh)
with **no** `spot_margin` component. The calculator only added the spot base when it saw a `spot_margin`,
so it read 0.33 c/kWh as the entire energy price → total collapsed to roughly the monthly fee.

Decision (owner-approved: "calculator guard now + prompt later"): add a deterministic guard in
`CanonicalContractPriceCalculator::resolvePhaseRates`. On a `Spot` contract with no `spot_margin`, any
standalone per-kWh energy rate **≤ `SPOT_MARGIN_CEILING_CENTS` (2.0)** is folded into the spot margin so
the spot base is applied. A rate **above** the ceiling is a genuine all-in price and stays fixed energy —
this protects **Cheap Markkinahintasähkö** (`energy_general` 6.99), which must not be double-counted
(6.99 stayed 404 €). The Season metering branch also gained a spot fallback (`?? $spotDay`) so a
Season-metered spot contract gets the base too. The five affected rows now cost ~432–462 €/yr, in line with
other spot contracts; the control stayed 404 €. Regression tests 21 (fold) and 22 (control) pin both sides.
Rationale for a threshold: no supplier sells all-in energy below ~2–3 c/kWh, and spot margins are
essentially always well under it, so the two populations separate cleanly (≤0.5 vs 6.99). The guard is a
safety net that also protects rankings if a future interpretation regresses.

**LLM prompt/validator changes recommended (implemented in prompt v16 / validator v12 — see below):**
- Classify a Spot contract's small per-kWh adder as `spot_margin`, not as fixed
  `energy_day`/`energy_night`/`energy_seasonal_*` (Spot Valo, Kosken markkinaWoima). The calculator now
  compensates deterministically, but the canonical JSON should be correct at source.
- Do not mark a Spot contract `incomplete` when the margin is present just because the description
  calls it a "toimitusmaksu" (Porvoo). Calculator now compensates, but the interpretation should be
  correct at source.

### Interpretation-layer fix: prompt v16 / validator v12 (option A — tighten the model-keyed rules)

Chosen direction after discussing where the LLM's judgment belongs. Two options were on the table:
**A.** tighten the deterministic model-keyed rules in the prompt + validator (validate-and-correct);
**B.** move the pure-function downstream fields out of the LLM into deterministic derivation. Decision:
**do A now, then move toward B.** Rationale: A uses the validate-and-correct loop as a forcing function
that surfaces where our rules and expectations are wrong, and hardens them under real-contract pressure;
once the rules are proven, flipping to B is just "assign instead of assert." Recognised that the current
architecture is already "B with LLM validation" — the validator computes the expected value and asks the
LLM to reproduce it. B's win is cost/iteration (re-derive for free, no wasted correction calls, no
non-convergence publish failures), **not** correctness — a wrong rule is wrong in either architecture.

Reframing decision: the prompt now leads with a **mission** — the structured API data is the trusted
baseline (~95% correct); the LLM's active judgment is scoped to exactly three jobs: (1) integrity /
deception detection, (2) type recovery the source enum cannot express (kvartaali / monthly market reset /
spot-plus-margin), (3) correcting a structured field only on explicit contrary text. Everything else is a
faithful transcription of structured components plus deterministic rules. This mission *is* the
extraction/derivation boundary for B, so writing it now is a down payment on B.

Rules baked into v16/v12 (scope: all five documented gaps):
1. **Spot ⇒ every energy c/kWh is `spot_margin`** regardless of the source tariff slot
   (General/DayTime/NightTime/Seasonal). A small day/night/seasonal value is the margin, not fixed energy,
   so no `time_of_use`/`seasonal` mechanism is added; equal adders collapse to one `spot_margin`. A value
   **above ~2 c/kWh** is a genuine all-in market/intro price and stays `energy_general` (protects Cheap
   Markkinahintasähkö's 6.99). Enforced deterministically: `interpretedComponentType` maps every Spot
   energy source type to `spot_margin`, and a new validator check rejects any `energy_*` ≤
   `SPOT_MARGIN_CEILING_CENTS` (2.0, mirroring the calculator) on a Spot contract.
2. A Spot delivery-fee/`toimitusmaksu` margin keeps pricing **complete** + `estimate_required` (Porvoo).
3. A duplicate/ambiguous monthly base fee resolves to the **higher** value and stays complete (Vattenfall).
4. A monthly market-price (`markkinahintasähkö`) reset is classified like a quarterly Kvartaalisähkö
   (periodic_market_reset, monthly cadence, estimate_required).
5. A disclosed recurring/market reset with a small first-period intro is `uncertain`, not `detected`.

The two deterministic validator checks (1) mean the old outputs of the five affected contracts now **fail**
validation, forcing re-interpretation to the correct shape. Local verification: re-interpreting Spot Valo
(×2 metering variants + ×2 business variants) and Kosken markkinaWoima under v16 published each on the
**first attempt, zero correction calls**, emitting `spot_margin` (0.26–0.5) instead of `energy_day/night/
seasonal`, dropping the spurious `time_of_use`/`seasonal` mechanisms, `misleading=not_detected`. Their
calculator totals are now 432–447 €/yr sourced from genuine `spot_margin` (no longer relying on the
calculator guard, which stays as a backstop). Cheap Markkina was left unchanged (6.99 above ceiling,
still its own `spot_margin=1.29`). Full-corpus dry-run of the new validator flagged exactly the five
target contracts on the margin rule with no false positives; a separate ~23-contract backlog fails on
pre-existing checks (flat-package, kvartaali cadence, expired-phase) because they were published under
older validators and have not been re-interpreted — a version bump will refresh them too.

### Computational definition of the deceptive/`detected` gate (prompt v16)

Owner clarification: a promotion is `detected` (misleading) **only when the structured data — its pricing
fields AND its discount/promotion fields (`has_discount`, `discount_value`, `discount_type`,
`discount_n_first_months/kwh`, `discount_until_date`, and the original/undiscounted `price`) — is
insufficient to compute the true first-12-month cost**. A promotion whose reduction and duration are
encoded in the structured discount fields is fully computable (the structured `price` is the
original/normal amount) → **not deceptive**, even when the description also advertises it. The mere
presence of promotional wording in a description never warrants `detected`. The test is **per component**:
one component's promo can be structured (not deceptive) while another's later increase is text-only
(deceptive). The classic `detected` case is the promo price sitting in the structured pricing fields with
no structured signal it is temporary, and the increase disclosed only in prose (Tyyni Vakiohinta,
Kokkola Tyyni).

Made the central definition in prompt v16's integrity section + self-check (previously only scattered
caveats, which were not strong enough — several `has_discount` promos were wrongly `detected`). This gate
is LLM judgment (job #1), so it lives in the prompt, not a deterministic validator check.

Local re-interpretation of all 16 previously-`detected` contracts under the refined prompt: 3 correctly
cleared (Vattenfall Helppo → uncertain consumption-effect; Seinäjoki/Porvoo spot → not_detected); 13 stay
`detected`, each verified to have a genuine **text-only** increase structured data cannot represent — the
model now explicitly separates the structured discount from the text-only increase in its own summaries
(e.g. Cheap kampanja: "represents ... the six-month monthly-fee discount, but omits the disclosed 0.78
margin after six months"). No false positives remained.

### Market-linked continuations are not deceptive (prompt v17 / validator v13)

Owner clarification on two more contracts: **Cheap Kvartaali** (quarterly market reset: 7.49 first-month
intro → 9.95 current quarter, locked to 30.9, revised quarterly) and **Cheap Markkina** (6.99 fixed first
month → Nord Pool spot + 1.29 margin). Both were wrongly `detected`. Owner: a market-reset or spot product
with a cheaper fixed first-period intro is not deceptive; the ongoing price is market-linked and disclosed
as variable, and **whether the next period's price is disclosed in the description is not a signal in
either direction** (the earlier prompt line "use uncertain unless a known omitted future period proves
higher cost" was exactly wrong and was removed).

New unifying gate: `detected` is for a hidden increase of a **persistent** priced component — a fixed
energy price jumping to a higher fixed energy price (Tyyni Vakiohinta 5.49→13.65) or a spot-margin adder
disclosed as rising while structured shows only the lower intro margin (Cheap kampanja 0.39→0.78). A
temporary fixed intro returning to the ordinary spot+margin (margin/fees unchanged) or a periodic market
reset is **not** that → `uncertain` + `estimate_required`. Prompt v17 states this; validator v13 enforces
the reset half deterministically (recurring schedule present + `detected` + only reset/intro/future issue
codes → must be `uncertain`; a genuine non-reset conflict code still permits `detected`). Verified under
v17: Cheap Markkina → uncertain, Cheap Kvartaali → uncertain (via one validator-forced correction),
Cheap kampanja → detected, Tyyni Vakiohinta → detected. Both cleared contracts still cost correctly as
estimates (Cheap Markkina 404 €, Kvartaali 541 €) with no card label. The Spot-intro case (Cheap Markkina)
is prompt-guided only, since distinguishing it from a margin hike is genuine LLM judgment; the reset case
has validator teeth.

**Versioning gotcha (learned):** the analysis fingerprint keys on the version STRINGS
(`prompt_version`/`validator_version`), not the file contents. Editing prompt content or validator code
without bumping the version leaves already-interpreted contracts idempotently skipped (they silently reuse
the prior result). Bump the version whenever content changes must re-run. v16/v12 were mid-session drafts
superseded by v17/v13 for this reason.
- Classify market-price products (e.g. Cheap Markkinahintasähkö) as recurring resets like their
  quarterly siblings, so they are treated as estimates rather than promos.
- For a genuinely ambiguous duplicate monthly fee, resolve to the higher and mark the pricing
  complete (Vattenfall), rather than `incomplete`.
- Consider not flagging `detected` for legitimate recurring/market products with a small first-period
  intro; the deceptive signal should be reserved for material structured understatement.
