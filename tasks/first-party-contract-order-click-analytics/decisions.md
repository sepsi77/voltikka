# Decisions

## 2026-08-05 — First-party seller-click analytics scope

- The first durable first-party action is a click on either seller CTA on a
  contract detail page.
- Store company, contract, displayed annual price, selected consumption, live
  price rank, ranking-universe size, rank consumption, estimate state, pricing
  basis, CTA location, source, medium, campaign, and landing path.
- Use a dedicated typed `contract_order_clicks` table. Do not introduce a generic
  analytics event warehouse for one event type.
- The event records a CTA activation received by Voltikka. It does not claim that
  the seller page loaded or that a purchase occurred.

## 2026-08-05 — Local browser attribution instead of Plausible data

- Do not read from or join against Plausible. Its browser tracker does not expose
  Plausible's computed visit source, medium, or landing page.
- The first-party system is independent of Plausible. The existing
  `Contract Order Clicked` Plausible event can remain, but removing or importing
  it is outside this task.
- Store attribution under the versioned `localStorage` key
  `voltikka_attribution_v1`.
- Choose `localStorage` instead of `sessionStorage` so one 30-minute attribution
  session works across tabs. The implementation must enforce expiration itself.
- Do not create or store a durable visitor or session identifier. Copy the four
  attribution dimensions onto each durable click row.

## 2026-08-05 — Session and first-touch semantics

- Session inactivity timeout: 30 minutes.
- Keep first-touch source, medium, campaign, and landing path unchanged while the
  session is active.
- Update only `last_activity_at` during the session.
- A new external UTM campaign starts a new attribution session.
- Attribution precedence: UTM campaign, known organic-search referrer, other
  external referral, then direct.
- A same-origin referrer is not an acquisition source.
- Store the landing pathname only. Do not retain arbitrary query parameters,
  fragments, or full external referrer URLs.
- Storage parsing and writes must fail safely. Use an in-memory object for the
  current document when `localStorage` is blocked or malformed.

## 2026-08-05 — Price and rank facts

- The durable price is the annual comparison total shown for the visitor's
  selected consumption. Store it as decimal euros with the selected kWh.
- The durable rank is ContractDetail's live rank, not its SEO rank. Use
  `$liveRank`, `$liveTotalContracts`, and `$comparisonConsumption`.
- `$priceRank` is deliberately excluded because it is pinned to 5,000 kWh for
  stable SEO metadata.
- Store both selected consumption and rank consumption. They can differ when a
  custom value uses an exact contract price but the nearest supported preset for
  market-wide ranking.
- Null means that price or rank is unavailable. Never convert an unavailable
  fact to zero.

## 2026-08-05 — Integrity and delivery

- Sign the server-derived contract, company, price, and rank context. Do not trust
  browser replacements for these facts.
- The signed context lifetime must exceed the public edge stale-HTML window. The
  initial requirement is at least 72 hours.
- Attribution is client-provided analytics input. Normalize, validate, and
  length-limit it, but do not place it in the signed context.
- Send events with `navigator.sendBeacon()` and use keepalive `fetch` only as a
  fallback.
- Keep the original external seller URL as the CTA `href`. Do not use an internal
  tracking redirect because crawlers, prefetchers, and link scanners can inflate
  redirect counts.
- Use a client-generated `event_uuid` with a unique database constraint so
  retries are idempotent.
- A signed context proves that contract, company, price, and rank facts came
  from Voltikka. It is not a one-use click token. Public HTML can be replayed
  with new UUIDs, so the endpoint has a bounded IP rate limit. Reports are
  directional analytics and not proof of seller-page load, purchase, or fraud.
- The endpoint is generic at `POST /api/analytics/events`, stateless,
  rate-limited, and synchronous. Analytics failure must never cancel or delay
  seller navigation.
- Generic routing does not mean generic validation or storage. A closed event
  registry maps `contract_order_click` to its typed validator and recorder, and
  unknown event names are rejected.

## 2026-08-05 — Data minimization

- Do not persist raw IP addresses, user-agent strings, complete referrer URLs,
  full landing URLs, arbitrary query strings, visitor IDs, or session IDs.
- Company and contract names are stored as event-time snapshots so later source
  data changes do not rewrite reporting history.
- Contract ID is retained for grouping, but analytics history must not be removed
  through a cascading contract delete.
- The privacy policy must disclose the first-party cookieless measurement before
  production activation.

## 2026-08-05 — Private analytics administration

- Add an `/admin` area for viewing first-party analytics data.
- Filament is the selected direction because this is the start of a reusable
  internal admin area and the project has no current admin UI. Confirm package
  compatibility with the installed Laravel and Livewire versions before adding
  the dependency.
- Reuse the existing `User` model and add an explicit `is_admin` flag that
  defaults to false. Valid credentials alone do not grant panel access.
- The click resource is read-only. It has no create, edit, delete, bulk-delete,
  restore, or relation mutation actions.
- The first version provides a paginated newest-first table, search, and filters
  for date, contract, company, acquisition dimensions, and CTA location. Charts,
  funnels, exports, and scheduled reports are not required.
- Public registration stays disabled. Production admin provisioning is a
  separate, confirmed production data mutation and is not part of code deploy.
- If no maintained Filament release is compatible, stop and revise the plan. Do
  not silently build a second custom admin framework.

## 2026-08-05 — Indefinite durable-event retention

- Durable `contract_order_clicks` rows have indefinite retention at the initial release.
- Do not add an analytics cleanup command, job, or schedule.
- Revisit retention if the analytics purpose or stored fields change. A future finite-retention decision must also update the privacy policy and operational documentation.

## 2026-08-05 — Implemented dependency and admin boundary

- Composer resolved Filament 5.7.5 with Livewire 4.3.5 and Laravel 11.
- Composer runs `filament:upgrade` after autoload generation. The Docker build
  therefore publishes Filament package assets inside the image. Generated
  Filament files under `public/css`, `public/js`, and `public/fonts` are not
  tracked source files.
- `/admin` has login and logout but no registration. `User::canAccessPanel()`
  requires `is_admin=true`.
- The click resource has only an index page. It has no record or toolbar
  mutation actions. Text filters use exact input and do not load all distinct
  values from the indefinitely retained table.
- `ContractOrderClickHandler` uses `createOrFirst()`. Only duplicate UUIDs become
  idempotent success; unrelated database errors remain visible.
- Both CTA links track primary/keyboard and guarded middle-button activation
  without changing or delaying the seller URL.

## 2026-08-05 — Verification

- `npm run test:js`: 10 tests passed.
- `php artisan test --filter=AnalyticsEventTest`: 9 tests and 81 assertions passed.
- `php artisan test --filter=ContractOrderClickAdminTest`: 7 tests and 65 assertions passed.
- `php artisan test --filter=ContractDetailPageTest`: 91 tests and 308 assertions passed.
- `npm run build`: passed with Vite 6.4.1. The existing stale Browserslist-data notice remains informational.
- `composer validate --no-check-publish`: passed.
- Focused Pint check, route checks, and `git diff --check`: passed.
- The full Laravel run completed 1,877 tests: 1,870 passed and 7 failed in pre-existing canonical short-term/date-weighted pricing assertions. The failures were in `CanonicalOfferSurfacesTest`, `ContractApiCanonicalPricingTest`, `ContractDetailPresenterTest`, `ContractTypeComparisonTest`, and `WeeklyOffersCanonicalPricingTest`. This task did not change those pricing services or assertions. All analytics, admin, JavaScript, and affected ContractDetail tests pass.
