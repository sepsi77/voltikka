# Create a shared spot-price importer

**Priority:** P2

## Goal

Use one implementation for spot-price normalization, VAT selection, quarter-hour persistence, and hourly aggregation.

## Scope

- Extract shared persistence from FetchSpot and BackfillSpot.
- Centralize VAT boundary rules and hourly aggregation.
- Replace chunk exists checks with expected interval coverage or missing-interval detection.
- Keep command-specific range, retry, and exit-status behavior in the commands.

## Acceptance criteria

- Live fetch and backfill use the same importer.
- A partially populated backfill chunk is not treated as complete.
- VAT boundary behavior has focused table-driven tests.
- Failed backfill chunks produce a failure status.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
