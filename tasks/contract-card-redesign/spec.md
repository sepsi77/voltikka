# Contract card redesign

## Goal

Make the contract card answer three questions that it does not answer today:

1. **Which pricing category is this?** One of exactly three, always shown.
2. **Is this annual price an estimate, and why?** One marker, one explanation, one link
   to `https://voltikka.fi/tietoa#menetelma`.
3. **What caveats apply?** A footer with a priority order instead of a flat row of
   inconsistent tags.

## The three pricing categories

Requested by the user. These are the pricing facts a consumer decides on.

| Category | Meaning | Rule |
|---|---|---|
| **Kiinteä hinta** | The energy price does not change during the contract. | everything that is not one of the two below |
| **Markkinahinta** | The price follows the market. Spot, kvartaalisähkö, markkinahintasähkö and any other seller-adjusted period price. | `pricing_model = Spot` **or** `canonical_pricing.recurring_schedule.present = true` with cadence not in (none, unknown) |
| **Kulutusvaikutus** | Otherwise fixed, plus an adjustment that depends on the consumption profile. | `pricing_model = Hybrid` **or** `canonical_pricing.consumption_effect.present = true` with `applies_to` in (base_contract, both) |

Market-following wins over consumption effect when a contract is both. The user defined
the consumption-effect category as "fixed otherwise", so a quarterly-reset base is not
that category. The consumption effect then stays a footer caveat.

Measured on 425 active local contracts: 235 fixed, 131 market-following, 56 consumption
effect. Three contracts are both market-following and consumption effect. One contract
(Helen Välkkysähkö Yritys) is `Hybrid` with no `consumption_effect` block, which is why
the `pricing_model = Hybrid` fallback is required.

## Estimate disclosure

Every contract whose 12-month total is an estimate shows one marker next to the €/v
figure. The marker opens a hoverable popover that states the typed reason and links to
the methodology page. Four reasons, from `calculated_cost.estimate_method`:

- `rolling_365_spot` — rolling 12-month realised spot average plus the contract margin
- `recurring_forward_curve_shift` / `recurring_spot_seasonal_index` /
  `hold_current_recurring_price` — market reset; current period exact, tail estimated
- `term_price_annualized` — fixed term shorter than 12 months, continuation price unknown
- `hybrid_base_only` — base components only; the consumption effect is not costed

All copy is generated from typed fields. No interpretation `summary` string is rendered.

## Footer

Two zones, replacing the current flat row.

- **Caveats** (left, at most two, priority ordered): price increase → consumption cap →
  short term with unknown continuation → consumption effect not included.
- **Attributes** (right): promotion, energy source with the real percentage.

Removed: the percentile callouts, the standalone `Arvio` tag, and the `Vihreä` label.

## Out of scope

- The contract detail page category chip (natural follow-up).
- `Vattenfall Helppo Pörssisähkö` publishing 107 €/v on its detail page (separate defect,
  see decisions.md).
