First read `.pi/agents/laravel-reviewer.md` in the repository root: it is your agent definition. Follow its role, rules and report layout.

Review the implementation of tasks/temporal-orchestration/spec.md against that spec, for phase 2 (read tasks/temporal-orchestration/phase2-plan.md: it holds the phase 2 decisions and runbook). Read-only: do not edit source, commit, switch branches, stash, or write anything except your report. Do not run Railway commands, production database access, live API calls, or scripts/sync-production-database.sh.
Range: 5b1646a..5d18ed7 on branch temporal-orchestration (the checkout at /Users/seppo/code/voltikka is on that branch), plus these local changes: none. Other local changes in the checkout are out of scope. Commits 323e6ce and 5b1646a are unrelated and out of scope.
Spec items:
S1 Deploy-safe: a web deploy never stops a running pipeline; a worker deploy loses at most one short activity attempt, which Temporal retries (spec Requirement 1; the deploy-window removal itself is phase 4).
S2 Event chaining: only the start of a chain is time-based; the morning consumers start when contract_import and eex_futures have ended, and the 12:00 deadline is kept (Requirement 2).
S3 Short, idempotent activities; every activity is safe to repeat (Requirement 3; splitting futures:fetch-eex is deferred by decision 2026-11-08).
S4 Rate limits in one place (Requirement 4; phase 3, not in this range).
S5 Backfills as workflows (Requirement 5; phase 3, not in this range).
S6 Existing guarantees kept: freshness rules, completion proofs, checkpoint semantics, Sentry Issues for failed imports, one-server execution, and "no automatic retry" where it exists on purpose (social publishing, failed morning writers, imports) (Requirement 6).
S7 Observable: failed and stuck workflows are visible in the Temporal UI and Sentry without reading container logs; one Issue per failure, no duplicates (Requirement 7).
S8 Simplest working setup; each new piece has a recorded reason (Requirement 8, root AGENTS.md principles).
S9 One group is cut over at a time; a group's Temporal Schedules and its routes/console.php entries never both run (Constraints; phase2-plan.md decision 4 and runbook).
S10 No production infrastructure, variable, deploy or schedule change without explicit user approval; nothing in this range mutates production (Constraints).
S11 Shared cache store: Redis for web, worker and queue (phpredis in the image; CACHE_STORE=redis is a rollout variable) (phase2-plan.md decision 1).
S12 Queue worker moves to the separate service voltikka-queue (docker-worker-entrypoint.sh queue); the web supervisord no longer starts it; the queue envelope (timeout/retry_after) is unchanged (decision 2).
S13 Every scheduled job moves to Temporal Schedules except the web-volume jobs (weekly file backup, logos:download); the worker import uses --skip-logos (decision 3).
S14 Worker and queue services reference every application variable of voltikka, above all the pricing flags that are part of the cache keys (phase2-plan.md "Target layout"; documented requirement for the rollout).
S15 Retry rule: a non-zero exit (CommandFailed) is never retried automatically; only a lost attempt (worker restart, heartbeat timeout) is retried (phase2-plan.md "Retry rule").
S16 Each moved job keeps its former Helsinki time unless a chain replaced the time; the morning chain replaces the 07:15/07:30/07:45 ticks (phase2-plan.md "Groups").
S17 Out of scope: no change to what the pipelines calculate (pricing, statistics, forecasts) (spec "Out of scope").
Read spec.md, decisions.md (above all the 2026-11-08 entries), tasks.json, phase2-plan.md and orchestration/AGENTS.md for context. There are no earlier reviews. Do not reopen recorded trade-offs without new evidence. Report concrete wrong, stale, misleading or unsafe results even when a decision permits them. Cite the decision; a fix that reverses an explicit user ruling needs user authorization.
Tests: do not run tests
Focus: Correctness of Laravel behavior against every spec item: commands, imports, transactions, idempotency, migrations, Livewire state, failure reporting, fail-closed paths, production safety defaults.
Write your report to tasks/temporal-orchestration/reviews/2026-11-08T12-53/laravel-reviewer.md, using the report layout in your agent definition. Give every finding: an ID with your prefix (REF, LAR, PRC, TST, PLT) and -NNN, Severity, Likelihood, Class (blocking | normal | backlog), the spec rule it violates (or "none"), path:line evidence, a user-impact scenario, and a fix with its blast radius. If you have no findings, say "Findings: none". Reply with the path, finding count and a 3-line summary.
