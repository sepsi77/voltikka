# Per-company retail premium dataset

## Goal

Record, for every active contract and every price period, how far the retail price sits above the
wholesale market price that the seller could have hedged at when the price was set. Accumulate this
over time to answer questions no other Finnish comparison site can answer:

- which seller charges the least above wholesale, rather than who looks cheap on today's snapshot;
- who passes a wholesale fall through to customers quickly, and who is quicker to raise than to cut;
- how a seller's premium behaves over a full year and across price cycles.

This started as a diagnostic inside `../market-reset-annualised-pricing/` (identifying the
pass-through parameter `beta`). The numbers are already being computed there and then discarded.

## Naming: this is NOT profit margin

Call it **retail premium** or **spread over wholesale**, never "margin" or "profit", in code, in
docs, and above all in public copy. The premium covers the seller's hedging cost, the profile/shape
cost of the customer group, imbalance cost, credit risk, customer acquisition, billing, support, and
only then profit. Publishing it as a profit figure for a named company would be wrong and would
invite legitimate complaints.

Related consequences:

- A higher premium is not automatically worse value. A seller whose customers are electric-heated
  houses carries a genuinely higher shape cost.
- Green sourcing and service level are real cost differences, not padding.

## What already exists, and what is missing

`contract_price_daily_statistics` already stores a `spot_margin` metric daily
(`segment_key = spot`), but only as a market aggregate: on 2026-07-24 it collapsed **58 contracts**
into min 0.29 / p20 0.40 / median 0.50 / avg 0.56 / p80 0.60 / max 1.60 c/kWh. The per-company
detail is computed and thrown away every night.

Missing:

1. a company and contract dimension;
2. inferred premium for non-Spot pricing models, which needs a wholesale reference from the futures
   curve;
3. the curve **vintage** — the reference must be the curve as of when the price was set.

## Premium definition per pricing model

| Pricing model | Premium | Quality |
|---|---|---|
| `Spot` | the disclosed `spot_margin` component | **exact**, already in canonical JSON, no inference |
| Market reset (monthly/quarterly) | `price − month-or-quarter future for the period, at the vintage before the period started` | inferred |
| `FixedPrice` fixed-term (6/12/24 mo) | `price − forward strip for the delivery term, at the offer vintage` | inferred |
| `Hybrid` | base only; the consumption effect is undisclosed | **not comparable**, flag and exclude |

## Build order: this task runs FIRST

This task can be implemented **before** `../market-reset-annualised-pricing/`, and should be. The
dependency runs the other way round: this dataset produces the per-company reference and `beta`
observations that the market-reset estimator is blocked on. That task currently has only 21
month-reference observations across 7 companies, several with n=1.

It is also the lower-risk half. This work is purely additive — a new table, a new service, a new
command — and touches no ranking, listing, caching, or public page. The market-reset task edits
`CanonicalContractPriceCalculator`, which changes live rankings.

**This task therefore owns the two shared components**, and the market-reset task consumes them:

1. the vintage-aware market reference-price lookup;
2. the lineage price-history helper (replacement-chain ancestors ordered by `price_date`).

### What already exists and needs no new code

- `App\Services\PriceForecasting\FixedTermHedgeCostService::calculate($asOfDate, $durationMonths)`
  already returns a term hedge cost in c/kWh incl. VAT for a given **vintage**, using
  `latestTradeDateBefore()` (`trade_date < asOf`, no same-day leakage) and the month → quarter → year
  ladder, plus a `coverage_quality` flag. The **fixed-term premium case needs nothing more than
  this.**
- `latestTradeDateBefore()` and `maturityForMonth()` on the same class are public and reusable.
- Spot premium needs no curve at all: it is the disclosed `spot_margin` component
  (`App\Services\CanonicalPricing\Enums\ComponentType::SpotMargin`) in the canonical JSON.

### The only genuinely new curve code

A lookup for **one specific delivery period at a given vintage** (the month or quarter containing a
reset period). `FixedTermHedgeCostService::calculate()` cannot do this, because it always starts at
`asOf->startOfMonth()->addMonth()` and averages a duration; a reset period may already be running.

**Do not refactor `FixedTermHedgeCostService` to generalise it.** It runs on the production 07:30
schedule and feeds immutable stored forecasts. Add the new lookup alongside it and leave the existing
class alone. Extracting a shared curve repository can happen later, with tests, as its own change.

### Keep consistent with the existing model

`FixedTermPriceForecastService` already estimates a **market-level** normal premium with EWMA over
`contract_price_daily_statistics`. This work is the per-company version of the same quantity. The two
must agree in aggregate, so add an explicit cross-check. Treat
`contract_price_daily_statistics` as read-only here — the EWMA forecast depends on its current
segments.

### Record every candidate reference, not one

For each observation, store the premium against **each** candidate reference (month, quarter, year,
term strip) rather than picking one. Which reference a seller actually prices from is still an open
question, and it differs by company: Pohjois-Karjalan tracks the front month (margin sd 0.15) while
Paneliankosken tracks the quarter (sd 0.22 against 2.32 for the month). Storing all candidates is
what lets this dataset answer that question instead of presuming an answer.

## Fairness rules for any ranking built on this

- **Always include the monthly fee.** A premium in c/kWh alone misleads, because low energy price
  with a high monthly fee is a common structure. Rank on total annual cost at a reference
  consumption, or on a premium with the fee amortised at that reference consumption. State the
  reference consumption.
- Keep VAT basis explicit. Business contracts are ex-VAT.
- Never mix a `Hybrid` into a premium ranking.
- Record the method version on every row, so old rows stay interpretable when the method changes.
  Same principle as the versioned interpretation JSON.

## Storage shape

One row per (contract lineage, price period) written when a new price is observed — **not** one row
per contract per day. The premium only changes when the price or the curve changes, and for analysis
what matters is one observation per price period. That keeps the table small (thousands of rows per
year, not hundreds of thousands).

Suggested columns:

- lineage key, contract id (the row that carried the price), company name
- pricing model, cadence, contract type, target group, metering
- period start, period end, first and last observed date
- energy price components, monthly fee, VAT basis
- reference kind (`spot_disclosed` / `month` / `quarter` / `year` / `term_strip`), reference trade
  date (the vintage), reference price
- premium c/kWh, and premium including the amortised fee at the reference consumption
- method version, and a quality flag (`exact` / `inferred` / `not_comparable`)

Make rows immutable by default, as `FixedContractPriceForecast` does: skip an existing row for the
same lineage/period/method unless `--overwrite` is passed. This protects the historical record.

## Why this must start now

The dataset can only be built **forward**:

- Contract price history goes back to **2026-01-21**.
- FI futures history goes back only to **2026-04-08**, and the public EEX window is about 45 days,
  so earlier curve vintages **cannot be recovered at all**.

So premiums for January to March 2026 are permanently unrecoverable. Every further day without
collection loses another day of a dataset that cannot be rebuilt later. The Spot case needs no curve
at all and could start immediately.

## Immediate technical payoff

Beyond the analytical value, this dataset supplies the per-company reference and `beta` that
`../market-reset-annualised-pricing/` is currently blocked on. Today there are only 21 month-reference
observations across 7 companies, several with n=1.

## Later, once enough history exists

- Pass-through asymmetry per company: compare the speed of increases against the speed of decreases.
- Combine premium with the existing deceptive-pricing detection to build a seller value/trust
  profile.
- A public page. Do not design that until the dataset has at least a full heating season, and have
  the wording reviewed, given the naming risk above.
