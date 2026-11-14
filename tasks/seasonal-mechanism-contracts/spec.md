# Contracts with different pricing mechanisms in different calendar months

Status: spec draft 2026-11-14, not implemented.

## Background

On 2026-11-14 the first interpretation of Keravan Energia **PerusFiksu 24kk** stopped every price
cache build on Railway production and the VPS (see `../vps-migration/decisions.md`, 2026-11-14,
and PR #15). The seller's text: "talvikuukaudet marraskuusta maaliskuuhun laskutetaan kiinteällä
hinnalla … loput vuodesta huhtikuusta lokakuuhun pörssihinnalla + marginaalilla". Prices: fixed
14.6 c/kWh (Nov–Mar), Spot + 0.55 c/kWh (Apr–Oct), monthly fee 5.50 €, 24-month term.

The interpretation (6090, fixture `laravel/tests/Fixtures/kerava-perusfiksu-24kk-hybrid-spot-transport.json`)
holds the correct facts: two `recurring_period` phases with `period_boundary` month names
(`November`–`March`, `April`–`October`), `recurring_schedule.present = false`. Today:

- The calculator resolves `period_boundary` only from a recurring reset schedule, so neither phase
  gets a window. It fills the year with the latest applicable price and costs all 12 months as
  Spot + 0.55 (566 € at 5,000 kWh). The fixed winter price is not used.
- The strict reader rejects that result; since PR #15 the contract is excluded with an error log.
  The statistics path does not use the reader, so the wrong total is stored in the v3 annual
  history for 2026-11-14 (266 / 566 / 1,867 € at 2,000 / 5,000 / 18,000 kWh).
- The interpretation marks a consumption effect only because the upstream enum is `Hybrid`
  (`evidence: pricing_model=Hybrid`, `applies_to: unknown`). The product has no consumption effect
  (user, 2026-11-14).
- `PricingCategoryResolver` puts every `Hybrid` enum contract in Kulutusvaikutus.

User, 2026-11-14: this is a new type of contract, and more contracts like it are likely. The
pricing calculation must handle **different pricing mechanisms in different calendar months** as a
general rule, not as a one-contract exception.

## Terms

- **Seasonal calendar:** a set of phases that each cover named calendar months and repeat every
  year. Together they cover each of the 12 months exactly once.
- **Season:** one phase of that calendar. Each season has its own complete mechanism: fixed energy
  rates (General, Time or Season metering) or a Spot margin, plus its fees.

## Requirements

- **S1 Representation.** The calculator recognizes a seasonal calendar from the current schema
  shape: every pricing phase has `period_boundary` start and end values that are English month
  names, and `recurring_schedule.present` is false. No other shape is read as a seasonal
  calendar. (Open question O1: an explicit schema field for later interpretations.)
- **S2 Validation, fail closed.** The months of all seasons cover January–December exactly once
  (a season can wrap the year end, for example November–March). Each season has a complete,
  unambiguous mechanism. Otherwise the contract is excluded with a typed reason
  (`unresolved_phase_boundaries` for a `period_boundary` that cannot be resolved,
  `incomplete_seasonal_calendar` for gaps, overlaps or incomplete seasons). It is never filled with
  one "latest applicable" price. This also covers the inactive contracts that misuse
  `period_boundary` with month counts (Hehku KIINTEÄ 24 kk, Vaasan Kiinteä 12 kk, Koillis-Satakunnan
  VakaaWirta 24 kk).
- **S3 Timeline.** `Support/PhaseTimelineBuilder` resolves each season into absolute windows that
  repeat every year across the 12-month window (and across the real term for a short fixed term).
  Window edges fall on Helsinki month starts. For a start on 2026-11-14 PerusFiksu gives: fixed
  14.11.–31.3., Spot 1.4.–31.10., fixed 1.11.–13.11. Every window is a `phase_breakdown` record.
- **S4 Annual costing.** Fixed seasons are costed exactly with their rates and the usage profile.
  Spot seasons use the existing `SpotForward/` monthly estimate plus the season's margin; the
  rolling-365 fallback rules stay the same. Fees follow their season. VAT, usage conservation,
  anniversary bins and measured offer savings keep their existing rules. A calendar with any Spot
  season is `comparable_estimate` with the existing Spot estimate method; a calendar of only fixed
  seasons is `comparable_exact`.
- **S5 No invented consumption effect.** For a seasonal-calendar contract, a consumption effect
  whose only evidence is the upstream `pricing_model` and whose `applies_to` is `unknown` is not a
  consumption effect: no `base_only_hybrid`, no `excludes_consumption_effect` assumption, no "Ei
  sisällä kulutusvaikutusta" text. A consumption effect with real source evidence keeps the
  existing Hybrid rules. Other Hybrid contracts do not change.
- **S6 Exact-period pricing.** `calculatePeriod()` (bill comparison) uses the same season windows,
  with realized hourly Spot prices in Spot seasons and the fixed rates in fixed seasons.
- **S7 Transport.** `ContractPricingViewData` accepts the seasonal result (fixed and Spot
  `phase_breakdown` records, no overlap) and round-trips it unchanged. The PR #15 per-contract
  catch stays as a safety net.
- **S8 Card category: Kiinteä hinta** (user decision 2026-11-14: "fixed when it counts the most").
  The card states the seasonal split in one line (Finnish copy by Claude, for example "Kiinteä
  hinta marras–maaliskuussa, pörssihinta huhti–lokakuussa"). `PricingCategoryResolver` does not
  put a seasonal-calendar contract in Kulutusvaikutus, also when the upstream enum is `Hybrid`.
  Showing one contract in two categories was considered and rejected: one contract has one card
  band, and the pricing-type filter and the card band must agree (`PricingBucket` invariant).
- **S9 Filter bucket: `kiintea`.** `PricingCategoryResolver::scopeBucket()` gives the same answer
  in SQL as `resolve()` in PHP, so the filter that lists the contract agrees with its card band.
- **S10 Statistics: treated as fixed price for now** (user decision 2026-11-14). The contract goes
  into its fixed-price segment (for PerusFiksu `fixed_term_24`), not `hybrid` or `spot`. Annual
  costs use S4. (Open question O2: unit energy-price metrics.)
- **S11 Other consumers.** The seller-set energy-price index, the fixed-term forecast history and
  the retail premium dataset do not treat a seasonal calendar as one all-year fixed rate. Each
  consumer either has a stated rule for it or excludes it explicitly.
- **S12 Cache and history.** `CalculatedCostPayloadSchema::VERSION` goes to 20. Historical v3
  reconstruction uses the same seasonal rules, so "History follows the current method": after the
  deploy, recalculate the v3 annual history for the affected dates (from 2026-11-14) with a dry run
  first and a separate production approval.
- **S13 Documentation.** `CanonicalPricing/AGENTS.md`, `ContractPricing/AGENTS.md`,
  `ContractCard/AGENTS.md`, `ContractStatistics/AGENTS.md` and the root `AGENTS.md` describe the
  seasonal calendar, its validation and the category decision.

## Open questions

- **O1 Interpretation schema.** Keep reading the current shape (S1) only, or also add an explicit
  calendar boundary (for example month numbers) to the next schema/prompt version so later
  interpretations state the calendar without ambiguity? A prompt or schema change causes new
  analyses. Recommendation: S1 now; the explicit field as a later, separate change.
- **O2 Unit energy-price statistics.** A seasonal contract has no single energy price. Recommendation:
  include it in the annual-cost medians of its fixed segment, exclude it from unit energy-price
  medians.
- **O3 SEO page `/sahkosopimus/kiintea-hinta`.** It requires `pricing_model = FixedPrice` and an
  exact calculation, so PerusFiksu is not listed there. Recommendation: keep that rule; the page
  copy promises a fixed price.

## Done when

- [ ] A test with the PerusFiksu fixture: listed, `comparable_estimate`, fixed 14.6 c/kWh in
      November–March and Spot + 0.55 c/kWh in April–October, correct windows for a mid-November
      start, no consumption-effect assumption, the reader accepts it.
- [ ] Tests for a wrapped and an unwrapped calendar, Time metering in one season, a fixed-only
      calendar (`comparable_exact`), and fail-closed cases (gap, overlap, unknown month, month
      counts in `period_boundary`, incomplete season).
- [ ] Exact-period test across a season edge.
- [ ] Card shows Kiinteä hinta with the seasonal line; the `kiintea` filter lists the contract
      and the `kulutusvaikutus` filter does not; SQL and PHP category agree.
- [ ] Statistics put the contract in `fixed_term_24`; S11 consumers have tested rules.
- [ ] Schema version 20; full suite passes; `spec-review` and the external PR review are clean.
- [ ] After user approval: deploy, then the dry-run and approved v3 history recalculation from
      2026-11-14.
