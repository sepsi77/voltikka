# Canonical phase-aware pricing

This directory consumes the validated `electricity_contracts.canonical_*` interpretation JSON
(produced by `../ContractInterpretation/`) to calculate accurate 12-month prices and to derive
the deterministic deceptive-pricing label. It is gated behind `config('canonical_pricing.enabled')`
(`CANONICAL_PRICING_ENABLED`, default false); when off, every consumer keeps its legacy
`ContractPriceCalculator` behavior unchanged.

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
when a finite term cannot be costed or estimated under the existing Hybrid hold
rule. The existing top-level totals stay annualized for ranking and comparison.
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
  costing, a missing required object, or a conflicting VAT basis for one component throws
  `CanonicalPricingParseException` so the caller excludes the contract instead of costing it on
  data it does not understand. Unknown *issue codes* (which never affect costing) are dropped, not fatal.
- `Support/PhaseTimelineBuilder` — resolves phase boundaries to absolute dates and segments the
  12-month window into elemental slices, each governed by at most one known-pricing phase.
- `Support/MonthlyUsageProfileBuilder` — the usage distribution (night shares, winter ×1.156 /
  summer ×0.889 weighting, cooling Jun–Aug, per-month heating), **extracted from
  `ContractPriceCalculator`** so both calculators stay numerically identical for constant prices.
  `ContractPriceCalculator` still exposes `WINTER_PRICE_MONTHS` / `NIGHT_TIME_SHARES` as aliases.
- `CanonicalContractPriceCalculator` — costs the annual window and assigns a
  `ContractComparability` verdict. Its typed `calculatePeriod()` entry point costs an exact bill
  period with the same parser, phase timeline, inherited rates, packages, mechanism switches,
  reset fill policy, and fail-closed rules. It accepts realized hourly Spot facts instead of
  adding a second bill-specific canonical calculator.
- `DTO/CanonicalPeriodPricingRequest` / `CanonicalPeriodPricingOutcome` — keep exact-period totals,
  measured period savings, relevant rates/margins, comparability, assumptions, and typed
  unavailable reasons separate from the 12-month payload.
- `MarketReset/` — annualises monthly/quarterly/seasonal/other reset products with a shape-only
  forward-curve shift instead of holding one seasonal price flat. Cadence `other` uses the
  quarterly calendar and reference proxy. Own flag, own `AGENTS.md`.
- `ContractPricingIntegrityService` — the deterministic label state machine.
- `CanonicalContractPricingService` — batch orchestrator + feature-flag gate. `metricsForContracts()`
  returns array-only metrics for cache/listings; `evaluate()` returns typed `{outcome, integrity}`
  for single-contract callers. `outcomesForContractsAtConsumptions()` parses each contract once for
  forward statistics that need several reference consumptions without loading relational rows.

## Phase-timeline algorithm

1. Window `W = [S, S+12 months)`, `S` = signup/start date (default today, Europe/Helsinki).
2. Resolve boundaries: `contract_start`/`none`/`unknown` start → `S`; `date(d)` end is inclusive →
   exclusive `d+1`; `after_months(N)` → `S+N` (N=0 ≡ S, N=12 falls outside W); `period_boundary`
   uses `recurring_schedule.current_period_*`. A phase whose end is before `S` (expired promo) is
   dropped so a known later phase can take over. **An unknown start with a resolvable end is the
   already-running current price and covers from `S`** — do not treat it as unresolved.
3. Segment `W` at phase and calendar-month boundaries; latest-starting phase wins on overlap.
4. Cost known segments by applying the governing phase's rates to the day-fraction of that month's
   usage. Spot phases use `spot_margin` + rolling-365 day/night averages. Uncovered tails are filled
   by holding the current phase forward only for active recurring resets or Spot; otherwise the
   contract is excluded. A short fixed term is the explicit exception: cost all covered segments
   up to the real term end and annualize that complete term, without filling the unknown tail. For
   an **active recurring reset** the filled tail (and any tail a phase only claims with `ends: none`)
   is additionally repriced per calendar month by `MarketReset/`, when
   `RESET_FORWARD_SHIFT_ENABLED` is on.

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
| `comparable_estimate` | yes | `estimate_required` (Spot / recurring hold); total labelled "Arvio" |
| `term_price_only` | yes | fixed-term < 12 mo, unknown continuation; ranked by term price annualized |
| `base_only_hybrid` | yes | Hybrid (`unsupported`); base-only total + "Ei sisällä kulutusvaikutusta" |
| `excluded_unknown_future` | no | open-ended promo with an undisclosed later price; detail page only |
| `excluded_incomplete` | no | broken/ambiguous/unsupported structured pricing; detail page only |

