# Current forward premium selection

> **History policy (2026-09-21):** when this estimator's current method changes, the stored annual
> history must be recalculated with the same method in as-of mode. Statements in this file that the
> Historical path is "unchanged", "strict", "retained", or "never invokes" a current component
> describe the implementation state. They are known gaps, not rules to preserve. The rule that stays
> is no look-ahead: a past date uses only peers, premiums, interpretations, and curve vintages known
> on that date. See "History follows the current method" in `../../ContractStatistics/AGENTS.md`.

The pure selector chooses an ephemeral retail premium for current non-fixed annual forecasts.
`CurrentPremiumEvidenceLoader` now connects it to the strict ordinary supplier-adjusted current
path, including the shared unchanged-energy extraction for redundant and fully disclosed fee-only phases. It also serves current market-reset candidates that lack an original reference. Real energy promotions and unknown gaps before known future energy spans remain outside this slice. Nothing in this directory writes
observations or changes the old RetailPremium dataset.

## API

`ForwardPremiumResolver::resolve(PremiumTarget $target, iterable<PremiumObservation> $observations): ?PremiumEstimate`

All input and result DTOs are `final readonly`. Dates use `CarbonImmutable`, reduced to calendar
dates. Callers must supply dates in one consistent market calendar (Europe/Helsinki). Period ends
are inclusive. Company names are exact source identities: no trimming, case folding, alias lookup,
or fuzzy matching takes place. Blank identities are rejected.

- `PremiumTarget`: contract ID, caller-trusted lineage/root ID, exact company name, compatibility,
  and as-of date. A replacement ID can use the same lineage ID. No name matcher is added here.
- `PremiumCompatibility`: `PremiumFamily` (supplier_adjusted, market_reset, or their separate
  supplier_adjusted_hybrid_base / market_reset_hybrid_base identities), existing
  `MeteringType`, `PremiumVatBasis` (included or excluded), named energy `ComponentType` buckets,
  and reset cadence. Reset cadence must be monthly, quarterly, seasonal, or other. Supplier-adjusted
  evidence must have null cadence. Every compatibility field must match exactly, including the
  complete sorted bucket set. Other and quarterly remain distinct, even if an adapter uses the
  same wholesale proxy. Mandatory consumption-effect bases never mix with ordinary rates.
  `PremiumFamily::isReset()` applies the same cadence and model-date rules to both reset families.
  Spot and locked fixed-term rates are not separate supported premium families. Current Hybrid-base
  eligibility requires explicit base effect, complete known canonical base and unchanged energy;
  numeric effects never contribute to premiums. The estimator request guards the same mechanism.
- `PremiumObservation`: trusted lineage, exact company, compatibility, complete named bucket
  premiums, verified retail energy offer signature, actual retail observation date, reference
  trade date, retail price-period start/end, reference delivery start/end, and evidence/reason
  provenance. The signature must identify the full normalized retail energy tariff (all named
  rates and relevant non-fee energy terms). It must exclude fees, contract IDs and observation
  dates. Do NOT use premium numbers alone as the signature: different retail offers can have
  equal spreads. The evidence loader owns this signature and trusted lineage resolution.
- `PremiumEstimate`: source enum (own_lineage, same_company, market), sorted bucket premiums,
  retained typed observations (with delivery bounds, signatures and provenance), sorted source
  lineages/companies/reference trade dates, observation/lineage/company/independent-variant counts,
  observed evidence bounds, sparse/lowerConfidence booleans, and sorted provenance flags.

Malformed evidence throws `InvalidArgumentException` at DTO construction; unsupported enum values
cannot enter the typed boundary. The pure compatibility DTO requires a known normalized VAT basis.
The current loader uses the canonical audience convention: explicit source VAT converts once;
unknown source VAT assumes that target basis, with explicit provenance. It never labels that
assumption as explicit raw VAT. Numeric values are normalized energy-only c/kWh, already on the target VAT basis. Finite negative and zero
spreads are valid. No fee-inclusive spread, explicit Spot margin, Hybrid consumption effect, null,
numeric string, NaN, or infinity is accepted. The loader must prove the energy-only origin; this
numeric selector cannot detect a fee hidden in a number falsely labelled as energy.

