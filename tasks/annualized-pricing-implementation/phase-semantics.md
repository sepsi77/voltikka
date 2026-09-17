# Phase price guarantees — source-backed extension approved

## Status

The user approved an extension of existing interpretation JSON with source-validated fixed-price
periods and discount formulas on adjustable tariffs. Implementation is staged in
[the child task](../source-validated-energy-rules/spec.md); the first stage isolates versions only.
The [approved policy](../../laravel/app/Services/CanonicalPricing/AGENTS.md#approved-annualized-comparison-policy-2026-09-15)
remains authoritative. Existing rows gain no facts and unknown terms keep old behavior. This is
not full-policy completion or production activation.
Manager-owned test and acceptance status stays in `final-verification.md`.

## Exact evidence pointers

Paths below are relative to the repository root. Line numbers describe the inspected local files.

- `laravel/resources/contract-interpretation/schema-v4.json`: `$defs.boundary` (504),
  `$defs.component` (531–619), and `$defs.phase` (620 onwards). Components carry `amount`,
  `normal_amount`, `price_role`, source kind and evidence. Phase boundaries carry timing.
  There is no component-specific price-lock guarantee or fixed-versus-adjustable discount rule.
- `laravel/resources/contract-interpretation/system-prompt-v19.md:97–102`: `normal_amount`
  means the undiscounted amount. Active structured discounts require scoped discounted and
  normal-continuation phases. An ongoing phase has `ends.kind=none`. These rules do not prove
  that the undiscounted tariff is a guaranteed future price.
- `laravel/app/Services/ContractInterpretation/ContractInterpretationValidator.php`:
  `validatePricing` (193), `validateStructuredDiscountCoverage` (464), and
  `validateBoundaryOrder` (717) check numeric evidence, discount amounts, component scope and
  timing. They do not validate component-specific price-lock semantics.
- `laravel/app/Services/CanonicalPricing/DTO/CanonicalComponent.php:14–21`: the calculation
  DTO retains type, actual/normal amounts, unit, role and VAT status, but not source-evidence
  quotes. The original interpretation can retain evidence; this DTO alone cannot resolve it.

## One DTO shape, two different terms

Illustrative energy prices are in c/kWh, with the same tariff and VAT basis:

| Phase | Timing | Amount | Normal amount |
|---|---|---:|---:|
| Introductory | Signup to after 3 months | 4 | 9 |
| Normal | After 3 months; end `none` | 9 | — |

This shape can represent either source statement:

- **A:** 4 is guaranteed for three months. Then the ordinary adjustable tariff applies;
  that tariff is currently 9.
- **B:** A reduction of 5 applies for three months to an adjustable tariff currently at 9.
  The tariff, and thus the billed promotional amount, can change during the offer.

The current numbers agree, but the future actual and normal segments need different treatment.
Neither the three-month boundary nor `normal_amount=9` resolves A versus B. Do not infer a
locked period or a normal-price premium anchor from this shape. Likewise, an announced future
price of 11 with end `none` does not establish an indefinite guarantee.

## Implications for the remaining plan

- Preserve known promotional actual amounts. Do not silently replace 4 with a forecast because
  the DTO cannot express the distinction. This proposal changes no current calculation.
- Actual and normal forecasts need separate source proof. In A, a proven actual lock does not
  also lock the ordinary normal tariff. In B, a proven discount formula must remain distinct
  from the adjustable underlying tariff. These are approved source-proof requirements; calculator integration remains pending.
- An own-normal premium anchor needs a validated normal tariff and an applicable pricing or
  reference period. A normal-phase start is not, by itself, that anchor. Do not invent one from
  the discount expiry or use the promotional amount as proof of the undiscounted premium.
- Unknown gaps and known future energy spans need a full actual/normal segment plan for each
  tariff bucket. Reuse the existing canonical billing timeline and `costWindow`; do not add a
  parallel framework. Preserve known spans, actual/normal boundaries, fees, VAT, consumption and
  real-term horizons. General, Time and Season must not collapse into one average-rate proof.
- The completed unchanged-energy fee slice, current reset premium slice and bounded mandatory
  base-effect Hybrid slice do not close this gap. See `phase-invariance.md`,
  `reset-premium-integration.md` and `hybrid-projection.md`. Strict Historical paths remain
  separate. The current ordinary fully known fixed 12/24-month policy is unchanged.

## Approved next implementation

Implement source-validated facts that distinguish component fixed-price periods from discount
formulas on adjustable tariffs. Do not infer guarantees from phase dates. The child task defines
stages for evidence, schema, actual and normal segments, own-normal anchors and unresolved cases.
Schema v4/prompt v19/validator v17 remain active until a separately approved release and
reinterpretation. Retain the known-price and incomplete-promotion safeguards.

There are no automatic application LLM calls, history rewrites or production actions in this
proposal. No network, production access, commit or push is authorized or required.