Order of decision in the calculator: unsupported Hybrid (phase timeline when fully covered; held
current base only when not covered) → fixed-term-term-only (all term phases, then annualize) →
incomplete (with two costable exceptions below) → `detected` contracts must be fully covered by
disclosed phases or they are excluded, **unless they are an active recurring reset** → estimate-fill
for recurring/spot → `exact`/`estimate_required` map to the two comparable verdicts.

Domain rules layered on top (each with a regression test and a documented reason):
- **Recurring market products** (monthly/quarterly/seasonal/other reset) are never excluded for
  `detected` and get no deceptive label — they behave like Spot (current period known, future resets with the
  market; a small first-period intro is not deception). The uncovered tail holds the most recent
  disclosed (recurring) price via `lastCoveredPhaseIndex`, not the phase at signup.
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

Suppressed even when `detected`:
- an **active recurring reset** (legitimate market product; the "Arvio" marker communicates the estimate);
- a **listed** promo that does not **materially** understate the year — the gate requires impact ≥ 30 €
  AND structured ≤ 80 % of true. Tyyni Vakiohinta understates by 434 € (42 % of true) → labelled; a
  6-month fixed that continues at a similar spot price (~50 € / ~10 %) → not labelled. Excluded contracts
  (later price unknown) keep their detail-only notice regardless.

**All UI copy is generated from typed fields; the LLM `summary` string is never rendered.**

## VAT assumption (documented)

Amounts are used as-is. Structured API prices are VAT-inclusive consumer prices; description prices
share the contract's VAT basis (business contracts stay ex-VAT). A component type appearing with both
`included` and `excluded` VAT in one contract → parse exception → exclusion.

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
   developer's environment. Tests that exercise either flag opt in via `config()->set()`.

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

Cards no longer read these payloads directly. `../ContractCard/ContractCardPresenter` turns
`calculated_cost` / `pricing_integrity` / `comparability` into one view model that both card
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
mid-period fixed/Spot or margin switch. Ordinary fees use the existing days/30 convention. Package
fee and allowance are both prorated by calendar-month fraction, reset separately per month, and do
not create promo status. The period promotion flag is the measured normal-minus-actual period
saving. Canonical mode loads no relational components; feature-off keeps the old period calculator.

`ContractTypeComparison` also uses one request-memoized typed annual outcome per candidate and
consumption basis in canonical mode. Auto-selection, the monthly chart, current unit/package facts,
average-monthly and annual totals, comparability, winner, and savings all use that same outcome. The
chart renders canonical `monthlyCosts` directly. Excluded or incomplete selections have no series
and stop the comparison instead of becoming zero; canonical queries do not load components.
Feature-off keeps the legacy calculator and historical monthly Spot basis. This widget has no
prepared result cache, so its migration required no cache payload version.

Company offer sections and the SEO offer listing use `CanonicalOfferFacts` in canonical mode.
It accepts only a listed canonical calculated outcome with a positive measured benefit, no
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

**When you add or remove a field on `calculated_cost`, bump
`CalculatedCostPayloadSchema::VERSION` once.** List, company, ranking, and prepared-page cache keys
all include this shared dependency. Their service-specific outer payload versions remain separate;
bump an outer version only when that wrapper's own membership, fields, or structure changes. The
import-driven version and the pricing-mode marker do not move on a code-only deploy, so the shared
schema marker prevents cards and aggregates from reading an old calculated-cost shape. Company and
ranking keys also include `ContractListCacheService::getVersion()`, so each published interpretation
invalidates their data instead of leaving it stale for 48 hours or one hour.
`ContractPricingIntegrity` gained typed `promo_rate_cents` /
`normal_rate_cents` for the dated receipt rows; that was schema v2.

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
  period stays exact, and any coverage from a *dated* phase end also stays exact.
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

## Deferred / known limitations

- **Spot** contracts still use one flat rolling-365 average for all twelve months. The same per-month
  price vector should eventually replace it (level anchored on rolling-365, shape from the curve), but
  the gain is small: the measured profile cost ranges from −0.3 % to +8.2 % across 2022-2025, and it is
  exactly **zero** for the flat default usage profile, because `MonthlyUsageProfileBuilder` applies the
  winter weighting only when `metering === MeteringType::Season`.
