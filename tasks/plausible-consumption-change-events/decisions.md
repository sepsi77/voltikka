# Decisions

- Add a dedicated Plausible event named `Contracts Consumption Changed` for contract comparison listings.
- Dispatch through the existing Livewire `track` event / `window.voltikkaTrack` Plausible wrapper instead of inline JavaScript.
- Include raw `consumption` in props because the analytics question is the typical entered kWh/year; also include `method` (`preset`, `direct`, `calculator`), `source=contract_listing`, and `base_path` for segmentation.
- Fire only when the numeric consumption value actually changes, so clicking the already-selected/current value does not create duplicate analytics events.
