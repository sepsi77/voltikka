# Decisions

- The contract-detail Sentry N+1 is likely from relation helper methods that ignored already eager-loaded relations.
- `ContractDetail` now eager-loads `activeContract` for the main contract and history contracts.
- `ElectricityContract::isActive()` now uses a loaded `activeContract` relation before falling back to an exists query, avoiding one `active_contracts` query per history row.
- `ElectricityContract::getReplacementChainBackward()` now batches predecessor lookups by chain depth instead of querying each current predecessor separately.
- Discount helpers now use loaded `priceComponents` when available, avoiding repeated `price_components` queries in detail/list JSON-LD and card paths.
