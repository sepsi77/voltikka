# Decisions

## One fixed GTM event name

Every app event is `event: 'voltikka_event'` with `event_name` and `event_props`. One Custom Event trigger forwards all events to Plausible. A trigger for a single event adds the condition `event_name equals ...`. If the Plausible name were the GTM `event` itself, a catch-all trigger would also match GTM's own `gtm.*` events.

## Reset `event_props` before each event

GTM merges nested objects in its data model. Without a reset, a key from an earlier event would stay in `event_props` of a later event that does not send that key. The bridge first pushes `{ event_props: null }`.

## No `interactive: true` option

`Engaged` sent `interactive: true` to Plausible. That is the Plausible default for custom events, so the data layer does not carry it.

## No noscript iframe

The GTM `<noscript>` iframe only serves noscript tags. Plausible needs JavaScript, so it is left out.

## Plausible gallery template for Initialization, Custom HTML for events (2026-10-30)

The workspace had the official Plausible template. It loads the script and calls `plausible.init()` with explicit options. The site script (`pa-pDXhgmi5eZqoIa4scLqJt.js`) has defaults `formSubmissions`, `fileDownloads` and `outboundLinks` true and merges init options over them, so the template's default checkboxes give the same tracking as the old hard-coded snippet. The template's Custom Event type is not used: its props table needs one row per key and drops falsy values. One Custom HTML tag forwards `event_name` and the whole `event_props` object.

## Privacy policy names Google Tag Manager (user decision 2026-10-30)

The analytics section says that Plausible is loaded through Google Tag Manager, that Tag Manager sets no cookies and collects no usage statistics, and that Google receives the ordinary technical data of the script request. `PrivacyPolicy::LAST_UPDATED` is 2026-10-30; if the release is much later, set the release date.
