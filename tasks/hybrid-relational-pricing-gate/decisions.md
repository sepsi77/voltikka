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
