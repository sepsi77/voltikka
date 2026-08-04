# First-party contract order-click analytics

## Background

Contract detail pages have two seller CTAs: the hero "Siirry myyjän sivuille"
link and the sticky "Myyjän sivuille" link. Both currently send the Plausible
custom event `Contract Order Clicked` with contract ID, company, and pricing
model. Voltikka does not persist these clicks in its own database.

The first first-party analytics event will record when a visitor follows one of
these CTAs. It must preserve the contract and price context shown at the time of
the click and the first-touch traffic attribution for the current browser
session.

## Goal

Persist one durable, queryable row for each accepted seller-CTA click with:

- company and contract identity
- the annual price shown for the selected consumption
- the live price rank and the size of its ranking universe
- the separate consumption bases for the displayed price and rank
- whether the displayed price is an estimate and its pricing basis
- CTA location (`hero` or `sticky`)
- session source, medium, campaign, and landing path

The event must not delay or replace navigation to the seller site.

## Product scope

### Included

- Contract detail pages only.
- Both outbound seller CTAs.
- Browser-side attribution stored in `localStorage`.
- A strict 30-minute inactivity timeout.
- One generic, stateless first-party event endpoint with an explicit event-type
  registry and event-specific validation.
- Typed event storage in Voltikka's database.
- A private Filament admin area for viewing and filtering the collected data.
- The existing Plausible event can remain, but the first-party implementation
  must not read from or depend on Plausible.

### Not included

- Seller-side purchase or lead confirmation.
- A public analytics dashboard.
- A reusable generic event warehouse or an endpoint that accepts arbitrary event
  names and unvalidated property bags.
- Long-lived visitor profiles.
- Cross-device attribution.
- A durable anonymous visitor or session ID.
- Importing Plausible Stats API or raw export data.

## Attribution storage

Use the versioned key `voltikka_attribution_v1` in `localStorage`.

The stored object has this logical shape:

```json
{
  "version": 1,
  "source": "google",
  "medium": "organic",
  "campaign": null,
  "landing_path": "/sahkosopimus/kiintea-hinta",
  "started_at": 1785571200000,
  "last_activity_at": 1785571500000
}
```

Requirements:

- Store timestamps as Unix milliseconds.
- Start a new attribution session when no valid object exists or inactivity is
  more than 30 minutes.
- Keep first-touch attribution unchanged during an active session.
- Update `last_activity_at` on page navigation and before sending the click.
- A new external campaign with UTM attribution starts a new session even when
  the previous session has not expired.
- Share the session across tabs through `localStorage`.
- Wrap storage access in `try/catch`. If storage is unavailable, keep a temporary
  in-memory attribution object for the current document. Tracking and seller
  navigation must not fail because storage is unavailable or malformed.
- Do not add a visitor or session identifier to this object.

## Attribution rules

Resolve attribution only when starting a session. Apply this order:

1. **UTM campaign:** use normalized `utm_source`, `utm_medium`, and
   `utm_campaign`. A missing campaign is null. A missing medium is `(none)`.
2. **Known search engine:** use the normalized provider as source and `organic`
   as medium. Start with an explicit small map for common engines such as Google,
   Bing, DuckDuckGo, Yahoo, Ecosia, and Brave Search.
3. **Other external referrer:** use the normalized referrer hostname as source
   and `referral` as medium.
4. **No external referrer:** use `direct` and `(none)`.

A same-origin referrer must not become a traffic source. If no valid stored
session exists in that case, use direct attribution.

Store only `window.location.pathname` as `landing_path`. Do not store the
fragment, arbitrary query parameters, or a full external referrer URL. Normalize
and length-limit every client-provided string again on the server.

## Contract click context

Price and rank are server-derived facts and must not be trusted as unsigned
browser values. Produce a versioned, server-signed click context for each
rendered ContractDetail state. It contains:

- context schema version
- contract ID
- contract name snapshot
- company name snapshot
- annual price in euros, nullable when no annual total is available
- selected consumption in kWh
- live price rank, nullable
- total contracts in the live ranking universe, nullable
- rank consumption in kWh, nullable
- estimate flag
- pricing basis, nullable
- issued-at and expiry timestamps

The context lifetime must tolerate Voltikka's public edge HTML stale window. Use
at least 72 hours unless the page-cache policy changes. Sign the complete payload
with a server secret. Do not put attribution data inside the signed context.

