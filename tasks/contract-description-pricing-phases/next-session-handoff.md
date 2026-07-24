# Next session handoff: canonical pricing calculations and pricing-integrity labels

## Purpose

This document is the starting point for the next coding-agent session.

The next session must focus on two goals:

1. Use validated canonical pricing for all public pricing calculations.
2. Add a deterministic label for contracts whose structured pricing materially understates the first 12 months, including promotional prices that later increase according to the description.

Do not let unvalidated LLM output directly control calculations, rankings, filters, or public warnings.

## Read these files first

Read these context and task files before code changes:

- `AGENTS.md`
- `laravel/AGENTS.md`
- `laravel/app/Services/ContractInterpretation/AGENTS.md`
- `laravel/app/Livewire/AGENTS.md`
- `laravel/app/Services/BillComparison/AGENTS.md`
- `tasks/AGENTS.md`
- `tasks/contract-description-pricing-phases/spec.md`
- `tasks/contract-description-pricing-phases/decisions.md`
- `tasks/contract-description-pricing-phases/tasks.json`
- `tasks/contract-description-pricing-phases/experiments/results.md`

## What this task completed

### Immutable source evidence

`contracts:fetch` now stores each distinct complete upstream contract payload in `contract_source_snapshots`.

- The original payload remains unchanged.
- A canonical SHA-256 fingerprint detects semantic source changes.
- Object-key order and harmless string whitespace do not change the fingerprint.
- List order remains significant.
- Shared `Details.SpotFutures` data is excluded from the semantic fingerprint.
- Snapshot writes are part of the import transaction.

Main files:

- `laravel/app/Models/ContractSourceSnapshot.php`
- `laravel/app/Services/ContractInterpretation/ContractSourceCanonicalizer.php`
- `laravel/app/Console/Commands/FetchContracts.php`

### Versioned LLM interpretation

Each source snapshot can have one interpretation for each analysis fingerprint. The fingerprint contains the source, schema, prompt, validator, provider, model, and reasoning versions.

Interpretation states are:

- `pending`
- `processing`
- `published`
- `failed`
- `superseded`

Each interpretation stores:

- Strict schema-constrained output
- Model and validator provenance
- Evidence
- Validation errors
- Usage and cost
- The complete initial and correction-call history
- Publication state

There is no human approval or manual override workflow. A model result can make one initial call and at most two correction calls. Every result must pass the same deterministic validator before publication.

Main files:

- `laravel/app/Models/ContractInterpretation.php`
- `laravel/app/Jobs/AnalyzeContractSourceSnapshot.php`
- `laravel/app/Services/ContractInterpretation/ContractAnalysisFingerprint.php`
- `laravel/app/Services/ContractInterpretation/ContractInterpretationDispatcher.php`
- `laravel/app/Services/ContractInterpretation/OpenRouterContractInterpretationClient.php`
- `laravel/app/Services/ContractInterpretation/ContractInterpretationValidator.php`
- `laravel/app/Services/ContractInterpretation/ContractInterpretationPublisher.php`

### Current production interpretation configuration

Production currently uses:

- Model: `openai/gpt-5.6-luna`
- Prompt: v15
- Schema: v3
- Validator: v11
- Reasoning effort: medium
- Maximum correction calls: 2
- `CONTRACT_INTERPRETATION_ENABLED=true`

Automatic interpretation is enabled for future `contracts:fetch` runs. Only new analysis fingerprints queue jobs. Unchanged contracts do not create new LLM calls.

### Deterministic validation now covers

The validator checks at least:

- Strict schema shape
- Exact contract identity
- Enum and range validity
- Date and boundary validity
- Exact description evidence
- Structured scalar evidence paths
- Numeric evidence and decimal commas
- Symmetric `+/-` and `±` numeric evidence
- Structured discount arithmetic
- Active versus stale discount metadata
- Structured component presence
- Spot, fixed, Hybrid, Time, Season, and recurring-reset taxonomy
- Package pricing, including zero-unit-price packages and excess-use packages
- Conservative Hybrid fallback
- Recurring future-price semantics
- Expired promotion exclusion based on `analysis_date`
- Warning-state consistency
- Structured-only FixedPrice and Spot completeness
- Opaque contract-ID copying

