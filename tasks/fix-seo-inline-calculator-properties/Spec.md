# Fix SeoContractsList inline calculator Livewire property errors

Sentry reports `PropertyNotFoundException` for `calcLivingArea`, `calcNumPeople`, and `calcBathroomHeatingArea` on the `seo-contracts-list` component when users edit inline calculator inputs on SEO contract listing pages such as `/sahkosopimus/aikasahko`.

Goal: make SEO listing inline calculator interactions safe and keep shared calculator behavior consistent with `ContractsList`.
