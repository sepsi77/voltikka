# First-party analytics

This directory owns the closed first-party event endpoint, the contract-order-click event and the page-feedback event ("Löysitkö etsimäsi?").
Read `../../../AGENTS.md`, `../../Http/AGENTS.md`, and `../../Livewire/AGENTS.md` first.

## Scope

The accepted event names are `contract_order_click` and `page_feedback`.

Primary files:

- `AnalyticsEventName.php` is the closed event-name enum.
- `AnalyticsEventDispatcher.php` maps each enum case to one typed handler.
- `ContractOrderClickContextSigner.php` signs and verifies server facts.
- `ContractOrderClickHandler.php` validates, normalizes, and inserts one typed row.
- `AnalyticsContextToken.php` is the shared HMAC token format; each signer passes its own purpose string.
- `NormalizesAnalyticsInput.php` is the shared attribution/path normalization of both handlers.
- `PageFeedbackCatalog.php`, `PageFeedbackContextSigner.php` and `PageFeedbackHandler.php` own the feedback event (see "Page feedback").
- `../../Http/Controllers/Api/AnalyticsEventController.php` validates the generic envelope (event name, UUID, context, attribution, page path) and passes the request to the typed handler. Each handler validates its own fields; `placement` is a contract-click field.
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

Both seller CTAs call `window.voltikkaAnalytics.trackContractOrderClick()` for primary/keyboard activation and guarded middle-button activation, and keep their direct seller `href`. The function uses `navigator.sendBeacon()` first and a `fetch()` request with `keepalive: true` only as a fallback. It does not prevent normal anchor activation. The existing data-layer event `Contract Order Clicked` is separate and must not depend on this request.

## Endpoint and persistence

`POST /api/analytics/events` uses the stateless API middleware group and the named `analytics-events` rate limit. It has no Laravel session and no CSRF token. An accepted event and a duplicate UUID both return `204 No Content`.

`ContractOrderClickHandler` accepts only `hero` and `sticky`. It normalizes attribution text to lower case, removes control characters, applies database length limits, and strips query and fragment text from paths. It inserts synchronously with `event_uuid` as the idempotency key. It uses `createOrFirst()` so only a duplicate UUID is treated as success; other database errors must remain visible.

`occurred_at` stores UTC wall time. Existing rows and new handler writes use this rule. `ContractOrderClick` has an explicit UTC accessor because the generic Eloquent datetime cast can hydrate a database string in the application timezone. The accessor returns an immutable UTC Carbon value and writes assigned date values as UTC database strings. Do not change new writes to Helsinki time.

`contract_order_clicks` has typed columns and reporting indexes. It has no generic properties JSON. Durable rows have indefinite retention at the initial release. There is no cleanup command, job, or schedule. A later finite-retention decision must include an explicit migration or cleanup design and updated privacy text.

Do not log event request payloads. Do not add raw IP addresses, user agents, full referrers, query strings, visitor IDs, or session IDs to this table.

## Page feedback ("Löysitkö etsimäsi?")

User decisions (2026-10-28, `../../../../tasks/site-feedback/`): every public page except the legal pages asks the question; reasons and goals are tailored to the page type; a goal question follows "Kyllä" and is the last step after "En"; free text is deleted after 12 months; the design is the Paper page "Feedback · Löysitkö etsimäsi?".

- `PageFeedbackCatalog` is the one source for page types, reason sets and goal codes. The Blade component shows its labels and the handler accepts only the codes of the signed page type, so a solar page can never store a contract reason. Codes are stored, labels are not. "Jokin muu" (`other`) is always last.
- `PageFeedbackContextSigner` signs only the page type and an optional subject (contract ID or company slug), 96-hour lifetime like the click context. Listing facts (consumption, pricing filter, result count, postcode used, bill mode, page) are unsigned display state read from the list element at vote time; the handler accepts them only on `listing` and only in closed shapes. The postcode itself is never stored.
- One browser UUID is one row. Step `vote` creates it (`createOrFirst`). Steps `reason` (only after "down") and `goal` fill their columns once within 120 minutes on the same page type; a late, repeated or out-of-order follow-up is a quiet no-op, never an overwrite. The 404 page (`not_found`) has no vote; its `goal` step creates the row with a null vote.
- Each answer stores the shown position of the chosen option. The browser shuffles the options (except "Jokin muu") so the first-option bias can be measured, and no option is pre-selected.
- Free text is limited to 500 characters and can hold personal data. `feedback:purge-text` (daily 02:15 Helsinki) nulls `reason_text`/`goal_text` after 12 months; the codes stay. The privacy page states this. Never log the text.
- `../../../resources/js/page-feedback.js` sends each step once through the shared Beacon/fetch sender and also sends the data-layer events `Feedback Vote`, `Feedback Reason` and `Feedback Goal` with codes only (never text). After a vote the widget is hidden site-wide for 30 days, after "Ei nyt" for 7 days (`voltikka_feedback_v1` holds only the `until` time).
- `../../../resources/views/components/page-feedback.blade.php` renders all steps hidden, with `wire:ignore` so a Livewire re-render keeps the visitor's step. Placement is per page type and explicit in each template; `tests/Feature/PageFeedbackWidgetTest.php` lists the templates. Listings put it at ledger slot 5 (after entry 4); calculators only after a result.

## Google Tag Manager data layer

Plausible is not in the app code. `resources/views/layouts/app.blade.php` loads the GTM container from `services.google_tag_manager.container_id` (`GTM_CONTAINER_ID`); without an ID no GTM script renders. Plausible is a tag in that container (setup: `../../../../tasks/google-tag-manager/gtm-setup.md`).

- `../../../resources/js/analytics-tracking.js` owns `window.voltikkaTrack(eventName, { props })`, the Alpine `$track` magic, the Livewire `dispatch('track', eventName:, props:)` bridge and the 30-second `Engaged` event. Every call site goes through it; do not call `window.plausible` or push to `dataLayer` elsewhere.
- Each call pushes `{ event_props: null }` and then `{ event: 'voltikka_event', event_name, event_props }`. The fixed GTM event name lets one trigger forward all events; the reset is needed because GTM merges nested objects across pushes, so an old key would otherwise leak into a later event.
- Only values inside `props` are sent. Props carry codes and ids only, never visitor text.
- `tests/JavaScript/analytics-tracking.test.js` checks the push shape; `tests/Feature/GoogleTagManagerLayoutTest.php` checks the container snippet.
