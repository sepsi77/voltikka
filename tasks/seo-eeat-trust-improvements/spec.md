# SEO / E-E-A-T trust improvements

## Background
An external SEO review of `/sahkosopimus` flagged that Voltikka (a YMYL money page) lacks
publisher identity, editorial accountability, funding disclosure, and methodology transparency,
and uses an unsubstantiated superlative ("Suomen kattavin"). These cap the trust score for a site
that ranks where people send their monthly electricity money.

## Truth to disclose
Voltikka is a **self-funded personal hobby project** (≈20 €/kk hosting, no revenue, no commissions,
no ad money). We disclose exactly that, honestly, while **keeping the owner's name off the site**.
Started 2026 (first commit 2026-01-19).

## Decisions (from user)
- Contact published on site: **voltikka7@gmail.com**
- Superlative: **tie to real live counts and hedge** → "yksi Suomen kattavimmista".
- Structure: **one combined `/tietoa` page** (About + funding + methodology with anchor sections).

## Hard constraints
- NEVER reference "Energiavirasto" or the Azure intermediary API in public copy (project memory).
  Contract data attributed only as "sähköyhtiöiden julkisesti saatavilla olevista hinnastoista".
- Spot prices attributed to ENTSO-E; spot forecasts to `vividfog/nordpool-predict-fi` (GitHub URL).

## Verified facts (for accurate copy)
- Cost formula = energia (c/kWh × kulutus) + perusmaksu × 12, incl. promos. No grid-transfer term in
  `ContractPriceCalculator` → "siirtomaksu ei sisälly" is accurate.
- Prices are VAT-inclusive (25.5%): spot inputs use `day_avg_with_tax`/`night_avg_with_tax`; contract
  energy components (14–20 c/kWh for default-supply) are typical Finnish VAT-incl retail figures.
- Spot "· arvio" uses `SpotPriceAverage::latestRolling365Days()` (rolling 12-month realized avg) + margin.
- "Säästö €/v" baseline = base annual cost − discounted annual cost = the contract's own first-year
  promo discount (NOT vs market/competitors). From `ContractPriceCalculator::discountSavingsTotal`.
- Live counts: `ElectricityContract::active()->count()`, `Company::count()` (see HomePage:22-23).
- Refresh schedule (routes/console.php): contracts daily 06:00; spot hourly.

## Out of scope
- No-JS/server-rendered listing fallback (Priority 4) — larger task, deferred.
- Naming a person / Y-tunnus / legal entity — intentionally omitted; no company exists.
</content>
</invoke>