## Public evidence privacy

`PremiumEstimate::toArray()` is the public boundary used by Supplier/Reset estimates and nested
V5 normal projections. It omits `source_companies` and `source_lineages`. Reference `provenance`
is controlled text from the typed family, reference-period proxy and normalized VAT basis; it
never copies observation prose, source/publication IDs, donor names or lineage tokens. The
`target_audience_vat_normalized_unknown_assumed` text describes the normalization convention:
unknown source VAT assumes the target. It does not claim explicit source tax evidence.
`PUBLIC_KEYS`, `PUBLIC_REFERENCE_KEYS`, `PUBLIC_FLAGS` and `publicProvenance()` also define the
strict read-model boundary. Cached/raw Supplier/Reset premiums, including normal projections,
must match this public vocabulary. The reader rejects private extras and free text; it does not
sanitize them or repair financial values.

Public output retains source category, bucket premiums, all counts, confidence, evidence bounds,
reference trade dates, pricing/delivery dates and proxy facts. Only the eight existing selector
flags can serialize; unknown caller flags stay private. Internal readonly source collections,
observations, signatures, provenance and flags stay intact for selection, deduplication and audit.
No price, hierarchy, weight or evidence rule changes. Candidate schema 19 is not deployed, so this
bounded correction needs no additional cache version. See
`tasks/source-validated-energy-rules/premium-public-privacy.md` and `PremiumPublicPrivacyTest`.

## Evidence dates and transfer

By default the reference trade date must be strictly before the observation's price-period start.
Reference delivery start/end must match THAT observation's complete retail price period.
For supplier evidence, `referencePeriodProxy=true` requires an observed `pricingDate` inside
the model delivery interval, no later than the observation date, and a trade strictly before that
date. Reset evidence instead uses `pricingDate = min(period start, comparison date)` as its model
reference bound. It can be outside the delivery interval for an already announced future period.
It must not follow the retail period start or the target as-of date, and its trade must be earlier.
The actual retail observation must independently be available by as-of. A reset proxy must contain
the retail period's end in its model interval; it does not assert an exact seller guarantee.
Exact monthly/quarterly bounds need no proxy. Seasonal/other and non-calendar bounds carry
`reset_reference_period_proxy` and lower confidence. The selector rejects future model bounds,
retail observations and reference trades. Actual observed price-period bounds stay separate from
the model interval. A prepared
quarter/month-average reference can represent the same interval; this selector does not build it.
Retail observations after the target date and reference trades on or after the target date are
ineligible. Known advance prices can have a future delivery period if their evidence is already
available. There is no requirement that the historical retail month equals a future target month:
an August spread can be a transferable estimate for September or later. Requiring target-month
equality would wrongly remove usable historical premium evidence. No target forward month is an
input to this selector.

## Selection and weights

1. Filter exact compatibility and as-of availability.
2. For each trusted lineage in this energy compatibility group, retain its newest eligible retail
   observation date. Older periods/rate signatures do not create additional weights. A lineage
   represents one product's compatible energy history, not independent simultaneous products.
   On the newest date, differing price signatures, premiums, company, reference date, or delivery
   periods make the lineage unusable. Do not select the first row or revive an older price.
   Equal evidence with different provenance can remain as separate audit observations only.
3. Use the target's own trusted lineage (and exact company) first.
4. Otherwise group equivalent full retail energy signatures within each company. Each variant has
   one weight, regardless of replacement rows or fee/ID-only clones. Prefer its latest observation
   date; reject same-day conflicting clone evidence. Keep all equal latest sources for provenance.