Current assets:

- `laravel/resources/contract-interpretation/schema-v3.json`
- `laravel/resources/contract-interpretation/system-prompt-v15.md`
- `laravel/config/contract_interpretation.php`

### Canonical publication

A valid latest interpretation writes these fields to `electricity_contracts`:

- `published_interpretation_id`
- Compatible validated classification fields
- `canonical_pricing`
- `canonical_source_consistency`
- `canonical_calculation`

Compatible classification fields include:

- `pricing_model`
- `contract_type`
- `metering`
- `fixed_time_range`

The publisher writes relational source price components only when the interpretation says that the source pricing is safe. The durable `relational_pricing_published` flag records this decision.

Unsafe states do not publish new relational prices:

- `structured_pricing_status=incomplete`
- `structured_pricing_status=conflicting`
- `misleading_first_12_months=detected`
- `calculation.status=incomplete`
- `calculation.status=unsupported`

Important: `CanonicalPriceComponentWriter` writes the validated **source API components**. It does not materialize LLM pricing phases into relational rows.

### Production backfill result

The full active-contract backfill completed on 2026-07-24.

Final state:

- Active contracts: 425
- Active current snapshots with a published interpretation: 425/425
- Production model calls for the operation: 470
- Production cost: `$5.0158`
- Active selected interpretations with relational pricing publication: 311
- Queue drained successfully
- Automatic future interpretation enabled after completion

Two prompt-v14 interpretations failed safely during the backfill. Prompt v15 and validator v11 corrected both with new immutable fingerprints. The old failed rows remain as audit records.

## Current frontend behavior

The frontend is not yet fully canonical-pricing based.

### What the frontend already gets from validated interpretation

Listings, filters, and details read the relational classification fields. These fields can contain validated LLM corrections:

- `pricing_model`
- `contract_type`
- `metering`
- `fixed_time_range`

### What calculations still use

Cards, rankings, contract details, and calculators still read relational `price_components` through methods such as:

- `ElectricityContract::getLatestPriceComponentsForCalculation()`
- `ElectricityContract::getLatestPriceComponentsForCalculationByContractIds()`

These are API component rows. For 311 active contracts, the current selected interpretation approved relational publication. Contracts without relational approval can still have older pre-backfill API rows because previously active contracts were intentionally not removed during rollout.

### Canonical data that is stored but not consumed

The frontend does not yet calculate from:

- `canonical_pricing`
- `canonical_source_consistency`
- `canonical_calculation`

These fields contain validated phases, promotions, package semantics, recurring resets, consumption effects, missing facts, and integrity findings.

This is the main gap for the next session.

## Next-session goal 1: use canonical pricing for calculations

### Required safety rule

“Use LLM-validated pricing” means use the schema-constrained output **after deterministic validation and publication**. Never parse raw model prose at request time.

Use only the interpretation selected by `electricity_contracts.published_interpretation_id` and the materialized canonical fields on that contract.

Fail closed:

- `exact`: calculate exact known first-12-month pricing.
- `estimate_required`: calculate only with an explicit documented estimate and show that it is an estimate.
- `incomplete`: do not show an exact annual total or rank it as if complete.
- `unsupported`: do not show an exact annual total or rank it as if supported.

Do not silently fall back to old raw API pricing when the current canonical result says incomplete, conflicting, detected, or unsupported.

### Recommended architecture

Create one central canonical calculation service instead of adding phase logic separately to each Livewire component.

A possible structure is:

- `CanonicalContractPriceCalculator`
- A DTO for the comparison start date, energy use, spot assumptions, and Hybrid assumptions
- A typed result that includes total, monthly values, exact/estimated state, assumptions, missing facts, and exclusion reason

The service should adapt validated `canonical_pricing` into deterministic calculation inputs. Keep `ContractPriceCalculator` as a lower-level source-component calculator until migration is complete, or extend it only behind a clear typed adapter.

