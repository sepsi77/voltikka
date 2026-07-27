# Decisions

- The company page is a household-first comparison page. Household facts, offers, Spot tables, summaries, and the main card list use contracts targeted to `Household`, `Both`, or the legacy null target.
- The business section uses contracts targeted to `Company` or `Both`. A `Both` contract appears in both audience sections because it is available to both audiences.
- The unexplained company rank is removed from the title. The title and H1 lead with the `{company} sähkön hinta` search intent.
- `Päivitetty` uses the newest `contract_source_snapshots.last_observed_at` among the company's active contracts. This is the last source check, even when the payload did not change. For legacy rows without a source snapshot, use the newest stored `price_components.price_date`. Do not use request time.
- The company contact address is labelled as the company's reported address. It is not presented as a sales area.
- The existing market comparison remains household-only. No business-market benchmark is invented.
- `CompanyDetail::$contractsCache` keeps one calculated and sorted collection for all active company contracts. Household and business lists filter it in memory, so the business section does not add a second contract query or a second pricing path.
- Organization `areaServed` contains Finland only when an active contract has `availability_is_national=true`. The WebPage schema uses the same stored page-update date as the visible hero copy.
- The delivery-area section was implemented and then removed by user decision. The component no longer queries DSO or postcode availability for this page.
- The visible FAQ and its FAQPage schema were removed by user decision. Hidden FAQ structured data is not retained.
- The company page uses the main comparison page's compact annual-consumption selector. It supports the same four presets and a tolerant direct kWh input. Its calculator action opens the standalone calculator because this component does not contain the main listing's inline calculator.
