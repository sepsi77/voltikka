# Decisions

- Reuse Voltikka's existing guarded tracking path (`Livewire dispatch('track')` -> `resources/js/plausible-tracking.js` -> `window.plausible`) instead of adding page-specific inline JavaScript.
- Use the existing Plausible event name `Bill Comparison Completed` for in-listing bill comparison activations, with `source=contract_listing`, so the same goal can aggregate bill-comparison usage while props can segment contract-listing usage.
- Fire only on the `$billActive` false→true transition in `ContractsList::recomputeBill()` to avoid duplicate events while the visitor edits already-valid bill inputs.
