# Decisions

- The compact selector in `resources/views/livewire/seo-contracts-list.blade.php` was the incumbent source of truth. The cheapest page contained an older copied selector, which caused the visible inconsistency.
- `resources/views/partials/contract-consumption-selector.blade.php` is now the final source of truth for the complete selector UI. `ContractsList`, `SeoContractsList`, and `CheapestContracts` include it, so preset, direct-input, calculator, notice, and mobile-collapse markup cannot drift.
- Household and cheapest views pass `isBusinessPage=false` and `showCalculatorTab=true`. The SEO view passes its page-specific values, so business labels and calculator visibility stay unchanged.
- Focused Livewire coverage checks that the cheapest view renders the blur-bound direct input and compact labels, and does not render the old heading or preset-tab label.
