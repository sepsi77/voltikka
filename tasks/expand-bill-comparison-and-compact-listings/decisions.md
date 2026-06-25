# Decisions

## 2026-06-25 — User decisions (AskUserQuestion)

- **Layout (point 3): compact toolbar, tools collapsible.** Slim hero; replace
  big preset cards + always-open calculator panel with a compact consumption
  row; calculator AND bill entry behind collapsed disclosures. Contracts rise
  near the top.
- **Rollout (point 2): all household-oriented pages.** Every SeoContractsList
  page + cheapest, but NOT `/sahkosopimus/yritykselle` (business). A household
  energy bill vs business contracts is not meaningful.
- **Detail price (point 1b): carry consumption to the detail page**, but the
  `?kulutus=` URLs must stay non-indexable via a clean self-canonical (user's
  explicit caveat). Verified `ContractDetail::canonicalUrl` is already the clean
  param-free URL and prepared-cache bypasses on any query, so the constraint is
  met by construction. Append `?kulutus=` only when it differs from the 5 000
  default to keep common URLs clean and limit crawl waste.

## Implementation notes

- **1a (done):** consumption-range pre-filter wrapped in `! isBillModeActive()`
  in both `ContractsList::getContractsProperty()` and the `SeoContractsList`
  override. Caps still enforced by `BillComparisonService::fitsConsumptionLimits()`
  on the bill-derived `$annualKwh` inside `buildMarketRow()`.
- **2 (business off):** `showBillComparison` resolved per-request from
  `targetGroup`. Base `SeoContractsList` enables it; disabled when
  `targetGroup === 'Company'`. `SahkosopimusIndex` stays explicitly on.
- **1b basis caveat:** detail page shows annual €/v; bill cards show period
  €/kk. Carrying consumption aligns the *consumption*, not the basis. Acceptable
  and the honest framing stays ("laskutusjaksollasi" on cards, "vuosikustannus"
  on detail).
