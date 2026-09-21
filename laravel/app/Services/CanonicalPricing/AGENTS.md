# Canonical phase-aware pricing

This directory consumes the validated `electricity_contracts.canonical_*` interpretation JSON
(produced by `../ContractInterpretation/`) to calculate accurate 12-month prices and to derive
the deterministic deceptive-pricing label. It is gated behind `config('canonical_pricing.enabled')`
(`CANONICAL_PRICING_ENABLED`, default false); when off, every consumer keeps its legacy
`ContractPriceCalculator` behavior unchanged.

## Approved annualized comparison policy (2026-09-15)

**Status: implemented locally, not deployed; release remains blocked.** The original
2026-09-15 proposal below records the approved target, not production acceptance.
This section governs intended future changes where older estimator or ranking rules conflict,
including the narrower routing and fallback rules in `SupplierAdjusted/` and `MarketReset/`.
The implementation and historical notes below remain a record of current or earlier behavior;
they are not proof that this policy is deployed. This approval does not authorize production
mutations. Existing billing, data, VAT, cache, and historical-evidence safety rules remain in force.
Review decisions and dated source examples: `tasks/annualized-pricing-policy/decisions.md`
(repository-root path).

### 1. One annualized comparison basis

The primary metric must always be a **comparable annualized contract price**, not a promise of
actual spending over the next 12 months. A six-month contract must always use its six-month cost
annualized, whether or not it discloses an automatic continuation. Apply every disclosed energy,
fee, and promotional change within those six months. Disclosure beyond the term must not switch
the comparison basis. Apply the same horizon to Hybrid and non-Hybrid contracts; retain the
separate disclosure that a Hybrid base price excludes the unknown consumption effect.

Use the same selected annual consumption and profile basis, with consistent disclosure. Never
label an annualized-term value as a next-year bill promise. This policy does not redesign the
current first-year treatment of ordinary fully known 12/24-month contracts or define a new
24-month ranking policy.

### 2. One policy for unknown non-fixed-term energy prices

Except for fixed-term locked pricing, preserve current or published known energy prices for their
applicable periods, then use current FI futures to project unknown future months. Hold explicit
Spot margins and monthly/base fees steady unless known changes or complete promotions specify
otherwise. Redundant phases, a fee-only promotion, normal-amount metadata, or a new contract ID
must not select a different forecast model for the same energy terms.

Reuse the canonical billing and phase timeline and its validated evidence. Never fall back to raw
relational current pricing. Known terms remain exact; projections must not replace them.

### 3. Retail premium from available evidence

Estimate the retail premium in this order:

1. Reliable, comparable evidence from the contract's own trusted lineage.
2. Comparable contracts from the same company.
3. Comparable market contracts.

A missing historical reference for one contract must move to the next premium source, with lower
confidence, rather than discard otherwise usable current futures. Sparse data alone is not an
exclusion reason: use this hierarchy and disclose uncertainty. Do not wait for future
coefficient-calibration data to use available evidence. Exact fitting thresholds and coefficients
were not approved in this discussion.

Keep the premium separate from the market forward curve. It is a **spread over wholesale**, not
profit and not an explicit Spot margin. At `beta = 1`, the existing
`P_current + F_m - F_reference` already equals futures plus an inferred premium
`P_current - F_reference`; it is not a separate source of market level. Comparable evidence must
match mechanism/tariff, wholesale delivery period, and VAT basis. Do not give duplicate weight
to replacement IDs or duplicate offers. Record the premium source and confidence; do not invent
a zero premium.

Check the existing `../RetailPremium/` dataset for applicability before reuse: it currently
excludes ordinary non-reset open-ended `FixedPrice` contracts. Seasonal/historical fallback is
permitted only when usable forward evidence or a defensible premium cannot be obtained, not
merely because the contract's own historical vintage is missing.

### 4. Incomplete promotions cannot improve ranking

If a price is presented as temporary or promotional but lacks required timing (an end date or
duration), or lacks a stated/derivable normal continuation, exclude the contract from price calculations.
Do not extend the promotional price indefinitely. Structured price data and seller prose both
count as evidence. Distinguish a parser omission from missing seller disclosure. Keep valid,
complete promotions. Legitimate unknown wholesale changes and ordinary fixed-term expiry are
not, by themselves, incomplete promotions.

Use a neutral insufficient-promotion-terms reason; proof of dishonest intent is not required.
The dated Hehku JATKUVA exclusion and Cheap Porssisahko inclusion decisions in the task file are
source-evidence regression examples, not hardcoded ID exceptions, permanent bans, or claims of
already changed eligibility.

### 5. One canonical contract-continuity identity resolver

All history, premium, and pricing consumers must use one shared lineage resolution based on the
existing trusted `replaced_by_contract_id` chain and matcher. A new API ID often means the same
product with a new price, not a new product history. Existing conservative replacement links
remain authoritative. This policy does not authorize new heuristic matches or production relinking.

Derive energy-price episodes **within that common lineage**. A fee-only change or ID change must
not imply energy repricing. Compare full tariff rates, not only a representative average. Evidence
gaps remain explicitly uncertain; never fabricate a known repricing event. Do not reuse raw
price-history display values as canonical current rates.

