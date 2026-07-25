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

## 2026-07-25 — Deployment verified; history needs an explicit backfill

State after deploy (checked read-only on production):

- `retail_premium_observations` exists (migration ran).
- **0 rows.** `routes/console.php` runs `retail-premiums:collect` at 07:15 Europe/Helsinki, so the
  first collection is the next morning.

### Why the forward-only collector will not unblock the market-reset task soon

`CollectRetailPremiumObservations` selects `ElectricityContract::query()->active()`, and
`RetailPremiumObservationService::observationContext()` builds from
`publishedInterpretation.sourceSnapshot`, using `snapshot->first_observed_at` as the curve vintage.

So each run captures **one observation per current price period per active contract**. Past price
periods are never visited. A monthly-reset lineage produces roughly one new observation per month, so
the per-company reference/`beta` question stays underpowered for months.

### Correction: inactive ancestors do not have interpretations or snapshots

A read-only production check disproved the first backfill assumption. Kokkolan Tyyni has 13 lineage
contracts and Helen Markkinahintasähkö has 7, but each lineage has only **one** contract with a source
snapshot and published interpretation: the active tip, first observed on 2026-07-23. The lineages do
have 184 and 185 distinct relational `price_components.price_date` days from 2026-01-21.

Therefore, an `--include-inactive` loop over `buildObservations()` cannot recover history. The safe
source is the daily relational component history. The active tip can supply only a calibrated
semantic/VAT template. Historical numeric values must always come from `price_components`, and the
historical row must not claim that its inactive carrier had an interpretation.

### Hard limits on what a backfill can recover

- **Inferred premiums (market-reset, fixed-term) need the curve.** FI futures history starts
  **2026-04-08**, and the public EEX window is about 45 days, so periods with a vintage before that
  date are permanently unrecoverable. Expect roughly 21 month-reference and 9 quarter-reference
  reset observations from 2026-04-08 to 2026-07-24, plus fixed-term rows.
- **Spot premiums need no curve.** They are the disclosed `spot_margin`, so Spot history is
  recoverable back to the earliest published interpretation, potentially to 2026-01-21. This is the
  larger and cheaper win.

### Implemented backfill design

- `retail-premiums:collect --include-inactive` keeps `--contract` scoped to active lineage tips and
  reconstructs compatible ancestor history from relational components. It does not call current
  source-snapshot observation construction for inactive contracts.
- Raw roles are calibrated against the active tip's latest relational rows plus canonical structured
  evidence. Description-only values are never invented. VAT is propagated only through that
  calibrated role and is explicitly flagged as historical template provenance.
- Same-signature rows on consecutive dates compress into one period, including safe contract-ID
  rotation. A missing day splits periods. Same-date lineage conflicts are skipped and never averaged.
- Historical rows use `retail-premium-history-v1`, keep interpretation/snapshot columns null, and put
  template IDs and all carrier/component provenance in `source_metadata`.
- The current open period is excluded by default at the day before the template snapshot began.
  `--include-open` is explicit. Historical reconstruction never calls
  `reuseOpenPricePeriodIdentity()`.
- Unresolved structured discounts stay as evidence but make the affected premium or fee-inclusive
  value null.
- Rows whose vintage predates 2026-04-08 are written as flagged `curve_unavailable` evidence rows,
  not skipped. `VintageAwareReferencePriceService::priceForVatBasis()` was also corrected so a null
  reference stays null instead of casting to 0.0.

## Production backfill outcome (2026-07-25)

