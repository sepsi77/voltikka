# Top-100 manual benchmark results

## Focused label scope

A positive label requires persisted text announcing that the current structured price is valid only for a stated period while corresponding promotion/future-phase metadata is absent, causing Voltikka to extend a temporary price beyond its validity.

Missing descriptions are negative/no-signal. Legitimate recurring resets are negative unless a separate introductory phase is omitted. Other calculation defects are tracked separately and are not promotion positives.

## Review process

- Four explorer subagents manually reviewed non-overlapping batches of 25 contracts from `top-100-input.json`; the failed final batch was rerun.
- A separate explorer subagent independently adjudicated every initial positive or ambiguous row.
- Labels use persisted production descriptions/components only.
- `deceptive` is an internal shorthand for misleading structured promotion data; it does not claim proven provider intent.

## Label counts

| Label | Count |
|---|---:|
| Description-only period/promotion mismatch | 4 |
| No target mismatch signal | 96 |
| Binary benchmark eligible | 100 |

## Positive labels

| Rank | Contract | Finding |
|---:|---|---|
| 3 | `brxibd-aalto-energia-oyj-tyyni-vakiohinta` | Structured 5.49 c/kWh and €2.99/month expire on 31 July 2026; disclosed 13.65 c/kWh and €5.99/month from 1 August are omitted. |
| 7 | `kz9acg-cheap-energy-finland-oy-cheap-porssisahko-kampanja-perusmaksualennus-6-kk-490-kk-ja-marginaalialennus-6kk-039-sntkwh` | Six-month monthly-fee discount is structured, but the disclosed margin increase from 0.39 to 0.78 c/kWh is omitted. |
| 37 | `iq7fja-vimpelin-voima-oy-vuodenaikatariffi` | Text says a temporary reduction ends on 31 August 2026 and the previous tariff returns, while all current components are marked `NoDiscount`; the exact returning rates are not supplied. |
| 43 | `1ucmby-cheap-energy-finland-oy-cheap-kvartaalisahko` | Legitimate quarterly product with a separate first-month promotion: fee discount is structured, but energy increase from 7.49 to 9.95 c/kWh is omitted. |

The rank-43 result confirms that recurring-quarterly classification must not act as a contract-wide exemption.

## Separate calculation gaps found

These are negative for the focused promotion benchmark but should become separate workstreams:

| Rank | Contract | Issue type | Finding |
|---:|---|---|---|
| 1 | `9a16oh-hehku-energia-oy-hehku-spot` | `pricing_model_mismatch` | Description says Spot, but persisted `pricing_model=FixedPrice`; Voltikka treats a 0.49 c/kWh margin as the full energy price. |
| 91 | `5me61f-paneliankosken-voima-oy-kosken-kayttowoima-24-kk-kulutusjousto` | `unsupported_consumption_effect` | Hybrid/kulutusvaikutus description states typical ±1.5 c/kWh and cap ±5 c/kWh, but current calculation uses only the 8.70 c/kWh base. |
| 92 | `gtjlmh-paneliankosken-voima-oy-kosken-kayttowoima-24-kk-kulutusjousto` | `unsupported_consumption_effect` | General-metering variant of the same unsupported consumption-effect product. |

The database does contain `pricing_model=Hybrid` for ranks 91/92, but that field is not enough to calculate the consumption effect. `ContractPriceCalculator` currently treats Hybrid General/seasonal components as ordinary fixed rates and does not add a consumption-effect estimate or range.

## Preliminary routing benchmark

Using the broader text routing rule on all 100 rows:

| Rule | TP | FP | FN | TN | Precision | Recall |
|---|---:|---:|---:|---:|---:|---:|
| Broad text routing | 4 | 15 | 0 | 81 | 21.1% | 100.0% |

Low routing precision is acceptable because the rule selects contracts for inexpensive extraction/classification; it is not the final warning decision. The earlier Spot/model consistency rule should remain a separate data-quality detector because it finds rank 1 but does not improve focused promotion recall.

These metrics remain preliminary:

- only four positive rows exist
- the sample is intentionally biased toward the cheapest 100 contracts
- persisted text can be stale
- reviewers did not inspect provider checkout pages
- most initial negatives did not receive an overlapping second review

## Next dataset improvements

1. Add every deterministic/anomaly candidate outside the cheapest 100.
2. Add a stratified random sample across pricing model, contract type, duration, and provider.
3. Double-label a random negative subset to estimate reviewer disagreement.
4. Keep separate benchmark labels for promotion mismatch, pricing-model mismatch, and unsupported consumption-effect products.
5. Store future reviewed production incidents as regression fixtures.
