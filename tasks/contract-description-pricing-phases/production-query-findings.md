# Production deterministic-detector findings

## Scope and method

Read-only queries were run on 23 July 2026 against the Railway production MySQL service. No production data was changed.

The query joined active contracts to their latest preferred non-zero price component per component type, following the same broad latest-component ordering used by Voltikka. It normalized the available Finnish/default/short/long description fields and measured phrase, date, and repeated-price signals.

These results evaluate candidate routing. A detected pricing schedule is not automatically deceptive.

## Coverage and individual signals

| Metric | Active contracts |
|---|---:|
| Total active contracts | 434 |
| With any persisted description text | 378 |
| Without usable persisted description text | 56 |
| Contains `asti` | 11 |
| Contains `alkaen` | 17 |
| Contains `aloitushinta` | 1 |
| Matches `ensimmäiset ... kk/kuukautta` | 20 |
| Contains `normaalihinta` | 0 |
| Contains an explicit calendar date | 28 |
| Contains a date in the next 30 days | 2 |
| Contains a date in the next 90 days | 13 |
| Contains at least two energy-price mentions | 18 |
| Contains at least two monthly-fee mentions | 18 |
| Contains multiple energy or monthly-fee mentions | 26 |

The initial combination rule routed 24 contracts (5.5% of all active contracts; 6.3% of contracts with descriptions). Manual inspection found genuine temporal pricing language in all 24. This is good precision for routing, but not a 100% deception rate: the set contains ordinary structured promotions, legitimate recurring products, fixed-term continuations, and actual source conflicts.

A broader practical routing rule—initial combination rule OR multiple monthly fees OR first-month phrase OR a date in the next 90 days—would route 34 contracts (7.8% of all active contracts; 9.0% of contracts with descriptions). This is a small LLM workload, especially when extraction runs only after the source-text hash changes.

## Why single signals are insufficient

- `alkaen` often refers to VAT/general-terms effective dates rather than promotions.
- `asti` also occurs in optional price-fixing and generic validity text.
- Multiple energy values can represent hybrid-product adjustment ranges or separate tariff components.
- A nearby date can be a signup deadline rather than a price transition.
- `normaalihinta` had no recall in the current active dataset.
- The first-month regex must support compact Finnish forms such as `3kk`, not only `3 kk`.

Useful additional transition phrases observed in production include:

- `tämän jälkeen` / `jonka jälkeen`
- `kampanjan jälkeen`
- `tarjouskauden jälkeen`
- `sopimuksen alkamisesta`
- `hinta on voimassa`
- `palaamme ... hinnastoon`
- `hinta tarkistetaan` / `hinta päivittyy`

## Keep legitimate recurring products separate

The broader 34-contract routing set included at least these legitimate recurring-reset products:

- `1ucmby-cheap-energy-finland-oy-cheap-kvartaalisahko` — quarterly reset, with a separate first-month promotion
- `6e4ly6-kokkolan-energia-oy-tyyni` — monthly reset
- `plbcxh-kokkolan-energia-oy-vuodenaika` — quarterly/three-month reset
- `gjhzfc-kokkolan-energia-oy-vuodenaika-uusiutuva` — quarterly/three-month reset

The recurring cadence is legitimate and must not trigger a deceptive-pricing warning. These products need a recurring-price methodology and a clear consumer explanation instead of being treated as fixed for 12 months.

However, recurring status must not suppress a separate promotion conflict. Cheap Kvartaalisähkö discloses:

- first month: 7.49 c/kWh and €0/month
- afterwards during the current quarter: 9.95 c/kWh and €4.90/month

Its structured General component is 7.49 c/kWh with no energy discount metadata; only the monthly first-month discount is structured. Thus it is both a legitimate quarterly product and a description-only introductory energy-price mismatch.

The routing set also contained normal fixed-term continuation disclosures, such as six-month fixed products that continue as spot contracts. `fixed_term_continuation` must be its own schedule type rather than a deceptive-promotion classification.

## Confirmed omitted or incomplete structured phases

### Clear fixed-date introductory mismatches

`brxibd-aalto-energia-oyj-tyyni-vakiohinta`:

- structured: 5.49 c/kWh and €2.99/month, no discounts
- text through 31 July 2026: 5.49 c/kWh and €2.99/month
- text from 1 August 2026: 13.65 c/kWh and €5.99/month

`4jxjsu-vihrea-alyenergia-oy-vakaa-valinta`:

- structured: 6.59 c/kWh and €2.99/month, no discounts
- text through 30 September 2026: 6.59 c/kWh and €2.99/month
- text from 1 October 2026: 13.65 c/kWh and €5.99/month

These are the clearest examples of the target problem.

### Partial structured promotions

Several contracts have valid structured metadata for one component but omit another disclosed phase. Therefore, contracts with `pricing_has_discounts = true` must still be checked.

Examples:

- Cheap Kvartaalisähkö structures the first-month monthly-fee discount but not the introductory energy-price transition.
- A Cheap Pörssisähkö campaign structures the six-month monthly-fee discount but leaves the General margin at the introductory 0.39 c/kWh even though text says it becomes 0.78 c/kWh after six months.
- A Vihreä Älyenergia offer structures a six-month margin/monthly discount but the additional first free month is not representable as a second stacked monthly phase.

### Explicit reversion without the future amount

Four active Vimpelin Voima tariff contracts say a temporary reduction is valid from 1 June through 31 August 2026 and that the previous price list returns automatically on 1 September. Their latest structured components are marked as non-discounted and the text does not state the returning prices.

