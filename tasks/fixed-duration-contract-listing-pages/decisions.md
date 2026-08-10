# Decisions

- Extend the established `SeoContractsList` surface and Voltikka visual system. Do not create separate Livewire components for each duration.
- Reuse precomputed statistics and forecast records. Do not calculate market prices during a listing request.
- Match each page to the existing statistics segments `fixed_term_6`, `fixed_term_12`, and `fixed_term_24`.
- Keep the page H1 focused on the broad duration query. Put the ranked results in a separate section with the exact H2 `Halvin {6|12|24} kk sähkösopimus` to also serve cheapest-contract intent.
- Show factual figures and dates where available. Do not let insight data affect contract sorting.
- Keep exact duration recognition closed to `Fixed6`, `Fixed12`, and `Fixed24`. Filter `contract_type=FixedTerm` and `fixed_time_range` directly, without product-name matching.
- Keep broad fixed-term insight behavior on the existing 12-month default. Exact pages pass their own duration to select null-consumption `energy_price` unit statistics and the matching eligible forecast.
- Put `Halvin {N} kk sähkösopimus` directly above both the normal results caption and the bill-mode current-contract area so the hierarchy does not disappear after bill entry.
- Use one `SeoContractsList` implementation and one shared Blade template. Add no navigation-menu items or new component classes.
- Keep the mobile hero free of horizontal overflow. Show trend and forecast dates per cell, scope the contract count to hintakehitys, and use the dark-surface 14 px readability floor for insight metadata.
- Use `Nousussa`, `Laskussa`, and `Vakaa` as the forecast direction labels. `Vakaata` is not a complete standalone label in this widget; the neutral headline is `Ennuste: vakaa hintataso`.
