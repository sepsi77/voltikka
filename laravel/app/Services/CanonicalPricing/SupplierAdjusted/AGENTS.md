# Supplier-adjusted open-ended annual estimate

> **Approved target, implemented locally; release blocked:** read the [annualized comparison policy (2026-09-15)](../AGENTS.md#approved-annualized-comparison-policy-2026-09-15) first. It governs intended future changes where the strict eligibility, routing, own-anchor fallback, or fee-coupled episode rules below conflict. These notes describe current implementation, not completion of that policy. Billing, VAT, evidence, and cache safeguards remain; this documentation does not authorize deployment or production mutations.

This directory annualises a deliberately narrow set of adjustable open-ended `FixedPrice` tariffs. It is separate from `../MarketReset/`: these suppliers disclose no recurring cadence or pass-through rule.

## Release blocker — 2026-09-16

The original read-only production preflight failed. The local reader now accepts SQL NULL,
JSON null and [] validation success; malformed/nonempty values, scalars and objects stay rejected.
Real-job fake-HTTP regressions pass and the intermediate sealed replay is complete. Further review
proved legacy no-discount and overlap-end anchor defects; both repairs and regressions are now
complete locally. The final manager gate passes 2773 tests / 18253 assertions plus builds; the fresh
sealed replay resolves broken anchors. Remaining premium/unknown-restart amounts are not accepted
as improvements. Supplier/Reset hold-beta, floor, vintage metadata and guard review remain open.
Release remains blocked for unaccepted
material pricing deltas and remaining audit decisions, not this repaired reader defect.
See `tasks/source-validated-energy-rules/audit-fixes.md` for current evidence and limits.

## Current anchor boundary repairs (local)

Pre-immutable Time/Season evidence accepts only the exact inactive discount representation:
NULL or `NoDiscount` type; NULL/false/0/`'0'` flags; NULL or finite numeric zero amounts/windows;
and a NULL end date. Numeric zero strings are valid. Unknown/active flags, malformed values,
nonzero metadata and non-NULL dates stay rejected. All full-tariff, VAT, source and cutoff checks
remain; raw history still cannot prove V5 normal semantics.

Immutable chronology adds an event on the Helsinki day after an inclusive end only when that
day is at or before as-of and another interval covers it. This detects an ended conflict without
inventing observations in gaps or after final coverage. Identical overlaps do not restart the run.
Missing Lammaisten July 23 interpretations must still break continuity. Focused tests passed
46 / 412 assertions; related tests passed 316 / 5252. See
`tasks/source-validated-energy-rules/anchor-boundary-repairs.md`. The final replay confirms repaired
anchors; remaining amounts still require economic review.

## Strict historical eligibility

`SupplierAdjustedEligibility::candidate` remains the historical primitive. It accepts relational `OpenEnded` + `FixedPrice` contracts with `General`, `Time`, or `Season` metering, an exact and complete canonical calculation, no recurring schedule, no consumption effect, and exactly one `current_structured` phase through `ends:none`. The phase can start at `contract_start`, `none`, `unknown`, or a date. An unknown or dated start is not a 12-month price guarantee, so the ordinary adjustable seller price stays an estimate.

The exact energy component set follows metering: `General` needs `energy_general`; `Time` needs `energy_day` and `energy_night`; `Season` needs `energy_seasonal_winter` and `energy_seasonal_other`. Missing or unrelated components are excluded. Identical duplicate expected energy values are accepted, but conflicts are excluded. The phase can contain no monthly fee, one monthly fee, or multiple current fee variants. Fee variants resolve with the calculator's existing conservative maximum rule. This covers Vimpelin Voima Oy Sulaketariffi, whose canonical phase contains five identical `monthly_fee=4.20` components, and fuse-size fee variants without making their ordinary energy price ineligible. Packages, normal amounts, discounts, Spot margins, other tariff rates, future phases, multi-phase timelines, mechanism switches, FixedTerm, Spot, and Hybrid are excluded.

The candidate retains one stable representative rate for market estimation and historical callers. Current episode matching uses its full normalized energy-rate map, metering, pricing mechanism, and VAT basis, not that average or the monthly fee. It is the General rate, `(day × 15 + night × 9) / 24` for Time, or `(winter × 5 + other × 7) / 12` for Season. These are the same weights as `ContractPriceStatisticsService` snapshots. They do not replace the exact tariff rates in calculation output.

Default V4 interpretations have no typed open-ended price-guarantee horizon. The local V5 reader
can use independently proved bounds only behind exact source/publication proof; the producer
remains off-default. A claim without such proof cannot exempt a price from estimation.

## Current base-effect Hybrid extension (local, 2026-09-15)

Current OpenEnded General/Time/Season base prices can use the same unchanged-energy proof and
estimator when canonical consumption effect is explicitly present on `base_contract`. Raw Hybrid
or FixedPrice is permitted; the normalized energy mechanism is Hybrid in both cases. Canonical
structured pricing must remain complete. Unsupported is permitted only with that explicit effect
and the complete recognized base-component proof. No status, source context, or numeric effect is
rewritten. Effect components and the existing proven zero placeholders are not billed; other unknown
charges remain ineligible. Spot, optional fixing, packages, actual energy promotions, future gaps,
and fixed terms do not gain eligibility. The primitive's optional `currentBaseHybrid=false` mode
keeps Historical strict by default. Shared current immutable extraction recognizes these signatures;
pre-immutable raw snapshots and tariff rows still cannot prove a Hybrid base episode.

The existing loader separates supplier Hybrid-base premiums from ordinary premiums. Own references
remain first; matching lineage/company/market evidence uses the same dates, VAT and deduplication.
The request carries normalized `pricingMechanism` to guard the estimator's premium family too.
BaseOnlyHybrid and the excluded-effect assumption remain. Forward and seasonal methods identify the
actual estimator; a hold-only result keeps HybridBaseOnly. Disclosed fee/normal/expiry helpers and
all three billing passes stay shared. Exact-period actual bills and Historical annual policy do not
receive current projections. See `tasks/annualized-pricing-implementation/hybrid-projection.md`.

## Estimate

The current calendar-month remainder keeps the exact published rate. Later comparison months use:

`P_m = P_current + beta * (F_m(today) - F_reference)`

`F_reference` is the FI month price for the month in which the observed current-price episode began, at the latest curve vintage before that episode start. The existing month -> quarter -> year forward ladder and reset settings supply beta, curve-age, seasonal-index, negative-floor, and absurdity rules. Current fallback order is own-reference forward shift, comparable forward premium with a usable current curve, realized Spot seasonal index, then hold the current supplier price. Historical keeps the former own-reference/seasonal/hold order. Every rung remains `comparable_estimate` and has a `supplier_adjusted_estimate` payload. The estimator does not project fee changes. Billing keeps disclosed fee phases; otherwise the fee stays flat. The payload records the applicable fee assumption.

## Dated market inputs (2026-09-11)

The seasonal fallback calls `spotSeasonalIndex(CarbonImmutable $asOfDate): ?array` with the request
date. It uses only completed Helsinki months before that date's month and declared ends before
that date; see `../MarketReset/AGENTS.md`. Missing history still holds the seller price.
The reference vintage lookup uses `min(episode start, asOfDate)`, but keeps the actual episode
month as delivery anchor. A future episode input gets `reference_vintage_bounded_by_as_of`;
legitimate past anchors are unchanged. Forward months use the request date, never the process clock.

## Episode evidence

`CurrentPriceEpisodeResolver::resolve(array $candidates, ?CarbonInterface $asOf = null)` reads the shared trusted predecessor lineages in one batch. Three further batched queries load selected snapshot fields, immutable source-observation intervals with source and publication identity, and dated interpretation fields. All evidence ends at the explicit Helsinki as-of date (default: today). No current numeric fallback or data write occurs.

Before immutable chronology starts for a carrier ID, a compatible General/FixedPrice snapshot can prove an exact singleton. Time and Season use one additional batched raw historical query only before immutable coverage. A same-date observed household snapshot must prove full tariff identity and VAT; weighted averages alone cannot prove buckets. Company, unknown units, promotional energy, missing buckets, and conflicts remain unknown. Source-covered invalid evidence never falls back to raw rows. Within immutable chronology, complete parser-valid ordinary unchanged-energy interpretations supply exact normalized tariff signatures through the shared current extraction; observation-scoped analysis, current publication pointers, validation errors, completion dates, source identity, and VAT remain checked. Unsupported output stays unknown except for the proven current base-effect extension above; conflicting output stays unknown. Source intervals carry their actual first/last coverage; there is no pointer-only anchor fallback. No snapshot after the first immutable observation can reopen missing or unsafe source evidence.

Matching ignores carrier ID and fees, but compares every energy bucket, metering, mechanism, and VAT basis. A→B→A stays separate. Same-date legacy observed evidence has local precedence over canonical snapshots. Missing dates between unchanged observations retain one observed proxy with explicit gap flags; they do not prove rates on missing days. Unknown/conflicting evidence clears the run with uncertainty flags. Every start is left-censored observed evidence, not a known seller repricing or hedge date. Immutable runs use `canonical_source_observation_run`; the separate historical AsOf resolver is unchanged.

Current immutable interpretations now use the shared current extraction for redundant and fully disclosed fee-only phases. Real energy changes and unknown energy still break the episode. Dedicated Historical replay keeps strict single-phase eligibility. Missing evidence never creates fabricated buckets. The resolver uses four batched queries for General and five for Time/Season. The General API now uses ten queries because current-curve availability adds one preflight; older nine-query results in task notes are run history. See `tasks/annualized-pricing-implementation/energy-episodes.md` for the narrow historical follow-up.

The current orchestrator now passes the explicit comparison date into the episode resolver for single evaluation, metric batches, multi-consumption statistics, and the period wrapper's annual comparison. Its request-local anchor key uses full sorted rates, metering, VAT, mechanism, Helsinki comparison date, and current source/publication IDs. Fees and consumption do not change energy identity. `resetMemoization()` clears the anchors before a retry. Tests use real dated snapshot evidence to reject future leakage through all four entry points, and a recording resolver to check equal-average bucket changes, fee independence, and retry clearing. The current unchanged-energy path also uses `ForwardPremium/CurrentPremiumEvidenceLoader` when its own historical reference is missing and the current curve is complete. The shared selector supplies own-lineage, company, then company-balanced market evidence without a minimum donor count. Source-proved energy-promotion/future-span eligibility and reset integration are now active locally under Current policy; V5 producer defaults remain off. See `tasks/annualized-pricing-implementation/integration.md`.

## Current source-backed normal episode evidence (local, proof-gated)

`SupplierAdjustedCandidate` has a trailing `normalTariffEvidence=false` option. It selects
source extraction only; it does not change economic identity. Rates, metering, VAT and mechanism
still own identity. Existing callers keep their old behavior.

`CurrentNormalCandidateExtractor::candidate(id, data, context, comparisonDate)` accepts only
already source-authorized, energy-rule-parsed data. It reuses `EnergyRulePlan` and the phase
timeline, checks independent current AdjustableTariff proof for every normal bucket, and reuses
the strict eligibility primitive for family guards and representative weights. Fixed-only actual
maps, fixed normal guarantees anywhere in the supported window (including future spans), packages,
resets, Spot and unknown billed charges are not donors. A fixed ACTUAL promotion remains eligible
when its NORMAL tariff stays adjustable. The primitive's constructor option
`currentNormalEvidence=false` permits EstimateRequired only after this independent normal-map
proof. It preserves the original status; it does not fabricate Exact. Default/Historical guards
and rejection of ordinary Incomplete/Unsupported data remain unchanged.
VAT normalization stays on a copy. Explicit base-effect Hybrid has a separate economic family.
The returned zero monthly fee is an energy-evidence placeholder, like snapshot evidence. It must
never supply a billed fee. The financial service now supplies real current fees separately.
A normal map superseded by a later price regime cannot supply an old monthly reference, even if
the later price returns to the same number. Expired dated normal locks do not block a newer,
independently dated adjustable quote. Active or future normal locks still exclude ordinary donors.

In this mode the episode resolver requires an exact registered persisted profile. V5 must be
schema-v5/prompt-v20/validator-v18 and pass fresh full InputBuilder/Validator proof against the
immutable source at the observation date before rule parsing. Publication chronology, ownership,
status, current pointers and scoped analysis guards remain required. First and last covered dates
must both prove the same current normal map. Compatible older ordinary interpretations can
supply their non-promotional actual map through the existing candidate path, not new normal facts.
Ambiguous output remains unknown. Source citations never enter candidates.

Bare pre-immutable snapshots and component rows cannot prove normal semantics in this mode.
Internal gaps break a normal run. A right-hand gap leaves no anchor unless the requested contract
still points to that matching source-validated observation and its normal map remains valid at
asOf. This preserves the old observation date after midnight without inventing later observations.
Ended/non-current evidence, conflicts and expired scope stay closed. Observation chronology, not
phase starts, guarantee expiry or rule applicability, dates the observed normal price. Default
mode keeps its raw-history and gap-proxy behavior. Historical is unchanged. Current service parsing,
normal targets and the private premium loader now use this evidence mode. Defaults remain V4;
the shared cache schema release is manager-owned. See
`tasks/source-validated-energy-rules/normal-episode-evidence.md` and `service-integration.md`
in that task folder for verification.

## Current unchanged-energy phases

`CanonicalContractPriceCalculator::supplierAdjustedCandidate` accepts an explicit comparison date
and `ComparisonPolicy` (default Current). Historical calls the unchanged strict primitive. Current
uses the existing timeline, effective components, and actual/normal rate resolver without usage or
euro calculation. Every phase must resolve to the same complete energy map. Equal energy
`normal_amount` metadata is harmless. Ambiguous energy duplicates, unknown coverage, mechanisms,
units, packages, and real energy changes fail closed. This is not energy-promotion or future-span
integration.

Chronological inheritance uses already-applicable prices. A finite typed fee-only introduction can
also inherit its adjacent typed Normal baseline; that is disclosed unchanged energy, not borrowing
a Future energy change into an initial gap. Unknown initial energy without this proof stays out.
An expired phase with an absolute inclusive end can still prove unchanged energy at that last
covered past day. This candidate-only check uses the existing timeline for Date, ContractStart,
None, or Unknown starts; it does not create an energy observation or repricing date. Every full
past energy map must still equal the current map. Missing past buckets and different old energy
fail closed. Future phases outside the comparison window are not skipped. The same helper serves
reset candidates. Immutable episode extraction now uses this shared full-map/coverage proof
instead of rejecting every later absolute phase start: a disclosed later fee boundary is not
energy repricing. A future energy phase cannot fill missing current energy. Historical remains strict.

The current fee comes from the governing signup phase, not the maximum future fee. All original
fee phases remain in billing. Fee-only boundaries do not extend the exact energy period beyond the
current calendar month.

The orchestrator, peer loader, and immutable source episode resolver share this extraction after
their existing date, publication, source, VAT, and promotion guards. No per-contract database read
or raw current fallback is added. Current normal annual billing preserves ordinary fee changes;
only a typed introduction uses its first normal continuation as a phase-only offer baseline.
A more expensive later fee must not inflate that offer. Explicit component normal amounts remain
primary, and offer terms select the same first normal continuation. The same
energy adjustment applies to actual, normal, and structured annual totals. The shared
`unchangedEnergyNormalRates` helper also supplies factual period normal fees for this same
unchanged-energy eligibility scope. Exact-period actual totals, days/30 fee proration, VAT,
and energy rates are unchanged; ordinary future fee increases no longer create period savings.
Periods receive no annual projection. Historical annual policy and broader period energy rules
are unchanged. The payload uses `monthly_fee_assumption=disclosed_phases` when resolved
fees change, and the receipt no longer claims that these fees stay flat. Current schema is 19.
For source-energy comparisons, the nested normal Supplier assumption is `disclosed_phases` if
actual or normal fees vary within the real window; two different constant fees stay `held_flat`.
No actual projection object is fabricated when none exists.

See `tasks/annualized-pricing-implementation/phase-invariance.md` for tests and remaining limits.

## Current comparable-premium fallback

The loader does not require `last_observed_at` to equal the comparison day. Current pointed evidence
can remain current after midnight. First observation and publication completion must be no later
than the as-of boundary; exact source/publication proof and no-lookahead checks remain required.
Premium provenance records the actual last-observed date, bounded by as-of, not a fabricated today
date. Malformed proven canonical peers are caught and excluded without losing valid alternatives;
`CurrentSupplierPremiumIntegrationTest` covers this boundary. Current reset integration is also
implemented locally; neither integration implies deployment or V5 producer activation.

The optional calculator `premium` argument follows `ComparisonPolicy`; Historical ignores it.
The supplier request adds exact `energyRates`, an optional typed premium, and actual profile
bucket/month weights. `SupplierAdjustedEstimate::offsetForMonthKey(month, bucket)` selects the
transferred bucket offset; callers without a bucket retain the historical scalar path. Costing
passes supply their actual tariff bucket, including structured-only and normal totals. The current
month is unchanged. The annual plausibility check uses the same bucket weights and zero floors as
the bill, not the representative snapshot price.

Only the new fallback uses basis `forward_premium`, method `supplier_adjusted_forward_premium`,
and payload policy `supplier_adjusted_forward_premium_v1`. Its existing supplier payload retains
premium source, company/lineage/variant counts, confidence, trade dates, actual pricing dates,
model delivery proxies, and target-VAT assumptions. Public copy states that current futures were
used with comparable retail evidence because the own historical reference was missing. Seasonal
copy no longer claims that futures themselves were necessarily unavailable. Configured annual v2
and explicit historical calculation are unchanged; no retained row uses the new method by relabelling.

## Current estimator release guards

Current explicitly rejects non-finite inputs/offsets and invalid, same-day or future curve/reference
vintages. The own reference must precede min(episode start, as-of). Hold reports effective beta 0;
Historical keeps configured beta and its existing reference rules. Finite negative wholesale is
valid. The reset enable switch does not disable Supplier.

The Current seasonal anchor uses the full selected usage-profile bucket weights, not tail-only
weights or the representative scalar. Representative rates and tariff identity stay unchanged.
The billed model-floor flag is set only when an adjusted negative bucket actually costs positive
usage. Preserve existing estimate flags during serialization. Public copy distinguishes this
Voltikka model floor from a seller minimum or guarantee. Historical output is preserved. See
`tasks/source-validated-energy-rules/estimator-release-checks.md` for the guards and regressions.

## Guardrails

- Keep the card pricing category fixed.
- Own-reference and historical estimates keep their scalar monthly shift. Current transferred premiums use each exact Time or Season bucket. Apply the same selected offsets to total, promotion-free base, and structured-only totals.
- Do not apply supplier-adjusted offsets in `calculatePeriod()`; exact bill periods remain factual.
- Do not reuse `reset_estimate`, recurring cadence values, or `EstimateMethod::None`.
## Public presentation

`SupplierAdjustedEstimateCopy` generates the popover and detail receipt note only from the validated `supplier_adjusted_estimate` record. The copy states that current energy rates are seller-published facts, the 12-month equivalent is Voltikka's estimate, the seller can change an open-ended price with notice, future prices and a change schedule are unknown, and the estimate is not a price promise. It explains forward prices as `tukkumarkkinan ennakkohinnat eli sähköfutuurit` before it uses the technical term. It never reads seller text or an interpretation summary.

All three basis rungs show the shared `Arvio` popover. General-tariff receipts show `Energia nyt`, soft `12 kk keskihinta, arvio`, and `Perusmaksu`. Time and Season cards keep their two exact current tariff rows plus the monthly fee, within the three-row cap. ContractDetail uses detailed mode and adds the soft 12-month equivalent before the fee. The category remains `Kiinteä hinta` with lock styling, but the band says only that the current energy price is fixed and that the seller can change it with notice. Copy calls current day/night or seasonal rates seller-published facts and separately calls the equivalent Voltikka's estimate. It does not call the weighted representative a published single energy price. No copy claims a recurring cadence or a contractual future price.
