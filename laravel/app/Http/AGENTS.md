# Public HTTP API

This directory contains public controllers and JSON resources. Read `../../AGENTS.md` and
`../../../AGENTS.md` first.

## First-party analytics event API

Primary files:

- `Controllers/Api/AnalyticsEventController.php`
- `../Services/Analytics/AGENTS.md`
- `../../routes/api.php`

`POST /api/analytics/events` is a stateless, named-rate-limited ingestion route. It does not use a Laravel session or CSRF token. The generic envelope accepts only event names in `AnalyticsEventName`; each event then uses its own validator and handler. The only current event is `contract_order_click`. Accepted and duplicate UUID events return 204.

Do not make this a schema-free event sink. Do not log request payloads. The client attribution is untrusted input. The handler normalizes it and uses only the server-signed contract, company, price, rank, estimate, and pricing-basis facts. See the Analytics context file for signature, retention, privacy, and data-minimization rules.

## Contract list and show API pricing

Primary files:

- `Controllers/Api/ContractController.php`
- `Resources/ContractResource.php`
- `Resources/PriceComponentResource.php`

When `CANONICAL_PRICING_ENABLED=true`:

- `GET /api/contracts` and `GET /api/contracts/{id}` expose typed canonical unit, package,
  comparability, estimate, exclusion, and integrity facts in `current_pricing`. Market-reset,
  supplier-adjusted, and forward-Spot estimates remain separate typed payloads.
- They omit `price_components`. Do not synthesize `PriceComponentResource` rows from canonical
  phases and do not fill a missing canonical field from a relational row.
- A valid numeric `consumption` still controls whether top-level `calculated_cost` is returned.
  The payload is the unchanged `CanonicalPricingOutcome::toCalculatedCostArray()` shape.
- An excluded outcome has `current_pricing.availability = unavailable`, its comparability value
  as `exclusion_reason`, null current unit/package values, and a null calculated total when a
  calculation was requested. Its integrity object keeps only the typed detected/reason/issue state;
  price-bearing integrity fields and generated fact text are not returned.
- List/show GET and HEAD read `ContractListCacheService::getCachedMetrics()` only. Requested
  consumption selects its exact cached set; an absent consumption uses the 5,000 kWh reference and
  omits `calculated_cost`. Current-pricing facts come from typed `ContractMetric` / pricing access.
  Cached canonical facts require integrity. Missing/custom/inactive/new-ID pricing is unavailable;
  it never triggers an annual batch, local evaluation, raw fallback, or zero-price result. Missing
  preset generations use the shared typed 503 boundary. Null totals sort last.
- `pricing_has_discounts` is derived from the canonical outcome. Package allowance pricing is not
  a promotion.
- Source-backed results also expose `energy_rule_comparison`, `benefit_is_estimate` and the shared
  typed `offer` facts. Actual price certainty is separate from normal-comparison certainty. The
  current unit fields never use the annual equivalent. Actual-only estimated continuations carry
  no invented normal tariff or savings. Projected normal comparisons omit price-bearing promotion
  integrity claims; typed detection/reason/issue facts remain.

When the feature is off, the explicit legacy branch loads and returns relational
`price_components` and reads cached legacy annual metrics. Keep that compatibility path until the
feature flag is retired by a separate decision.

There is no contract API response cache. A change to this shape does not require an application
cache-version bump.

## Contract statistics CSV

`GET /sahkosopimus/tilastot.csv` is an audit export. It includes every daily-statistics method
version, annual calculation/estimate/compatibility provenance, JSON `basis_counts`, and an
`is_active_annual_method` marker. Only annual rows at the configured active method get marker `1`;
unit rows remain `unit_statistics_v1` with marker `0`. Public pages still filter annual output to
the active method even though the CSV exposes shadow versions.

The export uses one sorted Eloquent cursor, not sorted OFFSET chunks: all-version history made
repeated sorting exceed the request time limit. Keep the existing date/segment/metric/consumption/
method order and model casts. On MySQL, disable `MYSQL_ATTR_USE_BUFFERED_QUERY` only on the
query connection's resolved read PDO during iteration, then restore its previous value in
`finally`. Release the iterator (and its PDO statement) before restoration or later queries,
including on hydration/output failure. Check attribute-setting failures. SQLite skips the MySQL
attribute. Do not add queries or relationship loading inside this unbuffered loop, global timeout
changes, or a separate connection. Regression tests cover 540 reverse-inserted rows and use a PDO
test double over SQLite to check read-PDO selection, statement release, and both prior buffer
settings on success and failure. A real MySQL performance check is separate release evidence.