### Pricing semantics to implement

Support these canonical facts:

- `contract_start`
- `after_months=N`
- Absolute dates
- Introductory and continuation phases
- Component-specific promotion periods
- Monthly fees
- General energy prices
- Day/night prices
- Seasonal winter/other prices
- Spot margin
- Flat package fees
- Positive excess-use prices
- Recurring monthly or quarterly resets
- Optional fixing excluded from the base Spot calculation
- Consumption effects and Hybrid assumptions

The comparison period must use an explicit signup/start date and the next 12 months. Do not flatten all phases into one annual price.

### Recurring and market prices

For Spot and recurring market-reset contracts:

- Do not present the current price as guaranteed for 12 months.
- Use an explicit forecast or historical estimate source.
- Record the estimate method in the result.
- Show the value as an estimate in every UI surface.

For Hybrid contracts:

- Do not assume a zero consumption effect without disclosure.
- Use validated expected, typical, and hard bounds when available.
- If the effect formula cannot be calculated, return `unsupported` or an explicit base-only scenario with a visible assumption.

### Package contracts

Do not calculate a package contract when the included-use allowance or excess-use rule is missing.

For a complete package:

- Apply the flat fee.
- Apply included use by its correct month/year scope.
- Apply the excess-use unit price only above the allowance.

### Existing consumers to migrate

At minimum, inspect and migrate all public pricing consumers:

- `laravel/app/Livewire/ContractsList.php`
- `laravel/app/Livewire/SeoContractsList.php`
- `laravel/app/Livewire/CheapestContracts.php`
- `laravel/app/Livewire/ContractDetail.php`
- `laravel/app/Services/ContractListCacheService.php`
- `laravel/app/Services/ContractPriceCalculator.php`
- Bill comparison services and in-listing bill comparison
- Contract statistics and price snapshot generation
- Any ranking, schema markup, or meta text that uses calculated price

Use `rg` to find every call to `ContractPriceCalculator`, `getLatestPriceComponentsForCalculation`, and direct `priceComponents` pricing logic before implementation.

## Next-session goal 2: deterministic “deceptive pricing” label

### Meaning of the label

The requested label identifies a factual pricing-data condition:

> The structured source pricing used by Voltikka materially understates the first 12 months because validated description evidence discloses a higher or missing later price.

Do not infer provider intent. The implementation should derive the label from validated facts and explain the concrete mismatch.

A Finnish UI label can use wording such as “Harhaanjohtava hinnoittelu”, but supporting text must state what is missing or understated. Consider a less accusatory visible label such as “Tarjoushinta ei kata koko vuotta” if legal or editorial review prefers it.

### Strong positive conditions

A public label should require the latest published interpretation and deterministic positive evidence. A strong initial gate is:

- `canonical_source_consistency.misleading_first_12_months === "detected"`

Then require one or more applicable factual issue codes, for example:

- `structured_matches_intro_only`
- `promotion_metadata_missing`
- `future_price_omitted`
- `future_price_unknown`

For a known later amount, calculate and show the impact over the first 12 months when possible.

Example:

- Structured API data contains a promotional energy price.
- Description says the promotion ends after three months.
- Description gives a higher normal price for months 4–12.
- Structured metadata does not represent that transition.
- The canonical phases and evidence pass deterministic validation.
- The label explains both prices and the change month.

### Cases that must not get the label

Do not label these as deceptive:

- A legitimate recurring monthly or quarterly market reset with unknown future prices
- Ordinary Spot price variation
- Optional fixing outside the base Spot price
- A correctly represented structured discount
- An expired historical promotion
- A price change that starts only after month 12
- A generic legal right to change an open-ended price
- Missing descriptions
- `misleading_first_12_months=uncertain`
- Unsupported Hybrid effects without a known positive direction

### Recommended implementation

Create a deterministic domain service, for example `ContractPricingIntegrityService`, that reads only published canonical data and returns a typed result:

- Label state: detected, uncertain, not detected, not assessable
- Public reason code
- Short Finnish explanation
- Relevant phase/component facts
- Evidence references
- Estimated first-year impact when calculable