Prior implementation gap, now resolved locally: history had a separate predecessor CTE, while
supplier episodes read only current IDs and included fees. Both now use the model's shared
`ElectricityContract::getReplacementLineageIdsByContractIds()` batch API; premium identities use
`getLineageIdentitiesByContractIds()`. See [current local status](#current-local-status).

### 6. Accepted projection approximations

For now, an unknown quarterly-reset tail may use monthly futures projections. Discretionary
open-ended changes may also be projected monthly after the known/current period. A quarterly-fixed
future step model and a seller repricing calendar are not required. Disclose these as estimates,
not actual known seller schedules. This policy makes no promise of model accuracy.

### Future implementation regression checklist

This is the full-policy regression checklist. Completed LOCAL PHP acceptance and remaining release
checks are separated in [current local status](#current-local-status).
Redundant-phase and fully disclosed fee-only-promotion equivalence are now implemented locally for ordinary unchanged-energy supplier tariffs. Source-proved energy promotions and known future spans are now integrated locally; the fresh combined manager LOCAL PHP gate passed.

- Same energy terms give the same projection with redundant phases or a fee-only promotion.
- Six-month costs use the same annualized horizon with or without continuation, for Hybrid and
  non-Hybrid contracts.
- Trusted ID lineage preserves continuity; fee changes remain separate from energy-price episodes.
- An old or missing own reference still uses usable futures with a defensible premium fallback.
- Incomplete promotions are excluded; the complete Cheap example remains eligible. Replace the
  old one-month 4 c/kWh hold-estimate expectation identified in the task decisions.
- Exact known terms, exact-period bills, VAT normalization, usage conservation, fee/package rules,
  and billed-total reconciliation remain intact. Preserve date-safe historical evidence and cache
  safety boundaries. Observed evidence is never rewritten, but a method change must be followed by
  a rebuild of the stored annual history with the same method in as-of mode; see
  "History follows the current method" in `../ContractStatistics/AGENTS.md` (2026-09-21, replaces
  the earlier sentence "this policy does not authorize historical rewrites"). A production apply
  still needs separate approval.

## Dated annual v3 integration (2026-09-21)

Explicit annual AsOf v3 uses shared Current candidate and calculation semantics, with the exact
historical date, historical anchors and date-local supplier/reset premiums. V1/v2 still use
Historical. Neither current-pointer loader is used by historical computation. The pure
`CurrentSourcePromotionEvidence::campaignRatesFromPayload` extraction is now shared with the dated
evidence resolver; current source validation is unchanged. V3 passes only the exact selected source
payload. V5/known-rule evidence fails closed until full dated validation exists; parsing remains
disabled. This is not full V5 parity or release approval. See `../ContractStatistics/AGENTS.md` for
exact-date donor, conservative lineage, dedicated evidence and audit limits. V3 selects the latest
exact-source reconstruction that passes full stored-profile validation at the target date, not the
latest timely or first later interpretation. Local validation covers 242 dates at all three
consumptions; July's processing seam is repaired, with 11 documented real reference/membership
transitions retained. Same-input fixtures and endpoint dominance are not full-store amount parity
or proof of forecast improvement. Defaults/public v2 and production remain unchanged.

Historical-policy statements below apply to v1/v2, not explicit annual v3. Dated release records
remain records of those releases. V3 still lacks dated V5 validation, trusted replacement lineage
and older absent donor carry-forward. Source-backed temporal and short-duration proof limits stay
explicit; see the statistics context for losses, bounded preview resources and release approvals.

## Current local status

**Default-V4 technically ready for user deployment approval (2026-09-16):** runtime repairs, independent review and the fresh
strict-transport replay are complete. All 27 numeric changes are accepted as policy-conformant,
not empirically better forecasts. The fresh candidate has 366 listed outcomes; the additional
Kerava exclusion rejects ambiguous same-phase fixed energy plus Spot margin. Final network-denied
PHP gate passes (2812 tests / 21214 assertions), as do smoke/UI checks. The manager accepts bounded
technical readiness. Final Pint, context/mirror and snapshot/loaded-source-hash checks all passed;
user deployment approval remains required.
No runtime blockers remain; no full HTTP/live-cache/ranking replay is claimed. See
`tasks/source-validated-energy-rules/release-readiness.md` for current evidence and limits.
Original failed reports remain dated historical evidence; no deployment or V5 activation is approved.

The approved rules above are unchanged. The source-backed financial and public behavior is **implemented and accepted locally**, not deployed. Manager acceptance is complete for the bounded local slices, including unchanged-energy
fees, reset premiums and mandatory base-effect Hybrid projections. The final Hybrid correction
supports active FixedTerm resets, not locked prices; supplier Hybrid remains OpenEnded-only.
The earlier 2773-test gate remains historical evidence, not verification of the final tree.
Current estimator guards, floor disclosure and serialization repairs are complete. Full synthetic
video/template review and final UI checks pass within their explicit limits; real-feed, physical
phone and social-compression checks are not claimed. This is not production acceptance. Current evidence is in
`tasks/source-validated-energy-rules/final-verification.md`; parent and earlier bounded records remain
in `tasks/annualized-pricing-implementation/final-verification.md`. Detailed progress:
`tasks/annualized-pricing-implementation/` (repository-root path).

- Six-month comparisons annualize the actual term, regardless of continuation or Hybrid. The
  incomplete-promotion guard uses exact source proof, including explicit Spot-margin campaigns
  and dated expired-promotion evidence. Complete promotions remain eligible.
- Current history and energy episodes share trusted lineage, full tariff buckets, fee-independent
  identity, and explicit dated anchors. V1/v2 Historical retain narrower identity/reference
  behavior. V3 uses dated exact-contract anchors and Current semantics, not current lineage trust;
  this is not a universal evidence or lineage parity claim. See `SupplierAdjusted/AGENTS.md`.
- `RESET_FORWARD_SHIFT_ENABLED` controls only Reset, not Supplier. Current estimators require finite,
  safe prior-vintage evidence and report effective hold beta 0. Supplier seasonal anchors use the full
  selected usage profile. Only a floor applied to positive billed usage sets the controlled model-floor
  flag; preserve existing serialized flags. This is a model limit, not a seller guarantee. Historical
  retains its semantics; legitimate negative Spot remains allowed.
- Current fixed-term `Below6` and `Between711` fail closed with `unknown_short_fixed_term_duration`.
  Range, phase and offer ends cannot prove exact duration. Fee-only short terms need in-term energy
  proof; do not borrow a post-term energy rate. Exact and known-long duration behavior is retained.
- Public premium transport omits donor companies/lineages and private observation/publication/source
  provenance. Controlled references retain dates, proxy/VAT facts and aggregate evidence counts.
  The strict reader rejects private/unknown keys, flags and malformed references, including nested
  normal/actual premiums; it does not sanitize invalid cached payloads into acceptance. Internal
  audit evidence remains intact. See `tasks/source-validated-energy-rules/premium-public-privacy.md`.
- Current rejects effective billed fixed energy plus Spot margin in the same applicable phase with
  `ambiguous_energy_mechanisms`, after short-term clipping. Factual periods return `NoPricing`.
  Non-billed references do not count. Disjoint fixed→Spot phases remain supported; Historical skips
  this new annual guard. The strict public reader requires complete Spot proof and non-overlapping
  phase windows for Hybrid→Spot transport, not just a compatible method label.
- Strict single-phase supplier forward premiums are integrated: keep a valid own reference first;
  otherwise use the pure selector's own-lineage, company, then company-balanced market evidence
  with a usable current curve. Tail offsets are per bucket; the known current month and fees stay
  unchanged. The new method is `supplier_adjusted_forward_premium`, basis `forward_premium`.
  See `ForwardPremium/AGENTS.md` for loader proof and selection rules.
- `ComparisonPolicy::Historical` keeps v1/v2 replay behavior without current peers. Explicit
  annual v3 instead uses shared Current rules with dated anchors and exact-date supplier/reset
  premiums. Neither path reads current peers for historical computation. Differences in v3 must
  have date-safety or missing-evidence reasons. See "History follows the current method" in
  `../ContractStatistics/AGENTS.md`.
  Exact-period bills never use annual projections. Current shared calculated-cost schema is **19**;
  it invalidates calculated-cost caches only, not history or the configured stored annual method.
- Current ordinary unchanged-energy General/Time/Season tariffs now share one consumption-free
  candidate extraction across pricing, peer evidence, and immutable energy episodes. Redundant
  phases, equal normal metadata, and fully disclosed fee-only promotions keep the same forecast.
  Original fees and measured savings remain in billing; Historical stays strict. See
  `SupplierAdjusted/AGENTS.md` and `tasks/annualized-pricing-implementation/phase-invariance.md`.
- Current reset missing-reference premiums now use the same loader and pure selector. A valid
  original reference stays first. Otherwise a complete actual-tail curve can use own-lineage,
  company or company-balanced market premiums per tariff bucket before seasonal/hold fallback.
  Current never uses today's old-period vintage; Historical retains that fallback. Known-period
  boundaries and real six-month horizons remain in core billing. Method `recurring_forward_premium`
  and policy `recurring_forward_premium_v1` identify the new calculation. See `MarketReset/AGENTS.md`
  and `tasks/annualized-pricing-implementation/reset-premium-integration.md` for scope and evidence.
- Supplier and reset candidates share `candidateApplicablePhases` for dated full-bucket proof.
  A current fee-only or partial tariff cannot inherit missing energy from a genuinely Future phase.
  Only a fee-only typed Introductory phase may use its adjacent typed Normal baseline. This is an
  evidence guard; it does not repair the older billing inheritance path or change Historical replay.
- Current OpenEnded base-effect Hybrids now reuse those unchanged-energy supplier and known-period
  reset paths. An active canonical reset also permits the existing FixedTerm reset contexts: a
  fixed term is not a locked energy guarantee under that mechanism. Short Hybrid reset calls pass
  the selected premium and clip to the real term before annualization; locked terms are unchanged. Explicit `present=true` / `applies_to=base_contract` plus complete canonical base
  evidence is required, including for raw FixedPrice. Premium families keep mandatory effect bases
  separate from ordinary rates. Numeric effects never enter costs. Base-only comparability stays;
  a real forecaster supplies the primary method. Supplier hold-only results keep HybridBaseOnly;
  fully locked fixed-term and Historical routing stays unchanged. Known fees, expiry proof, VAT, profiles and exact-period actual bills
  are unchanged. See `tasks/annualized-pricing-implementation/hybrid-projection.md` for local proof.
- The user approved local source-backed component guarantees and adjustable-discount semantics,
  not guarantees inferred from phase dates or normal amounts. Manager acceptance now also covers
  bounded source hardening, paired kernel corrections and the earlier normal-episode evidence stage.
  See `tasks/source-validated-energy-rules/final-verification.md` for that historical gate.
  Normal evidence is now connected to the current service and premium loader. Strict public
  transport supports nullable actual-only short-term facts, signed benefits and separate actual/
  normal certainty. Schema 19 safely regenerates calculated-cost caches for this integration.
- Current financial service integration is implemented locally. All four service paths require
  exact batched V5 source proof; required but failed proof excludes instead of downgrading to
  legacy fixed-price math. A hypothetical past bill start is not today's offer-publication cutoff.
  Factual periods use published source-rule rates on their own bounds, with no annual projection.
  They keep nullable normal facts and do not copy annual model assumptions. Default V4 and
  Historical remain unchanged.
- Normal targets use independently proved current rates and real current fees. Fixed normal spans
  can be targets but cannot supply an ordinary monthly own reference. The existing Supplier/Reset
  estimators, reference provider, premium selector and lazy peer loader retain their hierarchy.
  Reset normal repricing follows its schedule/cadence, not the promotional guarantee end. The
  paired kernel applies offsets once and retains the original fee timeline. Complete actual-only
  locks need no forecast query. The public offer helper and source-rule method are connected.
  Disjoint dated normal-price maps retain their own scopes. Source-rule phase rows mark a
  guaranteed energy price only for an unchanged protected actual rate across all applicable
  buckets; Hybrid base-only rows never claim an all-in guarantee. Model clipping without a sourced
  floor adds `energy_rule_nonnegative_model_floor_applied`, not a fabricated contractual floor.
- Later ordinary price regimes cannot revert to an old normal reference. Without dated evidence
  for a new regime, hold its latest known quote explicitly. The real Cheap source now has a
  priceable actual-only one-month 7.49 then announced 9.95 estimate, not a current-normal9.95
  premium anchor or measured offer benefit. See `tasks/source-validated-energy-rules/service-integration.md`
  and `source-language-completion.md` in that task folder. Oomi, Voima, Iin, Tyyni and Hehku
  remain Unknown for material source limits; this is not support for every seller sentence.
  Financial/public integration, including `energy_price_guaranteed`, is complete locally; the new
  combined manager LOCAL PHP acceptance gate passed historically. The September 16 production-data
  gate FAILED; repair/replay/performance review and Remotion visual checks remain open. Defaults stay V4/v19/v17/parser-v1.
  Producer activation requires a separately approved five-key current-profile switch and
  reinterpretation; Historical stays pinned. See that task's `current-integration-plan.md` and
  `release-plan.md` for tests, fresh production review prerequisites and compatible rollback limits.

## Why this exists

Providers game comparison sites by putting a cheap promotional price in the structured API data
and hiding the later increase in the free-text description. The structured price then flatters the
contract in rankings. The interpretation pipeline already extracts the real pricing phases; this
layer costs them honestly and labels the mismatch.

## Canonical offer savings

When a billed canonical component has an actual `amount` and a higher
`normal_amount`, `CanonicalContractPriceCalculator` costs a second,
promotion-free result on the same phase segments and usage profile as the actual
result. Spot averages and market-reset offsets are identical in both passes, so
market movement cannot become a false offer saving. A fully covered Hybrid costs
each disclosed base-price phase on the 12-month timeline and still excludes the
unknown consumption effect. A short fixed term costs every disclosed phase inside
the real term first, then annualizes the complete actual and normal term results
with the same `12 / term_months` factor. Do not hold the signup phase for either
path: that extends a short offer past its disclosed end.

`CanonicalPricingOutcome` stores the measured total and monthly differences.
Its `base_total_cost`, `base_monthly_costs`, `discount_savings_total`, and
`monthly_discount_savings` therefore come from one promotion-free calculation;
`total_cost`, comparability, inclusion, and sort key do not change. A shorter
component offer uses `normal_amount` only on the segments where that component
is billed, so a later normal phase is not counted twice. Phase-only promotions
without `normal_amount` keep the existing latest-normal-phase fallback, now
costed over the same window segments.

A short `term_price_only` or `base_only_hybrid` outcome also has
`calculated_cost.contract_term`. It contains `months`, `total_cost`,
`base_total_cost`, and `discount_savings_total` for the complete real term before
the `12 / term_months` comparison factor is applied. The term saving is the
difference between the term base and actual totals. For a short Hybrid, both
passes still exclude the unknown consumption effect and the annual outcome keeps
`comparability=base_only_hybrid` plus `estimate_method=hybrid_base_only`; its
assumptions also state `term_price_annualized`. This prevents the earlier
Unsupported-first branch from erasing a structural `Fixed6` term and labelling
its offer saving as a 12-month benefit. The field is null for non-short terms and
when a finite term cannot be costed or estimated from an identifiable applicable price.
Unknown in-term segments carry explicit continuation assumptions; the term total is not necessarily exact. The existing top-level totals stay annualized for ranking and comparison.
This is derived calculation output; it is not stored in the LLM interpretation
JSON.

The calculated outcome also carries `offer_terms`. Each term is derived inside
the calculator from the resolved governing phase span plus billed component
type, unit, and exact actual/normal amounts. A component `normal_amount` is the
primary source. When it is absent, an `introductory` phase can be compared with
its typed `normal` or `continuation` phase on the same resolved timeline. That
fallback is disabled for recurring market resets, so a seasonal period change
cannot become a false offer. Held-forward Hybrid outcomes use only their known
phase spans for the term, so a first-month billed-base offer remains one month
and the unknown consumption effect is still excluded. Exact first-N-month,
month-range, complete short-term, and absolute-date timings are supported;
multiple changed components share one timing. Only monthly fees and named
per-kWh energy/Spot-margin types are public. An unsupported component, duplicate
type, unresolved timing, or package produces no typed term. `CanonicalOfferFacts`
then fails closed instead of showing generic or partial copy. It formats
controlled Finnish text and never reads the phase label.

This logic does **not** read relational `price_components` or copy the legacy
calculator. The interpretation validator now rejects an active structured
`UntilDate` or first-N-month discount unless canonical phases contain the exact
scoped discounted component and its known normal-price continuation. Thus the
Surffari campaign cannot disappear and then use relational rows as a repair.
Monthly included-energy packages are typed and costed as described below.

## Read first

- Root `../../../AGENTS.md`, `../../../../AGENTS.md`
- `../ContractInterpretation/AGENTS.md` (how the canonical JSON is produced/validated)
- `resources/contract-interpretation/schema-v4.json` (the exact JSON shape)

## Components

- `CanonicalPricingParser` — JSON → typed DTOs. **Fails closed**: an unknown enum affecting
  costing or a missing required object throws
  `CanonicalPricingParseException` so the caller excludes the contract instead of costing it on
  data it does not understand. Component `vat_status` survives parsing; mixed explicit VAT bases
  are normalized for calculation, not rejected. Unknown *issue codes* are dropped, not fatal.
- `Support/PhaseTimelineBuilder` — resolves phase boundaries to absolute dates and segments the
  12-month window into elemental slices, each governed by at most one known-pricing phase.
- `Support/MonthlyUsageProfileBuilder` — one customer consumption distribution independent of tariff:
  default usage is flat across 12 calendar months; explicit heating and Jun–Aug cooling retain their
  shape. Day/night and winter tariff buckets partition that same usage, never add consumption.
  Extracted from `ContractPriceCalculator`; its historical feature-off seasonal constants remain
  for compatibility. See `Support/AGENTS.md` for calendar conservation and anniversary bins.
- `CanonicalContractPriceCalculator` — costs the annual window and assigns a
  `ContractComparability` verdict. Its typed `calculatePeriod()` entry point costs an exact bill
  period with the same parser, phase timeline, inherited rates, packages, mechanism switches,
  reset fill policy, and fail-closed rules. It accepts realized hourly Spot facts instead of
  adding a second bill-specific canonical calculator. `directGeneralRate()` is the narrow
  consumption-free boundary for the seller-set index: it reuses the same signup phase and
  inheritance logic and returns null for Spot, packages, and non-General tariffs.
- `DTO/CanonicalPeriodPricingRequest` / `CanonicalPeriodPricingOutcome` — keep exact-period totals,
  measured period savings, relevant rates/margins, comparability, assumptions, and typed
  unavailable reasons separate from the 12-month payload.
- `MarketReset/` — annualises monthly/quarterly/seasonal/other reset products with a shape-only
  forward-curve shift instead of holding one seasonal price flat. Cadence `other` uses the
  quarterly calendar and reference proxy. Own flag, own `AGENTS.md`.
- `SupplierAdjusted/` — annualises narrowly eligible ordinary adjustable open-ended fixed General,
  Time, and Season tariffs without inventing a recurring cadence. It keeps the current calendar-month remainder
  exact, then shifts later months from the observed current-price episode anchor. It has its own
  typed payload and `AGENTS.md`; it does not reuse `reset_estimate`.
- `ContractPricingIntegrityService` — the deterministic label state machine.
- `CanonicalContractPricingService` — batch orchestrator + feature-flag gate. `metricsForContracts()`
  returns `ContractPricing\CanonicalContractMetric` objects; `evaluate()` returns typed `{outcome, integrity}`
  for single-contract callers. `outcomesForContractsAtConsumptions()` parses each contract once for
  forward statistics that need several reference consumptions without loading relational rows.

## Phase-timeline algorithm

1. Window `W = [S, S+12 months)`, `S` = signup/start date at Helsinki midnight.
   All relative month boundaries use no-overflow anniversaries, including leap-day starts.
2. Resolve boundaries: `contract_start`/`none`/`unknown` start → `S`; `date(d)` end is inclusive →
   exclusive `d+1`; `after_months(N)` → `S+N` (N=0 ≡ S, N=12 falls outside W); `period_boundary`
   uses `recurring_schedule.current_period_*`. A phase whose end is before `S` (expired promo) is
   dropped so a known later phase can take over. **An unknown start with a resolvable end is the
   already-running current price and covers from `S`** — do not treat it as unresolved.
3. Segment `W` at phase, calendar-month, and contract-month anniversary boundaries;
   latest-starting phase wins on overlap. Normalize repeated calendar-month fractions across the
   rolling year so leap years and no-op phase insertion cannot change total consumption.
4. Cost known segments by applying the governing phase's rates to the day-fraction of that month's
   usage. Spot phases use `spot_margin` plus the `SpotForward/` monthly wholesale estimate. The
   rolling-365 overall/day/night evidence supplies the intraday shape only; it is the complete level
   only for the typed fallback. Unknown annual segments use the latest applicable already-started
   price or its disclosed normal amount. Never borrow a future phase for an earlier gap, skip a gap
   as free energy, or extend measured promo savings into assumed coverage. Known later phases win.
   Short terms cost the real term, including explicit in-term assumptions, then annualize by
   `12 / term_months`. Hybrids keep every disclosed base phase and exclude the consumption effect.
   For an **active recurring reset**, `MarketReset/` shifts only the unknown tail when enabled;
   an exact `tailStartsOn` split protects even a mid-month known boundary.
5. Ordinary annual fees use contract-month fractions: N complete contract months cost N fees.
   Packages retain calendar-month fee/allowance rules. One-time charges use original component
   identity, so inherited charges apply once and separate disclosed charges remain distinct.
   Reset and supplier-adjusted annual equivalents are costed energy euros × 100 / costed kWh;
   representative snapshot weights remain only for episode matching, not displayed equivalents.
   Monthly output uses anniversary bins and reconciles with the annual total.

An **empty-components phase is UNKNOWN coverage, never €0**, unless it has a
validated non-null `package` object.

## Monthly included-energy packages

Schema v4 puts package terms on the pricing phase. A package object has one
`monthly_fee_eur`, one positive `included_kwh` allowance, the only supported
`allowance_cadence` (`monthly`), and one positive
`excess_rate_cents_per_kwh`. Its phase has `components=[]`. This makes the fee
and excess rate one billing mechanism instead of two ordinary components.
Missing or invalid values, another cadence, a package plus billed components,
or a phase that contains both `flat_fee` (EUR/month) and `monthly_fee` fails
closed in `CanonicalPricingParser`.

For each calendar month, the calculator charges the package fee once and then
`max(month_usage - included_kwh, 0) * excess_rate`. A partial calendar month
pro-rates the fee, allowance, and usage by the same day fraction. Unused kWh do
not carry to another month. For a Time or Season profile, the usage buckets are
mutually exclusive, so the calculator sums all buckets first and applies the
one shared allowance. It never gives one allowance to each bucket.

A package is contract pricing, not a promotion. Actual and normal monthly costs
stay equal unless a separate future package-offer model is added. Thus package
allowances do not create `discount_savings_total`,
`monthly_discount_savings`, or `includes_discounts`. The typed calculated-cost
payload exposes `energy_package`; its schema version is v6. The calculator does
not read relational `price_components` to fill missing package facts.

## Comparability policy (the ranking/label contract)

| Verdict | Listed? | Meaning |
|---|---|---|
| `comparable_exact` | yes | full window covered, `calculation.status = exact` |
| `comparable_estimate` | yes | Spot, reset, supplier-adjusted, or explicit unknown-period continuation estimate; total labelled "Arvio" |
| `term_price_only` | yes | fixed-term < 12 mo; ranked by actual term cost annualized, regardless of continuation |
| `base_only_hybrid` | yes | Hybrid (`unsupported`); base-only total + "Ei sisällä kulutusvaikutusta" |
| `excluded_unknown_future` | no | no applicable identifiable price can fill an annual segment; not merely an undisclosed future price |
| `excluded_incomplete` | no | broken/ambiguous/unsupported structured pricing; detail page only |

Conflicting structured pricing is excluded first. Hybrid base-only and short-term paths then cost
chronological segments, including explicitly assumed gaps. An incomplete status caused only by
unknown future pricing does not block a costable estimate; disclosed Spot and resolvable duplicate
fees retain their exceptions. Remaining unidentifiable/unsupported pricing is excluded. A detected
promotion with unknown future prices can still list as an estimate with its factual warning.
Do not restore full-year certainty as an eligibility gate. A monthly fee alone is not proof of free energy.

Domain rules layered on top (each with a regression test and a documented reason):
- **Recurring market products** (monthly/quarterly/seasonal/other reset) are never excluded for
  `detected` and get no deceptive label — they behave like Spot (current period known, future resets with the
  market; a small first-period intro is not deception). Each uncovered segment selects the latest
  already-applicable disclosed price via `applicableKnownPhaseIndex`, not the signup phase or a future phase.
- **Costable incomplete Spot** (`isCostableSpot`): a Spot contract with a disclosed `spot_margin` is a
  spot estimate even if the LLM marked it `incomplete` (some phrase the margin as a "toimitusmaksu").
- **Spot margin misclassified as fixed energy** (`resolvePhaseRates`, `SPOT_MARGIN_CEILING_CENTS = 2.0`):
  on a `Spot` contract the energy price is always spot base + margin. Some interpretations tag the margin
  as a small fixed `energy_day`/`energy_night`/`energy_seasonal_*` rate (e.g. 0.26–0.5 c/kWh) with no
  `spot_margin` component; without a guard the calculator would read that tiny rate as the whole energy
  price and the total collapses to roughly the monthly fee (Spot Valo, Kosken markkinaWoima showed ~57–73
  €/yr). When a Spot contract has no `spot_margin` and every standalone per-kWh rate is **≤ 2.0 c/kWh**,
  those rates are folded into the spot margin so the spot base is added. A rate **above** the ceiling is a
  genuine all-in price (a market-price product at ~7 c/kWh, e.g. Cheap Markkinahintasähkö's 6,99 c/kWh
  first month) and stays fixed energy — folding it would double-count the base. Note that a contract can
  hold both shapes in different phases; see the mechanism rule below before assuming the whole year is
  flat. Bucket values are equal in practice so the `max` is
  exact; if they ever differ it is the conservative (higher) choice. This is a deterministic safety net that
  also protects rankings if a future interpretation regresses; the matching LLM-prompt fix (classify these
  as `spot_margin`) is a documented follow-up. Regression tests 21 (fold) and 22 (control) pin both sides.
- **Resolvable duplicate fee** (`isResolvableDuplicateFee`): a fully-covered contract whose only gap is
  two `monthly_fee` components lists, resolving to the higher fee. `resolvePhaseRates` takes `max` of
  duplicate monthly fees; `flat_fee` (eur_per_month) package charges add on top.
- **Component inheritance**: a promo phase that lists only the changed component (e.g. `monthly_fee = 0`
  for month 1) inherits the unchanged energy price from the base (fullest-priced) phase — it is not read
  as free energy. An explicit value (including 0) is an override and is not inherited.
- **Inheritance never crosses the per-kWh mechanism** (`effectiveBilledComponents`): `spot_margin` and the
  fixed `energy_*` rates are two ways of pricing the same kWh, so a phase that states one must not receive
  the other from the base phase. `resolvePhaseRates` prefers a fixed rate over the spot base
  (`$rate = $general ?? $spotDay`), so an inherited `energy_general` silently overrode the phase's own spot
  margin. **This was live.** Cheap Markkinahintasähkö is one flat month at 6,99 c/kWh and then Nord Pool
  monthly average + 1,29 c/kWh; the whole year was priced at the one-month promo rate, 404 €/v instead of
  486 €/v at 5000 kWh. `basePricingPhase` made it worse by breaking a component-count tie in favour of the
  earliest (promotional) phase, but the mechanism guard fixes the class of bug whichever phase wins.
  Measured 2026-07-26 on the 425 active contracts: exactly 3 change, all fixed-then-spot shapes, and the
  other two move *down* because their spot continuation is cheaper than the fixed term they inherited
  (Hehku KIINTEÄ 6 kk −41 €/v, Cheap Määräaikainen 6 kk −29 €/v). Inheritance **inside** one mechanism is
  unchanged, so a Time phase that restates only `energy_day` still inherits `energy_night`. Regression
  tests 23 (cross-mechanism) and 24 (same-mechanism control) pin both sides.
- **Annual Hybrid base-effect placeholders**: `Unsupported`, or current explicit base-effect pricing, with typed
  `consumption_effect.present=true` and `applies_to=base_contract` removes `Other` / `cents_per_kwh`
  rows with amount exactly zero and normal amount null or zero from an immutable calculation copy.
  Explicit `ConsumptionEffect` components are also omitted from that copy; they are never billed.
  Current candidate extraction shares this removal, without changing source status or effect numbers.
  The explicit base-effect mechanism is authoritative, not the legacy pricing-model enum;
  a `FixedPrice`-labelled contract with these same facts also gets a base-only estimate.
  This keeps known base phases and fees available (Helen Valkkysähkö: €716.88; Herrfors Vakaa:
  €442.60 at 5,000 kWh) after the chronological annual-cost change. Later known increases stay exact.
  The effect is excluded, not predicted zero. Conflicting sources, missing/nonzero amounts, positive
  normal amounts, wrong units, and fee-only tariffs still fail closed. Exact-period pricing and the
  general `Other` rejection do not change. Tests: `php artisan test --filter='CanonicalContractPriceCalculatorTest|AsOfAnnualCostCalculatorTest'`.
- **Duplicate-zero guard**: within a phase, a placeholder `0` never overwrites a real non-zero rate of
  the same component type.

## Integrity label

Gate: only `misleading_first_12_months === 'detected'` can produce a label
(`uncertain`/`not_assessable`/`not_detected` never do). Then by issue-code family:
- **Promo** (`structured_matches_intro_only`, `future_price_omitted`, `promotion_metadata_missing`,
  `future_price_unknown`): card pill "Hinta nousee {d.m.Y}" (or "Tarjoushinta ei kata koko vuotta");
  detail notice states both prices, the change date, and the first-year € impact
  (`trueTotal − structuredOnlyTotal`). A fixed-term whose only codes are the continuation codes is
  exempt (the "{N} kk sopimus" term pill explains it).
- **Data conflict** (`component_mismatch`, `insufficient_evidence`, `*_mismatch`, `other`):
  detail-page-only neutral notice; no accusatory card pill.

Suppressed even when `detected` (except the unknown-period caveat below):
- an **active recurring reset** (legitimate market product; the "Arvio" marker communicates the estimate);
- a **listed** promo that does not **materially** understate the year — the gate requires impact ≥ 30 €
  AND structured ≤ 80 % of true. Tyyni Vakiohinta understates by 434 € (42 % of true) → labelled; a
  6-month fixed that continues at a similar spot price (~50 € / ~10 %) → not labelled. Unknown-period
  estimates bypass this materiality suppression and keep a detail-only caveat: an assumed unchanged
  price is not proof of safety. They do not invent a known increase, card increase pill, or euro impact.
  The short-term continuation exemption also does not hide unknown in-term coverage.

**All UI copy is generated from typed fields; the LLM `summary` string is never rendered.**

## VAT basis

`Household`, `Both`, and legacy null targets use VAT-inclusive prices; `Company` uses VAT-excluded
prices. `CanonicalComponent::vatStatus` preserves source evidence. Calculation copies normalize
explicit included/excluded monetary amounts and normal amounts to the target once; unknown status
assumes the target basis. Mixed component bases are not a conflict. Percent/opaque units remain
unchanged. Packages and top-level consumption effects have no source VAT facts and keep that
explicit target-basis assumption. Stored parser/source data is never changed.

The existing configured `price_forecasting.fixed_term.vat_multiplier` supplies the conversion.
FI market curves are inclusive; Company calculations convert curve prices, reference prices,
Spot monthly values, and shape offsets exactly once before applying them to normalized components.
Do not scale the resulting reset/supplier offset again or mutate a shared household Spot estimate.
Exact-period pricing uses realized hourly tax evidence and never the annual market projection.
Typed `vat_basis` output controls public tax wording; it is not an LLM summary.

## Rollout

1. `config/canonical_pricing.php` — `CANONICAL_PRICING_ENABLED` (default false, **true in
   production**) and the independent `reset_forward_shift.enabled` /
   `RESET_FORWARD_SHIFT_ENABLED` (default false, **also true in production since
   2026-07-25**).

   **Both config defaults are false and both are true in production**, so a local `.env` that
   omits them prices market-reset contracts differently from the live site: Kokkolan Vuodenaika
   at 5000 kWh is 429 €/v with the reset flag off and 556 €/v with it on, which is what
   voltikka.fi serves. Both are documented in `.env.example` with the production value, and
   both are pinned to `false` with `force="true"` in `phpunit.xml` so the suite cannot inherit a
   developer's environment. The annual statistics method is also forced to the configured legacy
   default there; AsOf tests opt in through config. This does not alter production configuration.
   Tests that exercise either pricing flag opt in via `config()->set()`.

   `PricingMode` snapshots both flags once per request or command. Resolve normal pricing services
   through `app()`. Direct construction must supply both `PricingMode` and a
   `CanonicalContractPriceCalculator`; the calculator requires a `MarketResetPriceEstimator`.
   Explicit hold-flat calculations use `ResetEstimatorSettings(enabled: false)` and cannot occur
   because an estimator dependency was silently absent. The service constructor rejects a
   `PricingMode` whose reset state disagrees with the supplied estimator.
2. `contracts:compare-canonical-pricing {--consumption=} {--start-date=} {--json=} {--resets} {--fail-on-parse-errors}`
   diffs legacy vs canonical totals across all active contracts and lists exclusions/labels. Run it on
   the synced local DB and on production (read-only) before flipping the flag. `--resets` switches to
   the hold-flat-vs-forward-shift review for market-reset lineages.
3. Cache keys use `PricingMode::cacheMarker()` (`c0r0` through `c1r1`) so canonical state,
   reset-shift state, and the expected statistics basis come from one immutable value. Toggling
   either flag at a new request or command boundary busts stale caches immediately.

## Consumers migrated

Listings (`ContractsList`/`SeoContractsList`/`CheapestContracts`/`SahkosopimusIndex`),
`ContractListCacheService`, `CompanyListCacheService`, `ContractRankingService`, `ContractDetail` (hero/notice/meta/JSON-LD),
`CompanyDetail`, `LocalContractsService`, `ContractTypeComparison`, `BillComparisonService`
(annual + exact-period canonical outcomes for all three bill surfaces), `WeeklyOffersVideoService`,
`CalculateContractPercentiles` (listed, non-detected only), the API controllers, and
`ContractPriceStatisticsService` (all forward numeric metrics and measured offer state; **backfills
always pass `useCanonical: false`** because historical seller observations must never be
reinterpreted with today's canonical data).

Cards hydrate existing Eloquent transport attributes immediately. `../ContractCard/ContractCardPresenter` turns
one `ContractPricingViewData` plus typed `ContractPricingIntegrity` into one view model that both card
templates render. In canonical mode, current rates, fees, package facts, phase rows, offer
membership, totals, and savings come only from a payload with `pricing_basis = canonical`; no
passed price or loaded relation can fill a gap. Excluded outcomes have no current rates. Short
fixed-term benefit copy uses `calculated_cost.contract_term`, not annualized savings. See
`../ContractCard/AGENTS.md`.

`ContractDetail` uses the same canonical current values for its receipt, title price phrase,
current-price meta text, and Product JSON-LD. Missing values are omitted; canonical-only
contracts can emit available values; excluded outcomes emit no Offer. Its historical chart and
version/replacement timeline remain relational observed evidence and are not current fallbacks.

All three bill-comparison surfaces use one batched canonical period path. Relative phases anchor at
that counterfactual signup date; absolute dates stay absolute. General consumption is flat, Time is
85/15, Season uses actual winter dates, and Spot phases use each matching realized hour, including a
mid-period fixed/Spot or margin switch. Zero matching realized observations for required Spot hours
remain unavailable. A partial gap is filled per UTC hour with the observed arithmetic mean for that
Europe/Helsinki calendar date, or with the requested period's observed required-hour mean when the
whole Helsinki day is absent. The completed map is shared by actual and normal-price passes and the
outcome records `missing_spot_hours_filled_with_observed_average`. This keeps nearly complete factual
bill periods available without inventing a market level when all evidence is absent. Ordinary fees use
the existing days/30 convention. Package fee and allowance are both prorated by calendar-month
fraction, reset separately per month, and do not create promo status. For current unchanged-energy
supplier candidates, annual and exact-period normal fees share `unchangedEnergyNormalRates`:
explicit component normal amounts are primary, a typed introduction uses its first normal
continuation, and ordinary fee changes stay on their own segments. A later fee increase cannot
become an earlier period's offer saving. Actual period arithmetic, source-promotion guards,
Historical annual policy, and broader energy-changing period rules are unchanged. No annual
projection enters factual periods. The period promotion flag is the measured normal-minus-actual
period saving. Canonical mode loads no relational components;
feature-off keeps the old period calculator.

`ContractTypeComparison` also uses one request-memoized typed annual outcome per candidate and
consumption basis in canonical mode. Auto-selection, the monthly chart, current unit/package facts,
average-monthly and annual totals, comparability, winner, and savings all use that same outcome. The
chart renders canonical `monthlyCosts` directly. Excluded or incomplete selections have no series
and stop the comparison instead of becoming zero; canonical queries do not load components.
Feature-off keeps the legacy calculator and historical monthly Spot basis. This widget has no
prepared result cache, so its migration required no cache payload version.

Company offer sections and the SEO offer listing use `CanonicalOfferFacts` in canonical mode.
It accepts `ContractPricingViewData` and only a listed canonical outcome with a positive measured benefit, no
package, and a complete supported `offer_terms` payload. It formats the actual component price
and exact duration/date in controlled Finnish; raw phase labels, seller text, and interpretation
summaries are never display fallbacks. Ordinary offers state the 12-month comparison-period
benefit; a short fixed term uses its unannualized `contract_term` benefit and labels the real
duration. The SEO candidate set is not prefiltered by relational rows, so canonical-only offers
remain eligible when their typed term is complete. Product JSON-LD uses the same facts. Feature-off
keeps the relational membership and label paths.

The weekly-offers generated-data service also uses this boundary. In canonical mode it starts from
all active household contracts and calls `metricsForContracts()` once for each of 2,000, 5,000, and
10,000 kWh. Membership requires a positive `CanonicalOfferFacts` benefit with no package at 5,000
kWh, plus a listed outcome and no detected integrity state at every output level. It ranks by the
real customer benefit at 5,000 kWh, then canonical total,
then contract ID, before keeping one contract per company. Its API, Remotion input, and prompt use
typed canonical totals, normal totals, current rates, comparability, estimate state, and benefit
basis. A short term states the real term benefit; annualized totals are comparison values only.
Feature-off keeps the old relational data and prompt branch.

The public contract list/show API follows the same boundary. In canonical mode,
`Api\ContractController` uses one `metricsForContracts()` batch for each list page, returns typed
canonical current facts in `current_pricing`, returns the existing canonical `calculated_cost` only
when consumption was requested, and omits relational `price_components`. Excluded results carry an
explicit unavailable/comparability state and no current rates. Feature-off responses retain the
legacy `PriceComponentResource` rows and calculator. See `../../Http/AGENTS.md`.

**When you add or remove a field on `calculated_cost`, or change which contracts receive a materially
different calculated outcome, bump `CalculatedCostPayloadSchema::VERSION` once.** The bump refreshes
caches only. When it moves annual totals, also plan the history rebuild that
`../ContractStatistics/AGENTS.md` ("History follows the current method") requires. List, company,
ranking, and prepared-page cache keys all include this shared dependency. Their service-specific
outer payload versions remain separate; bump an outer version only when that wrapper's own
membership, fields, or structure changes. The import-driven version and the pricing-mode marker do
not move on a code-only deploy, so the shared schema marker prevents cards and aggregates from
reading an old calculated-cost shape or pricing verdict. Company and
ranking keys also include `ContractListCacheService::getVersion()`, so each published interpretation
invalidates their data immediately. Shared list/company prices otherwise remain in the active
verified generation until replacement; see `../Caching/AGENTS.md`.
`ContractPricingIntegrity` gained typed `promo_rate_cents` /
`normal_rate_cents` for the dated receipt rows; that was schema v2.

Current schema **v19** adds the release boundary for source-backed financial/public integration,
including nullable actual-only short-term facts and separate actual/normal certainty. It safely
regenerates calculated-cost caches; it does not rewrite statistics/history, relabel retained rows,
or change annual-v2, beta or interpretation defaults. The combined LOCAL PHP gate passed; remaining release checks do not establish production acceptance.
Schema **v18** covered the preceding local term/promotion, dated lineage-episode and supplier-premium
changes. Neither cache version is proof of deployment or producer activation.

Schema **v17** corrects the market-reset seasonal fallback reference: monthly stays the exact
anchor month; quarterly, seasonal, and other use the calendar-day-weighted containing quarter
with anchor-year day counts. A published quarter price must not be divided by only its last
month's index. It changes calculated-cost caches, not stored annual statistics or the active public
annual method. Forward priority, beta, and supplier-adjusted monthly references stay unchanged.
See `MarketReset/AGENTS.md` for the rule and invalid-index fallback.

Schema **v16** invalidates pricing-semantic caches once for chronological unknown-period estimates,
short-term/Hybrid coverage, no-overflow anniversary fees and bins, one-time charge identity,
common flat default usage, reconciled annual equivalents, local Spot evidence and baseload fallback,
and audience/component VAT normalization. Its original midnight cache boundary has since been
replaced by explicit verified annual-price generations; direct calculations still use their supplied
or current start date. See `../Caching/AGENTS.md`. The version changes
calculation output and caches only: it does not rewrite stored interpretations, historical snapshots,
annual statistics, or old method evidence. Historical method facts below remain release history.

Schema **v15** corrects recurring-reset energy boundaries for fee-only phase transitions.
The calculator compares adjacent resolved energy buckets and mechanisms with the existing component
inheritance rules. A fee change with unchanged energy does not extend known energy coverage or move
the reference period/vintage. Fee billing and measured benefits keep the original phase timeline.
Explicit recurring boundaries, finite coverage before a gap, and real energy changes stay intact.
See `MarketReset/AGENTS.md` for the September 2026 Aalto regression and historical-statistics limits.

Schema **v14** adds `calculated_cost.spot_estimate` and changes canonical Spot annual-cost semantics
from a flat trailing-365 level to a monthly FI forward strip with trailing intraday shape. Persistent
list, company, ranking, and prepared-page caches must not retain v13 Spot sort values. The payload
carries both curve vintages, every touched month, source kinds, annual equivalents, shape evidence,
confidence, and fallback flags.

Schema **v12** adds `calculated_cost.supplier_adjusted_estimate`. Schema **v13** expands the same
payload from General-only `contract_start`/`none` cases to eligible General, Time, and Season tariffs
whose one current phase can also start at `unknown` or a date. The strict consumer boundary and
public contract API carry the typed basis, episode evidence, market vintages, current rate,
12-month equivalent, flat-fee assumption, and fallback flags. Three supplier-adjusted estimate
methods distinguish forward-curve, Spot-seasonal-index, and hold-current rungs.

Schema **v11** invalidates cached list, ranking, company, and prepared-page membership after
`other` became a listed recurring reset cadence. It adds no calculated-cost field.

Schema **v10** extends `calculated_cost.contract_term` to short
`base_only_hybrid` outcomes. Their real-term base-only total and saving are
captured before annualization, while comparability and the Hybrid exclusion stay
unchanged. This makes a six-month Hybrid offer state its six-month benefit.

Schema **v9** adds `calculated_cost.offer_terms`: exact resolved timing plus typed actual and
normal component amounts. Canonical public offer copy now requires this payload and fails closed
for unsupported or untyped terms. List and prepared-page cache payload versions both moved to v9.

Schema **v8** is the company/SEO offer boundary. Offer membership and Product JSON-LD now use
canonical measured facts, including real-term benefit copy, instead of relational discount rows.

Schema **v7** is the card/detail cache boundary for canonical-only current values and
real-term offer copy. It adds no interpretation field; it prevents stale prepared payloads and
view models from crossing the consumer migration.

Schema **v6** adds `calculated_cost.energy_package` with the monthly package
fee, included kWh, monthly cadence, and excess-use rate. Package totals use the
allowance month by month and never report package inclusion as an offer saving.

Schema **v5** adds `calculated_cost.contract_term` for the unannualized cost and
benefit of a fully costed short term. It is null for non-term and excluded
outcomes.

Schema **v4** changed the canonical offer fields:
`base_monthly_costs`, `discount_savings_total`, and
`monthly_discount_savings` now carry the measured promotion-free calculation.
This fixes canonical offers such as Vattenfall's 50 percent base-fee cases while
leaving their already-correct actual total unchanged.

Schema **v3** enriched `calculated_cost['phase_breakdown']`. Each governing phase now records
the coverage the timeline actually resolved for it (`window_start`, `window_end`, the last day
inclusive) and the rates it was costed at (`uses_spot`, `energy_cents`, `spot_margin_cents`,
`monthly_fee`). `ContractCard/CardReceiptLines` reads it to state a mid-window switch between
the two per-kWh mechanisms as two dated rows ("Energia 25.8. asti 6,99" / "Marginaali 26.8.
alkaen 1,29"). **Keep the record here rather than re-deriving boundaries in a presenter** —
`Support/PhaseTimelineBuilder` is the only implementation of that algorithm and must stay so.

Eligible adjustable open-ended fixed General, Time, and Season tariffs use the separate
`SupplierAdjusted/` path. Only the current calendar-month remainder stays exact. Later months use
`P_m = P_current + beta * (F_m(today) - F_reference)`, where the reference is the FI month contract
for the observed current-price episode's start month at the latest vintage before that episode
start. Time and Season tariffs apply the same additive shift to each exact rate. Their stable
representative rate uses statistics snapshot weights only for episode matching; the displayed
12-month equivalent comes from billed energy and the same costed consumption. Forward curve, Spot seasonal index, and hold-flat are all typed estimates.
Multiple monthly-fee variants resolve to the same conservative maximum as the calculator. This
keeps supplier-adjusted eligibility aligned with the exact current fee already used for ranking. Exact-period pricing never applies this annual projection.

Public card and contract-detail copy reads only the typed `supplier_adjusted_estimate` payload.
Every forward-curve, Spot-seasonal-index, and hold-current result gets the shared `Arvio` popover.
General receipts separate `Energia nyt` from `12 kk keskihinta, arvio`. Time and Season cards keep
their two exact tariff rows and fee, while ContractDetail also shows the estimated equivalent. The
fixed category band states that only the current prices are fixed and the seller can change them
with notice. ContractDetail adds one quiet basis note and a short qualifier that separates the
published current facts from the annual estimate. No surface presents this path as a disclosed
cadence or a future contractual rate.

The detail-page notices live
in `resources/views/livewire/contract-detail.blade.php` (after the hero): the neutral market-reset
notice first, then the amber integrity notice.

## Market-reset contracts: annualized with a shape-only forward-curve shift

Market-reset products (`recurring_schedule.present` with cadence `monthly` / `quarterly` /
`seasonal` / `other`) publish one price per period. Cadence `other` covers a validated recurring
reset with no exact calendar boundaries and uses the quarterly calendar and reference proxy.
Holding that seasonal price flat for twelve months was a
**live defect**: too low in summer, too high in winter, on roughly 32 lineages, about two thirds of
them quarterly. `config('canonical_pricing.enabled')` is **true in production** even though the
config default is false, so do not read "default off" above as "inert".

That is now fixed in **`MarketReset/`** — read `MarketReset/AGENTS.md` before changing any of it.
The current period stays exact and only the tail is repriced:

```
P_m = P_current_period + beta * (F_m - F_reference)
```

Behaviour summary, with the reasons living in `MarketReset/AGENTS.md`:

- Gated behind its own flag **`RESET_FORWARD_SHIFT_ENABLED`** (default false), separate from
  `CANONICAL_PRICING_ENABLED` because that one is already live and cannot stage this. Flag off is
  byte-identical to hold-flat, and the flag varies the list/ranking/page cache keys (`r1`/`r0`).
- **Two curve vintages, deliberately.** `F_m` reads today's curve (latest `trade_date < today`),
  because the coming year's level is what the customer will actually pay. `F_reference` reads the
  **pricing** vintage (latest `trade_date <` the current period's start), because that is the forward
  the seller priced the period from — the same rule `RetailPremium` uses for spread measurement.
  Reading the reference at today's vintage instead inflates the implied spread by pure front-month
  convergence, measured at 1.58 c/kWh (about +79 €/yr at 5000 kWh) on monthly cadences.
- `beta` is **one global value** (1.0). Per-company calibration stays the documented future work
  below, and is also what pins down the effective pricing date behind the vintage proxy.
- A phase with `ends: none` is **not** a credible reset boundary; at minimum the current cadence
  period stays exact, and finite known energy coverage also stays exact. A fee-only transition
  with unchanged resolved energy is not an energy boundary.
- Ladder: forward-curve shift → multi-year spot seasonal index (lower confidence) → hold flat, with
  the rung recorded on the outcome as `EstimateMethod::RecurringForwardCurveShift` /
  `RecurringSpotSeasonalIndex` / `HoldCurrentRecurringPrice`, plus a typed
  `calculated_cost['reset_estimate']` basis payload.
- Guards: negative-price floor, stale-**forward**-curve threshold, and an absolute absurdity band on
  the annual equivalent. The band is deliberately **not** relative to the fully-fixed market: a reset
  that honestly annualises above a fixed deal is a true finding, not something to suppress.
- **No deceptive-pricing label.** The suppression rule for active recurring resets is correct: the
  price change is the published mechanism of the product, not hidden promotional text.
- UI shows the known current-period price and the estimated 12-month equivalent as **two separate
  figures**, and the total stays marked "Arvio".

Staging: `php artisan contracts:compare-canonical-pricing --resets`.

## TO BE IMPLEMENTED IN THE FUTURE: per-company calibration of the reset estimate

The shipped implementation in `MarketReset/` deliberately uses **one global `beta` and a
cadence-driven reference**. Making `beta` and the reference period **per company** is a documented
future improvement, not part of the first rollout. It is also the proper fix for the front-month
convergence bias recorded in `MarketReset/AGENTS.md`.

Why it is deferred rather than done now:

- Pass-through is measurably a company trait. Within-company premium dispersion is well below
  across-company dispersion, and companies reprice their products together.
- But it can only be calibrated from observed resets against the futures curve **at the vintage the
  price was set**, and the FI curve history starts **2026-04-08**. EEX publishes only about a 45-day
  rolling window server-side, so earlier vintages are **permanently unrecoverable** — verified by
  request, not assumed (an expired quarter maturity returns zero rows even with the cap lifted).
- That leaves a sample of two companies today. `retail-premiums:calibrate` on production 2026-07-25:
  Kokkolan Energia **1.01** (R² 0.66, 3 pairs) and Pohjois-Karjalan Sähkö **0.61** (R² 0.67, 3 pairs),
  both on a month
  reference. Quarterly cadences have one period each inside the curve window, so they are uncalibrated.

When it becomes possible:

- **1 October 2026** gives every quarterly lineage a second period, so about 24 lineages contribute a
  pass-through step at once. January 2027 doubles it.
- Alternatively, buying historical FI Base month/quarter settlements for January–April 2026 would unlock
  it about two months earlier and roughly double the monthly evidence. Vendors and verified terms are in
  `tasks/retail-premium-dataset/decisions.md`; all the exchange routes found were annual subscriptions,
  so waiting is the cheaper default.

The observation dataset that will feed the calibration already exists and collects daily — see
`../RetailPremium/AGENTS.md`. **Any analysis must filter to the current `method_version` pair.**

## Forward-looking Spot annual estimate

`SpotForward/SpotForwardPriceEstimator` reads one complete FI Base forward strip for every calendar
month touched by `[window start, window start + 1 year)`. A mid-month start touches 13 calendar
months. The current in-delivery month uses the latest curve strictly before that month began; later
months use the latest curve strictly before the comparison date. The shared provider returns
VAT-inclusive c/kWh and falls from month to quarter to year contracts. The forward strip applies to
all supported audiences after full-bill normalization: Household/Both/null inclusive, Company excluded.

Futures are baseload prices. The estimator preserves the trailing-365 intraday shape as additive
`day - overall` and `night - overall` offsets. Historical AsOf pricing can use an accepted stored
shape with at least 98% hourly coverage because it supplies only these offsets, not the future market
level; complete/partial coverage stays in the annual-result provenance. It does not apply historical
monthly seasonality on top of futures because the forward strip already contains that shape. Each
projected wholesale bucket is floored at zero before the exact contract margin is added. Fees,
phases, and measured discounts stay contractual facts.

Missing, stale, or incomplete curve evidence produces one typed rolling-365 fallback when a
historical level exists. Insufficient or absent historical shape does not reject a complete curve:
use zero day/night offsets at lower confidence with `zero_intraday_shape_fallback` and the
public `spot_forward_curve_flat_baseload_shape` assumption. Preserve actual coverage and unavailable
historical-source facts; do not invent a verified shape. Forward and rolling months are never mixed. `CanonicalContractPricingService`
memoizes one estimate per window and shape evidence set, including coverage, after parsed contracts
prove that Spot is needed. New `rolling_30d_local` / `rolling_365d_local` rows cover half-open Helsinki
calendar-date windows with raw timestamps parsed as UTC. Readers accept legacy rolling types,
choose the newest eligible date, and prefer local on ties. Legacy UTC-date evidence keeps explicit
`legacy_utc_dates` provenance, never verified local shape; no old rows are rewritten. `calculatePeriod()` never receives this estimate and keeps using realized hourly Spot data.

## Retry-local calculation state

`CanonicalContractPricingService::resetMemoization()` clears Spot assumptions, supplier episode anchors, and Spot estimates before a price-cache retry or full candidate attempt. The container also supplies the shared market provider so this reset clears the EEX provider's request-local reads. The annual calculator itself has no outcome memo, and each new build reloads contract models and parses their current canonical JSON. No formula, pricing mode, historical evidence, or process-wide cache is changed. See `../Caching/AGENTS.md` for the two-attempt boundary.

## Deferred / known limitations

- FI Base futures are market prices for baseload delivery, not an hourly customer-price forecast.
  The rolling-365 day/night offset is a coarse household load-shape proxy. Quarter and year fallback
  instruments also flatten the monthly shape inside their delivery period. Public copy must keep the
  result labelled as an estimate, not a price promise.
