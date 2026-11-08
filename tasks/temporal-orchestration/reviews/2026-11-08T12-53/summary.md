# Temporal orchestration phase 2 — spec review 2026-11-08T12-53
Initial range: 5b1646a..5d18ed7 (branch temporal-orchestration; base = the commit before the phase 2 commit, because the branch also carries the unrelated commits 323e6ce and 5b1646a) · Reviewers run: refactor, test, laravel, pricing, platform (Pi provider in T3, openai-codex/gpt-6.1-sol, thinking high) · Skipped: frontend (no views/CSS/JS/SEO change), copy (no public Finnish text)
Verification range: 12f5e21..f6452f2 (all five reviewers)
Final assessed HEAD: f6452f2 (verification pass); later local changes listed below
Later source changes: `MorningReadiness.php` (+`deadline_alerted`, read-only), `jobs.py` (separate noon check, late outcome, consumer timeout 60 min), `worker.py` (drain 65 min), `sentry.py` (optional test transport), tests, docs and runbook. Verified by manager inspection and tests: Python 36 passed; affected PHP 157 passed; `git diff --check` clean. No stored identity or write path changed, so no extra focused reviewer check was required.

## Spec items
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

## Verdict: ACCEPT (for R1, the collectors-only release)
All first-round blocking findings are fixed or have a recorded deferral that does not affect the collectors-only release. The verification pass found two new runbook/drain issues and four partly fixed items; the manager fixed the two new issues and two partial items after the pass, and deferred two test gaps as acceptance checks before R3/R4. No ❌ spec item remains for this release.

## Spec rollup
17 items: ✅ 11 · ⚠️ 4 · ❌ 0 · ➖ 2

| # | State | Reason |
|---|---|---|
| S1 | ⚠️ | Web deploys stop no moved job; worker drain protects claims. Whole-EEX repeat after a lost attempt is a recorded limit; deploy window stays until R4/R5. |
| S2 | ✅ | Lanes start when both producers end; PHP lower clock bound removed; noon check independent. |
| S3 | ⚠️ | EEX not split (decision 2026-11-08). |
| S4 | ➖ | Phase 3. |
| S5 | ➖ | Phase 3. |
| S6 | ✅ | Gates, claims, no-retry rules, gas-key skip and noon alert preserved. |
| S7 | ✅ | Failed children/jobs are red; one Issue per failure, tested through the production Sentry setup. |
| S8 | ✅ | Reasons recorded. |
| S9 | ✅ | Only collectors leave `console.php`; one release per later group; rollback pauses Temporal first. |
| S10 | ✅ | No production mutation. |
| S11 | ⚠️ | phpredis in the image; Redis switch is R2 (approval). |
| S12 | ✅ | Queue entrypoint ready; queue stays in web until R3 (by design). |
| S13 | ⚠️ | Collectors moved now; other groups per runbook. |
| S14 | ✅ | Documented requirement for the rollout. |
| S15 | ✅ | `CommandFailed` non-retryable; real-activity test. |
| S16 | ✅ | Independent schedule table test. |
| S17 | ✅ | No calculation change. |

## Blocking findings
None open for R1. Owner of the deferred acceptance checks: next phase 2 PRs (R3, R4).

## Non-blocking findings
| ID(s) | Title | Sev/Lik | Class |
|---|---|---|---|
| TST-002 (rest) | No real heartbeat-loss/crash test | Medium/Low | deferred acceptance check |
| TST-003 (rest) | No real PHP + Temporal + database morning test | High/Medium | deferred acceptance check before R4 |

## Verified
- Collectors-only cut-over: `laravel/routes/console.php` vs `schedules.py` group `collectors` (platform verification, WebSchedulerTest).
- Cache release order and independent lanes (pricing verification, `test_a_slow_consumer_does_not_delay_the_other`).
- Drain: interruptible kill + repeat, claim-based finish (`test_a_deploy_*`), protected timeouts < drain (`test_claim_based_steps_finish_during_a_deploy_drain`).
- One Sentry event per failure with production init (`test_one_sentry_event_per_failed_job`).
