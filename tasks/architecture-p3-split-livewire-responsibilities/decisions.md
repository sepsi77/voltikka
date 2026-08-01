# Decisions

## Initial decisions

- Start with ContractDetail because it has the highest responsibility count.
- Use presenters and query services only where a clear responsibility exists.
- Do not move interactive Livewire state into domain services.
- No implementation decision is final until the current behavior is confirmed with tests.

## Confirmed current behavior

- `ContractDetail` owns relational history queries, backward replacement traversal, version ordering, component labels/order, and historical promotion copy in addition to Livewire state and actions.
- Its prepared payload exposes `priceHistory`, `contractHistory`, `priceTypeLabels`, and `priceTypeOrder`; their shapes are public cache compatibility requirements.
- The focused relational-history baseline passes: 6 tests and 13 assertions.

## First extraction design

- Extract one `ContractPriceHistory\ContractHistoryPresenter::present(ElectricityContract)` service. It will own backward-chain loading and the four prepared history values without changing their array shapes.
- Keep the three Livewire computed history properties as thin compatibility adapters over one request-local presenter result.
- Preserve the recursive predecessor query, depth limit, eager loads, date ordering, positive-price preference, zero-row last-seen semantics, Spot/winter/unknown labels, and historical promotion formatting exactly.
- Keep history relational. Do not introduce canonical pricing fallbacks or move current pricing, redirect handling, or interactive state.
- Add direct presenter tests without Livewire hydration. Keep the existing page tests as end-to-end compatibility coverage.
- Do not bump the detail prepared-cache version because no payload key or value shape changes.

## First extraction result

- Added `ContractHistoryPresenter`, which owns the recursive backward-chain query, bounded bulk relation loading, relational price history, version summaries, labels/order, and historical promotion copy.
- `ContractDetail` now keeps thin computed compatibility adapters over one request-local cached presentation. Forward redirects, current pricing, price development, and interactive state remain in Livewire.
- Added four direct presenter tests with 29 assertions, including a 27-contract chain that proves the 25-level limit and bounded queries.
- The combined presenter and focused detail-history regression set passes: 11 tests and 43 assertions. Pint passes for the presenter, component, and tests.
- The prepared payload stays at v18 because all four key/value shapes are unchanged.
- This completes only the relational-history slice. SEO metadata/schema and generated-copy extraction remain pending before the architecture task is complete.

## SEO metadata and structured-data extraction

- Added `ContractDetail\ContractDetailSeoPresenter` and immutable `ContractDetailPresentationInput`. The input contains only the loaded contract plus already-derived active, display, ranking, consumption, current-price, calculated-cost, relational-history, cheaper-summary, exclusion, CO2, URL, and visible FAQ facts.
- The presenter owns page/OG titles, meta description, canonical passthrough, and WebPage, Product, BreadcrumbList, and FAQPage JSON-LD policy. It does not depend on Livewire, query, calculate prices, or lazy-load model relations.
- `ContractDetail` keeps thin computed compatibility adapters over one request-local presentation. Ranking, calculation, FAQ generation, history loading, redirects, bill comparison, and interactive state stay in Livewire or their existing services.
- Product offers still consume only the current display facts. Thus canonical omissions remain omitted, excluded contracts have no offers, monthly-package facts keep their special shape, and brand logos remain local-only.
- The prepared detail cache stays at v18 because no serialized key or value shape changed.
- Added five direct presenter tests with 27 assertions. The focused existing ContractDetail SEO/schema set passes 14 tests with 64 assertions. The full existing `ContractDetailPresenterTest` has 15 passing tests and only its known strict-float failure (`30.00000000000003` versus `30.0`).
- Pint passes for all four touched PHP files. `git diff --check` passes.
- A full `ContractDetailPageTest` run has 89 passing tests and one unrelated date-dependent unique-key collision in `test_spot_faq_answers_the_variation_question_only_with_real_history` on 2026-07-31. The focused SEO/schema tests pass, and this slice does not change Spot FAQ data setup.

## Final responsibility boundary

- The task is complete through two reviewable extractions: relational history/query preparation and SEO metadata/schema policy. Both units have direct tests without Livewire hydration, while the old computed-property and prepared-cache payloads remain compatible.
- Interactive state, redirects, selected-consumption ranking, bill comparison, and current price orchestration stay in Livewire as required.
- The visible FAQ, verdict, and terms methods remain in `ContractDetail` for now. They combine selected Livewire state and already-focused card/ranking services, so moving them would add a large context DTO without removing a query or pricing boundary. The task scope makes those extractions conditional on usefulness; they are not required for the current responsibility split.
- `ContractDetail` no longer owns the backward history query/presentation or metadata/schema policy. The prepared detail payload remains v18 because no public key or serialized value changed.
- Final Laravel regression: 1,842 tests pass. The only two failures are the previously documented date-dependent duplicate Spot-average fixture and strict floating-point equality assertion; neither is in an extracted presenter or changes public output.