Do not expose raw model summary text directly. Generate UI copy from known reason codes and validated typed fields.

If the label must be filtered or queried at database scale, materialize a derived boolean/reason code only after the service rules are stable. Keep `canonical_source_consistency` as the source evidence.

## Important edge cases

- Some canonical outputs contain an empty future or recurring phase to represent an unknown schedule. The schema currently permits this. A calculator must not treat an empty phase as a zero-price period. Use `recurring_schedule`, `calculation.status`, and `missing_facts` to determine behavior.
- `analysis_date` is the cutoff for expired absolute-date phases.
- `after_months=12` begins after the first 12 comparison months.
- `after_months=0` is equivalent to `contract_start` for first-N-month discounts.
- A positive Spot supplier margin is `spot_margin`, not the complete energy price.
- A monthly administration fee is not a package fee.
- An excess-use package can contain both `flat_fee` and positive `energy_general` components.
- Unknown future recurring prices require an estimate but do not by themselves make current structured pricing incomplete.
- Existing failed interpretations must remain immutable. Version changes must create new analysis fingerprints.

## Testing requirements

Add tests before changing public calculation behavior.

Include at least:

1. A three-month promotion followed by a known higher normal price.
2. A promotion followed by an unknown normal price.
3. A correctly structured discount with no warning.
4. A recurring quarterly product with unknown future prices.
5. A fixed 12-month exact contract.
6. A six-month fixed contract with unknown continuation.
7. A Spot contract with margin and monthly fee.
8. A Time contract with day/night phases.
9. A seasonal contract.
10. A complete package and an incomplete package.
11. A Hybrid contract with known bounds and one with unsupported semantics.
12. An expired promotion.
13. A phase starting at month 12.
14. A contract with `misleading_first_12_months=detected` that gets the label.
15. `uncertain` and `not_assessable` contracts that do not get the label.

Test every migrated pricing consumer so cards, rankings, details, bill comparison, and statistics use the same canonical calculation result.

Useful focused command:

```bash
cd laravel
php artisan test tests/Feature/ContractInterpretationPipelineTest.php
php artisan test --filter=ContractPriceCalculator
php artisan test --filter=ContractsFilterTest
```

Run the complete relevant test set before deployment.

## Operational notes

- Production automatic interpretation is enabled.
- Do not enable calculators to consume a new canonical format before old and new records have compatible handling.
- Use a staged rollout and compare canonical totals with current totals on a representative production sample.
- For a production backfill command launched from the local checkout, set `QUEUE_CONNECTION=database` explicitly. Otherwise the local `.env` can make the command process jobs synchronously.
- Keep Railway production changes subject to the confirmation rules in `AGENTS.md`.
- Keep OpenRouter secrets and Railway connection values out of logs and documentation.
- Keep `AGENTS.md` and symlinked `CLAUDE.md` context files synchronized.

## Suggested implementation order

1. Inventory all pricing calculation call sites.
2. Define typed canonical calculation and integrity results.
3. Build phase-boundary and component application tests.
4. Implement the central canonical calculator.
5. Add strict handling for incomplete and unsupported results.
6. Add the deterministic pricing-integrity service and label rules.
7. Migrate listing/card ranking and cache generation.
8. Migrate contract details and bill comparison.
9. Migrate statistics, snapshots, schema markup, and other remaining consumers.
10. Compare old and new totals on a bounded production sample.
11. Deploy behind a feature flag if the migration cannot be atomic.
12. Monitor ranking changes, excluded contracts, warnings, queue health, and calculation errors.

## Current repository and production references

At handoff creation:

- Repository HEAD before this handoff: `d82d588`
- Deployed interpretation code commit: `28678b0`
- Production variable deployment: `ab71908c-1d2a-4e3a-96df-8dec56fa8920`
- Railway project: `Voltikka`
- Railway environment: `production`
- Railway service: `voltikka`
- Production site: `https://voltikka.fi/`

Review newer commits and production state at the start of the next session rather than assuming these references are still current.