5. Use same-company variant medians per bucket. If none exists, calculate each company's variant
   median, then take the median across companies per bucket. Sort numeric values; even medians
   average the two middle values. Divide first to avoid finite-input overflow. Input order never
   chooses a price or changes result order.
6. Return null when no defensible variant remains. Never invent a zero spread.

Counts distinguish retained source observations and lineages from independent variant weights.
Clone lineage counts can exceed variant counts. One independent variant is accepted; there is no
calibration gate or new minimum sample requirement. Single-variant/single-company evidence is
explicitly sparse. Comparable transfers and sparse evidence have lower confidence. Conflict and
deduplication flags are retained. These describe evidence quality, not forecast accuracy. Rejected
incompatible/future rows cannot supply a price. Conflicts can cause fallback to a later source tier.

Existing SpotForward keeps its explicit margin. At beta = 1, futures plus an own spread equals
`P_current + F_month - F_reference`.

## Current supplier adapter

`CurrentPremiumEvidenceLoader::forCandidates(candidates, contracts, anchors, asOf, resetCandidates = [])` returns target-ID
keyed optional `PremiumEstimate` values. Eligible targets with a missing own month reference and
a complete, fresh current curve trigger peer reads. Target eligibility remains the calculator's
existing rule; donor evidence always requires pointed publication proof. One available-vintage
preflight can run without an own anchor, but an unavailable current curve makes no peer reads.
The loader reads the active OpenEnded or FixedTerm, FixedPrice or Hybrid, General/Time/Season universe with
selected columns, first source observation and publication completion no later than the comparison
date, and exact current source/publication proof. Current pointed evidence remains usable after
midnight; there is no same-day last-observed gate. Provenance uses the actual last-observed date,
bounded by as-of, not today's date. No-lookahead checks remain. Malformed proven canonical peers
are caught and excluded without discarding valid alternatives; the integration regression covers this. It reuses `CurrentSourcePromotionEvidence`, the parser, the promotion
assessment, the calculator's consumption-free current supplier extraction, and the dated current episode resolver.
The loader passes its explicit comparison date to `CurrentSourcePromotionEvidence::forContracts`.
That existing single-query read also returns `energy_rules_valid`: exact V5/v20/v18 output,
canonical-column equality, observation/publication ownership and dates, and fresh full source
validation. Legacy `valid` and campaign `rates` keep their old meaning. Current readers consume
this proof for parser opt-in and normal-tariff premium integration under Current policy.
This is implemented locally, not deployed; V5 producer activation remains off-default. No new peer query or per-contract query is added. Fees, Spot margins,
Hybrid effect amounts, and incomplete promotions cannot become donor energy premiums. The broad
query is not eligibility: only the calculator's proven current mechanism can donate. Missing raw
Hybrid historical anchors stay missing, then use matching peers or the existing fallback. There
is no second loader. See `tasks/annualized-pricing-implementation/hybrid-projection.md`.

One cached peer universe per Helsinki date serves the orchestrator instance and all consumption
passes. `resetMemoization()` clears it on retry. There is no global or persistent cache. Shared
`ElectricityContract::getLineageIdentitiesByContractIds()` supplies trusted root hashes in two batch
reads. A valid observed energy anchor uses the existing month reference at the latest vintage
before the anchor. Per-bucket premium is normalized retail rate minus VAT-matched reference.
Missing references supply no observation; finite negative spreads stay valid.

The supplier estimator keeps its own valid reference first. Only its missing-own-reference path
can consume the prepared comparable premium, before seasonal/hold fallback. Each tail bucket uses
`beta * (current_future + selected_premium_bucket - current_retail_bucket)`. The current month,
fees, zero floor, and actual/normal/structured bill passes retain the same billing rules. The guard
uses the actual profile bucket/month weights, not an unrelated representative average.

