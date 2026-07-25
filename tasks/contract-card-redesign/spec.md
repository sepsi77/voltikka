# Contract card redesign

## Goal

Make the contract card answer three questions that it does not answer today:

1. **Which pricing category is this?** One of exactly three, always shown.
2. **Is this annual price an estimate, and why?** One marker, one explanation, one link
   to `https://voltikka.fi/tietoa#menetelma`.
3. **What caveats apply?** A footer with a priority order instead of a flat row of
   inconsistent tags.

The approved visual direction is **"Kuitti v2"**: `mockups/kuitti-v2.html` (open in a
browser; serve with a UTF-8 charset header or the Finnish text garbles). It was iterated
with the user from five candidate directions and reconciled against `DESIGN.md` and
`PRODUCT.md`. The mockup is the layout authority; this spec is the rules authority.

## Approved card anatomy (top to bottom)

### 1. Type band (single purpose, always shown)

A full-width tinted strip at the top of the card. It communicates the pricing category
and **nothing else**. Warnings never appear in the band, no matter how important.

Contents: category icon + one bold plain-Finnish sentence + a `·` separator + a detail
sentence. All copy is generated from typed fields. No em dashes (DESIGN.md rule).

| Category | Tint / ink | Icon | Bold sentence | Detail sentence |
|---|---|---|---|---|
| Kiinteä hinta | `slate-100` / `slate-700` | lock | "Energian hinta ei muutu" | "Voimassa toistaiseksi" or "Määräaikainen N kk" |
| Kiinteä, scheduled published change | same as fixed | lock | "Kiinteät hinnat" | "Julkaistu etukäteen, ei sidottu markkinaan" |
| Markkinahinta, spot | `#e0f2fe` / `#0369a1` | wave | "Hinta seuraa pörssin tuntihintaa" | "Muuttuu joka tunti" |
| Markkinahinta, reset | same as spot | wave | "Hinta tarkistetaan neljännesvuosittain / kuukausittain / kausittain" (by cadence) | "Seuraava tarkistus {d.m.Y}" from `recurring_schedule.current_period_end` + 1 day; omit when unknown |
| Kulutusvaikutus | `#ede9fe` / `#6d28d9` | pulse | "Kiinteä hinta + kulutusvaikutus" | "Korjaus riippuu kulutusprofiilistasi" |

Fixed is deliberately grey: certainty is the default state, colour marks deviation from
it. The market and usage tints are a **new semantic colour axis** and must be documented
in `DESIGN.md` as data colours (like the emissions tiers) before this ships; see the
reconciliation section.

The right end of the band holds the **Arvio chip** when the total is an estimate.

### 2. Body: identity + receipt lines + price stub

- **Identity**: logo tile, contract name (18px / 700, truncating), meta line
  "Company · duration". Metering words (Aikasähkö, Kausisähkö) may appear in the meta
  line; they are not categories.
- **Receipt lines**: the price structure itemised with dotted leaders, at most three
  rows. Estimated rows render in a softer weight so known vs estimated is visible in the
  breakdown itself:
  - fixed: `Energia … 7,20 c/kWh` / `Perusmaksu … 4,05 €/kk`
  - spot: `Pörssin keskihinta 12 kk … 7,45` (soft) / `Marginaali … 0,39` / `Perusmaksu …`
  - reset: `Energia nyt, 30.9. asti … 7,97` / `Loppuvuosi, arvio … 10,51` (soft) / `Perusmaksu …`
  - usage effect: `Perushinta … 8,59` / `Kulutusvaikutus … ± profiilisi mukaan` (soft) / `Perusmaksu …`
  - scheduled change: `Energia 31.7. asti … 6,99` / `Energia 1.8. alkaen … 9,90` / `Perusmaksu …`
  - Time/Season metering: day/night or winter/other rates as the rows.
- **Price stub**: separated by a 1px dashed vertical divider. €/kk as the largest number
  (`tabular-nums`, decimal in `slate-400`), €/v below it, then the outline "Katso" CTA.

### 3. Footer strip

`slate-50` background, top border `slate-100`. Two visual classes in one row, warnings
first:

- **Warnings** are filled pills: `coral-50` background, `coral-200` border, `coral-700`
  text, warning/triangle icon. Priority order, at most two shown:
  1. price increase ("Hinta nousee {d.m.Y}", from the integrity payload)
  2. consumption cap ("Max N kWh/v", display rule below)
  3. short fixed term with unknown continuation ("N kk sopimus, jatkohinta ei tiedossa")
  4. consumption effect not included in the total ("Ei sisällä kulutusvaikutusta";
     only when the category chip is not already Kulutusvaikutus)
