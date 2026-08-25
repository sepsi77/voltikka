# First-party analytics

This directory owns the closed first-party event endpoint and the contract-order-click event.
Read `../../../AGENTS.md`, `../../Http/AGENTS.md`, and `../../Livewire/AGENTS.md` first.

## Scope

The only accepted event name is `contract_order_click`.

Primary files:

- `AnalyticsEventName.php` is the closed event-name enum.
- `AnalyticsEventDispatcher.php` maps each enum case to one typed handler.
- `ContractOrderClickContextSigner.php` signs and verifies server facts.
- `ContractOrderClickHandler.php` validates, normalizes, and inserts one typed row.
- `../../Http/Controllers/Api/AnalyticsEventController.php` validates the generic envelope.
- `../../../resources/js/attribution.js` owns browser attribution.
- `../../../resources/js/first-party-analytics.js` owns Beacon and fetch delivery.

A new event type must add an enum case, a dispatcher branch, its own validation, a persistence decision, and tests. Do not change this endpoint into an arbitrary event or JSON-property store.

## Signed contract context

`ContractOrderClickContextSigner` uses a purpose-derived HMAC key from `APP_KEY`. It does not use the raw application key as event data. The token contains an exact version 1 schema and expires after 96 hours. This period is longer than the 72-hour minimum and tolerates the public stale HTML window.

`ContractDetail` signs these live facts:

- `calculatedCost.total_cost`
- selected `consumption`
- `liveRank`
- `liveTotalContracts`
- `rankConsumption()` (the view value is `comparisonConsumption`)

Do not use `priceRank`. It is fixed at 5,000 kWh for SEO. A custom selected consumption can have a different rank-consumption basis. Missing price and rank facts stay null.

Verification checks the signature, version, exact key order and schema, field types, issued time, expiry time, and maximum lifetime. Do not accept browser replacements for a contract, company, price, rank, estimate, or pricing-basis fact.

## Browser attribution

The browser key is `voltikka_attribution_v1`. The object has no visitor ID or session ID. It keeps first-touch source, medium, campaign, and pathname during one active attribution period. It updates `last_activity_at` on navigation and before a click.

The inactivity rule is strict: more than 30 minutes starts a new period. A changed UTM campaign also starts a new period. Resolution order is UTM, known organic search, other external referral, and direct. A same-origin referrer is direct when no valid stored attribution exists.

Storage reads, parsing, removal, and writes use safe failure boundaries. If `localStorage` is blocked, one in-memory object supports the current document. Store only a pathname. Do not store a query, fragment, full referrer, full URL, IP address, user agent, visitor ID, or session ID.

Both seller CTAs call `window.voltikkaAnalytics.trackContractOrderClick()` for primary/keyboard activation and guarded middle-button activation, and keep their direct seller `href`. The function uses `navigator.sendBeacon()` first and a `fetch()` request with `keepalive: true` only as a fallback. It does not prevent normal anchor activation. The existing Plausible event is separate and must not depend on this request.

## Endpoint and persistence

`POST /api/analytics/events` uses the stateless API middleware group and the named `analytics-events` rate limit. It has no Laravel session and no CSRF token. An accepted event and a duplicate UUID both return `204 No Content`.

`ContractOrderClickHandler` accepts only `hero` and `sticky`. It normalizes attribution text to lower case, removes control characters, applies database length limits, and strips query and fragment text from paths. It inserts synchronously with `event_uuid` as the idempotency key. It uses `createOrFirst()` so only a duplicate UUID is treated as success; other database errors must remain visible.

`occurred_at` stores UTC wall time. Existing rows and new handler writes use this rule. `ContractOrderClick` has an explicit UTC accessor because the generic Eloquent datetime cast can hydrate a database string in the application timezone. The accessor returns an immutable UTC Carbon value and writes assigned date values as UTC database strings. Do not change new writes to Helsinki time.

`contract_order_clicks` has typed columns and reporting indexes. It has no generic properties JSON. Durable rows have indefinite retention at the initial release. There is no cleanup command, job, or schedule. A later finite-retention decision must include an explicit migration or cleanup design and updated privacy text.

Do not log event request payloads. Do not add raw IP addresses, user agents, full referrers, query strings, visitor IDs, or session IDs to this table.
