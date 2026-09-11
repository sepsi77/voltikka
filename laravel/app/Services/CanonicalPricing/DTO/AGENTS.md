# Canonical pricing VAT copies

The calculator selects VAT included for Household, Both, and a missing target group. Company selects VAT excluded. `ContractContext::includesVat()` owns this rule. The calculator receives one configured `price_forecasting.fixed_term.vat_multiplier` snapshot from its container binding.

`CanonicalContractData::withVatBasis()` converts explicit component amounts and normal amounts together. Unknown component VAT and packages assume the selected target basis. Do not infer seller tax facts from that assumption.

`SpotAssumptions::withVatBasis()` and `SpotEstimate::withVatBasis()` return selected-cost copies. Their legacy `WithTax` field names do not override `vatBasis`. The original shared market evidence remains inclusive. Dates, coverage, counts, confidence, and flags remain unchanged. Repeated conversion to the same basis is a no-op. Rolling averages are normalized at the configured current rate for annual estimates; this is a current-basis estimate, not historical tax reconstruction.

`HistoricalSpotPrice` accepts optional explicit `centsPerKwhWithoutTax` evidence for exact periods. Company exact pricing prefers that evidence. Inclusive-only hours from 2024-09-01 onward can use the configured current multiplier. Earlier inclusive-only hours cannot prove an excluded bill and return unavailable for Company Spot pricing. The hourly reader should supply actual excluded prices where available. Exact periods never receive annual market offsets.

Public annual results carry `vat_basis`. Reset and supplier requests scale both inclusive reference and forward prices to the selected bill basis. Candidate and direct-rate boundaries also normalize components, so price episode matching and bills use the same basis.
