# Decisions

## 2026-08-06 — Task created

- The current flat-price treatment is not an honest annual expectation for an open-ended contract whose supplier can change the price with notice.
- The existing `ends: none` canonical boundary means that no end date is disclosed. For an adjustable open-ended tariff, it must not be treated as a 12-month price guarantee.
- Use the same market-aware principle as recurring reset pricing, but keep a separate typed model because the seller does not disclose a reset cadence or pass-through rule.
- Keep the current price exact and visible. Mark only the 12-month outcome as an estimate.
- Apply eligibility from contract semantics, not from whether Voltikka has already observed a price change. A short history is missing evidence, not proof of price stability.
- Do not change fixed-term or explicitly guaranteed prices.
- Do not label ordinary adjustability as deceptive pricing.
- The first implementation must stay simple and reuse existing curve/reference infrastructure where possible. Per-company timing and pass-through calibration remain future work until enough observations exist.

## 2026-08-06 — Core implementation completed

- The new `CanonicalPricing/SupplierAdjusted/` path is separate from recurring market resets. It never creates a cadence and never writes `reset_estimate`.
- Eligibility is exact `OpenEnded` + `FixedPrice` + `General` with one complete current structured phase, no recurring schedule, no consumption effect, no package, and no promotion, future phase, Spot margin, or mechanism switch.
- Duplicate identical monthly fees are one explicit eligibility exception. Sulaketariffi has five identical €4.20 canonical fee components. They resolve through the existing conservative maximum rule. Conflicting fee amounts remain ineligible.
- The current calendar-month remainder stays exact. The estimated tail starts on the first day of the next calendar month.
- The forward reference is the FI month contract for the episode-start month at the latest curve vintage before that episode start. Later delivery months use today's curve. The existing reset beta, staleness, seasonal-index, negative-floor, and absolute-plausibility settings are reused.
- All three rungs are `comparable_estimate`: supplier forward shift, supplier Spot seasonal index, and hold current supplier price. Every rung writes `supplier_adjusted_estimate` and states that the monthly fee is held flat.
- Episode anchors come from one batched snapshot query, with observed-seller runs preferred. The source-observation fallback requires its snapshot to match the published interpretation snapshot. A missing anchor is typed and holds the price flat. The orchestrator memoizes anchors across repeated consumption calculations.
- Annual offsets apply to total, promotion-free base, and structured-only totals. Exact-period bill calculation does not apply them.
- `CalculatedCostPayloadSchema` moved once, from v11 to v12. `ContractPricingViewData` and the public contract API validate and expose the separate payload.
- Public card and contract-detail explanation is not part of this core slice. It remains pending. Surface-copy tests and final end-to-end documentation therefore also remain open.

## 2026-08-06 — Public presentation completed

- `SupplierAdjustedEstimateCopy` is the only source for the new public estimate explanation and detail receipt note. It reads the validated supplier-adjusted payload only. It does not read seller text, interpretation summaries, or a reset cadence.
- Every supplier-adjusted basis gets the existing shared `Arvio` popover. The copy separates the seller's published current price from Voltikka's 12-month estimate, states the notice rule and unknown future schedule, and says that the estimate is not a price promise.
- The pricing category stays `Kiinteä hinta` with lock styling. The band now states that the current energy price is fixed and that the seller can change it with notice, instead of claiming that the energy price does not change.
- General-tariff receipts use the three-row set `Energia nyt`, soft `12 kk keskihinta, arvio`, and `Perusmaksu`. No row labels the estimate as a contractual future rate.
- ContractDetail keeps one hero `Arvio` marker, adds one quiet supplier-adjusted receipt note, and uses a short qualifier that separates the current fact from the annual estimate without copying the full popover.
- `/tietoa#menetelma` and the statistics annual-cost methodology now state how adjustable open-ended estimates work and where uncertainty remains.
