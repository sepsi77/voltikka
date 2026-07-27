# Decisions

## The gate conflated two different questions

`canPublishSourcePricing()` asked one question — "is this source pricing safe to expose?" —
but answered it partly from `calculation.status`, which answers a different question: "can
Voltikka compute a 12-month total from it?"

Those come apart exactly on Hybrid contracts. A joustosähkö product publishes a real base
energy rate and a real monthly fee, and adds a customer-specific consumption effect it does
not price. The correct interpretation is therefore `calculation.status = unsupported` — no
12-month total is derivable — while the base components are complete, disclosed, and
identical to what the pre-interpretation importer wrote for a year. Refusing to publish
them did not protect anyone; it froze 49 contracts' prices and deleted a whole segment from
the statistics page.

The fix is a narrow carve-out in the publisher, `isConsumptionEffectOnly()`. It is
deliberately conjunctive — all of:

- `unsupported_consumption_effect` present in `issue_codes`;
- no other issue code except `structured_matches_description` (which only records that prose
  and structured data agree);
- `calculation.status === 'unsupported'` (**not** `incomplete`, which means facts beyond the
  effect are missing too);
- `structured_pricing_status !== 'conflicting'` (a disagreement inside the source is not an
  unpriced adjustment);
- `misleading_first_12_months !== 'detected'`;
- `pricing.consumption_effect.present === true` with `applies_to` in `base_contract`/`both`.

That last clause matters: an `optional_fixing` effect only applies if the customer fixes the
price, so it can never be the reason a *base* contract is unsupported — if such a contract
is unsupported, something else is wrong and it stays blocked.

Precedent: validator v4 already carved out `recurring_reset_requires_estimate` for exactly
this reason — an expected, product-defining reason for an estimate must not be read as
unsafe source data. This is the same shape one level down, in the publisher.

## Why the publisher and not the prompt or validator

The LLM output is right. A joustosähkö contract genuinely has an unquantifiable consumption
effect, and `unsupported` + `incomplete` is the honest description of it. Changing the prompt
or validator to describe it differently would be lying to make a downstream boolean behave.

Consequence: **`validator_version` and `prompt_version` are deliberately NOT bumped.** They
participate in the analysis fingerprint, so bumping either would force a paid re-interpretation
of ~1500 contracts to produce byte-identical output. The version fields track "the model's
answer would change"; nothing here changes the model's answer.

## Why a repair command was unavoidable

`relational_pricing_published` is decided once, when an interpretation publishes, and stored.
Every later `contracts:fetch` reads that stored boolean. So relaxing the gate does nothing for
a contract that already published under the strict rule — its flag stays false until a new
source snapshot triggers a new interpretation, which for a stable product may be weeks away.
All 49 Hybrid contracts were in exactly that state.

`contracts:republish-gated-pricing` re-runs the current gate over stored output, lifts the flag
where it now passes, and refills the days lost in between. It is written generally rather than
as a one-off Hybrid script, because any future relaxation of the gate leaves the same residue.

To keep the command and ingestion from drifting, `canPublishSourcePricing()` was made **public**
and the command calls it, rather than restating the rule. This copies the reasoning already
written above `CanonicalPriceComponentWriter::resolveRows()`, which is public for the same
reason: a repair that re-derives the rule for itself becomes the bug it exists to clean up.

## Refilling is evidence, never inference

