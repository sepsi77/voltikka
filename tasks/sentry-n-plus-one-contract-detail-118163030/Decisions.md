# Decisions


## 2026-05-07

- This issue is separate from the `SeoContractsList` card fix. Contract detail already eager-loads the main contract relations, but inactive replacement redirects and history-chain discovery could still walk replacement links through repeated relation queries.
- `ContractDetail` now resolves forward replacement chains for inactive redirects with a recursive CTE and then bulk-loads candidate replacements with `activeContract`.
- Contract history now discovers backward replacement-chain IDs with a recursive CTE and then eager-loads all visible versions with `company`, `priceComponents`, and `activeContract` in fixed relation queries.
- Added regression tests that render replacement history and inactive redirect chains while asserting bounded `price_components` / `active_contracts` query counts.
