# Decisions

## 2026-07-25 — Why this is a separate task

The premium calculation is a by-product of `../market-reset-annualised-pricing/`, where it exists
only to identify the pass-through parameter `beta`. It is split out because it has its own schema,
its own schedule, its own quality rules, and an eventual public surface, while the market-reset task
is only about correcting annualised cost estimates.

The two share two components. The later build-order decision supersedes this paragraph: build them
in this retail-premium task and consume them from the market-reset task:

- the vintage-aware reference-price service (futures curve lookup with month → quarter → year
  fallback);
- the lineage price-history helper (replacement-chain ancestors ordered by `price_date`).

## 2026-07-25 — Call it retail premium, never margin or profit

The quantity is the spread between the retail price and the wholesale price the seller could have
hedged at. It contains hedging cost, the profile/shape cost of that seller's customer mix, imbalance
cost, credit risk, acquisition cost, billing and support cost, and only then profit.

Publishing it as a "margin" or "profit" for a named company would be factually wrong and would
invite justified complaints. A higher premium also does not prove worse value: serving
electric-heated houses genuinely costs more in shape cost, and green sourcing is a real cost.

## 2026-07-25 — One row per price period, not one row per day

The premium only moves when the price or the curve moves, and for analysis the meaningful unit is one
observation per price period with the vintage that applied when the price was set. Daily rows would
produce hundreds of thousands of rows per year and add no analytical value. Period rows keep the
table in the thousands per year.

## 2026-07-25 — Include the monthly fee in anything comparative

A c/kWh premium alone is misleading, because a low energy price with a high monthly fee is a common
structure in this market. Any ranking must use total annual cost at a stated reference consumption,
or a premium with the fee amortised at that reference consumption.

## 2026-07-25 — Spot can start immediately, the rest cannot

The Spot premium is the **disclosed** `spot_margin` component in the canonical pricing JSON
(`ComponentType::SpotMargin`). It is exact and needs no futures curve, so per-contract collection can
start at once. The market-reset and fixed-term cases need the vintage-aware reference service first.

## 2026-07-25 — The dataset can only be built forward, so delay is permanent loss

- Contract price history: from **2026-01-21**.
- FI futures history: from **2026-04-08** only, and the public EEX chart window is about 45 days, so
  earlier vintages cannot be recovered.

Premiums for January to March 2026 are therefore permanently unrecoverable, and every additional day
without collection loses another day. This is the main argument for starting now rather than after
the market-reset work finishes.

## 2026-07-25 — Keep numerically consistent with the existing forecast model

`FixedTermPriceForecastService` already estimates a market-level normal premium with EWMA over
`contract_price_daily_statistics`. This work is the per-company version of the same quantity. The two
must agree in aggregate; a disagreement is a bug in one of them, so add an explicit cross-check.

## 2026-07-25 — This task runs BEFORE the market-reset task

The build order in the first version of this spec was wrong. It said this task depends on the
reference-price service from `../market-reset-annualised-pricing/`. The dependency actually runs the
other way:

- This dataset **produces** the per-company reference and `beta` observations that the market-reset
  estimator is blocked on (only 21 month-reference observations exist today, across 7 companies,
  several with n=1).
- This work is additive — new table, new service, new command — and touches no ranking, listing,
  caching, or public page. The market-reset task edits `CanonicalContractPriceCalculator` and moves
  live rankings.
- Two of the four premium cases need no new curve code at all. Spot uses the disclosed `spot_margin`
  component; fixed-term uses `FixedTermHedgeCostService::calculate($asOf, $duration)`, which is
  already vintage-aware with the month → quarter → year ladder.

So this task owns the two shared components (the vintage-aware single-period reference lookup and the
lineage price-history helper), and the market-reset task consumes them.

## 2026-07-25 — Do not refactor FixedTermHedgeCostService

It is on the production 07:30 schedule and feeds immutable stored forecast rows. The new
single-delivery-period lookup goes alongside it, not inside it. Extracting a shared futures-curve
repository is a later change with its own tests. `contract_price_daily_statistics` is read-only for
this task, because the EWMA forecast depends on its current segment keys.

## 2026-07-25 — Store every candidate reference per observation