- Deployed commit `729d552` as Railway deployment `0e1fe4f7-c346-42ec-8f54-0c4f6d801752` in the production `voltikka` service.
- Dry runs were completed before each write for Spot, reset, fixed-term, and Hybrid groups over `2026-01-21` through `2026-07-24`.
- Production now has 1,388 `retail-premium-history-v1` rows and 521 current `retail-premium-v1` rows. The complete range is `2026-01-21` through `2026-07-25`.
- There are 384 rows with a calculated retail premium and 340 with a fee-inclusive retail premium. Futures vintages cover `2026-04-08` through `2026-07-24`; 454 rows preserve earlier or missing-curve evidence as `curve_unavailable`.
- Historical rows cover 934 carrier contract IDs across 155 calibrated replacement lineages. Conservative rejection and flags remain visible. Unknown VAT, unresolved discounts, ambiguous source days, overlap conflicts, and unsupported durations were not corrected.
- The Spot historical command was run again without `--overwrite`; it saved 0 and skipped all 93 existing identities. This verified production idempotency.
- Vaasan Sähkö `0.0000` energy values are stored unchanged and carry `zero_or_negative_retail_energy_price`; they are not converted into a retail premium.
- The post-write cross-check found current comparable medians for 6, 12, and 24 months. The 12-month dataset median was `+2.0427 c/kWh` versus market EWMA `+1.7949 c/kWh`; the 24-month median was `+2.3006 c/kWh` versus `+2.1120 c/kWh`.

## 2026-07-25 — Backfill landed; three data issues block the reset analysis

The backfill is mechanically sound (1,909 rows, 2026-01-21..2026-07-25, idempotent on re-run). But the
**market-reset population is not yet analysable**. Of 143 reset rows, only **9** are usable for
identifying the reference, and they reduce to **one** lineage with more than one period.

### Issue 1 — duplicate price periods where the price did not change

Across the whole dataset, 259 of 1,209 consecutive same-series pairs have an **unchanged** retail
price, and **101 of those carry the same `price_signature`**. Examples:

| Company | Periods | Price | Signature |
|---|---|---|---|
| Vattenfall | 2026-02-04 → 2026-02-13 | 8.62 both | **SAME** |
| Nordic Green Energy | 2026-02-03 → 2026-02-13 | 7.70 both | **SAME** |
| Äänekosken Energia | 2026-02-03 → 2026-02-13 | 8.60 both | **SAME** |
| Nordic Green Energy | 2026-07-01 → 2026-07-23 | 7.99 both | differs |

The `price_signature` dedupe exists but is not collapsing these, so one price period is stored as
two. This matters directly: an unchanged price against a moved reference reads as **zero
pass-through** and biases `beta` down. In the single usable reset series, dropping the spurious step
moves `beta` from **0.61 to about 0.95** — the conclusion flips on this bug alone.

The "signature differs, price identical" cases are a second, separate question: something in the
signature changes while the price does not.

### Issue 2 — `vat_basis = unknown` on 90 % of reset rows

128 of 143 reset rows are `unknown`; 1,465 of 1,909 rows overall. VAT resolution clearly works
sometimes — `cadence = none` has 320 `included` rows — but fails for almost the whole reset
population. Monthly Household splits 14 unknown / 14 included, so resolution depends on something at
component or phase level.

Keeping the "do not infer VAT only from `target_group`" rule is right. But leaving 90 % of the target
population `unknown` makes the dataset unusable for the question it was built to answer, because
included-VAT and excluded-VAT rows must not be mixed. Needs a real resolution path from the canonical
component or source payload.

### Issue 3 — no `quarter` reference is ever stored for quarterly resets

Quarterly resets have 34 `month` rows and 57 `curve_unavailable` rows and **zero `quarter` rows**,
across 24 lineages. Quarterly products are the majority of the reset population (22 of 32 lineages),
so **the central month-versus-quarter question cannot be tested at all** right now.

**This also corrects an assumption from the market-reset task.** I had reasoned that a quarter
contract would not exist for a quarter already in delivery, so the quarter reference would have to be
derived by averaging the three month contracts. The data disproves that: on 2026-04-08 the FI quarter
maturities included `202607` (Q3), and `202607` only disappears from 2026-07-23 when Q3 entered
delivery. At the pricing vintage — before the period starts — the quarter contract **is** published.

So `VintageAwareReferencePriceService` needs a plain quarter lookup for the quarter containing the
reset period. The month-average fallback is only needed for a mid-period re-anchoring vintage, which
is the less important case.