## Company resource logos

`Resources/CompanyResource` returns `Company::getLogoUrl()` instead of the raw upstream
`logo_url`, so a locally stored and optimized logo wins. External-only URLs can remain a visible
API fallback, but public Product, ItemList, and Organization JSON-LD accepts only
`Company::getLocalLogoUrl()` and omits unverified external images.

## Calculation API pricing

`POST /api/calculate-price` loads only the contract row before it selects its pricing source. In
canonical mode it evaluates the published canonical JSON, adapts the outcome through
`ContractPricingViewData::fromCanonicalOutcome()`, and must not eager-load or query
`price_components`. In feature-off mode, the existing model helper loads the latest relational
components and adapts the legacy result through `fromLegacyResult()` before it serializes the
unchanged response. Keep this branch boundary explicit.

Detailed `energy_usage` always requires `total`, including when `consumption` is also supplied.
The total is authoritative. Missing component consumption is added to `basic_living`; a component
sum above the total returns a 422 validation error on `energy_usage.total`. Only validated
snake-case component fields reach the DTO. `basicLiving` can be fractional so a fractional cooling
input does not lose the remaining fraction through integer conversion. The shared DTO does not
normalize other callers.

An optional heating array requires `room_heating` and exactly the calendar keys 0 through 11.
Each value must be finite, numeric, and non-negative. Values are weights, normalized to the supplied
room-heating total. Positive room heating requires a finite positive weight sum. Zero room heating
produces a zero monthly distribution; no array means the calculator uses its normal heating profile.
This policy accepts partial breakdowns without losing annual consumption or silently ignoring an
array for which the room-heating branch cannot run.

The calculation API has no response cache. Shared annual list/company payloads use non-expiring
generation writes with schema and pricing-mode markers, not a calculation-day suffix or a 48-hour
TTL. Rankings have a one-hour wrapper TTL but rebuild from retained annual metrics. Verified
replacement activates one generation; retired tracked payloads receive one hour of reader grace
before bounded cleanup. Public prices remain cached while background producers replace them.
Uncached custom GET consumption is unavailable; only explicit user actions can calculate exact
prices through `PublicPriceCalculationPolicy`. The explicit calculation POST remains unchanged.
See `../Services/Caching/AGENTS.md`.
This guidance does not change unrelated CompanyList prepared-data cache lifetimes.

## Weekly-offers video API pricing

`GET /api/video/weekly-offers` returns `data.pricing_basis`. In canonical mode, each offer contains
only typed canonical `pricing`, per-consumption totals/normal totals/monthly averages, comparability,
estimate state, and measured customer benefit. It does not return the legacy `discount`, `costs`, or
`savings` fields and it must not query `price_components`. Short fixed terms use the actual term
benefit and identify annualized totals as comparison values. The feature-off response keeps the old
relational payload for compatibility with staged rollback. This endpoint has no response cache, so
this response change needs no cache-version bump.

Both video modes read the shared 2,000/5,000/10,000 kWh sets. A generation check before and after
each read prevents mixed-generation prices. Promotion retries are limited to two cache-only attempts;
exhaustion or a missing preset returns 503 with Retry-After and no-store. Missing contract profiles
are ineligible. Canonical ranking, one-company selection, benefit/term facts, and public fields stay
unchanged. Legacy costs and savings use retained metric values, not annual calculation.

The internal `ContractTypeComparison` widget also reads retained typed pricing during public GET,
HEAD, and automatic Livewire POST initialization. Candidate selection, annual/monthly charts,
current rates/packages and benefit/estimate copy use the same view data. No monthly series is
reconstructed. Typed exclusion comparability remains in unavailable chart/display data, without
exposing a priced excluded side. Actual consumption/mode/selection action hooks grant the shared request-local
permission; explicit legacy actions retain their previous monthly math. Unavailable sides stop
comparison, not a zero or sentinel winner. Tests: `OtherPublicGetPriceCacheTest`.
