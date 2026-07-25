# Decisions

## 2026-07-25 — Choice of estimator

Considered four estimators for the annualised cost of a market-reset contract.

1. **Hold the current period price flat** (current behaviour). Rejected. It has a large seasonal
   bias: about −151 € per year at 5000 kWh in July, and the same error with the opposite sign in
   winter.
2. **Shape-only forward-curve shift**: `P_m = P_current + beta * (F_m - F_current)`. **Chosen.**
   It uses only differences in the futures curve, so it imports the seasonal shape and not the
   price level. A uniform curve error cancels out. The estimate stays anchored on the provider's
   own real disclosed price, which also keeps it comparable with Spot contracts that are ranked on
   observed rolling-365 spot.
3. **De-seasonalise against realized spot**. Rejected. A reset price is a *forward* price for the
   coming period. Kokkolan Tyyni's July price (4.98 c/kWh incl. VAT) matches the July future
   (4.51) plus about 0.5 c/kWh of margin, and not realized July spot (rolling-30 average 2.58).
   Using realized spot as the period reference overstates the implied margin by about 2 c/kWh.
4. **Anchor to the fixed-term retail market** (median 10.48 c/kWh on 2026-07-24). Rejected. A
   fixed price includes a hedging premium that a market-reset customer does not pay, so this
   anchor over-prices these products by design.

**Superseded on 2026-07-25:** an earlier version of this entry said the contract's own price history
was unusable because the product spans many contract ids. That was wrong. The
`replaced_by_contract_id` chain recovers the full lineage (184-185 priced days for most reset
products), and that recovered history is what identified the reference and `beta`. See the
replacement-chain audit entry below. The history is not the source of the *forward* estimate, but it
is the source of the *parameters*.

## 2026-07-25 — Keep the current period exact

Only the held-forward tail is replaced. The current period price is contractual for that period,
so it is not an estimate. The phase timeline already covers the current period from a disclosed
phase.

## 2026-07-25 — No deceptive-pricing label

Market-reset products stay exempt from the integrity label. The existing suppression rule for
active recurring resets is correct: the price change is the published mechanism of the product and
not hidden promotional text. The "Arvio" marker carries the uncertainty instead.

## 2026-07-25 — beta stays at 1.0 until measured

Full pass-through of the forward shape is the correct prior for a provider that hedges one month
or one quarter ahead. Some providers smooth their resets, which would mean `beta < 1`. Do not
tune `beta` from a guess. Measure it from observed resets first.

## 2026-07-25 — Futures collection verified

- A bounded live dry run works: `php artisan futures:fetch-eex --area=FI --tenor=month
  --maturity=202608 --maturity=202609 --dry-run` returned 32 daily settlement points per
  instrument. The EEX public endpoint and the collector are both healthy.
- Production is current. Latest `trade_date` in `electricity_futures_eod_prices` is 2026-07-24,
  with 100 FI rows in the last 7 days and 20623 rows in total.
- The **local** development snapshot of that table is stale (no `trade_date` after 2026-05-22).
  Refresh it with `futures:backfill-eex` before you do local work on this feature. The public EEX
  history window is about 45 days.

## 2026-07-25 — Use the latest settlement, not a long trailing average

Measured on 55 production trade dates (2026-05-11 .. 2026-07-24), FI area, 12-month window
2026-07 .. 2027-06:

- Per-maturity daily move: mean absolute **0.112 c/kWh**, maximum 0.93 c/kWh.
- Day-over-day change in the estimator output is typically ±0.1 c/kWh, which is about ±5 €/yr at
  5000 kWh.

Daily noise is therefore too small to justify a long trailing average. A long average would also
cost more than it saves, because the curve **trends**: the 12-month strip rose from 7.52 to
8.67 c/kWh over these 10 weeks. A trailing 30-day average lags by about half its window, which at
that trend rate is roughly 0.3 c/kWh of lag bias — larger than the noise it removes.

Decision: use the **latest available settlement** with `trade_date < today` (the same
no-same-day-leakage rule as `FixedTermHedgeCostService`). Optionally apply a short 3-5 day median
purely as an outlier guard against a bad print. Do not use a long trailing average.

## 2026-07-25 — Anchor to the quarter strip, not the front month (RISK)

The reference period matters far more than the averaging window. Over the same 55 trade dates:

| Reference | mean shift | sd | range |
|---|---|---|---|
| July 2026 month future | +3.40 c/kWh | 0.88 | +2.49 .. +6.22 |
| Q3/2026 month strip | +1.30 c/kWh | 0.32 | +0.83 .. +2.47 |

The front-month reference is not stationary. It drifted from +2.63 to +6.22 c/kWh as July
approached, because the front month collapsed (4.89 → 2.45 c/kWh) while the strip rose. A monthly
reset contract is priced from the front month exactly when that decoupling is largest, so the
front-month anchor is both three times noisier and structurally biased.

Evidence that full front-month pass-through over-corrects: with the July month reference,
Kokkolan Tyyni's annual equivalent becomes 11.14 c/kWh and Helen Markkinahintasähkö 13.75 c/kWh,
against a fully-fixed 12-month market median of 10.48 c/kWh. A market-tracking product should
normally sit *below* the fixed market, because the customer carries the risk that a fixed price
hedges.

Decision: use the **quarter strip that contains the current reset period** as the reference, for
monthly and quarterly cadences alike, and hold that reference for the whole current period. Treat
this as a judgement to validate, not a certainty. It is the main reason `beta` must be measured
against observed resets before the flag is turned on.

## 2026-07-25 — Apply the same machinery to Spot, but expect a small gain

Spot contracts currently use one flat rolling-365 spot average for all twelve months. The same
per-month price vector should replace it, so Spot, market-reset, and fixed contracts share one
mechanism. Anchor the **level** on rolling-365 spot and take only the **shape** from the curve:

