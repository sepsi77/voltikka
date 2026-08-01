# Decisions

## Scope

- “Process on blur” applies to ordinary controls in which a visitor enters a value. It means the browser must not send a Livewire update or invoke calculation/validation while typing.
- Search and autocomplete fields are explicitly marked with `data-search-input` and use live debounce. Waiting for blur makes a search result list appear only after the visitor leaves the field, which is not usable.
- Checkboxes, radio buttons, selects, range sliders, file inputs, and explicit buttons remain immediate controls. Waiting for blur on those controls would hide the result of a complete discrete choice and can break keyboard use.
- The permanent prevention mechanism will be a repository-wide test over Laravel Blade templates, not a JavaScript wrapper or generic form framework. The test also covers currently unused templates so an old form cannot return with live typing behavior when it is reused.

## Audit

- The active violations are in the Solar and heat-pump calculators, the shared in-listing/detail bill form, cheapest-contract consumption, company/location search, and contract-type search.
- The old `contracts-list.blade.php` template is not on an active route, but it has the same debounced numeric bindings. It is included in the change and test because it can otherwise reintroduce the defect if reused.
- Existing typed inputs in the standalone bill, consumption, SEO listing, company-detail, and contract-detail views already use `wire:model.blur`.
- No active `wire:input`, `wire:change`, Alpine-to-Livewire input/change action, or custom JavaScript input/change processing was found.

## Implementation

- Replaced live/debounced ordinary editable bindings with `wire:model.blur` in the Solar and heat-pump calculators, shared bill form, cheapest-contract page, and dormant contract-list template.
- Solar address autocomplete, company/location search, and contract-type search remain live-debounced and carry `data-search-input`, so the policy test can distinguish intentional search behavior from accidental per-keystroke calculation.
- Preset buttons and complete discrete controls remain immediate. Checkboxes and selects intentionally keep `wire:model.live` where the selected choice must update the form at once.
- Added `tests/Unit/FormInputBlurPolicyTest.php`. It scans every Blade input and textarea, requires exactly `wire:model.blur` for editable model bindings, rejects `wire:input`/`wire:change`, and rejects Alpine input/change handlers that call `$wire`. It also names the three Solar fields directly.
- The first blur-only pass also moved search inputs to blur. This was corrected after review: address autocomplete and company, location, and contract searches are live-debounced marked search inputs. Ordinary values remain blur-only.

## Numeric input hardening

- Every current number input already had an HTML `min`, but HTML constraints do not protect a Livewire request. The permanent policy test now requires a non-negative minimum for every number/range input unless it has the documented `data-allow-negative` marker.
- Consumption calculator values were already clamped to 20 m², 1 person, or 0, but the correction was silent. The component now keeps field-specific `numericNotices`, writes the corrected value back, and renders adjacent accessible alerts.
- Heat-pump room height, people, prices, investments, interest, and period now clamp before DTO construction and use the existing visible form error area. Living area and active bill quantities retain their specific validation errors.
- Solar electricity price now shows a correction notice when it is clamped to the supported 0–50 c/kWh range.
- Shared bill kWh/total fields reject non-positive values, clear the invalid value, and render adjacent errors. Standalone optional annual kWh rejects a negative value visibly.
- Listing inline calculator dimensions/extras and direct-consumption inputs now write corrected non-negative values back before calculation and show visible notices. Company direct consumption follows the same rule.

## Verification

- Focused input regression: 260 tests and 871 assertions pass.
- Full Laravel regression: 1,855 tests and 6,590 assertions pass.
- `FormInputBlurPolicyTest` passes with three repository-wide checks: ordinary/search update boundaries, non-negative numeric minima, and explicit Solar behavior.
- Final targeted Pint, Impeccable detector, and `git diff --check` pass.