### Current best estimate of beta, for the record

One usable series: Pohjois-Karjalan Sähkö, monthly, month reference, 4 stored periods of which one is
spurious. The two clean steps imply pass-through **1.08 and 0.85**, so `beta` is near **1.0** against
a month reference. Premium sd 0.70 c/kWh.

That is one company and two observations. It is not shippable, and the quarterly majority is
completely untested. Fix the three issues above, then re-run the identification.

## 2026-07-25 — The three data issues, diagnosed and fixed

All three were reproduced from evidence before any code changed. One of the three was not the bug it
looked like, and one cannot be fixed inside this service at all. Method versions are bumped to
`retail-premium-v2` / `retail-premium-history-v2`, so a re-collection **inserts** new rows beside the
existing 1,909 and the old rows can be diffed against the new ones. Nothing is overwritten and
`--overwrite` is not needed.

### Fix 1 — duplicate periods: the cause was one missing import day, not a broken signature

The dedupe mechanism was working. `price_signature` matched on both sides of every reported pair,
exactly as designed. The split came from the *other* condition in `compressPeriods()`, which required
strictly consecutive `price_date` days.

Local `price_components` has 184 distinct days from 2026-01-21 to 2026-07-24 and exactly **one** gap:
**2026-02-12 is missing entirely**. Every confirmed example pair starts its second period on
2026-02-13. One missed import day split roughly one price period per series, which matches the
reported 101 same-signature pairs.

So the old rule "a missing day splits periods" was the defect. New rule, and the reason it is right:

- A day the whole import missed (no `price_components` row for **any** contract) is no evidence of a
  price change. An unchanged signature continues across it.
- A day where the lineage had rows that could not be read (ambiguous, incomplete, or conflicting
  carriers) is also no evidence of a price change. It bridges too, with its own flag.
- A day where the import **did** run and the lineage was simply absent is genuine: the product left
  the market. That still ends the period, and the next period is flagged
  `period_follows_lineage_absence`.

This needs no arbitrary maximum gap length, because the discriminator is evidence rather than
duration. Bridged and absent dates are stored in `source_metadata` so nothing is hidden.

Local before/after on the same database, all groups, 2026-01-21..2026-07-24:
`periods_reconstructed` **1,117 → 986**, with `gap_bridged_periods = 131` and
`lineage_absence_boundaries = 15`. So 131 spurious duplicate periods collapsed and 15 genuine
off-sale boundaries were kept.

### Fix 3 — the quarter lookup already worked; the delivery period was the problem

`maturityForMonth($month, 'quarter')` and the plain quarter lookup were both correct. Proven locally:
`forDeliveryMonth('2026-05-20', '2026-07-01')` returns `month,quarter`.

The production symptom has a different cause. `buildMarketResetObservations()` anchors the delivery
period on the period's own first observed date, and quarter futures exist only for quarters that have
**not** entered delivery. So:

- a period starting on a quarter boundary (2026-07-01, vintage 2026-06-30) does get a `quarter` row;
- a period starting mid-quarter gets none, because that quarter is already in delivery.

Mid-quarter starts dominate the production reset population, for two reasons: the Fix 1 gap-splits
manufactured them, and several providers genuinely reset off calendar boundaries (Paneliankosken
2026-04-16 / 05-21 / 07-21, Kokkolan 2026-05-05 / 06-04). Add to that the forward collector, which
anchors on the snapshot's first-observed date (2026-07-23 for most tips).

Fix: `forResetPeriod()` keeps the plain quarter lookup as the primary path and adds
`quarter_month_average` as a **separate** candidate — the day-weighted average of the three month
contracts of that quarter, available even mid-delivery. It is deliberately not merged into `quarter`,
because the month-versus-quarter question needs the directly observed quarter settlement to stay
clean. Rows also record `vintage_inside_delivery_period`.

Local run: **57 `quarter_month_average` rows where v1 had none**, 31 of them on quarterly cadences.

