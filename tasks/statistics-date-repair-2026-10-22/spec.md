# Repair the statistics of 2026-10-22

## Cause

On 2026-10-22 the revoked OpenRouter key failed 49 interpretation jobs (HTTP 401). The current
writer then left out every contract whose 22 October payload had no published interpretation:
new contracts stayed inactive, and changed existing contracts failed the publication-evidence
guard. The stored 22 October statistics have 227 snapshots (258 on 21 October).

23 October was repaired with the standard current commands on 23 October (257 snapshots,
9 forecasts). See the conversation record in `decisions.md`.

## Requirement

Produce for 2026-10-22 the statistics that the current writer would have produced if the
interpretations had published on time, from dated evidence only:

- no later seller facts or prices, no current peers, no future curves;
- existing snapshots and other dates stay unchanged (rows are only added);
- unit (`unit_statistics_v1`) and legacy annual aggregates of the date are recalculated from all
  snapshots of the date; `annual_cost_as_of_v3` rows of the date are replaced by the v3 historical
  calculator over the complete date;
- preview first; production apply needs a verified full backup and explicit approval.

## Parts

A. Repair command for one past date. A contract is added when an exact covering source
   observation exists on the date, it has no snapshot on the date, v3 selects a target-validated
   interpretation of that exact source, the source pricing passes the publication gate, and the
   dated audience is Household/Both/null. Identity comes from the dated payload plus the
   publisher's own attributes of the selected output. Numbers come from the v3 calculator outcomes.
   A compare mode rebuilds snapshots of contracts that already have one and reports differences.

B. History-only analysis of a failed interpretation whose source is no longer current
   (12 contracts: one-day payloads on 22 October, replaced on 23 October). It never publishes.

C. After apply: forecast for 22 October with `forecasting:run-fixed-contracts --as-of=2026-10-22`.

Out of scope: the seller-set energy-price index of 22 October (its writer reads current contract
facts for all contracts); record it as a remaining gap.