Each missing day is rebuilt only from the `contract_source_snapshots` payload whose
`first_observed_at`/`last_observed_at` window covers that day, resolved through
`CanonicalPriceComponentWriter::resolveRows()`, so a filled row is identical to what a correct
import would have written. A day with no covering snapshot stays missing and is reported.
Nothing is carried forward from a neighbouring day — that is the same rule
`ContractStatistics/AGENTS.md` states for gaps ("do not carry prices forward for missing
dates/contracts").

Days that already have rows are never touched. Rows exist exactly when the import was not gated
that day, and that import saw the live payload; the snapshot is at best a tie.

## What was deliberately left alone

- **Activation.** The command does not write `active_contracts`. Once the flag is true, the next
  `contracts:fetch` activates the contract through its normal path. Adding rows for contracts the
  last fetch did not observe would resurrect dead products.
- **The other 30 blocked contracts.** 21 FixedPrice and 9 Spot contracts are blocked for genuine
  reasons (`conflicting` structured pricing, `misleading = detected`, `incomplete` calculations).
  The command re-runs the live gate over them and will correctly decline to reopen them. They are
  a separate question about interpretation quality, not about this gate.
- **Backfilling before 2026-07-25.** The gate only started blocking when interpretation went live
  on 2026-07-24, so there is nothing earlier to repair.

## Production result, 2026-07-27

Deployed as commit `1dbcccf`. `contracts:republish-gated-pricing --from=2026-07-25 --apply`
reported 114 blocked published interpretations, of which **61 passed the relaxed gate — all 61
Hybrid**. It lifted those flags and filled 142 contract-days / 306 component rows. The 53 that
stayed blocked are FixedPrice and Spot contracts with genuine `conflicting` pricing,
`misleading = detected`, or `incomplete` calculations; the carve-out correctly declined them.

About 30 contract-days were skipped for lack of a covering source snapshot. Every one belongs to
an **inactive** contract that left the market (Oomi Jousto 12/24 kk, Fortum Duo, some Helen
Välkkysähkö Yritys); 437 contracts were observed normally on 2026-07-27, so this was the
"evidence, never inference" rule working, not a fetch gap.

Statistics recalculated for 2026-07-25…27 and caches cleared. The `hybrid` segment is back at
avg ≈ 518 €/v for all three days, and the Joustosähkö line reaches the current day again.

## Open: three Hybrids are still frozen for an already-benign reason

The segment came back at **n=28**, not the pre-incident 39–41. Accounted for exactly:
39 active Hybrid Household − 7 still missing rows − 4 with a null `annual_cost_5000_kwh` = 28.

The 7 are blocked because the carve-out is conjunctive and they carry a **second** issue code:

| second code | contracts | assessment |
|---|---|---|
| `recurring_reset_requires_estimate` | 3 (Vaasan Vaikuttaja, Kosken käyttöWoima 12 kk kulutusjousto, Korpela Kvartaali) | **arguably should publish** — validator v4 already declares this an expected, product-defining reason that is not unsafe source data. A product can legitimately be both a consumption-effect Hybrid and a periodic market reset. Its current structured components are still exactly what the customer pays now |
| `pricing_model_mismatch` | 2 (Kokkolan Aalto 6 kk, Vattenfall Helppo Pörssisähkö) | keep blocked — the interpretation disputes the source classification, which is a real signal |
| `insufficient_evidence` | 2 (both Lammaisten IISI-KULUTUSJOUSTO) | keep blocked — conservative default when the source gives nothing to check against |

Adding `recurring_reset_requires_estimate` to the benign allowlist beside
`structured_matches_description` would recover 3 of the 7 and is consistent with validator v4.
It moves real published prices for named companies, so treat it as a reviewed change, not a
tweak. Not done yet — awaiting a decision.

## Resolved: the gate was inverted (2026-07-27, second change)

Inspecting the four contracts held by `pricing_model_mismatch` and `insufficient_evidence`
showed the earlier "keep blocked" judgment was wrong for all four:

- **Kokkolan Aalto 6 kk** — upstream says `FixedPrice`; the description ("pörssihintaan
  perustuva kulutusvaikutus") made the interpretation recommend `Hybrid` at high confidence.
- **Vattenfall Helppo Pörssisähkö** — upstream says `Spot`; the description discloses a
  350 kWh/kk limit with 0.50 snt/kWh above it. Recommended `Hybrid`, high confidence.

In both, `published_fields` contains `pricing_model` and the contract row already carries the
corrected value. `pricing_model_mismatch` was recording a correction Voltikka had **already
accepted and applied**, not an open dispute.

- **Lammaisten IISI-KULUTUSJOUSTO YLEISSÄHKÖ 12 kk / AIKASÄHKÖ 24 kk** — `model_status=match`,
  summaries state the structured data is complete and correct, and the cited evidence is only
  structured fields. `insufficient_evidence` meant the seller published no prose to verify
  against. Thin documentation, not a defective price.

So the allowlist framing was the wrong shape: it treated "we have no positive confirmation" as
a reason to withhold. The owner's framing settled it — the LLM check exists to *validate* the
structured data, which is correct in roughly 95% of cases; trust it unless the description or
another field gives a reason not to.

`canPublishSourcePricing()` is now inverted. It blocks on:

1. `misleading_first_12_months = detected`;
2. `structured_pricing_status = conflicting`;
3. any issue code not classified harmless by `issueCodeLeavesComponentsTrustworthy()`, with
   **unknown codes blocking** so a code added to the schema later is conservative by default.

`calculation.status` no longer participates at all — derivability is not trustworthiness.

Blocking codes: `component_mismatch`, `structured_matches_intro_only`,
`promotion_metadata_missing`, `future_price_omitted`, `other`. Each names a concrete way the
stored components would misstate what the customer pays.

One conditional: **`pricing_model_mismatch`**. It is the only classification code that touches
price safety, because `pricing_model` decides how a component is *read* — a 0.4 c/kWh `General`
is the spot margin on a Spot contract and the entire energy price on a FixedPrice one. It is
harmless only when the correction itself publishes (high confidence); below that the contract
keeps a model the interpretation believes is wrong and the same rows would be priced as
something they are not. `pricingModelCorrectionPublishes()` mirrors `canonicalClassification()`
so the two cannot disagree.

`contract_type_mismatch` and `metering_mismatch` are unconditionally harmless: which product
this is does not change what the seller charges, and a genuinely wrong component is reported by
`component_mismatch`.

Two tests from the first change were deleted rather than kept, because they asserted the narrow
rule itself: "an incomplete calculation still blocks" and "an optional_fixing effect does not
open the gate". Both are false under the inverted rule by design. They were replaced by tests
for what the new rule actually promises, including an unknown-code test guarding the
conservative `default`.

### Production result of the inverted gate

Deployed as `1c70628`. Of 53 still-blocked published interpretations, **32 passed** and 21 did
not. The split fell exactly along "is there a named reason to doubt the components":

| passing | n | | blocked | n |
|---|---|---|---|---|
| `unsupported_consumption_effect` + `insufficient_evidence` | 7 | | `promotion_metadata_missing` + `structured_matches_intro_only` (± future codes) | 9 |
| `insufficient_evidence` alone | 10 | | `component_mismatch` | 4 |
| `recurring_reset_requires_estimate` + `insufficient_evidence` | 4 | | `future_price_omitted` | 4 |
| `unsupported_consumption_effect` + `recurring_reset_requires_estimate` | 4 | | `other` | 3 |
| `pricing_model_mismatch` + `unsupported_consumption_effect` | 2 | | benign codes but `misleading = detected` | 1 |
| `structured_matches_description` / `future_price_unknown` / no codes | 5 | | | |

Both guards demonstrably fired: the `other` default blocked 3, and the deception rule blocked one
whose only issue code (`future_price_unknown`) was harmless.

25 of the 32 were **not** Hybrid (Helen Helpposähkö XS/S/M/L, Turku Louna Helppo, Pohjois-Karjalan
Optimi takuu, Vaasan Kuukausipaketti XS, Vihreä Älyenergia Verraton pörssisähkö). They had been
blocked purely by the old `calculation.status`/`incomplete` rule. The joustosähkö report was the
symptom that surfaced a gate-wide problem.

32 flags lifted, 73 contract-days / 162 component rows filled, statistics recalculated for
2026-07-25…27. Segment counts at 5 000 kWh, 07-24 → 07-27: hybrid 39 → 34, fixed_term_12 49 → 53,
spot 59 → 52, open_ended 62 → 55.

### The open-ended step on 07-25 is not a composition effect (earlier note here was wrong)

`open_ended` moved from ~628 €/v on 07-24 to ~701 €/v on 07-25…27. This was first written up here
as the withheld promo contracts biasing the average upward. **That was wrong, twice over**, and the
measurement is worth keeping so nobody re-derives it:

- The 07-27 open-ended set is a **strict subset** of the 07-24 set — no contract joined. Seven left.
- The contracts still withheld are **expensive**, not cheap. Pricing them through canonical and
  adding them back moves `open_ended` 701 → **703**. Full effect of adding every withheld contract
  we can price: spot 433 → 437, quarterly 644 → 646, open_ended 701 → 703, fixed_term_6 646 → 637.
  Composition explains essentially none of the step.

The step is **14 surviving contracts repricing upward**, summing to +3508 € across the 55 contracts
present on both days — which accounts for the whole gap. The movers name the cause:

| change | contract |
|---|---|
| 721 → 1418, 752 → 1250, 783 → 1082 | Vaasan Sähkö **Kuukausipaketti** XS/S/M |
| 415 → 670, 415 → 652 (×2) | Pohjois-Karjalan **Optimi kuukausi** |
| 427 → 665 | Helen **Markkinahintasähkö** |
| 559 → 797 | Fortum **Kesto** yleissähkö |

These are market-reset and flat-fee-package products, and **`RESET_FORWARD_SHIFT_ENABLED` was
enabled in production on 2026-07-25** — the same day. The root `AGENTS.md` states the old method
"understated them badly in summer"; July is summer, so the new, higher figures are the correction.
The Vaasan packages moved for a second reason: validator v14 changed how their monthly charge maps
(€35/mo + 16.6 c/kWh), so a re-interpretation raised their total.

None of this is caused by the publication gate or by the republish. Two independent pricing-accuracy
changes landed on the same day as the gate fix, which is what made the chart look like one event.

### Still worth doing: price withheld contracts from canonical phases

Independent of the above, the statistics pipeline drops contracts it could price.
`ContractPriceStatisticsService::calculateForDate()` does:

```php
$components = $contract->getPriceComponentsForCalculationDate($dateString);
if ($components === []) { continue; }        // <- canonical pricing is never consulted
```

So a contract with no relational rows gets no snapshot at all, even though `buildSnapshot()` would
have taken its `annual_cost` from `CanonicalContractPricingService` anyway when
`CANONICAL_PRICING_ENABLED` is on. Measured on 2026-07-27: of the 18 still-withheld **active**
contracts, **14 already produce a listed canonical total**. Only Vimpelin Voima's 4 do not — their
`continuation` phase carries zero components because the pre-discount price list is undisclosed, so
`calculation.status = incomplete` and canonical correctly refuses.

The accuracy argument is stronger than the count argument. On 07-24 the relational snapshot recorded
**Kokkolan Tyyni at 279 €/v** and **Aalto Tyyni Vakiohinta at 310 €/v**, because the relational rows
held only the promo price. Canonical prices the same two contracts at **555** and **748**. Tyyni
Vakiohinta (5.49 → 13.65 c/kWh) is the worked example in `ContractInterpretation/AGENTS.md` for
exactly this deception. So the statistics page was publishing fake-cheap numbers for them until the
gate withheld them, and canonical would publish honest ones.

Caveat for whoever implements it: a canonical-only snapshot has no relational components, so the
per-component c/kWh fields (`energy_price_cents_per_kwh`, `monthly_fee_eur`) stay null and only the
`annual_cost` metric gains these contracts. `cleanValues()` already drops nulls, so the c/kWh series
is unaffected. Historical backfill must still pass `useCanonical: false`.

## Canonical pricing now drives the statistics page too (2026-07-27)

Implemented. `ContractPriceStatisticsService::calculateForDate()` no longer skips a contract with
no relational components when canonical pricing is enabled; it builds the snapshot from the
canonical phases instead.

The owner's framing decided the shape: **the raw API price is a seller-controlled input and is
subject to manipulative presentation, which is the entire reason the LLM canonical layer exists.**
So canonical pricing is the source of truth for every published price, and a surface that falls
back to relational rows — or drops a contract for having none — re-exposes the manipulation the
pipeline caught. That is recorded in the root `AGENTS.md` as a project-level rule, not just a note
about this page.

Two guards kept:

- A contract canonical also refuses to total is still skipped, rather than written as an all-null
  row. Vimpelin Voima's tariffs are the case: an undisclosed pre-discount price list leaves the
  continuation phase with zero components.
- The legacy non-canonical path still requires components, because it has nothing else to read, and
  `BackfillContractPriceStatistics` always passes `useCanonical: false` — today's interpretation
  must not be applied retroactively to a historical date.

A canonical-only snapshot carries `annual_cost_*` only; `energy_price_cents_per_kwh` and
`monthly_fee_eur` stay null because nothing relational exists to read them from, and `cleanValues()`
already drops nulls, so the c/kWh series is unchanged.

Note the accuracy direction. This is not only a count fix: the relational path had been recording
Kokkolan Tyyni at 279 €/v and Aalto Tyyni Vakiohinta at 310 €/v — their promo prices, presented as
the year's cost — against canonical figures of 555 and 748. The page was publishing the seller's
framing until the gate withheld those rows.

### Deployed result

`5a893d8`. Statistics recalculated for 2026-07-25…27: **12 canonical-only snapshots** per day
(annual cost present, per-component c/kWh null). Segment counts at 5 000 kWh, 07-24 → 07-27:

| segment | before | after | note |
|---|---|---|---|
| spot | 59 → 52 | **59** | fully back to the pre-incident level |
| fixed_term_12 | 49 | **53** | above it |
| fixed_term_24 | 49 | **50** | |
| quarterly | 13 | **14** | |
| fixed_term_6 | 20 | **18** | |
| open_ended | 62 | **57** | |
| hybrid | 39 | **34** | 4 carry a null `annual_cost_5000_kwh` |

The open-ended average stayed ~699 (was 701), confirming the corrected diagnosis above: the
07-25 step is repricing, not composition.

Note the page legitimately shows **different contract counts per metric**. The
"Hinnat sopimustyypeittäin" table counts the `energy_price` metric and a canonical-only snapshot
has none, so Pörssisähkö reads 52 there while the annual-cost chart uses 59. Both are honest
per-metric counts; `cleanValues()` drops nulls per metric. Do not "fix" this by inventing a
c/kWh figure for a canonical-only row.

### Why some contracts have no energy price (and the copy that got it wrong first)

The `energy_price_cents_per_kwh` column of a snapshot comes from `representativeEnergyPrice()`,
which reads the **relational** components. So it is null exactly when the contract has no
relational rows for that date — i.e. when the publication gate withheld them. Verified on
2026-07-27: all 12 snapshots with a null energy price had **zero** components; none was a
"has components but no energy component" case.

A first attempt at explaining this to visitors said the price is missing "when the price changes
during the first year". That is wrong and was caught in review: nearly every contract's price
changes during a year. Spot changes hourly, a quarterly product reprices four times, a fixed term
ends. Those all still have a publishable unit price:

| product | what the unit price column shows |
|---|---|
| spot | realized 12-month spot average + the published margin |
| monthly/quarterly reset | the current period's published price |
| fixed | the fixed published price |
| **these 12** | *nothing publishable* |

The actual distinction is not whether the price changes but whether the seller's structured
per-unit field states something that honestly represents the contract. For these 12 it does not.
Their issue codes say so directly — 8 carry `structured_matches_intro_only` /
`promotion_metadata_missing` / `future_price_omitted` (the structured number is the campaign rate
and the ongoing rate is in prose or absent), 1 carries `component_mismatch`, and 2 market-reset
products are held by an unclassified `other`. 10 of the 12 have an `introductory` first phase, and
the names show it: Tarjous, KAMPANJA, Opiskelija.

Publishing that number as the contract's energy price would understate it and drag the segment
median down — Tyyni Vakiohinta's structured rate is 5.49 c/kWh against the 13.65 that applies for
most of the year. The whole-year figure is still computed from every phase, so the contract stays
in the annual-cost table. Hence: no unit price, but a year price.
