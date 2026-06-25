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

## 2026-06-25 — Follow-up: deeper compaction + direct consumption input

User feedback after first pass: the consumption + filter sections still took too
much vertical space; the preset chips had lost the info the big cards carried;
and consumption only supported presets/calculator (no direct entry).

- **Consumption selector reworked** into a single compact row of preset
  info-cards (label + description + kWh restored) plus a free-text "Tiedän
  kulutukseni" input tile. The big always-open calculator/tab pill is gone; the
  calculator is now behind a header toggle (desktop) / in-panel toggle (mobile).
- **Direct input:** `directConsumption` property + `updatedDirectConsumption()`
  (positive-only; ignores blank/zero so a cleared mobile field never zeroes
  consumption). Mirrors `$consumption` (seeded in `booted()`, synced by
  `selectPreset`/`calculateFromInlineCalculator`); editing it clears the preset.
- **Filters collapse on all sizes now** (were desktop-always-open). The shared
  `partials/contract-filters.blade.php` accordion trigger ("Rajaa hakua") shows an
  active-filter count badge and defaults open only when `hasActiveFilters()`.
  Verified the `x-show`/`x-collapse` reveal works (display:flex, height:auto).
- Verified desktop + mobile: first contract now sits at the fold; direct input
  reranks (313→307 at 12 000 kWh); calculator + filters open on demand.

## 2026-06-25 — Polish pass (impeccable) on the compacted selector

The first compaction over-shrank the consumption selector and tripped DESIGN.md.
Fixes, all grounded in DESIGN.md (not preference):

- **Readable-By-Default Rule (DESIGN.md ~L250).** Preset/direct tiles used
  `text-[11px]` in `text-slate-400`. Bumped descriptions/labels to `text-xs`
  `slate-500` (min ink), the kWh value to `text-sm font-bold slate-900` (it is
  the meaningful number), and the direct-input placeholder/suffix to `slate-500`
  (≥4.5:1). Type no longer reads cheap/cramped.
- **Active selector = sanctioned coral gradient + glow** (`from-coral-500
  to-coral-600` + `shadow-coral`), replacing the flat `bg-coral-600` block.
- **Contradictory direct-input value fixed.** The "Tiedän kulutukseni" tile
  showed a number (e.g. 2000) while a different preset card was highlighted.
  `selectPreset()` now clears `directConsumption` to null and `booted()` only
  seeds it when `selectedPreset === null`, so the field shows its placeholder
  under a preset and only carries a value when it IS the active (custom) choice.
- **Em dashes removed** from two product-copy strings (results bar, independence
  note) per the no-em-dash-in-product-copy rule.
- Verified both states + mobile in browser; 80 listing/bill tests pass.
