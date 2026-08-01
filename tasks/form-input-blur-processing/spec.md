# Process editable form inputs only on blur

## Goal

Prevent Livewire form recalculation, validation, requests, and other processing while a visitor is still typing. The Solar calculator electricity-price field is the known broken case, but the rule applies to all active Laravel forms.

## Requirements

- Editable text, number, email, telephone, URL, date, month, week, time, datetime-local, and textarea values must sync to Livewire only on blur.
- Do not use live, debounce, input, keyup, or change processing for those ordinary editable fields.
- Search and autocomplete fields are the deliberate exception: mark them with `data-search-input` and use live debounce so results update while the visitor types.
- Keep discrete controls such as checkboxes, radio buttons, selects, range sliders, files, and explicit action buttons on their native immediate interaction when that is required. These controls do not have a typing session to protect.
- Preserve current validation, normalization, calculation, query-string, analytics, and output behavior after blur.
- Numeric inputs that represent consumption, dimensions, people, prices, costs, usage, capacity, periods, or other non-negative quantities must reject or normalize negative values at the component boundary and define the correct HTML `min` value.
- A corrected invalid numeric value must produce a visible, accessible field notice. Do not silently calculate from a negative value.
- Keep legitimately signed quantities signed when the domain requires it.
- Add regression tests for the Solar electricity-price input, Consumption calculator negative values, and repository-wide guards that prevent these problems from returning.
- Update the relevant `AGENTS.md` files with the permanent rule and its reason.
- Do not change public calculation formulas or production data.