- **Facts** are quiet inline tags: promotion ("N kk tarjous", `coral-700` text, tag
  icon) and the energy source as a green data tag ("Päästötön" or "Uusiutuva N %" with
  the real percentage, `badge-green` colours).

Removed from the card: the percentile callouts, the standalone `Arvio` footer tag, the
`Vihreä` label, and the emissions left stripe (replaced by the footer energy tag; needs
the DESIGN.md edit below).

### Consumption cap display rule

`consumption_limitation_max_x_kwh_per_y` produces a footer warning **only when the limit
is 30 000 kWh/v or lower**. A reasonable household cannot hit a higher cap (the largest
consumption preset is 18 000 kWh/v), so caps like "Max 80 000 kWh/v" are noise and are
not displayed.

Exception: when the user's selected consumption actually exceeds the limit, the warning
is always shown regardless of the threshold, because the card is greyed out
(`exceeds_consumption_limit`) and the reason must stay visible. The greying/sort
behaviour itself is unchanged.

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

The category derivation must not depend on `CANONICAL_PRICING_ENABLED`; it reads
`canonical_pricing` directly, which the interpretation pipeline publishes independently
of the pricing flag.

## Estimate disclosure

Every contract whose 12-month total is an estimate shows the **Arvio chip** at the right
end of the type band: a white pill button with border, "Arvio" text, and an info icon.
It must read as interactive (hover: coral border + text; visible focus ring), not as a
decorative stamp. It opens a popover on hover, focus, and tap; Escape and blur close it.

The popover states the typed reason and ends with a link "Näin laskemme arviot →" to
`https://voltikka.fi/tietoa#menetelma`. The existing `x-info-tip` bubble is
`pointer-events-none` and cannot hold a link, so this needs a new `x-info-popover`
component (hover intent delay so the pointer can travel into the bubble; white surface,
`slate-200` border, `shadow-md`, link in `coral-600`).

Four reasons, from `calculated_cost.estimate_method`:

- `rolling_365_spot` — rolling 12-month realised spot average plus the contract margin
  (state the day/night baseline figures and the margin)
- `recurring_forward_curve_shift` / `recurring_spot_seasonal_index` /
  `hold_current_recurring_price` — market reset; current period exact until its end
  date, tail estimated from derivatives market prices
- `term_price_annualized` — fixed term shorter than 12 months, continuation price unknown
- `hybrid_base_only` — base components only; the consumption effect is not costed

All copy is generated from typed fields. No interpretation `summary` string is rendered.

In bill mode (`billMode`) the annual Arvio chip is not shown; the period figures keep
their existing "laskutusjaksollasi" tooltip behaviour. The type band still shows, since
the category does not depend on the cost payload.

## Implementation shape

- **One server-side derivation.** A `ContractCardPresenter` (or equivalent view-model)
  computes category, band copy, receipt lines, estimate payload, and footer items. Both
  `contract-card.blade.php` and `featured-contract-card.blade.php` consume it; the
  featured card currently has no integrity pill, no reset figures, and no estimate
  marker because the ~120 lines of Blade derivation were copied and drifted.
- `SeoContractsList` page filters already implement category-like logic; align them with
  the shared derivation rather than adding a third copy.
- Cards must keep using only already-loaded relations (no lazy loads; see the N+1 note
  in `contract-card.blade.php`).
- Suppress the "Säästö 0 €/v" discount block when the saving rounds below ~5 €/v.

## DESIGN.md reconciliation (ships with this task)

1. Document the pricing-category tints as a semantic data-colour axis (sky = follows
   market, violet = consumption effect; fixed stays slate). They appear only in the card
   type band, only as tints, never decoratively.
2. Warnings use coral (`coral-50/200/700`), not amber; red/amber/green stay reserved for
   emissions data.
3. The emissions left stripe on contract cards is replaced by the footer energy data
   tag; update the sanctioned-stripe rule and the listing-header legend accordingly.
4. Add the popover component spec (the only sanctioned interactive tooltip).
5. Band copy uses `·` separators, never em dashes.

## Out of scope

- The contract detail page category chip (natural follow-up).
- `Vattenfall Helppo Pörssisähkö` publishing 107 €/v on its detail page (separate
  defect, see decisions.md).
