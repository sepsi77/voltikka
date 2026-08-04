# Decisions

- Reuse Voltikka's existing guarded tracking path (`Livewire dispatch('track')` -> `resources/js/plausible-tracking.js` -> `window.plausible`) instead of adding page-specific inline JavaScript.
- Use the existing Plausible event name `Bill Comparison Completed` for in-listing bill comparison activations, with `source=contract_listing`, so the same goal can aggregate bill-comparison usage while props can segment contract-listing usage.
- Fire only on the `$billActive` false→true transition in `ContractsList::recomputeBill()` to avoid duplicate events while the visitor edits already-valid bill inputs.
- The browser bridge now treats the installed Livewire 3 callback value as the named-detail object. It also accepts the old one-element array shape at negligible cost.
- The dependency-free Node regression test executes the real `resources/js/plausible-tracking.js` in a hidden fake document, triggers `livewire:init`, and verifies the event name and nested `props` passed to Plausible. The hidden document prevents the engagement timer from keeping the test process open.
