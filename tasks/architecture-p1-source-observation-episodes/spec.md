# Model source observation episodes

**Priority:** P1

## Goal

Represent A to B to A source changes without losing chronology or assigning dates to the wrong payload.

## Scope

- Separate immutable payload identity from observation chronology.
- Store observation episodes or per-import observations.
- Add one explicit current source-observation pointer.
- Update interpretation dispatch, stale checks, publication, and repair logic to use the same current observation rule.

## Acceptance criteria

- An A to B to A sequence has three chronological observation periods.
- Latest-source checks agree in every consumer.
- Repair commands cannot treat a non-continuous payload as continuous.
- Existing immutable source payload evidence remains available.
- Recurrent stored output is revalidated at the new episode date; an expired absolute phase uses one date-scoped fallback analysis without changing old output.
- Historical gated price repair requires a valid safe interpretation for each selected snapshot, not only the current payload.
- Backfill planning and writes use one transaction with stable contract-before-snapshot locks and complete preflight.
- Existing import rows are locked before updates, forward retail identity includes the exact episode ID, and mixed company freshness compares episode-backed and legacy groups.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
