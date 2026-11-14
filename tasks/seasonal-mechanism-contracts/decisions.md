# Decisions

## 2026-11-14 – Task started after the PerusFiksu incident

- Incident and stop-gap: `../vps-migration/decisions.md` (2026-11-14) and PR #15 (`b147c81`). The
  contract is excluded until this task is deployed; the other contracts are priced normally.
- User decisions 2026-11-14:
  - PerusFiksu is a new type of contract: fixed price in the winter months, Spot + margin in the
    summer months. It is not a Hybrid in the sense of the other Hybrid contracts and has no
    consumption effect.
  - More contracts like this are likely, so the pricing calculation must support different pricing
    mechanisms in different months as a general rule.
  - Card category and filter bucket: show it in both if possible, otherwise Kiinteä hinta,
    "because it's fixed when it counts the most". Manager: one contract has one card band, and the
    filter bucket must agree with the band (`PricingBucket` invariant), so Kiinteä hinta and
    bucket `kiintea`; the card line states the seasonal split.
  - Statistics: treat it as fixed price for now.
- Facts found 2026-11-14 (VPS copy of production data): 7 contracts have `period_boundary` without
  a recurring schedule; only `fxn0lr-keravan-energia-oy-perusfiksu-24kk` is active. Three are older
  inactive PerusFiksu versions; three inactive fixed-price contracts misuse `period_boundary` with
  month counts (`4`, `24`, `2`, `12`, `3`, `contract_end`).
