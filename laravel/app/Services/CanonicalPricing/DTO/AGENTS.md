# Canonical pricing VAT copies

## Source-backed energy facts

`CanonicalComponent` has a trailing `EnergyPriceRule $energyRule = new EnergyPriceRule` argument.
Old constructors and default parser mode produce Unknown, even when extra raw rule fields exist.
`CanonicalPricingParser::parse(..., bool $withEnergyRules = false)` reads the new fields only when
explicitly selected. Current service paths require exact batched V5 publication/source proof;
Historical boundaries explicitly pass false. Defaults remain V4. Invalid V5 or unpointed known rules
cannot fall through to legacy fixed-price calculation.

`EnergyPriceRuleKind` has Unknown, FixedPrice, AdjustableTariff, AbsoluteDiscount and
PercentageDiscount. `EnergyPriceRule` keeps kind, own nullable PhaseBoundary starts/ends,
nullable discountValue/floorAmount, and nullable EnergyNormalBasis. The normal basis uses the
Unknown/FixedPrice/AdjustableTariff subset with independent bounds. Actual and normal monetary
rates stay on CanonicalComponent. Source citations stay in private interpretation JSON, not these DTOs.

`EnergyPriceRule::fromArray()` validates exact object keys, enum values, conditional operands,
finite numbers, supported date/duration bounds and evidence shape. This is shape validation, not
source proof. Missing old rule becomes Unknown; a malformed supplied object throws. Fixed prices
and discounts need finite spans. Adjustable observations can end with none but are not guarantees.
Unknown objects have no asserted bounds, operands, basis or evidence.

`withVatBasis()` preserves the rule and its independent normal basis. It scales an absolute
reduction and source floor once with the monetary amount; percentages do not scale. Unknown VAT
still assumes the selected basis. No source zero floor is inferred. Candidate copies now preserve
the typed rule. `CanonicalComponent::withoutEnergyOffer()` removes only a genuine sourced energy
offer and uses its own normal basis, never the actual guarantee. Positive percentage operators at
normal zero survive; equal fixed metadata and zero operators do not remove a lock.

## Current energy-rule kernel

Explicitly parsed known Current rules enter `EnergyRulePlan` inside the existing calculator.
Historical and parser-default Unknown data retain the old path. The plan requires complete current
General/Time/Season actual and normal maps for paired projections. A second bounded pass can admit
a priceable actual timeline when a normal comparison cannot be proved. It rejects unsupported
mixtures and contradictory guarantees. Spot and packages are not admitted. A later independently fixed span wins on its own bounds; it cannot
fill an earlier gap. Genuine missing current energy remains unavailable.

The bounded plan also accepts a complete ordinary fixed billed map without normal metadata.
Actual guarantees remain exact. A finite ordinary non-promotional quote can be a projection target
for its unknown tail, with a missing own anchor and compatible peers before seasonal/hold fallback.
It is not an ordinary monthly premium donor. Actual and normal energy remain identical. Fixed6 uses the real term,
while Fixed12/24 keep the existing first-year policy. Fees and fee-only offers still use core billing.

If the paired plan fails, the same builder checks an actual-only timeline. Each bucket needs an
explicit guarantee or an applicable, separately announced ordinary continuation. It checks every
rule/parent boundary and preserves overlap, current coverage and fee guards. It ignores normal
projections. A later announcement applies only from its own start; it cannot fill an earlier gap.
An unknown tail after that announcement holds the latest quote as an estimate, not the old promo.
The full-source Cheap case therefore costs 7.49 for one month, then estimates from announced 9.95,
without a current-normal map, a fabricated observation date, a normal comparison or a benefit.

For a genuine or unresolved energy offer whose normal basis is unavailable, independently priceable
actual costs remain available. The plan/comparison has `normalAvailable=false`; base total is null, base
months and signed differences are empty, and net difference is null. It emits
`energy_rule_normal_unavailable`, never `energy_rule_normal_known`. There is no measured benefit or
offer term. Short-term actual cost and its annualization remain available internally; no normal
term cost is invented.

Normal proof is collected independently of actual expiry. A matching current typed Normal or
Continuation phase can establish current coverage with an Unknown rule. An already-started expired
fee parent can still supply an ongoing own energy guarantee. Future parents cannot supply earlier
actual coverage. Conflicting overlapping energy guarantees or normal guarantees fail closed.
Different normal maps can apply in separate periods. A dated normal guarantee takes priority over
an older adjustable quote during its own period. It is not an earlier current-price anchor.
Parents starting at or after the cost-window end are ignored; past independent facts are not.

Ordinary future fixed changes replace the applicable price regime. Unknown later months cannot
return to an old normal map. Without a defensible dated reference for the new regime, the kernel
holds its latest known rate as an explicit estimate. Dated expired announcements remain available
for this fallback on later comparison dates. Returning to the original numeric price after a different
regime does not restore the original observation or offsets. Superseded normal maps are not current
normal facts or premium anchors. A new independently dated normal quote can replace an expired
normal lock. The kernel never uses a phase start as an observation date.
Fixed offer removal requires the component's Introductory role;
a numeric difference on a Future role is insufficient. An explicit future introductory overlay with
the same proven normal map is supported. Every remaining billed introductory segment needs an
explicit fixed/formula rule: a shorter energy guarantee cannot silently end a longer promotion.
Repeated unchanged guarantees and fee-only splits retain their original billing.

`NormalEnergyProjection(currentRates, SupplierAdjustedEstimate|ResetEstimate)` carries an explicit
exact normal bucket map in the bill's VAT basis. Existing estimates have only a scalar current
summary, so this wrapper is required for multi-rate proof. The map must equal the sourced plan.
Supplier scalar summaries must match the existing 15/9 or 5/7 episode weights; reset summaries
must match the existing consumption-weighted calculator helper. No rates or operands are scaled
again. The wrapper selects existing offsets, not a new forecast model. Missing projection means
an explicit normal-hold assumption, not a normal guarantee.

