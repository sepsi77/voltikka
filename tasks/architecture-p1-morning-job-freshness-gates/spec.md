# Add freshness gates to dependent morning jobs

**Priority:** P1

## Goal

Prevent retail-premium and forecast jobs from using stale or incomplete upstream data only because their scheduled clock time arrived.

## Scope

- Define the required freshness facts for contract imports, interpretations, statistics, and EEX futures.
- Check those facts before retail-premium collection and forecast generation.
- Return failure when required scheduled output is absent.
- Add common reporting for failed or skipped dependent jobs.

## Acceptance criteria

- Retail-premium collection does not run on an incomplete contract import.
- Forecast generation does not publish current output from stale required inputs.
- A missing required input creates a visible failure or explicit deferred state.
- Tests cover delayed interpretation and stale futures cases.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