```
P_m = S_rolling365 + beta * (F_m - F_strip12m)
```

Two honest limits on the size of the gain:

1. With the default usage profile the correction is exactly **zero**. `MonthlyUsageProfileBuilder`
   applies the winter consumption weighting only when `metering === MeteringType::Season`. For
   `General` and `Time` metering with `basicLiving` only, every month gets 1/12 of the annual kWh,
   and `sum(usage_m * (F_m - F_strip))` is zero for a flat profile. The correction bites only for a
   winter-weighted profile.
2. Measured against realized FI spot for 2025-07-24 .. 2026-07-24, the consumption weighting moves
   the effective spot price from 7.127 to 7.358 c/kWh — a **profile cost of +0.231 c/kWh, +3.2 %,
   about +12 €/yr** at 5000 kWh. Last winter was mild (March 3.49 c/kWh, December 4.56 c/kWh), so
   this is a low estimate, but the order of magnitude is tens of euros and not hundreds.

So the Spot change is correct and worth doing, but it is 10-25 times smaller than the market-reset
error. Sequence it after the reset fix.

**Open decision, deliberately not bundled:** whether Spot should keep rolling-365 as its level
anchor or move to the forward strip. On 2026-07-24 the forward strip was 8.67 c/kWh against a
rolling-365 average of about 7.13 c/kWh, so moving the level would raise every Spot estimate by
roughly 20 % (about +75 €/yr at 5000 kWh) and would change the site's headline spot claims. Keep
rolling-365 for now, and decide the level question separately from the shape question.

**Prerequisite for any real Spot gain:** the flat monthly usage profile for `General` and `Time`
metering. Making it winter-weighted for all metering types changes the ranking of *every*
contract, not only Spot, so it needs its own task, its own before/after diff, and its own review.

## 2026-07-25 — Futures beat realized spot history as the shape source

Question raised: is a seasonal shape from realized spot history better than one from the futures
curve? Measured on local FI hourly spot, 2022-2025 (four complete years, 27049 hours).

