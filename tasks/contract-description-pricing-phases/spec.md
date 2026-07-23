# LLM-based electricity contract interpretation

## Expanded scope

Every imported electricity contract should be interpreted by an LLM rather than sending only deterministic promotion candidates. The source Consumer API remains the immutable upstream record, while a versioned interpretation layer supplies richer, validated semantics to Voltikka.

The interpretation should initially:

1. parse the contract's term and pricing mechanism
2. extract all disclosed pricing components, phases, discounts, reset schedules, and consumption-effect terms
3. compare the extracted facts with the source's structured fields and identify materially misleading or incomplete structured pricing
4. verify the source pricing category and recommend a corrected effective category when needed

Deterministic rules remain valuable for validation, prioritization, anomaly detection, and benchmark evaluation, but they no longer gate which contracts are analyzed.

## Why

The source schema cannot represent several increasingly common market products accurately:

- introductory prices followed by description-only increases
- legitimate monthly or quarterly market resets
- fixed-base contracts with a variable consumption effect
- optional price fixing layered over Spot
- mixed component schedules
- future reversion to an unspecified tariff

The schema is unlikely to evolve as quickly as the market. A strict, auditable LLM interpretation layer provides flexibility without forcing provider text directly into Voltikka's trusted calculation inputs.

## Non-negotiable boundaries

- Preserve every imported source version in `contract_source_snapshots`; never replace that evidence with LLM output.
- Never let free-form model prose directly drive calculations, ranking, filters, redirects, or public warnings.
- Require schema-constrained output, evidence for extracted facts, and automatic deterministic validation.
- Missing description or missing evidence is neutral/unknown, not proof of correctness or deception.
- There is no human approval or review workflow. Valid results publish automatically; invalid results fail and keep the previous publication.
- Treat descriptions as untrusted data and explicitly prevent prompt instructions embedded in source text from changing the analysis task.

## Contract taxonomy

Do not force the complete interpretation into one overloaded contract-type enum. Parse independent axes:

### Term

- `open_ended`
- `fixed_term`
- `unknown`
- fixed duration or date range when disclosed

### Pricing mechanism

- `spot`
- `fixed`
- `consumption_effect`
- `periodic_market_reset`
- `time_of_use`
- `seasonal`
- `flat_fee_or_package`
- `mixed`
- `unknown`

A contract may have more than one mechanism. For compatibility, the interpretation may also recommend Voltikka's current broad `pricing_model` value (`Spot`, `FixedPrice`, or `Hybrid`), but the richer mechanism list is the source of future behavior.

### Metering / component schedule

- general
- day/night
- seasonal winter/other
- fuse-size or consumption-tier dependent
- unknown

### Schedule kinds

- introductory promotion
- recurring market reset
- fixed-term continuation
- seasonal tariff
- signup deadline
- general-terms/VAT date
- optional fixing
- other/unknown

A legitimate recurring schedule must not be labeled deceptive merely because its current price expires. A recurring contract can still separately contain an omitted introductory promotion.

## Source snapshot and change detection

### Import prerequisite

The importer refreshes current source fields and stores the complete upstream contract payload. After a canonical interpretation publishes, later imports preserve its classification fields until a newer interpretation publishes.

On each fetch, preserve the complete interpretation-relevant upstream payload before splitting it into relational source tables. Retain versioned snapshots for auditability and automatic reanalysis.

### Canonical source fingerprint

Build a deterministic canonical input containing at least:

- upstream and local contract IDs
- contract and company names
- all term, pricing-model, metering, target-group, availability, consumption-limit, and billing fields
- all description/extra-information languages
- pricing name and discount flags
- time-period definitions when available
- all price components, units, discounts, expiry dates, and upstream pricing/component version metadata

Normalize irrelevant ordering and whitespace. Exclude the local fetch date, retrieval postcode, and other values that do not change contract meaning.

Store:

- `source_fingerprint = sha256(canonical_source_payload)`
- `analysis_fingerprint = sha256(source_fingerprint + output_schema_version + prompt_version + provider + model)`

### Reanalysis rules

Queue analysis when:

- no successful interpretation exists for the analysis fingerprint
- any semantic source field changes and therefore the source fingerprint changes
- the output schema, prompt, provider, or model version changes
- an operator explicitly requests reanalysis

Do not queue again when the same analysis fingerprint already exists. Failed jobs retry with bounded backoff and can be queued again by command. Continue using the last published interpretation until a newer result succeeds and passes automatic validation.

Dispatch jobs only after the import transaction commits. Analysis must not block the contract fetch itself.

## Versioned data model

Recommended separation:

### `contract_source_snapshots`

- contract ID
- source fingerprint
- canonical/raw input payload
- selected upstream version/timestamp metadata
- first and last observed timestamps

### `contract_interpretations`

- contract and source-snapshot IDs
- analysis fingerprint and status
- schema, prompt, provider, and model versions
- typed derived classifications
- raw schema-constrained result
- deterministic validation result
- confidence and integrity status
- evidence and issue codes
- token usage, errors, start/completion timestamps

### Canonical publication

Validated components and phases remain in versioned interpretation JSON, which is the interpreted pricing history. Common compatible classifications and the current `canonical_pricing`, `canonical_source_consistency`, and `canonical_calculation` JSON publish automatically into `electricity_contracts`. `published_interpretation_id` selects the current version and interpretation `published_fields` records exact field ownership.

The existing relational tables remain the public read model. New contracts stay inactive until first validation. A later source fetch must not overwrite owned classification fields or publish changed prices before its newer interpretation validates. The durable `relational_pricing_published` flag keeps unsafe incomplete/conflicting source pricing in canonical JSON without activating a new contract or replacing relational components on later imports. No review table, approval profile, manual override, or source resolver is part of this version.

## LLM output contract

Use strict JSON Schema. The response should include:

- parsed term type, duration, and evidence
- pricing mechanisms and compatible broad pricing-model recommendation
- metering/component schedule and evidence
- price components with:
  - component type
  - amount and unit
  - VAT inclusion when stated
  - base/normal versus discounted status
  - applicable metering/tier
  - evidence quote and source field
- pricing phases with:
  - fixed start/end dates or first-N-month semantics
  - component-specific prices
  - continuation/reset behavior
  - evidence for every number and date
- recurring reset cadence and formula/source when disclosed
- consumption-effect terms:
  - base price
  - expected effect
  - typical minimum/maximum
  - hard cap/floor
  - cadence/formula
- conflicts with structured source fields
- missing facts required for calculation
- per-field confidence, not only one global score

The model must use `null`/unknown when text does not provide a fact. It must not invent market assumptions or calculate annual totals.

## Deterministic validation

Before an interpretation can become effective:

- verify that every extracted number/date/unit occurs in cited source evidence
- normalize decimal commas, HTML, Unicode spaces, and Finnish unit variants independently
- reject impossible dates, backwards/overlapping phases, and unexplained component gaps
- validate component units and plausible ranges
- compare extracted values with source components and discount metadata component by component
- distinguish calendar dates from first-N-month terms and signup deadlines
- check broad category consistency against both source fields and extracted mechanism evidence
- reject unsupported category transitions or low-confidence contradictions
- record `uncertain` when future pricing is acknowledged but not disclosed

The first implementation validates the strict schema, contract identity, exact cited source values, numeric component evidence, structured component presence, classification consistency, dates, enums, ranges, and basic phase boundary order. More cross-phase and unit checks can be added as automatic rules. There is no human fallback.

## Integrity assessment

Avoid making provider intent the primary machine judgment. Derive factual issue codes such as:

- `structured_matches_description`
- `structured_matches_intro_only`
- `promotion_metadata_missing`
- `future_price_omitted`
- `future_price_unknown`
- `pricing_model_mismatch`
- `metering_mismatch`
- `component_mismatch`
- `unsupported_consumption_effect`
- `recurring_reset_requires_estimate`
- `insufficient_evidence`

