# GTM container setup for Plausible

The app pushes one GTM event for every analytics event:

```js
{ event: 'voltikka_event', event_name: 'Contract Order Clicked', event_props: { contract_id: '…', company: '…' } }
```

Event names and props are the same as the earlier Plausible calls, so the Plausible goals stay valid.

## 1. Import

`gtm-container-import.json` is built from the workspace export `GTM-T6BLZDB7_workspace3.json` (container 284340568). It keeps the Plausible gallery template (`cvt_284340568_3`) and adds:

| Kind | Name | Content |
|---|---|---|
| Variable | `DLV - event_name` | Data Layer Variable `event_name`, version 2 |
| Variable | `DLV - event_props` | Data Layer Variable `event_props`, version 2 |
| Trigger | `CE - voltikka_event` | Custom Event `voltikka_event` |
| Tag | `Plausible - Initialization` | Plausible template, type Initialization, Script ID `pa-pDXhgmi5eZqoIa4scLqJt`, trigger All Pages |
| Tag | `Plausible - voltikka_event` | Custom HTML, trigger `CE - voltikka_event` |

GTM: Admin → Import Container → choose the file → existing workspace → Merge → Overwrite conflicting tags, triggers and variables.

Initialization options: File Downloads, Form Submissions and Outbound Links are on, Hash Based Routing is off. These are the same values as the defaults in the site script (`domain: "voltikka.fi", formSubmissions: true, fileDownloads: true, outboundLinks: true`, read from the script on 2026-10-30). The script merges the init options over its defaults, so tracking does not change. If you change these settings in the Plausible dashboard, change the tag too, because the tag values win.

The event tag:

```html
<script>
  window.plausible = window.plausible || function () { (window.plausible.q = window.plausible.q || []).push(arguments); };
  window.plausible({{DLV - event_name}}, { props: {{DLV - event_props}} || {} });
</script>
```

The template's own Custom Event type is not used for app events. It takes props as a fixed table, one row per key, and it drops falsy values (`includes_vat: false` would be lost). The app sends more than 30 keys, and new keys would need a GTM change each time. The Custom HTML tag sends the whole props object. The stub line makes it safe if it fires before the script loads. Keep it ES5 for the GTM Custom HTML parser.

For a trigger on one event only (for example a later Google Ads conversion), use a Custom Event trigger on `voltikka_event` with the condition `DLV - event_name` equals `Contract Order Clicked`.

## 2. Check and publish

1. Run the app locally with `GTM_CONTAINER_ID` in `laravel/.env` and open GTM Preview (Tag Assistant).
2. Load a page: "Plausible - Initialization" fires on Container Loaded (All Pages).
3. Click a consumption preset on `/sahkosopimus`: a `voltikka_event` appears with `event_name` `Contracts Preset Changed`, and "Plausible - voltikka_event" fires.
4. On localhost, Plausible does not send: the browser console shows `Ignoring Event: localhost` for the page view and the event. That is correct ("Capture on Localhost" is off). To see the `plausible.io/api/event` requests, use Preview on the production site after the deploy.
5. Publish the container.

## 3. Release order

1. Publish the container (sections 1–2). The production site does not load it yet, so nothing is counted twice.
2. Set `GTM_CONTAINER_ID` on the Railway `voltikka` service (a production variable write: needs explicit approval).
3. Build and deploy the image (needs explicit approval). Before the build, retain the currently deployed `app-*.js` and `app-*.css` (see "Edge HTML caching vs hashed assets" in `laravel/AGENTS.md`), because this change gives the app JS a new hash.
4. After the deploy, check that production loads `gtm.js` and that Plausible shows page views and events in real time.

If the variable is missing at deploy, the site sends no analytics until the variable is set.