**Realized monthly index (month average / that year's annual average):**

| Month | 2022 | 2023 | 2024 | 2025 | mean | sd | spread |
|---|---|---|---|---|---|---|---|
| Jan | 0.57 | 1.50 | 2.72 | 1.30 | 1.52 | 0.77 | 2.16 |
| Feb | 0.77 | 2.22 | 0.03 | 1.17 | 1.05 | 0.79 | 2.20 |
| Oct | 1.34 | 0.06 | 1.65 | 1.21 | 1.07 | 0.60 | 1.59 |
| Dec | 2.03 | 2.07 | 0.17 | 0.89 | 1.29 | 0.80 | 1.90 |

Mean sd of the annual monthly index across all twelve months is **0.42**. The dispersion is worst
exactly in the winter months that drive the correction. February 2024 came in at index 0.03 and
October 2023 at 0.06. Those are single weather, hydro, and wind outcomes, not seasonality. Four
years cannot pin January's index closer than roughly ±0.4.

Only the shoulder months are stable: April 0.18, May 0.18, June 0.20, July 0.20, November 0.15.

**The futures curve agrees with the historical mean inside that noise band.** Mean absolute
difference between the live futures-implied index (2026-07-24) and the realized 4-year mean index
is **0.21** — half the year-to-year sd of the realized index itself. So the two sources do not
conflict, and the futures curve additionally states which year we are in.

Conclusion: the futures curve is the better shape source. Realized history is not a better curve.
It is an average of past outcomes whose year-to-year dispersion is largest where precision matters.

## 2026-07-25 — Do NOT use realized history to disaggregate a quarter future

Tested the natural refinement: use quarter futures for the level and realized history for the
monthly split inside a quarter, on the theory that the common year effect cancels within a quarter.

It mostly does not. Mean sd of the within-quarter monthly ratio is **0.33**, against 0.42 for the
full-year index — only a small improvement. Finnish spot shocks are monthly (a cold windless
February, a wet October) and not annual, so they do not cancel within a quarter. October sd 0.58,
December 0.59, February 0.52.

Decision: when only a quarter future exists, keep the price **flat across the three months of that
quarter**. Flat within a quarter is at least unbiased. Do not invent an intra-quarter shape from
history. This supersedes any implication in `spec.md` that history should shape quarters.

## 2026-07-25 — Keep the historical index as a last-resort fallback only

The historical shape is still better than no shape when futures are unavailable. For January the
flat assumption carries a systematic bias of about 0.52 index points, while the historical mean is
unbiased with a predictive sd of about 0.77, so RMSE is roughly 0.93 flat against 0.77 historical.
Keep the multi-year mean index as fallback step 4, mark it as a lower-confidence basis, and never
prefer it over an available curve.

## 2026-07-25 — Correction: the spot profile cost is a range, not 3.2 %

The earlier +0.231 c/kWh (+3.2 %, about +12 €/yr) figure came from one year only, and the
year-to-year dispersion above means one year is a weak estimate. Per complete year:

| Year | profile cost | at 5000 kWh |
|---|---|---|
| 2022 | −0.3 % | −3 €/yr |
| 2023 | +8.2 % | +27 €/yr |
| 2024 | +1.4 % | +3 €/yr |
| 2025 | +1.6 % | +4 €/yr |
| futures curve 2026-07-24 | +4.0 % | +17 €/yr |

The honest range is about 0 to +8 %, so 0 to +27 €/yr, and the live curve implies +4 % for the
coming twelve months. This does not change the sequencing decision: the spot correction is still
an order of magnitude smaller than the market-reset error. It does confirm that the coming year's
profile cost should be taken from the curve and not from a trailing year.

## 2026-07-25 — CORRECTION: the anchor is not a design choice, it is the same knob as beta

The earlier decision "anchor on the quarter strip" was reasoned partly from the *plausibility of
the output*, which is not a valid basis. The algebra shows why. The annual average of the estimate
is:

```
annual = P_current + beta * (F_strip - F_reference)
```

`F_reference` and `beta` enter multiplicatively into one term. Changing the reference is therefore
identical to changing `beta`. For Kokkolan Tyyni on 2026-07-24:

| Basis | annual equivalent |
|---|---|
| front month (July), beta = 1.0 | 11.14 c/kWh |
| Q3 strip, beta = 1.0 | 7.39 c/kWh |
| front month, beta = 0.39 | 7.39 c/kWh |

Choosing the Q3 anchor **is** choosing `beta = 0.39` on the front-month anchor. Picking it because
the answer looks more reasonable is fitting one free parameter to one desired output. Treat the
reference period as a parameter to identify from data, not as a decision to settle by judgement.

## 2026-07-25 — Why the front month drifts away from the strip (the mechanism)

A front-month future converges to the realized spot price of that month as delivery approaches. It
stops being a forecast and becomes almost a fact about one month. One month of Finnish weather is
extreme and idiosyncratic, and the annual strip does not follow it, because a wet windy July says
nothing about January. Observed over 55 trade dates:

| Date | July 2026 future | 12-month strip | gap |
|---|---|---|---|
| 2026-05-11 | 4.89 c/kWh | 7.52 | +2.63 |
| 2026-07-24 | 2.45 c/kWh | 8.67 | +6.22 |

The gap grows structurally, not randomly. So the front month is a poor *stable* reference, but that
is an argument about estimator variance and not about which reference is correct.

## 2026-07-25 — Observed reset behaviour suggests two populations, not one

Examined the General-price history of all 32 reset contracts in the local snapshot. Result: **one
observed price change in total**, and that one (Vaasan Sähkö Vaikuttaja, 6.60 → 0.00) looks like a
data artifact rather than a reset.

Contracts that held one price across a period boundary:

| Contract | Cadence | Held | Days |
|---|---|---|---|
| Aalto Kuukausihinta | monthly | 8.98 c/kWh | 136 |
| Aalto Huoleton | quarterly | 7.69 c/kWh | 123 |
| Keravan Kvartaalisähkö | quarterly | 11.50 c/kWh | 115 |
| Keravan Bio/Tuulikvartaalisähkö | quarterly | 12.50 c/kWh | 115 |
| Cheap Kvartaalisähkö | quarterly | 7.49 c/kWh | 39 |

Over that same window the front-month future fell from about 4.9 to 2.45 c/kWh. A monthly-reset
product that holds one price for 136 days has near-zero pass-through of the front month.

**Confound, must be resolved before trusting this:** a reset may appear as a *new contract id*
rather than a changed price on the same id. There are 10 rows named "Tyyni", so id rotation is
real. The measurement must follow `replaced_by_contract_id` chains and not single ids. Several
contracts also have short observation windows (24 days from 2026-07-01), which is a retention
artifact of the local snapshot.

If the finding survives the chain-aware recheck, then these 32 contracts are **two populations**:

- **(a) genuine market trackers** — reset every period, high pass-through. The forward shift is
  needed and hold-flat is badly wrong.
- **(b) sticky nominal resets** — the source declares a cadence, but the price moves rarely in
  practice. For these hold-flat is closer to correct, and the right fix may be to reclassify them
  rather than to estimate them.

Applying one anchor and one `beta` to both populations is wrong either way. Classify first from
observed price behaviour, then estimate.

## 2026-07-25 — Data available to identify beta

- Production `electricity_futures_eod_prices`: from **2026-04-08**, 77 distinct trade dates
  (about 3.5 months). The public EEX window is ~45 days, so earlier curve history cannot be
  recovered.
- Production `price_components`: from **2026-01-21** (about 6 months), which is longer than the
  curve history.

So roughly 3.5 months of overlap: about 3 monthly-reset events per monthly contract and one
quarterly boundary. That is thin but not nothing. Run the identification on production data,
chain-aware, before enabling the flag. Do not enable a full-pass-through correction on 32 named
companies' contracts from zero clean observations.

## 2026-07-25 — Chain-aware classification: they ARE all trackers (earlier finding retracted)

The "two populations" hypothesis was wrong. It was an artifact of contract-id rotation. Grouping
by company + product name across the whole lineage:

- reset contracts: 32 (local snapshot)
- groups by company + name: **27**
- classification: **tracker 26, sticky 0, too short 1**

Example, Helen Markkinahintasähkö, one price per month across 7 contract ids:
11.41 → 11.08 → 11.56 → 10.63 → 8.07 → 7.40 → 7.59 c/kWh (Jan..Jul 2026).

So market-reset contracts really do reset, and the hold-flat estimate really is wrong for them.

**RETRACTED side finding.** An earlier version of this entry claimed that
`replaced_by_contract_id` links none of these lineages. That was a bug in the analysis, not a
defect in the matcher. The union-find was built over interpreted contracts only, and **only the
newest row in a lineage carries `canonical_pricing`**, so every group trivially contained one
member. See the next entry for what the chains actually do.

## 2026-07-25 — Reference identification: no single reference fits all companies

Method: for each observed reset, take the curve **vintage from the last trade date before the reset**
(not today's curve) and compute the implied margin `P_new - F_reference`. The correct reference is
the one whose implied margin is stable across a lineage's resets.

Per-lineage margin stability (sd, c/kWh):

| Lineage | Cadence | vs month | vs quarter |
|---|---|---|---|
| Pohjois-Karjalan Optimi kuukausi | monthly | **0.15** | n/a |
| Helen Markkinahintasähkö | monthly | 0.86 | n/a |
| Turku Louna Kuukausi | monthly | 1.69 | n/a |
| Paneliankosken Kosken käyttöWoima | monthly | 2.32 | **0.22** |

Pooled: month reference n=21, mean margin +2.23, sd 2.10. Quarter reference n=9, mean +1.53,
sd 3.04 (badly polluted by one artifact, see below).

Conclusion: **different companies price from different references.** Pohjois-Karjalan tracks the
front month almost exactly (sd 0.15, so `beta = 1.0` with a month reference is right for them).
Paneliankosken tracks the quarter (sd 0.22 against 2.32 for the month). A single global reference
cannot serve both. This retires the earlier "anchor everything on the quarter strip" decision.

**Vintage matters and partly rescues the front month.** Helen's July price is 7.59 against a July
future of 4.03 as observed on 2026-06-30, giving a margin of +3.56. Against the 2026-07-24 curve
(2.45) the same price implies +5.14. Using the curve as of the pricing date removes most of the
front-month convergence problem, because it reads the front month *before* it converged. Always
use the vintage from just before the period started, never the latest curve, for the reference.

## 2026-07-25 — Margin: contract-specific level, company-specific behaviour

Question raised: should the margin be company-specific rather than contract-specific? The data says
the answer splits in two.

**Keep the margin level contract-specific.** It is not estimated — it is `P_current - F_reference`,
computed from the contract's own published price. Pooling it would erase real product differences.
Vaasan Sähkö's own products differ: Perussähkö 6.29, Tuulisähkö 6.65, and a green premium is a
genuine product attribute, not noise.

**Make the pricing behaviour company-specific**, meaning the reference period and `beta`. The
evidence:

| Measure | month reference | quarter reference |
|---|---|---|
| mean WITHIN-company margin sd | 1.45 | **0.66** |
| pooled ACROSS-company margin sd | 2.10 | 3.04 |

Within-company dispersion is clearly lower than across-company dispersion, so pricing policy is a
company attribute. Company mean margins differ widely on the month reference: Turku +0.73,
Kokkolan +1.22, Helen +2.74, Paneliankosken +3.22, Pohjois-Karjalan +3.92. Companies also reprice
their products together — Pohjois-Karjalan moved three products on 2026-07-01, Kokkolan moved two
on 2026-06-30.

Design: estimate the reference and `beta` **per company** where there are enough observed resets
(propose a threshold of 3), and fall back to a global default below that. Use the company-level
margin as a **sanity check** too: a contract whose implied margin is far from its company's other
products is a likely data error.

## 2026-07-25 — Sample is still too small to ship on

- 21 month-reference observations and 9 quarter-reference observations, across 7 companies.
- Several companies have n=1, so no per-company sd can be computed for them.
- Curve history starts 2026-04-08, so the January-March resets (the largest market moves) cannot be
  used. This will improve on its own as the daily collection accumulates.

Keep the flag off. Re-run the identification when there are at least 3 observations per company for
the companies that matter.

## 2026-07-25 — Data-quality issues found on the way

1. **Vaasan Sähkö Vaikuttaja published a 0.00 c/kWh General price** on 2026-07-23, still present on
   2026-07-24. It alone drags the pooled quarter-reference sd from about 1.2 to 3.04. A 0.00 energy
   price should almost certainly never reach rankings. Needs investigation and probably a guard.
2. **Several resets are not on calendar boundaries**: Paneliankosken on 2026-04-16, 05-21, 07-21;
   Fortum Kesto on 2026-06-15; Kokkolan on 2026-05-05 and 06-04. So the LLM-provided
   `recurring_schedule.current_period_start/end` may not match the real reset dates, which matters
   for picking the reference period. Prefer observed reset dates over declared ones where history
   exists.
3. **Suspicious cadence classifications**: "Kosken käyttöWoima 12 kk - kulutusjousto" and
   "Fortum Kesto" are interpreted as monthly resets. A product named "12 kk" resetting monthly
   deserves a recheck of its interpretation.

## 2026-07-25 — Replacement detection is NOT the problem (verified)

Audited all 38 reset lineages on production. For each, compared what the `replaced_by_contract_id`
chain recovers (walking backwards through predecessors) against what a company + name +
target_group + metering key recovers.

**Result: 37 of 38 chains are complete.** Both methods find the same ancestor ids and the same
number of priced days. Examples:

| Lineage | Chain ancestors | Name-key ancestors | Priced days, both |
|---|---|---|---|
| Kokkolan Tyyni | 12 | 12 | 184 |
| Helen Markkinahintasähkö | 6 | 6 | 185 |
| Pohjois-Karjalan Optimi kuukausi | 6 | 6 | 185 |

So the matcher is doing its job for these products. Do **not** open a broad "improve replacement
detection" task. Two narrower findings are real:

### 1. Target-group transitions break the chain (one known case)

`ntfqs8-kokkolan-energia-oy-vuodenaika` (Household) has **0** chain ancestors but 3 by name key, and
loses **103 days** of price history. Kokkolan Vuodenaika exists as `Both`, `Household`, and
`Company` rows; `plbcxh` (Both) carries 4 ancestors and 124 days while the Household and Company
rows start fresh.

Cause: the matcher requires an identical `target_group` as a hard requirement (see root
`AGENTS.md`). Splitting a `Both` product into separate `Household` and `Company` rows is a
legitimate same-product change, but it fails that test. A narrow fix is to allow
`Both -> Household`, `Both -> Company`, and the reverse merge as a one-to-many link, keeping all
other hard requirements and the existing conservative name scoring.

### 2. The chain is a converging DAG, not a sequence

Five old Tyyni ids all point straight at `ofzsgl`, skipping the intermediate rows between them. So
walking `replaced_by_contract_id` gives the correct **set** of ancestors but not the price **order**.
Anything reconstructing a price history must collect the ancestor set and then order by
`price_date`.

A reusable helper is worth adding, because the obvious workaround (group by company + name) is
wrong: it over-merges distinct variants. Korpela Kvartaali showed a Household price of 6.95 and a
Company price of 5.54 on the same date under one name. The correct lineage key is the chain, or
company + name + target_group + metering.

## 2026-07-25 — Correction: the quarter contract IS published at the pricing vintage

An earlier entry in this file reasoned that for a quarter already in delivery, EEX no longer publishes
that quarter contract, so a quarter reference would have to be derived by averaging the three month
contracts. **That is wrong for the case that matters.**

FI quarter maturities on 2026-04-08 included `202607` (Q3 2026). `202607` only disappears from
2026-07-23, when Q3 entered delivery. A quarterly reset price is set *before* the period starts, so at
that vintage the quarter contract exists and a plain lookup works. Deriving a quarter from month
contracts is only needed for a mid-period re-anchoring vintage, which is the less important case.

## 2026-07-25 — Still blocked, on data quality rather than on time

The premium backfill landed (1,909 rows, 2026-01-21..2026-07-25), but the reset population yields only
**9 usable observations across 1 usable lineage**. Three issues in
`../retail-premium-dataset/` block the identification; see that task's `decisions.md`:

1. duplicate price periods where the price did not change (101 pairs share a `price_signature`) —
   biases `beta` down; the single usable series gives 0.61 with the bug and about 0.95 without it;
2. `vat_basis = unknown` on 128 of 143 reset rows;
3. no `quarter` reference stored for any quarterly reset, so the central month-versus-quarter question
   is untestable — and quarterly products are 22 of the 32 reset lineages.

Best current estimate: `beta` near **1.0** against a month reference, from two clean steps at one
company (Pohjois-Karjalan Sähkö, pass-through 1.08 and 0.85, premium sd 0.70 c/kWh). Not shippable.
Do not enable `CANONICAL_PRICING`-side reset changes on this basis.

## 2026-07-25 — Measured beta after the v2 fixes: near 1.0 on a MONTH reference

Deployed the RetailPremium v2 fixes and re-collected production (v2 rows sit beside v1; both are
diffable). Identification re-run on `retail-premium-history-v2`, market-reset population, using the
newly stored VAT-consistent reference prices.

Only 14 reset rows carry a curve reference (10 unknown VAT, 4 included), giving **3 multi-period
series**. Two companies now have enough steps to estimate pass-through, against one before:

| Company | Reference | Pairs | beta | R^2 |
|---|---|---|---|---|
| Pohjois-Karjalan Sähkö | month | 2 | **0.90** | **0.99** |
| Kokkolan Energia | month | 3 | **1.01** | 0.66 |
| pooled | month | 6 | 0.53 | 0.25 |

The pooled figure is lower than either company because the through-origin fit weights by `dF^2` and a
third series with poor pass-through dominates it. With n=6 pairs the pooled number is not meaningful;
the per-company fits are the informative ones.

**Robustness to the VAT scale ambiguity.** Because most reset rows still have `vat_basis = unknown`,
`beta` is ambiguous by the 1.255 VAT factor. Tested both assumptions: Pohjois-Karjalan is unchanged at
0.90 (its rows carry a known basis), Kokkolan moves 1.01 → 1.27. Both stay consistent with
`beta` at or slightly below 1.0.

### This reverses my earlier "conservative quarter anchor" decision

The measured evidence says monthly resets **do** track the front month nearly fully — 0.90 with
R^2 0.99 for Pohjois-Karjalan. Earlier in this file I chose the quarter strip as a conservative
provisional anchor, reasoning from the implausibility of the resulting annual figures. That reasoning
was already flagged as invalid (fitting a parameter to a desired output); the data now contradicts its
conclusion too. **For monthly cadences, prefer a month reference with `beta` near 1.0.** The original
"beta stays at 1.0 until measured" prior was right.

### Still blocked for quarterly cadences, which are the majority

There are **zero direct `quarter` reference rows**, because production vintages sit inside the
delivery quarter and EEX stops publishing a quarter once delivery starts. The v2 fix adds a
`quarter_month_average` candidate (day-weighted average of the quarter's three month contracts),
deliberately kept as a separate candidate so the directly observed settlement stays clean.

Coverage: 32 `quarter_month_average` rows across 24 quarterly lineages on the forward collector, but
only 3 on the history collector, and none of those form a multi-period series. So the
month-versus-quarter question is **not yet testable** — each quarterly lineage currently has one
period. It becomes testable after the **1 October 2026** quarterly resets give a second period.

### Practical consequence

`beta = 1.0` with a month reference is now defensible for monthly-cadence resets on two companies'
evidence. It is NOT yet defensible for quarterly cadences, which are 22 of 32 reset lineages. Do not
enable the reset pricing change for quarterly products before the October resets. A monthly-only
first rollout is possible but covers a minority of the population, so waiting is probably better.

## 2026-07-25 — IMPLEMENTED: shape-only forward-curve shift, behind its own flag

Shipped in `laravel/app/Services/CanonicalPricing/MarketReset/`. Full implementation notes and the
non-negotiable rules live in that directory's `AGENTS.md`. Local tests: 1,202 passed / 3,755
assertions, up from a 1,173 / 3,617 baseline.

Reference `beta = 1.0`, one global value. Reference by cadence: month contract for `monthly`, quarter
contract with a `quarter_month_average` fallback for `quarterly` / `seasonal`. Both `F_m` and
`F_reference` read from the single latest vintage with `trade_date < today`, as specified.

### The defect was bigger than "an uncovered tail"

The spec framed the bug as `EstimateMethod::HoldCurrentRecurringPrice` filling the *uncovered tail*.
Costing all 32 local lineages showed **three** paths that all held one seasonal price flat, and only
the first was the documented one:

| Path | Lineages | Shape |
|---|---|---|
| `costWindow` estimate fill | ~15 | phase end `unknown` or a dated end short of 12 months |
| `costWindow` fully "covered" | 12 | a phase with `ends: none`, so the window looked fully covered and `estimateFill` was **false** — the outcome was not even marked as a held-forward estimate |
| `costHeldForward` | 3 | Hybrid `unsupported` base-only annualization |

So the tail boundary cannot be read off segment coverage. `resetTailStart()` takes the latest of the
cadence period end, the declared `current_period_end`, and the end of any coverage from a phase with
a **dated** end. `ends: none` is explicitly not trusted as a reset boundary: a product that resets
quarterly does not have a known price for twelve months. Do not revert that — 12 of 32 lineages
depend on it, and they are the ones the old code silently mislabelled as fully covered.

### The anchor is the last *disclosed* period, not always the current calendar period

Kokkolan Tyyni discloses both July (4.98 c/kWh, `price_role: current`) and August (6.94,
`price_role: future`). `future` components are billed, so August is real coverage. The estimate
therefore anchors on August against the **August** month future and reprices from September, rather
than anchoring on July as the spec table assumed. Result: annual equivalent 10.52 c/kWh, not the
11.14 the spec projected from a July anchor. Anchoring on the newest disclosed price and its own
delivery month is strictly more current information, so this is a deliberate improvement, not a
deviation to undo.

### `quarter` never resolves in practice, and that is expected

An earlier entry corrected itself to say the quarter contract **is** published at the pricing
vintage. True, but this estimator reads **today's** vintage by design, and EEX drops a quarter
contract once that quarter enters delivery. Verified on the refreshed local snapshot: at trade date
2026-07-24 the FI quarter maturities are 202610, 202701, 202704, 202707, 202710, 202801, 202804 —
202607 is gone. So **all 25 quarterly lineages resolved to `quarter_month_average`**, and the direct
`quarter` lookup is only reachable in the last days before a quarter starts. The primary/fallback
ordering is still correct and is kept; just do not read "fell back to the month average" as a defect.

### RETRACTED: the one-vintage rule (superseded the same day, see the next entry)

The first implementation followed the brief's "one vintage for both `F_m` and `F_reference`" rule
exactly, and measured its cost rather than assuming it: for a **monthly** cadence the reference period
is the month currently in delivery, whose future has largely converged to realized spot, so
`P_current - F_reference` inflated the spread by **1.58 c/kWh** (about **+79 €/yr** at 5000 kWh).
Monthly resets consequently annualised at 12.5-13.9 c/kWh against a fully-fixed median of 10.47.

That rule is now **retracted and replaced**. See the next entry.

### Guards, and one design decision worth keeping

- Negative floor is applied **per bucket** as `max(0, rate + offset)`, not on the offset, so a
  Time-metered contract cannot have its night rate floored using its day rate.
- The band on the annual equivalent is an **absolute** absurdity band (0-60 c/kWh), not a multiple of
  the fully-fixed median. See the entry below on why a market-relative band was removed.
- A missing delivery month aborts the whole forward shift instead of holding that one month flat. A
  partly-shifted, partly-flat window would be a silent hybrid with no honest basis to report.
- `baseTotalCost` and `structuredOnlyTotal` carry the **same** offsets as `totalCost`. If only the
  total were shifted, a winter reset would show a fabricated "Säästö … ilman tarjousta" on the card,
  and a reset carrying conflict codes would report an integrity impact mixing the promo effect with
  the seasonal repricing. Pinned by a regression test.

### Rollout

`RESET_FORWARD_SHIFT_ENABLED`, default false, separate from `CANONICAL_PRICING_ENABLED` (which is
already true in production). Flag off is byte-identical: the estimator short-circuits before touching
any market data, and a test asserts the fake curve is consulted zero times. The flag varies
`contract_list_metrics:…:c{0,1}r{0,1}:…`, `contract_rankings_5000kwh:c{0,1}:r{0,1}`, and
`ContractPageCacheVersion`. Two pre-existing memoization tests pinned the old key strings and were
updated to the new format; no assertion was weakened.

Verified by rendering the real pages locally with the flag on: contract detail, `/`,
`/sahkosopimus`, `/sahkosopimus/kvartaalisahko`, `/sahkosopimus/halvin-sahkosopimus`,
`/maksatko-liikaa`, `/sahkosopimus/tilastot` all return 200, and the two-figure display and the
neutral reset notice render as intended.

## 2026-07-25 — CORRECTION: two vintages, not one. `F_reference` uses the pricing vintage

The "one vintage" rule is retracted. Its justification — that a mixed vintage "reintroduces level
drift" — was wrong, and the algebra shows why:

- The seller set `P_current` at some `T0` before the period, from the forward for that period as it
  stood then. Their spread is `pi = P_current - F_ref(T0)`.
- The honest annual equivalent is `F_strip(today) + pi`.
- If the whole curve rose by X between `T0` and today, that estimate rises by X. **That is correct,
  not noise.** The market genuinely got more expensive, the next reset will reflect it, and the
  customer really will pay more. Cancelling it would delete real information.
- The one-vintage rule instead computed `pi' = P_current - F_ref(today)`. For a period already in
  delivery `F_ref(today)` has converged toward realized spot, so `pi' > pi` systematically. That is a
  pure artifact — the 1.58 c/kWh measured above.

So the quantity that actually needed cancelling is **front-month convergence**, which only ever
affects `F_reference`. Implemented:

1. `F_reference` → latest `trade_date` strictly before the current period's **start date**.
2. `F_m` → latest `trade_date < today`, unchanged.
3. No trade date before the period start → fall back to today's vintage and flag
   `reference_vintage_fallback_today`, rather than dropping to the much weaker spot index.

This is the same vintage rule `RetailPremium` uses for spread measurement, and for the same reason.
The `max_curve_age_days` staleness guard therefore applies to the **forward** vintage only; the
reference vintage is legitimately up to a quarter old.

### Measured effect

| Cadence | n | mean Δ€ before → after | mean 12 kk c/kWh before → after |
|---|---|---|---|
| monthly | 7 | +278.9 → **+223.5** | 12.94 → **11.84** (−1.11) |
| quarterly | 25 | +119.5 → **+128.7** | 10.90 → **11.08** (+0.18) |

The five **July-anchored** monthly lineages each fell by exactly **1.55 c/kWh** — the 1.58 c/kWh
artifact scaled by the tail's 4892.5/5000 share of the window. The two **August-anchored** ones
(Kokkolan Tyyni, Aalto Kuukausihinta) are unchanged, correctly: August had not started on 2026-07-25,
so "latest trade date before the period start" *is* today's trade date and its future has not
converged. Quarterly moved slightly the other way because the Q3 reference was *lower* at the pricing
vintage (5.923 vs 6.149 c/kWh).

Vintages actually used, 32 lineages, none falling back: 5 monthly at 2026-06-30, 2 monthly at
2026-07-24 (unstarted August period), 25 quarterly at 2026-06-30.

## 2026-07-25 — RESOLVED: `quarter` versus `quarter_month_average` is numerically irrelevant

The expectation was that the pricing vintage would let quarterly resets reach the **direct** quarter
contract, since the quarter has not yet started. Two production facts settle it:

1. **EEX drops a quarter contract a few trading days BEFORE delivery begins**, not on the first day of
   it. FI quarter `202607`'s last settlement is **2026-06-26**, while the pricing vintage for a Q3
   period starting 2026-07-01 is 2026-06-30. So the direct lookup is empty and all 25 quarterly
   lineages still resolve to `quarter_month_average`.
2. **It does not matter.** Across **96** production trade-date/maturity pairs where both exist, the
   quarter settlement and the day-weighted average of its three month settlements agree to a mean
   absolute difference of **0.002 EUR/MWh** and a maximum of **0.006 EUR/MWh = 0.0007 c/kWh**. An EEX
   quarter settlement *is* the day-weighted average of its months, so `quarter_month_average` is an
   **exact reconstruction**, not a degraded proxy.

Decision: keep the candidate ordering as specified and **do not** add a look-back rule to reach the
expired quarter contract. It would buy 0.0007 c/kWh and add a second vintage knob.

## 2026-07-25 — Removed the market-relative plausibility band: it encoded an economic prior

The first implementation banded the resulting annual equivalent to `[0.25, 2.5] ×` the fully-fixed
12-month retail median. That quietly encoded the prior *"a market-reset product must be cheaper than a
fixed deal"*, which was also used in an earlier (already retracted) entry to argue for a quarter
anchor. The prior is weak: Helen at 7.59 c/kWh against a 4.03 c/kWh forward for the same month implies
a spread near 3.6 c/kWh, entirely plausible for an incumbent with inert customers on a near-default
product. If such a product's honest annual equivalent really is above a 10.47 c/kWh fixed deal, that
is a **true and useful finding**, and suppressing it repeats the error of tuning a parameter until the
output looks reasonable.

The guard is now an **absolute** absurdity band only (`absurdity_band`, 0-60 c/kWh), which catches a
broken reference or a bad print and nothing else. The fully-fixed median is still read but **only** as
reported context in `contracts:compare-canonical-pricing --resets`.
`test_the_guard_does_not_suppress_a_reset_that_annualises_above_the_fixed_market` exists to fail if a
market-relative band ever comes back. Confirmed no-op on live data: **0 of 32** lineages hit the guard
before or after.

## 2026-07-25 — Residual: the pricing vintage is a proxy for the pricing date

"Latest trade date before the period start" is a proxy for when the seller actually set the price, and
it runs late. Cheap announces the next quarter's price by the 15th of the preceding month, Helen by the
15th or the prior business day. A mid-June Q3 pricing date would have read the reference around
43.5-44.3 EUR/MWh instead of 47.2. That residual is exactly what the deferred **per-company
calibration** identifies — the reference period *and* the effective pricing date per seller, from
observed resets. It is deliberately not guessed at in the estimator.

## 2026-07-25 — Deployed to production with the flag OFF

- Merged `market-reset-annualised-pricing` into `main`, deployment `d2cb065a` SUCCESS.
- Verified in production: `canonical_pricing.reset_forward_shift.enabled` = **false**, `beta` = 1.0.
  Production behaviour is unchanged. `/`, `/sahkosopimus`, `/sahkosopimus/kvartaalisahko`,
  `/sahkosopimus/halvin-sahkosopimus` all HTTP 200.
- Suite verified independently, not taken from the agent report: **1,209 passed / 3,778 assertions**
  (pre-task baseline 1,173 / 3,617). No forbidden path touched.

### Production `--resets` result at 5000 kWh, curve vintage 2026-07-24

**38 reset lineages, 36 shifted, 2 fell back to hold flat. Mean delta +153 €, max +255 €. Every delta
is positive**, which is the expected sign in July and a useful coherence check.

Annual-equivalent energy prices now straddle the fully-fixed 12-month household median of
10.48 c/kWh instead of sitting far below it: low end Korpela 8.22, Vaasan Perussähkö 8.83, Kokkolan
Vuodenaika 8.89; high end Keravan 15.04, Fortum Kesto 14.75. Some resets are genuinely cheaper than
fixed and some are genuinely dearer, which is the outcome a correct estimate should produce.

Reading caveat: `Vattenfall Yrityksen Kesto` (15.88) and the Fortum business products are ex-VAT
business contracts and must not be compared against the VAT-inclusive household median.

The two hold-flat fallbacks are `Fortum Yritys Spot Portfolio` and
`Paneliankosken Kosken käyttöWoima` — no reference resolved (`none @ none`), so they keep today's
behaviour. Both were already on the list as suspicious monthly-reset interpretations, so the safe
fallback is the correct outcome here.

`forward_month_from_quarter_contract` is flagged on all 36 shifted lineages: from 2027-02 onward no
month contract exists, so the month→quarter→year ladder uses quarters. Expected, not a defect.

### Residual bias is conservative, which is the right direction

The pricing vintage is a slightly **late** proxy for the real pricing date. Cheap and Helen both
publish by the 15th of the preceding month, and a mid-June read of Q3 would have been about
43.5-44.3 rather than the 47.2 EUR/MWh actually used. A reference that is too high makes the implied
margin too low, so the annual estimates are mildly **understated** — roughly 20 €/yr — not overstated.
For a public ranking of named companies that is the safer direction to err.

### The flip is deliberately left to the user

Flipping `RESET_FORWARD_SHIFT_ENABLED` changes what Voltikka publishes about named companies and will
substantially demote these 36 contracts. It is one env var and it is reversible. The remaining
judgement is that `beta = 1.0` is measured-supported only for the monthly cadence (0.90 with R² 0.99,
and 1.01); the 27 quarterly lineages use it as a principled prior until the 1 October resets allow
calibration.

## 2026-07-25 — The calibration re-run is now automated: `retail-premiums:calibrate`

The remaining blocker on this task was a **human remembering** to re-run the reference/`beta`
identification after the 1 October 2026 quarterly resets. That is now a scheduled, read-only report
instead.

`php artisan retail-premiums:calibrate` (`app/Services/RetailPremium/RetailPremiumCalibrationService.php`)
re-measures pass-through from `retail_premium_observations` and prints per company and per cadence:
series, pairs, `beta` under both VAT assumptions, R², mean premium sd, and the reference kind each
company appears to price from. `--json=` dumps the full grid. It writes nothing and changes no
pricing; wiring a measured value into the estimator stays a separate, reviewed change.

`routes/console.php` runs it **monthly on the 2nd at 08:00 Europe/Helsinki**, after the
1st-of-month resets have been imported and interpreted. It logs a summary line at `info` and
escalates to `warning` with "calibration review needed" once the quarterly cadence is measurable
(at least one company with `RETAIL_PREMIUM_CALIBRATION_MIN_PAIRS`, default 3, pass-through pairs)
and its measured median differs from `canonical_pricing.reset_forward_shift.beta` by more than
`RETAIL_PREMIUM_CALIBRATION_BETA_THRESHOLD` (default 0.25 absolute). That warning is the
self-surfacing mechanism and must not be replaced by a note in a docs file.

Three method points that were decided while building it, all documented in
`app/Services/RetailPremium/AGENTS.md`:

1. **The review only fires when NO VAT assumption reconciles the measurement with the configured
   value.** Most reset rows still carry `vat_basis = unknown`, so `beta` is ambiguous by the 1.255
   VAT factor. Escalating on the worse assumption alone would warn on every single run purely
   because of that factor.
2. **Method-seam pairs are dropped**, per the existing rule in `RetailPremium/AGENTS.md`: a row
   flagged `continues_prior_history_period` repeats an unchanged price against a moved reference and
   reads as zero pass-through. This is a deliberate addition to the brief's method; the report prints
   how many pairs it dropped so the effect is visible. On local data it dropped 9.
3. **A reference kind whose reference never moved cannot win the stability ranking.** Its premium sd
   is zero and it produces no pairs, so it would silently hide a kind that does carry a measurement.
   Kinds with at least one pair rank first; the purely most-stable kind is reported beside it. This
   was found on real data — Pohjois-Karjalan's frozen `quarter` candidate has sd 0.00 against
   `month` at 0.27.

### Local run, 2026-07-25 — NOT the production picture

The local snapshot holds 139 in-scope reset observations against production's larger set, and the
local `electricity_futures_eod_prices` table resolves different pricing vintages, so the reference
prices differ from the ones behind the figures recorded earlier in this file.

| Company | Cadence | Pairs | beta (VAT incl.) | beta (VAT excl.) | R² | Measured reference (sd) |
|---|---|---|---|---|---|---|
| Kokkolan Energia | monthly | 2 | 1.51 | 1.90 | 0.97 | month (1.13) |
| Pohjois-Karjalan Sähkö | monthly | 2 | 1.63 | 1.63 | 1.00 | month (0.27) |
| Turku Energia | monthly | 1 | −1.59 | −1.99 | n/a | month (1.47) |
| Aalto, Cheap, Kokkolan | quarterly | 0 | n/a | n/a | n/a | — |

Quarterly has **zero** pass-through pairs locally, so it reports as uncalibrated and no warning
fires — the expected state before October.

The local monthly figures do **not** reproduce the production 0.90 / 1.01 recorded above, and the
cause is the data, not the method. Pohjois-Karjalan's local reference for the July period is
4.5130 c/kWh where production read 4.03, because the local futures snapshot has a different latest
trade date before 2026-07-01. Kokkolan loses one of its three production pairs locally because two
consecutive periods resolve to an identical reference (`dF = 0`, correctly skipped). **Run the
report against production before drawing any conclusion from it.**
