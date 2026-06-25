# Promote "Maksatko liikaa?" and integrate bill comparison into contract listings

## Background

`/maksatko-liikaa` (`App\Livewire\BillComparison`, `App\Services\BillComparison\*`)
is a standalone bill-anchored comparison tool. It is currently only discoverable
through the nav ("Sähködata" dropdown), mobile menu, and footer. We want to:

1. **Promote** the feature more prominently, starting with the home page.
2. **Integrate** its core value directly into the contract listing pages: let a
   user optionally enter their current bill and see the **EUR savings vs their
   current contract** on the listing cards, without leaving the listing.

## Goals / product decisions (agreed with user)

- The headline value users care about is **EUR savings vs their current contract**.
- Do **not** take users out of the listing pages for the integrated experience.
- The standalone `/maksatko-liikaa` page stays (SEO landing + FAQ schema).

### Comparison basis (decided)

- **Default = period basis (facts).** When the user does NOT supply an annual
  consumption, compare against each contract's **same-period** cost
  (`BillComparisonService::periodCostEur`), NOT an annualised average. The user's
  bill total is ground truth; savings = `bill total − contract period cost`.
  - Fixed/General: exact for the period.
  - Spot: uses **actual historical hourly** `SpotPriceHour` for the exact date
    range (e.g. a January bill vs January's real spot prices). Skipped if no spot
    history for the period.
  - Time-of-use: 85/15 day/night split. Seasonal: split by the period's actual
    winter/other days.
  - Spot assumes flat hourly consumption (tight estimate); fixed/general is exact.
- **Annualised savings** are explicitly de-scoped from the MVP because annualising
  one month's implied unit rate is biased for non-flat (spot/seasonal/time)
  contracts. Treat as a later opt-in gated on the user supplying annual kWh.

### Card display in period mode (decided)

- The listing card keeps its existing shape: **€/kk headline** (today = annual
  estimate ÷ 12; in period mode = `period cost ÷ months-in-period`), with a
  secondary line.
- Add a **"säästö X €/kk"** line (period savings ÷ months) — a fact for that
  period. Neutral slate per `DESIGN.md` / `HeatPumpCalculator` precedent (NOT
  green/red; green/red is reserved for CO₂).
- Secondary line in period mode becomes period-scoped (e.g. `112 € / laskutusjakso`)
  instead of the annual `€/v`, so we never imply an annual figure we didn't compute.
- Label must read "laskutusjaksollasi" / "samalla jaksolla" so a winter bill's
  higher €/kk is not mistaken for a typical going-forward monthly cost.
- Never display the user's implied c/kWh as if it were their energy price (it is a
  blended bill average, possibly incl. base fees). See BillComparison AGENTS docs.

### Current contract anchor

- Show a "Nykyinen sopimuksesi" anchor (rank + period cost) plus per-card savings,
  reusing the bill tool's verdict styling. (Inline-slotted-at-rank is a possible
  alternative; anchor + per-card deltas is the recommended presentation.)

## Design constraints (DESIGN.md)

- Coral ≤ 10% of screen; no coral background mass; no second accent colour.
- No exaggerated-savings / urgency language; no nested cards; no em dashes in copy.
- Savings deltas = neutral slate tabular; coral reserved for the one headline / CTA.

## Scope / order (decided with user)

1. **Home-page promo first** — promote `/maksatko-liikaa` on the home page with a
   calm, on-brand link/band. Ships independently.
2. **In-listing bill comparison** (period-basis MVP) — on all contract listing
   surfaces: home `/` (`ContractsList`), `/sahkosopimus` + SEO pages
   (`SeoContractsList`).

## Non-goals (MVP)

- Annualised savings on cards (future opt-in).
- Auto-identifying which DB contract the user is currently on.
- Persisting/aggregating anonymous comparison results.
