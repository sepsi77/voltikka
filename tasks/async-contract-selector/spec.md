# Async contract selector for editorial comparison widget

Problem: `/sahkosopimus/kannattaako-porssisahko` embeds `ContractTypeComparison`, whose Blade view server-renders every available contract in two `<select>` elements. This creates a large, low-quality DOM dump of contract names for crawlers and is not ideal UX.

Goal: replace the giant server-rendered dropdown option lists with an interaction-gated searchable async selector. Initial HTML should include only the currently selected/auto-cheapest contracts and no full contract-name list.

Scope:
- Update `ContractTypeComparison` Livewire component and view.
- Preserve current comparison behavior: auto-select cheapest by default; user can choose a specific contract for A/B.
- Keep visible loading feedback for mode/consumption/selection changes.
- Avoid rendering all contract names into initial DOM.
