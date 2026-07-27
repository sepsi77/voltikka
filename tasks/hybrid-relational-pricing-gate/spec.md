# Hybrid contracts lost their relational prices to the interpretation publication gate

## Reported symptom

On `/sahkosopimus/tilastot?kulutus=5000` the **Joustosähkö** trend line stops at
2026-07-20 (weekly bucket) while every other contract type continues to 2026-07-27.

## Diagnosis

The contract sync itself is healthy. The `hybrid` segment has **no daily statistic rows
and no contract price snapshots at all** from 2026-07-25 onward, because no Hybrid
contract has had a `price_components` row written since 2026-07-24.

| Date | hybrid snapshots | hybrid `annual_cost` @5000 kWh |
|---|---|---|
| 2026-07-23 | 45 | n=41 |
| 2026-07-24 | 43 | n=39 |
| 2026-07-25 → 27 | 0 | none |

Chain of causation:

1. Import-time interpretation went live in production on **2026-07-24**
   (`CONTRACT_INTERPRETATION_ENABLED=true`).
2. From the next fetch, `FetchContracts::contractsAllowedForImmediatePricePublication()`
   only writes a contract's source components when its published interpretation carries
   `relational_pricing_published = true`.
3. That flag comes from `ContractInterpretationPublisher::canPublishSourcePricing()`,
   which refused publication when `calculation.status` was `incomplete`/`unsupported`
   or `structured_pricing_status` was `incomplete`/`conflicting`.
4. A Hybrid ("joustosähkö"/"kulutusvaikutus") product **always** produces exactly that
   shape, by design: prompt v17 requires `unsupported_consumption_effect`,
   `structured_pricing_status = incomplete` and `calculation.status = unsupported`,
   because the seller does not publish the amount of the customer-specific consumption
   effect. Production confirmed 75/75 Hybrid interpretations at `calc=unsupported`.

So the gate closed permanently on every Hybrid contract.

Production impact measured on 2026-07-27: **79 active contracts** blocked — all 49 Hybrid,
plus 21 FixedPrice and 9 Spot. Contracts with fresh price rows fell from ~435 to ~345.
The non-Hybrid blocks look legitimate (genuinely `conflicting` pricing or
`misleading = detected`); the Hybrid block does not.

Secondary damage beyond the chart: the Hybrid contracts stayed listed and were priced from
frozen 2026-07-24 components, which would never refresh.

## Requirements

1. Publish a Hybrid's base components when the *only* thing preventing a 12-month total is
   the unquantifiable consumption effect. Keep every other reason to withhold intact.
2. Re-open the gate for interpretations that already published under the strict rule. The
   flag is written once and read by every later import, so a code change alone reaches
   nothing already published.
3. Refill the lost days from evidence only — the immutable source snapshot in observation
   on that day — never by carrying a neighbouring day forward.
4. Do not force re-interpretation. The stored LLM output is correct; only the deterministic
   publication decision on top of it was wrong.

## Acceptance

- `hybrid` daily statistic rows exist for 2026-07-25…27 and the Joustosähkö line reaches
  the current day on `/sahkosopimus/tilastot`.
- Hybrid contract cards price from current components again.
- A Hybrid interpretation carrying any second issue code, a detected deception, an
  `incomplete` calculation, `conflicting` structured pricing, or an `optional_fixing`-only
  effect still does **not** publish relational prices.