Use ContractDetail's live values:

- `$calculatedCost['total_cost']` for `annual_price_eur`
- `$consumption` for `consumption_kwh`
- `$liveRank` for `price_rank`
- `$liveTotalContracts` for `rank_total`
- `$comparisonConsumption` for `rank_consumption_kwh`

Do not use `$priceRank`; it is fixed at 5,000 kWh for SEO metadata. A custom
consumption can have an exact displayed price but a rank calculated at the
nearest supported preset, so both consumption fields are required.

## Event delivery

Use one shared JavaScript function for both CTAs.

On normal link activation:

1. Read or refresh the attribution object.
2. Generate a UUID `event_uuid` with `crypto.randomUUID()` and a safe fallback.
3. Send the event to Voltikka with `navigator.sendBeacon()`.
4. If Beacon is unavailable or rejects the payload, use
   `fetch(..., {method: 'POST', keepalive: true})`.
5. Let the direct external seller link open normally.

Do not replace the seller URL with a tracking redirect. Redirect endpoints can
count crawlers, security scanners, and prefetch requests as user clicks.

The delivery failure must never prevent seller navigation. The event reports a
CTA activation received by Voltikka; it does not prove that the seller page
loaded or that the visitor bought a contract.

## Endpoint

Add one generic stateless event-ingestion endpoint:

```text
POST /api/analytics/events
```

Use a stable request envelope such as:

```json
{
  "event_name": "contract_order_click",
  "event_uuid": "0198...",
  "context": "signed-server-context",
  "attribution": {
    "source": "google",
    "medium": "organic",
    "campaign": null,
    "landing_path": "/sahkosopimus/kiintea-hinta"
  },
  "page_path": "/sahkosopimus/sopimus/example",
  "placement": "hero"
}
```

The route is generic, but the server must not be a schema-free event sink. Map
each supported `event_name` through an explicit registry or closed enum to its
own validator and recorder. The first and only accepted type in this task is
`contract_order_click`. Unknown event names fail validation. A later event type
must add its own schema, handler, persistence decision, and tests.

The endpoint must:

- use no Laravel session or CSRF token
- accept only registered event names
- validate and verify the event type's signed context
- accept only known CTA locations for `contract_order_click`
- normalize and length-limit attribution fields and paths
- reject expired or modified contexts
- use `event_uuid` for idempotency inside the event type
- apply a bounded rate limit
- insert synchronously
- return `204 No Content` for an accepted event or duplicate event
- avoid logging request payloads

The endpoint can accept client attribution as untrusted analytics input. It must
not accept client replacements for signed price, rank, company, or contract
facts.

## Persistence

Create a dedicated `contract_order_clicks` table. Use typed columns instead of a
generic JSON properties column.

Suggested columns:

| Column | Type / rule |
|---|---|
| `id` | bigint primary key |
| `event_uuid` | UUID/string, unique |
| `occurred_at` | UTC timestamp, indexed |
| `contract_id` | string, indexed; historical snapshot, no cascading delete |
| `contract_name` | string snapshot |
| `company_name` | string snapshot, indexed |
| `annual_price_eur` | decimal, nullable |
| `consumption_kwh` | unsigned integer |
| `price_rank` | unsigned integer, nullable |
| `rank_total` | unsigned integer, nullable |
| `rank_consumption_kwh` | unsigned integer, nullable |
| `is_estimate` | boolean |
| `pricing_basis` | bounded string, nullable |
| `cta_location` | bounded string (`hero` or `sticky`) |
| `session_source` | bounded string |
| `session_medium` | bounded string |
| `session_campaign` | bounded string, nullable |
| `landing_path` | bounded string |
| `page_path` | bounded string |
| `created_at` | UTC timestamp |

Do not store raw IP addresses, user-agent strings, complete referrer URLs, full
landing URLs, or arbitrary query strings in the analytics table.

The event remains valid when price or rank is unavailable. Store null rather
than zero for unavailable numeric facts.

## Private admin area

Use Filament for the first Voltikka admin panel. Composer resolved Filament
5.7.5 with Livewire 4.3.5 and Laravel 11. The implementation installs the panel
packages needed for `/admin`; Composer publishes their generated assets during
the Docker image build.

The panel must:

- live under `/admin`
- use the existing `App\Models\User` authentication model
- have no public registration
- require an explicit `users.is_admin` boolean, default false
- deny panel access to every non-admin user even when credentials are valid
- use secure login and logout through Filament
- expose `ContractOrderClick` as a read-only resource
- offer no create, edit, delete, bulk-delete, restore, or relation mutation action
- default to newest clicks first
- paginate results and avoid loading the complete event table

The first resource must show:

- event time
- company and contract
- annual price and selected consumption
- rank as `rank / rank_total` and the rank-consumption basis
- estimate/pricing basis
- source, medium, and campaign
- landing path and event page path
- CTA location

Add native filters for date range, company, contract, source, medium, campaign,
and CTA location. Add search for company and contract. A simple count for the
selected result set is sufficient for the first version. Charts, funnels, CSV
exports, scheduled reports, and editable analytics records are outside this
scope.

Admin-user provisioning is an explicit operational action. Document the safe
command or process, but do not create or modify a production user during normal
code deployment. Any production user write requires separate confirmation.

Do not build a parallel custom admin framework for the same responsibility.

## Retention

Durable `contract_order_clicks` rows have indefinite retention at the initial
release. There is no automatic cleanup command, job, or schedule. Revisit this
decision if the analytics purpose or stored fields change.

The short-lived browser attribution expires logically after 30 minutes even
though the stale object can remain in `localStorage` until the next Voltikka
page load removes or replaces it.

## Likely implementation areas

- `laravel/resources/js/attribution.js` or a small equivalent module
- `laravel/resources/js/plausible-tracking.js` or a separate first-party click
  delivery module
- `laravel/resources/js/app.js`
- `laravel/app/Livewire/ContractDetail.php`
- `laravel/resources/views/livewire/contract-detail.blade.php`
- `laravel/routes/api.php`
- a generic analytics envelope controller and event registry, plus a focused
  contract-order-click validator, signer, recorder, model, and migration
- `laravel/composer.json` and `laravel/composer.lock` for a compatible Filament
  installation
- the Filament panel provider, read-only resource, authorization changes, and
  `users.is_admin` migration
- `laravel/tests/JavaScript/`
- `laravel/tests/Feature/`
- `laravel/resources/views/livewire/privacy-policy.blade.php`
- the closest `AGENTS.md` files for Livewire, services, and database semantics

## Test requirements

### JavaScript

Test the real attribution and delivery modules for:

- UTM precedence and normalization
- organic, referral, and direct classification
- first-touch preservation
- 30-minute expiration
- a new external campaign starting a new session
- same-origin referrer handling
- malformed and unavailable `localStorage`
- Beacon delivery and keepalive fetch fallback
- no navigation cancellation when delivery fails

### Laravel

Test:

- valid signed context insertion
- modified, malformed, and expired context rejection
- duplicate `event_uuid` idempotency
- hero and sticky CTA location validation
- nullable unavailable price/rank storage
- use of the live rank fields, not the fixed SEO rank
- attribution normalization and maximum lengths
- the generic stateless endpoint envelope and unknown-event rejection
- both CTA render locations using the shared first-party tracking path
- non-admin panel denial and admin panel access
- absence of create, edit, delete, and bulk mutation actions in the analytics
  resource
- admin search, filters, ordering, and pagination

## Acceptance criteria

- A normal click on either contract-detail seller CTA creates at most one local
  event row and still opens the original seller URL immediately.
- The row contains the company, contract, displayed annual price, selected
  consumption, live rank, rank total, rank consumption, and attribution facts.
- A custom consumption stores different price and rank bases when the page uses
  the nearest ranking preset.
- Attribution survives same-browser tabs and expires after 30 minutes of
  inactivity.
- Invalid storage data, blocked storage, or a failed analytics request does not
  break the page or CTA.
- Browser changes to price, rank, company, or contract values fail signature
  verification.
- The generic endpoint rejects unknown and malformed event types before any
  persistence.
- An authorized admin can browse and filter click rows at `/admin`; a normal or
  unauthenticated user cannot access the panel or mutate analytics records.
- No raw IP, user agent, complete referrer, arbitrary query string, visitor ID,
  or session ID is persisted.
- Relevant PHP and JavaScript tests pass, the production asset build passes, and
  documentation matches the shipped behavior.