A focused `deceptive_pricing` label can be derived for benchmark evaluation, but it is not part of a production review workflow. Public UI should explain the concrete mismatch and whether Voltikka corrected it.

Missing descriptions are not a detector signal. They simply reduce what can be verified.

## Effective classification and pricing

Validated compatible classification fields are materialized into the existing canonical relational tables. Current downstream systems continue to read those tables:

- listing filters and SEO pages
- listing cards and cached metrics
- rankings
- contract details and JSON-LD
- contract statistics
- bill comparison
- replacement matching

A valid latest interpretation publishes automatically. Only high-confidence category mismatches with internally consistent recommendations can replace source classification fields. Current rich pricing JSON publishes for frontend use, but phase-aware calculations remain disabled until the calculator can consume it safely.

If deterministic validation fails, the job can make at most two model correction calls. Each correction receives the same normalized source input, the previous complete output, and exact validation errors. The same complete validator runs after each call. All attempts are retained, and a result that still fails does not publish.

Historical statistics must not apply today's interpretation retroactively. Interpretation rows and source snapshots provide version history.

## Calculation behavior

### Multi-phase pricing

Calculate from an explicit comparison/signup date across the following 12 months. Apply each component phase to the consumption and monthly fees in its calendar period, including partial months. Do not flatten every phase into `base price - discount`.

### Periodic reset products

Do not present the current monthly/quarterly rate as a guaranteed annual rate. Use an explicitly documented estimate methodology and label it as an estimate.

### Consumption-effect / Hybrid products

The current calculator effectively assumes zero consumption effect and does not disclose that on listing/detail pages. For generic comparisons:

- show the fixed/base cost
- state the assumed consumption effect
- include disclosed expected/typical and capped ranges when validated
- avoid presenting the base-only result as exact

Accurate individualized calculation ultimately requires interval-level consumption and corresponding market prices.

Production text matching consumption-effect terms found 42 active contracts: 38 stored as Hybrid, one FixedPrice, and three Spot/optional-fixing products. This demonstrates why both source fields and semantic interpretation are needed.

## Operational design

- Use one reusable structured-output OpenRouter client rather than copying command-specific calls.
- Use queued, fingerprint-idempotent jobs with bounded timeouts, retries, and rate limiting.
- Record model, prompt/schema version, token counts, latency, and failures.
- Batch or cache carefully, but preserve one auditable interpretation per contract/source version.
- Keep secrets out of stored prompts/results and logs.
- Support a command to backfill all active contracts and another to re-run by contract, fingerprint, or model version.

## Rollout

1. Fix source refresh/snapshot persistence.
2. Define and test canonical fingerprints and strict output schema.
3. Backfill all active contracts through the automatic interpretation queue.
4. Publish valid compatible classifications automatically.
5. Expand automatic numeric and phase validation.
6. Add validated phase-aware pricing and explicit Hybrid estimates.
7. Update downstream surfaces that need richer interpretation JSON and add regression fixtures.

## Existing investigation assets

The earlier description-only investigation remains useful as one benchmark slice:

- `production-query-findings.md`
- `benchmark/README.md`
- `benchmark/top-100-input.json`
- `benchmark/top-100-labels.json`
- `benchmark/results.md`

Its focused benchmark contains four description-only promotion mismatches among the 100 cheapest active household contracts. It should be expanded with category mismatches, Hybrid/consumption-effect products, recurring resets, and a stratified random sample before enabling automatic corrections.

Prompt/model/schema experiments are documented in `experiments/README.md` and `experiments/results.md`. The production pipeline uses GPT-5.6 Luna with prompt v6, schema v3, low reasoning, exact automatic post-validation, and up to two bounded correction calls. The first production import created 434 snapshots; its prompt v5 calls failed strict evidence validation and did not publish, which led to the aligned prompt v6 and validator-aware local experiment workflow.
