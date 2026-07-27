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
