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

## Read first

- Root `../../../AGENTS.md`, `../../../../AGENTS.md`
- `../ContractInterpretation/AGENTS.md` (how the canonical JSON is produced/validated)
- `resources/contract-interpretation/schema-v3.json` (the exact JSON shape)

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
- `CanonicalContractPriceCalculator` — costs the window and assigns a `ContractComparability` verdict.
- `MarketReset/` — annualises monthly/quarterly/seasonal reset products with a shape-only
  forward-curve shift instead of holding one seasonal price flat. Own flag, own `AGENTS.md`.
- `ContractPricingIntegrityService` — the deterministic label state machine.
- `CanonicalContractPricingService` — batch orchestrator + feature-flag gate. `metricsForContracts()`
  returns array-only metrics for cache/listings; `evaluate()` returns typed `{outcome, integrity}`
  for single-contract callers (detail page, statistics, bill comparison).

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
   by holding the current phase forward only for active recurring resets, Spot, or fixed-term
   annualization — otherwise the contract is excluded. For an **active recurring reset** the filled
   tail (and any tail a phase only claims with `ends: none`) is additionally repriced per calendar
   month by `MarketReset/`, when `RESET_FORWARD_SHIFT_ENABLED` is on.

An **empty-components phase is UNKNOWN coverage, never €0.**

## Comparability policy (the ranking/label contract)

| Verdict | Listed? | Meaning |
|---|---|---|
| `comparable_exact` | yes | full window covered, `calculation.status = exact` |
| `comparable_estimate` | yes | `estimate_required` (Spot / recurring hold); total labelled "Arvio" |
| `term_price_only` | yes | fixed-term < 12 mo, unknown continuation; ranked by term price annualized |
| `base_only_hybrid` | yes | Hybrid (`unsupported`); base-only total + "Ei sisällä kulutusvaikutusta" |
| `excluded_unknown_future` | no | open-ended promo with an undisclosed later price; detail page only |
| `excluded_incomplete` | no | broken/ambiguous/unsupported structured pricing; detail page only |

Order of decision in the calculator: unsupported → fixed-term-term-only → incomplete (with two
costable exceptions below) → `detected` contracts must be fully covered by disclosed phases or they are
excluded, **unless they are an active recurring reset** → estimate-fill for recurring/spot →
`exact`/`estimate_required` map to the two comparable verdicts.

Domain rules layered on top (each with a regression test and a documented reason):
- **Recurring market products** (monthly/quarterly/seasonal reset) are never excluded for `detected`
  and get no deceptive label — they behave like Spot (current period known, future resets with the
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
  genuine all-in price (a market-price product at ~7 c/kWh, e.g. Cheap Markkinahintasähkö) and stays fixed
  energy — folding it would double-count the base. Bucket values are equal in practice so the `max` is
  exact; if they ever differ it is the conservative (higher) choice. This is a deterministic safety net that
  also protects rankings if a future interpretation regresses; the matching LLM-prompt fix (classify these
  as `spot_margin`) is a documented follow-up. Regression tests 21 (fold) and 22 (control) pin both sides.
- **Resolvable duplicate fee** (`isResolvableDuplicateFee`): a fully-covered contract whose only gap is
  two `monthly_fee` components lists, resolving to the higher fee. `resolvePhaseRates` takes `max` of
  duplicate monthly fees; `flat_fee` (eur_per_month) package charges add on top.
- **Component inheritance**: a promo phase that lists only the changed component (e.g. `monthly_fee = 0`
  for month 1) inherits the unchanged energy price from the base (fullest-priced) phase — it is not read
  as free energy. An explicit value (including 0) is an override and is not inherited.
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

   When verifying reset behaviour by hand, resolve the service through `app()`. A plain
   `new CanonicalContractPricingService()` gets no `MarketResetPriceEstimator` (the calculator's
   `$resetEstimator` defaults to null and the estimator is bound only in `AppServiceProvider`),
   so it shows hold-flat behaviour whatever the flag says.
2. `contracts:compare-canonical-pricing {--consumption=} {--start-date=} {--json=} {--resets} {--fail-on-parse-errors}`
   diffs legacy vs canonical totals across all active contracts and lists exclusions/labels. Run it on
   the synced local DB and on production (read-only) before flipping the flag. `--resets` switches to
   the hold-flat-vs-forward-shift review for market-reset lineages.
3. Cache keys carry a `c1`/`c0` canonical basis marker **and** an `r1`/`r0` reset-shift marker
   (`ContractListCacheService`, `ContractRankingService`, `ContractPageCacheVersion`) so toggling
   either flag busts stale caches immediately.

## Consumers migrated

Listings (`ContractsList`/`SeoContractsList`/`CheapestContracts`/`SahkosopimusIndex`),
`ContractListCacheService`, `ContractRankingService`, `ContractDetail` (hero/notice/meta/JSON-LD),
`CompanyDetail`, `LocalContractsService`, `ContractTypeComparison`, `BillComparisonService`
(annual cost + excluded-row skip), `WeeklyOffersVideoService` (skips detected/excluded),
`CalculateContractPercentiles` (listed, non-detected only), the API controllers, and
`ContractPriceStatisticsService` (forward daily only — **backfills always pass `useCanonical: false`**;
historical statistics must never be reinterpreted with today's canonical data).

Cards no longer read these payloads directly. `../ContractCard/ContractCardPresenter` turns
`calculated_cost` / `pricing_integrity` / `comparability` into one view model that both card
templates render: the price-increase warning and the term/hybrid caveats are coral footer pills,
the market-reset current-vs-estimated figures are two receipt rows, and the estimate marker is a
single "Arvio" popover in the card's type band. See `../ContractCard/AGENTS.md`.

**When you add or remove a field on these payloads, bump
`ContractListCacheService::PAYLOAD_SCHEMA_VERSION` and
`Caching\ContractPageCacheVersion::PAYLOAD_SCHEMA_VERSION`.** The import-driven version and the
`c`/`r` flag markers do not move on a code-only deploy, so cards would otherwise read a stale
cached shape for up to 48 hours. `ContractPricingIntegrity` gained typed `promo_rate_cents` /
`normal_rate_cents` for the dated receipt rows; that was schema v2.

The detail-page notices live
in `resources/views/livewire/contract-detail.blade.php` (after the hero): the neutral market-reset
notice first, then the amber integrity notice.

## Market-reset contracts: annualized with a shape-only forward-curve shift

Market-reset products (`recurring_schedule.present` with cadence `monthly` / `quarterly` /
`seasonal`) publish one price per period. Holding that seasonal price flat for twelve months was a
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

- Bill-comparison **period** cost still uses its own component-rate math for the historical billing
  period (as before); only the annualized estimate and the excluded-row skip go through canonical.
  Phase-resolved period rates are a possible future refinement.
- `ContractPriceStatisticsService` canonical mode changes only the `annual_cost` fields; per-component
  c/kWh chart fields stay relational for continuity.
- **Spot** contracts still use one flat rolling-365 average for all twelve months. The same per-month
  price vector should eventually replace it (level anchored on rolling-365, shape from the curve), but
  the gain is small: the measured profile cost ranges from −0.3 % to +8.2 % across 2022-2025, and it is
  exactly **zero** for the flat default usage profile, because `MonthlyUsageProfileBuilder` applies the
  winter weighting only when `metering === MeteringType::Season`.
