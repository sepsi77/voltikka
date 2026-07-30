# Separate contract import and post-import workflow

**Priority:** P1

## Goal

Separate the authoritative contract database import from cache, interpretation, statistics, and other post-import work.

## Scope

- Extract the database import transaction from FetchContracts into a focused service.
- Return a typed import result that records completeness and changed snapshots.
- Run required and optional post-import stages through explicit failure boundaries.
- Remove hidden service-location and unchecked nested command results from the workflow.

## Acceptance criteria

- The database import can be tested without running the Artisan command.
- A cache warm failure cannot prevent required statistics work.
- Each interpretation dispatch failure is isolated and reported.
- The command returns a failure status when a required stage fails.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