Which wholesale reference a seller prices from is unresolved and differs by company:
Pohjois-Karjalan tracks the front month (implied margin sd 0.15), Paneliankosken tracks the quarter
(sd 0.22 against 2.32 for the month). Storing the premium against month, quarter, year, and term
strip for every observation is what lets this dataset settle the question later. Picking one
reference now would bake in an assumption and destroy the evidence.

## 2026-07-25 — Boundaries for a parallel agent

- Stay out of `app/Services/CanonicalPricing/` — that is the market-reset task's area.
- Do not change ranking, listing, or caching code.
- The Vaasan Sähkö Vaikuttaja 0.00 c/kWh price will appear as an extreme premium outlier. Record it
  and flag it; do not fix it here. It is being handled in a separate session.
- Shared files that could conflict if both tasks run at the same time: `routes/console.php` (schedule
  entries), `config/`, root `AGENTS.md`, and `app/Services/AGENTS.md`.

## 2026-07-25 — Spot-first implementation slice

The first implementation adds `retail_premium_observations`, the `retail-premiums:collect` command,
and `RetailPremiumObservationService`. It collects active published Spot contracts at 07:15
Europe/Helsinki and stores one `spot_disclosed` row for each canonical pricing phase that has a
numeric `spot_margin`.

Rows use `retail-premium-v1`, a 5000 kWh reference consumption, the component VAT basis, and an
exact quality label. The fee-inclusive value amortises the canonical monthly fee over 5000 kWh. A
missing fee stays null and gets a quality flag; zero and extreme premiums are stored and flagged.
No futures curve is used for Spot.

The immutable identity is `(observation_key, reference_kind, method_version)`. A normalized
`price_signature` excludes evidence text, so a source-only interpretation update can reuse the open
semantic price period. A changed price creates a new period. Existing rows are skipped unless
`--overwrite` is given.

The lineage helper now lives on `ElectricityContract`. A recursive CTE with set-producing `UNION`
returns the current contract plus the complete predecessor DAG without a depth limit. A second query
loads raw `PriceComponent` models ordered by `price_date` and deterministic tie-break fields.

The `lineage_key` hashes the oldest roots in the current replacement DAG. The row also stores the
active lineage tip and all known member/root IDs in source metadata. This keeps normal linear
replacement rotations stable while retaining enough provenance to reconcile a later repaired or
converged graph.

## 2026-07-25 — Inferred references and cross-check implementation

`VintageAwareReferencePriceService` was added beside the forecast services. It reuses the public
`latestTradeDateBefore()` and `maturityForMonth()` methods but does not modify or generalize
`FixedTermHedgeCostService`.

For one reset delivery month it returns every available month, quarter, and year instrument at the
latest trade date strictly before the source snapshot's first-observed date. This uses the observed
reset date instead of trusting a declared calendar boundary. For a fixed term, it builds separate
pure-month, pure-quarter, and pure-year strips when that tenor covers every delivery month. It also
calls `FixedTermHedgeCostService::calculate()` unchanged to store the existing month → quarter → year
fallback strip as `term_strip`.

Raw EEX values stay traceable in reference metadata. Each candidate stores both VAT-included and
VAT-excluded normalized values. The observation selects one only when the canonical retail component
has a matching explicit VAT basis; `unknown` and mixed VAT produce a null premium and a quality flag.
Business status alone never decides VAT.

Market-reset rows are inferred against month, quarter, and year candidates. Fixed-term rows are
inferred against all available pure-tenor candidates plus `term_strip`. Each energy component gets
its own rows; multi-rate tariffs are flagged because the component premiums are not a consumption-
weighted aggregate. Hybrid base prices are retained as `not_comparable` with no inferred premium.
A missing curve creates a `curve_unavailable` evidence row instead of silently dropping the retail
price. Zero retail prices, including the known Vaasan outlier, are retained and flagged.

`retail-premiums:cross-check` is a read-only diagnostic. For included-VAT, single-rate fixed-term
`term_strip` rows, it reports the dataset median and company medians beside the stored market-level
median forecast's current and normal EWMA retail premiums. This gives an explicit consistency check
without changing `contract_price_daily_statistics` or the forecast model.
