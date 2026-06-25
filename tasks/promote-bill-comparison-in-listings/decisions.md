# Decisions

## 2026-06-25 — Initial design alignment with user

- **Promote first, then integrate.** Home-page promo link to `/maksatko-liikaa`
  ships first as an independent change. The deeper in-listing integration follows
  on all contract listing pages.

- **EUR savings is the product north star.** The user cares about euro savings vs
  their current contract, shown in-context on the listings, not a separate tool.

- **Period basis, not annualised, for the MVP.** Annualising one month's bill into
  an annual figure has two halves:
  - consumption (kWh) annualisation is solid (`ConsumptionProfile`, seasonal, not
    ×12), and
  - cost (€) annualisation = `implied unit rate × annual kWh`
    (`BillComparisonService:115`), which holds the unit price flat and is therefore
    biased for spot/seasonal/time contracts (a winter bill overstates annual cost
    and thus savings).
  Decision: when the user does NOT enter annual consumption, show **period-basis
  savings only** (facts). Annualised savings are a future opt-in gated on the user
  supplying annual kWh.

- **Same-period contract pricing already exists.** `periodCostEur` prices each
  contract for the exact billing dates + the user's kWh, using actual hourly
  `SpotPriceHour` for spot. No new pricing math needed for period mode.

- **Card shape preserved.** Cards lead with €/kk (today annual÷12). In period mode
  the €/kk is `period cost ÷ months-in-period`; secondary line becomes period-scoped;
  a neutral-slate "säästö €/kk" line is added. Framed "laskutusjaksollasi" so a
  winter bill's higher €/kk is not read as a typical monthly cost.

- **Honesty guardrails:** do not show the user's implied c/kWh as their energy
  price; spot period cost assumes flat hourly consumption (tight estimate); fixed
  is exact; spot skipped when no period history.

- **Design discipline:** savings deltas neutral slate (green/red reserved for CO₂),
  coral ≤10%, no urgency/exaggeration, no em dashes in copy.

## 2026-06-25 — Home-page promo implemented

- The home route `/` is served by `App\Livewire\HomePage` →
  `resources/views/livewire/home-page.blade.php` (a bento-grid landing page), NOT
  `ContractsList`. Root CLAUDE.md's "`/` → ContractsList" is stale.
- Promo added as a **full-width closing tile** (`md:col-span-2 lg:col-span-3`) at
  the end of the "Palvelut" bento grid. The grid is featured(2-wide) + 4 singles =
  6 cells (balanced); a 5th single would break the last row, so a full-width
  closing tile keeps it balanced and gives the feature prominence via width.
- Kept it a white bordered tile (sibling-consistent, does not overpower the dark
  primary "Sähkösopimukset" tile), horizontal layout, single coral-tint icon,
  coral CTA "Vertaa laskuasi". Copy: "Maksatko sähköstäsi liikaa?" + one line.
- `lg:col-span-3` was not in the prebuilt CSS (page serves static build, no
  `public/hot`); ran `npm run build` to compile it.
- Per user: removed the "Et tarvitse vuosikulutusta" callout from the tile copy
  (pointless callout).

## 2026-06-25 — In-listing bill comparison (period mode) on /sahkosopimus

- `/sahkosopimus` = `SahkosopimusIndex extends SeoContractsList extends ContractsList`,
  shared `seo-contracts-list.blade.php`. Built the feature into the shared
  component/view, gated by `$showBillComparison` (true only on SahkosopimusIndex)
  so it ships on /sahkosopimus first; rollout to other listing pages = flip the flag.
- Service: added `BillComparisonService::periodRowsForContracts()` + extracted
  `periodContext()` (shared with `compare()`). Reuses `periodCostEur`; available
  rows only. Existing 10 BillComparison tests stayed green (safe refactor).
- Component: bill inputs + actions + `buildBillModePaginator()` in base
  `ContractsList`; one-line branch in both `getContractsProperty` overrides after
  filters; bill state interactive-only (never #[Url]) so cached default GET is
  unaffected (+ explicit `! $billActive` guard in `isDefaultListingCacheable`).
- Card: `billMode`/`periodComparison` props → period €/kk + period-scoped
  secondary line + neutral-slate "Säästö €/kk" (green/red reserved for CO2).
- Tests: `tests/Feature/SahkosopimusBillModeTest.php` (4). No regressions in
  affected suites (one pre-existing unrelated city-page failure that also fails
  on origin: `/sahkosopimus/paikkakunnat/pudasjarvi` needs a municipality seed
  not present locally).

## 2026-06-25 — impeccable critique + fixes

- Detector only flagged the emissions-tier `border-l-4` stripes on the contract
  card — a false positive: DESIGN.md explicitly sanctions that single colored
  side-stripe (CO2 encoding), and it is pre-existing, not part of this work.
- Fixes applied from the critique:
  - Dark "Sinun sopimuksesi" anchor: secondary ink slate-400 → slate-300
    (DESIGN "Readable-By-Default" rule: never below slate-300 on slate-950).
  - Added recompute loading feedback (reused `<x-spinner>` bottom-right pill +
    `wire:loading.delay.class="opacity-50"` dim) — the listing previously gave no
    status feedback during debounced Livewire updates (Nielsen #1).
  - Bill-mode card now labels a spot contract's headline rate "Marginaali" (the
    annual `calculated_cost` is_spot flag is absent in period mode).
  - `aria-pressed` on the period preset chips (screen-reader toggle state).
- Verified desktop + mobile (390px) screenshots; decimal separators are correct
  Finnish commas. A one-off unstyled screenshot was a browser-session artifact
  from the asset-hash swap during `npm run build` (curl confirmed CSS 200).

## 2026-06-25 — Card showed two competing euro numbers (user feedback)

- The bill-mode card showed both `37,8 €/kk` (period ÷ months) and
  `39,09 € / laskutusjakso` (period total) — two euro figures of near-identical
  magnitude that read as conflicting "monthly" numbers (they differ only because
  the period isn't exactly 30 days).
- Fix: keep **€/kk as the single headline** (consistent with the anchor + the
  savings, both €/kk); drop the period-total line from the card face and move the
  exact period total into the "laskutusjaksollasi" caption tooltip. One euro
  figure per card now.
- Blade gotcha hit + fixed: `@if` glued directly to a word (`laskutusjaksollasi@if`)
  is not parsed as a directive (word boundary fails Blade's `\B@` match), leaving
  an unbalanced `@endif` → compile error. Build such labels in PHP
  (`$bcCaption = ... ? ... : ...`) instead of inline `@if` inside text/slots.
