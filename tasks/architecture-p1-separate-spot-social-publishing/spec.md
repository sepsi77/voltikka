# Separate spot import from social publishing

**Priority:** P1

## Goal

Make spot-price import idempotent and free of hidden external publishing side effects.

## Scope

- Remove direct social pipeline execution from spot:fetch.
- Create an explicit new-market-day publication task or job.
- Give publication a durable unique date identity.
- Require an explicit production enable setting for external posting.

## Acceptance criteria

- Running spot:fetch manually cannot publish social content directly.
- A market day can publish at most once unless an operator requests a retry.
- Spot import success is independent from social publication success.
- Tests cover repeated fetches and publication retries.

## Source

Created from the read-only Voltikka Laravel architecture review. This task does not authorize production changes.
