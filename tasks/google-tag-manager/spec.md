# Google Tag Manager instead of hard-coded Plausible

## Goal

Load analytics through Google Tag Manager (GTM). Plausible is added as a GTM tag. Application code sends data-layer events, which GTM uses as triggers; it does not call `window.plausible` directly.

## Requirements

- The layout loads the GTM container from `GTM_CONTAINER_ID` (`services.google_tag_manager.container_id`). No ID means no GTM script (local, tests).
- The hard-coded Plausible script is removed from the layout.
- `window.voltikkaTrack(eventName, { props })` keeps its signature, so Blade `$track`, Livewire `dispatch('track', ...)`, page feedback and quote copy do not change. It pushes `{ event: 'voltikka_event', event_name, event_props }` to `window.dataLayer`.
- The engagement timer (`Engaged`) stays.
- First-party analytics stays independent of GTM.
- The GTM container setup is documented in `gtm-setup.md`.