Note for anyone re-checking locally: the local futures snapshot ends 2026-05-22, so every local
vintage is early and the direct quarter contract is nearly always available. That is why the local
v1 run shows 342 quarter rows and cannot reproduce the production zero. The production zero is a
vintage-versus-delivery timing artifact, not a lookup bug.

### Fix 2 — partly fixable here; the rest is an upstream interpretation gap

Three real defects were found and fixed:

1. **The premium is an energy-price spread, so it only needs the energy component's VAT basis.**
   Both builders were combining the energy component and the monthly fee into one basis, so an energy
   price with a disclosed basis plus a fee with an unknown basis collapsed to `mixed` and threw away a
   usable premium. `vat_basis` is now energy-only, the fee keeps `fee_vat_basis`, and the
   fee-inclusive value requires the two to agree.
2. **The wholesale reference was being discarded whenever VAT was unknown.** `reference_price_cents_per_kwh`
   was null, so the row carried no curve evidence at all. The settlement price and both VAT variants are
   now always stored. That is what actually unblocks the reset analysis: `beta` is measured from
   *differences*, which need only a consistent scale, so an unknown-VAT row is still usable for
   pass-through while correctly staying out of premium-level aggregates. Local: **861 unknown-VAT rows
   now carry recoverable reference evidence, against 0 before.**
3. **Within-contract propagation.** If a component says `unknown` and the same contract discloses
   exactly one explicit basis elsewhere, that basis fills the gap, recorded as
   `vat_basis_source = contract_propagated`. This never touches `target_group` and never crosses
   contracts.

What cannot be fixed here, with counts. Of 431 interpreted contracts locally:

| Contract VAT evidence | Count |
|---|---|
| every component explicit | 122 |
| **no component explicit anywhere** | **308** |
| partly explicit, so propagation applies | 1 (covering 1 component) |
| explicit but self-contradicting | 0 |

So propagation is provably near-worthless: it fires on 1 contract. The reason 70 % of components say
`unknown` is upstream and structural:

- the Azure source payload has **no VAT field at all** — `Details.Pricing.PriceComponents[].OriginalPayment`
  carries only `Price` and `PaymentUnit`;
- `vat_status` is the only VAT field anywhere in `schema-v3.json`, and
  `resources/contract-interpretation/system-prompt-v17.md` never mentions VAT or "alv", so the model
  correctly answers `unknown`.

The observed explicit labels do follow the feed convention (Household 261 included against 4
excluded; Company 74 excluded against 4 included), but resolving VAT from that convention is exactly
the `target_group` inference the rules forbid, and the ~4 % counter-examples are why. Those rows stay
`unknown`. The real fix is a `ContractInterpretation` prompt change plus reinterpretation, which
republishes canonical pricing for every contract and therefore belongs in its own task.

### Secondary case — "price identical, signature differs" is the method-version seam

Not a changing field. Every reported example pairs a date at the end of reconstructed history with
**2026-07-23**, the day the forward collector's snapshot begins. The two method families define
`price_signature` differently on purpose (the history hash is period-signature plus role plus method;
the forward hash includes the phase boundaries), and AGENTS.md deliberately forbids them from merging.

Left unmerged, as intended, but the seam is now machine-detectable: the forward row gets
`continues_prior_history_period` and `source_metadata.continued_history_observation_key` when a
history period ends the previous day at the same price and fee. A pass-through analysis must drop
that step.

Also fixed: `RetailPremiumCrossCheckService` did not filter `method_version` and would have mixed v1
and v2 rows after the bump. It now compares the current pair only.

### Deployment consequences

- **A production migration is required**: `2026_07_25_000002_add_vat_and_reference_evidence_to_retail_premium_observations`.
  It is additive and nullable only, so existing v1 rows stay valid and simply keep nulls in the new
  columns.
- **No `--overwrite` re-collection.** `method_version` is part of the unique identity, so re-running
  collection after the deploy inserts v2 rows beside the v1 rows. The 1,909-row historical record stays
  intact and both versions can be diffed on the same price periods.
