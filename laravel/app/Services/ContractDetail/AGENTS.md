# AGENTS.md

Context for contract-detail metadata and structured-data presentation.

See `../AGENTS.md` for service-subtree rules and `../../Livewire/AGENTS.md` for the page boundary.

## Purpose

`ContractDetailSeoPresenter` owns the ContractDetail page title, OG title, meta description,
canonical passthrough, and WebPage, Product, BreadcrumbList, and FAQPage JSON-LD policy.
`ContractDetailPresentationInput` is its immutable read input.

## Responsibility boundary

- Livewire keeps contract loading, active-state resolution, ranking, current price calculation,
  relational history loading, cheaper-contract queries, CO2 calculation, and visible FAQ generation.
- Livewire supplies only already-derived facts. The presenter does not query, depend on Livewire, or
  call a component.
- The presenter can read only preloaded `company` and `electricitySource` relations. An unloaded
  relation is treated as absent so presentation cannot cause a lazy query.
- The Livewire computed properties remain compatibility adapters over one request-local presenter
  result. The prepared detail payload keeps the same keys and v18 schema.

## Structured-data guardrails

- Product offers use only `currentDisplayValues` supplied by Livewire. In canonical mode those facts
  are canonical-only: missing values stay missing, and excluded pricing emits no offers.
- A monthly package excess rate is not an ordinary energy price. Keep its separate offer and included
  monthly kWh property.
- Product brand logos use only `Company::getLocalLogoUrl()`. Never expose an external seller logo in
  structured data.
- FAQPage must contain exactly the visible FAQ item question/answer pairs supplied in the input.
- Inactive contracts keep metadata but emit no WebPage, Product, BreadcrumbList, or FAQPage schema.
- Use tolerant contract classification accessors for in-memory decisions. Product property labels
  preserve the existing scalar output mapping and pass unknown values through.