`forward_premium` basis and `supplier_adjusted_forward_premium` method identify the changed current
financial calculation. The existing supplier payload carries `current_policy`, typed premium
source/counts/confidence/reference provenance, and a named fallback reason. Explicit Historical
calculation ignores supplied premiums and never invokes this loader. Configured annual v2 remains
unchanged; retained history is not relabelled or rebuilt. Shared calculated-cost schema is now 19.

## Current reset adapter

The optional reset candidates are typed `ResetPremiumCandidate` frames from the calculator's
consumption-free current helper. Supplier and reset candidate proof now share
`candidateApplicablePhases`: a current fee-only or partial tariff cannot borrow energy from a
future phase. Only a fee-only typed Introduction can use its adjacent typed Normal baseline.
The loader rejects an unsafe peer while retaining compatible proven alternatives; ordinary
billing inheritance and Historical replay are outside this candidate-proof correction. The same batched query, pointed proof, parsed canonical evidence,
lineage identities and request-local cache prepare both families. The orchestrator separates the
selected maps and passes `resetPremium` only to the reset estimator; `premium` remains the supplier
argument. Single, metrics, multi-consumption and period-wrapper annual calls share this preparation.
Factual period costs never receive the projected rates.

An original reset reference remains first. Missing references trigger peers only with a fresh,
complete curve for that target's required tail months. Known months and months beyond a short
real term do not require futures. Donor premiums use full normalized rates minus the VAT-matched
month or quarter reference at the latest vintage before `min(period start, as-of)`. Quarter-month
averages represent the whole quarter, not a fictitious monthly seller guarantee. Current source
observations keep their actual last-observed date. Historical calls never invoke this loader.

Reset method `recurring_forward_premium`, basis `forward_premium`, and policy
`recurring_forward_premium_v1` retain the typed source/count/date/confidence evidence. Actual,
normal and structured costs share each bucket's offset and the core's exact tail boundary. The
ordinary candidate still rejects an unknown gap before a future known phase, real energy promotions,
ambiguous or incomplete tariffs, packages and Spot. Explicit Hybrid base pricing uses its separate
compatible family. Source-rule promotions use the normal-map preparation described below.

One request-local peer universe is reused; retry clears it. This is not a promise of constant total
SQL for arbitrary dates. The real EEX provider caches repeated `(as-of, period, kinds)` references,
but its vintage reference service queries each distinct reference. A release performance check
must measure distinct-vintage costs. No persistent premium table or second loader was added.
Source-rule normal maps and latest-known price-regime handling are now connected through the
existing calculator. Unknown or contradictory normal evidence cannot become a premium donor.

## Source-authorized current normal donors

The same private loader now accepts exact-proof V5 current normal maps. A normal9 quote behind
actual promo4 contributes normal9 minus its dated wholesale reference, never promo4. Supplier donors
use `CurrentNormalCandidateExtractor` and normal-mode episode resolution. Fixed normal spans remain
ineligible for ordinary monthly donor evidence; target preparation is deliberately broader. Reset
donors use the current normal target map with their own cadence and reference period. Family,
tariff, VAT, cadence, lineage and economic-variant deduplication rules remain unchanged.

V5 parsing requires the batch `energy_rules_valid` fact. An expected V5 or known-rule publication
with failed proof cannot become a legacy donor. Source/publication IDs remain in evidence provenance;
service anchor memos include normal/actual mode, full rates, context/date and source identities.
The evidence-only zero fee never enters estimator billing: target requests use the original current
fee, and the original phase fee timeline remains in `costWindow`. Own references still avoid peer
SQL. Request retry clears loaded evidence. No premium data is persisted.

`CurrentEnergyRuleFinancialIntegrationTest` covers own and transferred Supplier/Reset normal
premiums and flat SQL counts at 1, 8 and 32 contracts with repeated consumptions.

## Verification

`cd laravel && php artisan test --filter=ForwardPremiumResolverTest`

Tests use prepared numeric observations only and extend PHPUnit's plain TestCase. No database,
network, application service boot, LLM, or production access is needed.