- Any analysis must filter to the current method-version pair. This is now written into
  `laravel/app/Services/RetailPremium/AGENTS.md`, because the v1 rows keep the duplicate-period and
  unresolved-VAT defects forever.

### Verification

- Full suite before: **1,160 passed, 3,541 assertions**. After: **1,173 passed, 3,617 assertions**.
  No failures either side.
- 13 new tests pin each fix: the direct quarter lookup at a pre-period vintage, the derived quarter
  inside delivery and its absence when a month is missing, an import-outage day producing one period,
  a changed price across the same outage producing two, a lineage absent from a running import
  splitting, an unreadable conflicting day bridging, VAT propagation in both builders, reference
  evidence surviving unknown VAT, a fee on another basis nulling the fee-inclusive value, and the
  history/forward seam flag.
- One existing test encoded the old behaviour and was rewritten rather than weakened:
  `test_missing_day_splits_the_same_price_into_two_periods` asserted
  `['2026-01-01', '2026-01-03']` for one unchanged price with no import at all on 2026-01-02. It is
  replaced by `test_import_outage_day_keeps_one_period_for_an_unchanged_price` plus three tests that
  keep the split for the cases where a split is still correct.
- What local data cannot verify: local futures stop at 2026-05-22 and only a subset of contracts are
  interpreted, so no local run reproduces the production vintage timing, the production
  quarter-row zero, or the production VAT counts. The local before/after numbers above are
  structural evidence on the same database, not a production prediction.

## 2026-07-25 — Earlier futures vintages cannot be recovered from the public sources

Tested two candidate sources for pre-2026-04-08 FI forward curve data.

### 1. The Azure `SpotFutures` field — dead end

The Azure contract payload carries `Details.SpotFutures`, preserved in our snapshots since January and
deliberately excluded from the interpretation fingerprint. It is **a single scalar price, not a term
structure** (`FetchContracts::processSpotFutures()` reads one number), so it cannot price a specific
delivery quarter. The stored `spot_futures` table has one `date` and one `price` column, and the value
is identical (5.602 c/kWh) on 2026-01-21, 01-22, 01-25 and 2026-04-21 — a stale, constant field.
Unusable for this purpose.

### 2. The EEX public chart endpoint — the 45-day window is server-side and anchored to today

The `history_window_days = 45` config is not our own conservatism. Tested with the cap lifted
(`--start-date=2026-01-01 --history-window-days=400 --dry-run`):

| Maturity | Trading ended | Prices returned |
|---|---|---|
| FI quarter `202607` (Q3 2026) | 2026-06-30 | **13** |
| FI quarter `202604` (Q2 2026) | 2026-03-31 | **0** |

Q3 returns exactly the overlap between its trading life and the ~45-day window measured back from
today; Q2 returns nothing at all. So the window is enforced by the API relative to *now*, and
`--start-date` cannot reach behind it. **Any maturity that stopped trading more than about 45 days ago
is permanently gone from this endpoint.**

Consequence: the Q2 2026 reference (vintage late March) is unrecoverable, so the Q2 → Q3 quarterly step
stays half-blind and the first usable quarterly step remains **1 October 2026**.

### Paid route, if waiting is not acceptable

EEX sells historical market data as a separate product, and Nordic-focused vendors (Montel, Volue,
LSEG, ICIS) carry the same series. A one-off purchase of FI Base month and quarter settlements for
January-April 2026 would do two things at once: unlock the quarterly month-versus-quarter question
about two months early, and roughly double the monthly evidence, because monthly lineages have price
history back to 2026-01-21 and would gain about three more usable steps each. Cost and licence terms
are unknown and this is a commercial decision, not a technical one.

### No further loss is occurring

The daily collector fetches the whole published curve and upserts, and it is healthy (verified
2026-07-25). Everything from 2026-04-08 onward is retained permanently. The gap is historical only and
will not grow.