The detector should route these, but an LLM cannot invent the future values. The correct result is `future_price_unknown` / `review_required`, not a fabricated annual estimate.

## Ordinary promotions correctly represented

The detector also found many legitimate promotions whose structured metadata appears to cover the relevant first-year monthly-fee discount, including examples from Cheap Energy, Hehku, Lumme, Imatran Seudun Sähkö, and Kokkolan Energia.

Kokkolan Energia Surffari is a useful negative example: its text states a campaign margin through 31 August and a higher margin from 1 September, while the General component correctly stores the normal margin plus an `UntilDate` discount. It should not receive an integrity warning after validation confirms the match.

This demonstrates why repeated prices are a good extraction trigger but not evidence of deception by themselves.

## Recommended routing and classification

### Stage A: candidate routing

Route when any of these holds:

1. Multiple energy or monthly-fee values plus a transition phrase/date.
2. Any multiple monthly-fee values, because this was high-signal in the sampled data.
3. A first-N-month phrase, including compact forms such as `3kk`.
4. A near-future date plus promotion/reversion/reset language.
5. Two adjacent date ranges plus any price or price-reset language.

At the observed production size this should route roughly 34 active contracts before further tuning.

### Stage B: schedule extraction/classification

Extract one or more schedule types:

- `introductory_promotion`
- `recurring_market_reset`
- `fixed_term_continuation`
- `seasonal_tariff`
- `signup_deadline`
- `general_terms_date`

Recurring evidence should include existing quarterly phrases plus monthly/reset phrases and supporting source fields. Classification should be centralized because current main-listing, SEO-listing, and statistics heuristics differ.

### Stage C: integrity comparison

Compare extracted phases component-by-component with structured API prices and discounts:

- exact match: use structured data, no integrity warning
- structured values match only the intro phase: extracted future phase must correct ranking
- one component represented, another omitted: merge only after deterministic validation
- legitimate recurring reset with unknown future values: use a separate forecast/methodology and label it as variable, not deceptive
- future amount disclosed as reverting but absent: mark unverified/review-required
- low-confidence contradiction: block cheapest ranking until reviewed

## Low-price anomaly query

A follow-up read-only query measured latest structured prices against peer segments. This is an orthogonal candidate route, not proof of deception.

For household-eligible, open-ended FixedPrice contracts having positive General and Monthly components, the production medians were:

- energy: 9.50 c/kWh
- monthly fee: €4.90/month

A simple joint rule—both energy and monthly fee below 80% of their segment medians—returned exactly three contracts:

1. Kokkolan Energia `Tyyni`: 4.98 c/kWh and €2.53/month — legitimate monthly-reset product whose next disclosed month is 6.94 c/kWh.
2. Aalto Energia `Tyyni Vakiohinta`: 5.49 c/kWh and €2.99/month — clear description-only future increase.
3. Vihreä Älyenergia `Vakaa Valinta`: 6.59 c/kWh and €2.99/month — clear description-only future increase.

This supports using joint peer anomalies: after schedule classification removes the legitimate monthly-reset case, the rule finds both clearest target contracts.

Energy price alone was materially noisier. Low FixedPrice/OpenEnded General values also found:

- zero-energy package products whose cost is embedded in a high monthly package fee
- a product described as Spot but stored as FixedPrice, indicating a separate source-model mismatch
- legitimate monthly-reset products
- ordinary competitive fixed prices

Likewise, low fixed-term energy outliers were mostly normal market offers. Spot products require a separate margin baseline: their General component is commonly around 0.5 c/kWh and cannot be compared with fixed energy prices.

An abnormally low monthly fee without component discount metadata was useful. At less than half the Spot peer median it found two products already identified from text; both disclose a higher monthly fee after 12 months.

### Conclusion on recall

The text rule's recall cannot be proven from production because there is no labeled set of deceptive contracts. The observed 24/34-candidate precision only evaluates routing quality. Recommended recall comes from the union of:

1. text schedule/transition detection
2. peer-normalized component and annual-cost anomalies
3. source-model consistency checks, such as a product described as Spot but stored as FixedPrice
4. structured-discount sanity checks
5. previous-version/provider-product price-change comparisons

The combined system should be periodically evaluated against manually reviewed labels. Every reviewed candidate—positive or negative—should become a regression fixture and a detector-quality record.

## Separate Hybrid / kulutusvaikutus finding

A description scan for `kulutusvaikutus`, `kulutusjousto`, and related forms found 42 active products:

- 38 stored as `pricing_model=Hybrid`
- 1 stored as `FixedPrice`
- 3 stored as Spot products discussing optional price fixing

The source therefore identifies most consumption-effect products as Hybrid, although text fallback is still needed for outliers and optional-fixing contexts. The current calculator does not implement Hybrid consumption effects: only Spot receives special pricing treatment, so Hybrid components are calculated like ordinary fixed rates.

Kosken käyttöWoima ranks 91/92 in the cheapest-100 sample disclose a typical ±1.5 c/kWh effect and hard ±5 c/kWh limit. At 5,000 kWh these represent approximately ±€75 and ±€250 per year around the €483 base estimate. This is a separate `unsupported_consumption_effect` calculation issue, not a description-only promotional mismatch.

## Data-quality prerequisite

Production contains active descriptions with already-passed campaign dates while latest component rows are current. The importer does not refresh most text fields for an existing contract. Source text must be refreshed or versioned before LLM extraction is trusted; otherwise the model can accurately extract stale terms.