Own actual/normal bounds resolve through `PhaseTimelineBuilder` by using typed temporary phases.
Additional splits retain the existing calendar and no-overflow billing fractions. `costWindow`
overrides absolute energy bucket rates before `costSegment`; no supplier/reset estimate is passed
again to those segments. Fixed actual rules stay exact; normal rates use their independent fixed
bounds or the supplied projection before percentage/reduction and source-floor application.
The existing nonnegative model floor remains separate. Estimated formula clipping without a sourced
floor, and estimated negative normal/direct-adjustable baseline-plus-offset clamps, add
`energy_rule_nonnegative_model_floor_applied`. Fixed overrides are resolved first; discarded
projections do not imply a billed clamp. An actual source floor does not hide an independent normal
model clamp. Arithmetic and source-floor values are unchanged; no contractual zero floor is invented.
Source-rule phase rows carry `energy_price_guaranteed`. This is true only when an explicit fixed
actual rule protects the displayed rate throughout that span. Applicable Time/Season buckets must
all have that same rate. Formula certainty, a normal-price guarantee and an exact total are not
proof for this flag. Base-only Hybrid rows stay false because the consumption effect is excluded.
Fees, one-time charges, usage and short-term
annualization keep the existing billing path. Energy introduction alone cannot discount a monthly
fee: explicit normal fees (including zero/equal/lower) are primary; implicit fee continuation needs
the fee's own Introductory role. Legacy unchanged-energy fee logic is unchanged.

Exact bill periods use a separate source-rule plan with no projection. They split at the rule's own
bounds, then use published rates and the source operator through the existing hourly-consumption
and days/30 fee calculation. A fee-phase boundary cannot shorten or extend the energy offer.
Unavailable normal facts produce `normalPeriodTotal=null` and zero measured savings. Period
assumptions do not inherit annual model clipping, normal forecasts or later continuation claims;
they record their own published-rate basis and any latest-known continuation actually used.
Source-rule annual results also omit the legacy blanket hold-current assumption: their typed
normal projection or explicit hold already states the calculation basis.

`EnergyRuleComparison::toArray()` now supplies the public `energy_rule_comparison` record with
method identity `source_energy_rules_v1`, signed annualized monthly differences and net difference,
separate actual/normal certainty, normal availability, hold state, annual equivalent and typed
projection provenance. Normal `projection` and `current_normal_rates` are separate from the optional
`actual_projection`. The trailing constructor arguments are `?float $annualEquivalentEnergyPrice`
and `SupplierAdjustedEstimate|ResetEstimate|null $actualProjection`, followed by optional
`?array $currentNormalRates`. The latter preserves proved normal baseline rates for held/fixed
comparisons without a projection object; it must agree with supplied normal projection rates.
Actual-only estimated continuations may carry `actual_projection` only when an actual estimator
object exists; otherwise it stays null. They never invent current normal tariff evidence. The trailing
`disclosedFeeChanges=false` flag records variation in actual OR normal fees within the real cost
window. It changes only the nested normal Supplier `monthly_fee_assumption` to `disclosed_phases`;
two different constant fees remain `held_flat`. It preserves the genuine current fee and adds no
reset fee field or actual projection object. Quotes remain private. Actual current rates stay in the existing outcome rate fields, never in the annual equivalent.

The serializer retains signed savings and nullable normal facts, including actual-only real short-term
totals. It no longer throws for actual-only Fixed6. `includes_discounts` requires available normal
pricing, positive signed net benefit and genuine typed offer terms. Exact actual prices keep their
own method even when normal comparison is estimated; only estimated actual pricing uses
`EstimateMethod::SourceEnergyRules`. Legacy/Historical transport remains compatible.
`ContractPricingViewData` validates the new record, monthly/net/real-term reconciliation and conditional
absence. `Support/EnergyRuleOfferTerms::build(data, plan, start, end)` describes supported terms only;
it never bills or recalculates savings. Formula components carry typed kind, operand and optional floor.
Shared schema activation is manager-owned; this public-output unit does not bump it. See
`tasks/source-validated-energy-rules/public-output.md` for the current surface and verification record.


The calculator selects VAT included for Household, Both, and a missing target group. Company selects VAT excluded. `ContractContext::includesVat()` owns this rule. The calculator receives one configured `price_forecasting.fixed_term.vat_multiplier` snapshot from its container binding.

`CanonicalContractData::withVatBasis()` converts explicit component amounts and normal amounts together. Unknown component VAT and packages assume the selected target basis. Do not infer seller tax facts from that assumption.

`SpotAssumptions::withVatBasis()` and `SpotEstimate::withVatBasis()` return selected-cost copies. Their legacy `WithTax` field names do not override `vatBasis`. The original shared market evidence remains inclusive. Dates, coverage, counts, confidence, and flags remain unchanged. Repeated conversion to the same basis is a no-op. Rolling averages are normalized at the configured current rate for annual estimates; this is a current-basis estimate, not historical tax reconstruction.

`HistoricalSpotPrice` accepts optional explicit `centsPerKwhWithoutTax` evidence for exact periods. Company exact pricing prefers that evidence. Inclusive-only hours from 2024-09-01 onward can use the configured current multiplier. Earlier inclusive-only hours cannot prove an excluded bill and return unavailable for Company Spot pricing. The hourly reader should supply actual excluded prices where available. Exact periods never receive annual market offsets.

Public annual results carry `vat_basis`. Reset and supplier requests scale both inclusive reference and forward prices to the selected bill basis. Candidate and direct-rate boundaries also normalize components, so price episode matching and bills use the same basis.
