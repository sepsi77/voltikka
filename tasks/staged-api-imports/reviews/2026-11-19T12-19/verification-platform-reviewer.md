# platform-reviewer — staged-api-imports verification
Range: `cdc93650e77e1db73f7f9067fee987a73de59c62..743f1c14a0066e6e65ac042bc529ff91ef877a5c` · Result: 4 fixed; 0 partially fixed; 0 not fixed; 0 new findings

Scope: PLT-001–PLT-003 and TST-005, their fixes and directly affected replay behavior only. Static verification; no tests, live API calls, production access or Artisan commands run. Local untracked verification prompts are not implementation changes.

## Per-finding verification

### PLT-001 — Fixed

- `orchestration/src/voltikka_orchestration/s_forecast.py:53-58` now excludes `CommandFailed` from the input retry policy, while retaining three attempts for lost activity attempts. Both fetch and store use that policy (`:154`, `:163`); refit uses the same helper (`:344`). Model/training retry policies remain separate and unchanged.
- The fake now raises the production-shaped, intrinsically retryable `ApplicationError(type="CommandFailed")` (`orchestration/tests/test_s_forecast.py:66-68`), rather than making the failure nonretryable itself.
- Fetch/store failure tests assert exactly one invocation, no refetch and one Issue (`:183-198`, `:296-307`). The lost-store test separately requires store-only repetition (`:201-207`). These assertions expose the original policy defect.

### PLT-002 — Fixed

- `staged_fetch()` retains the command, closed error, current step and saved payload reference across activity failures (`orchestration/src/voltikka_orchestration/s_forecast.py:139-170`). Shared `failure_facts()` supplies the reference fields (`orchestration/src/voltikka_orchestration/staged.py:84-87`).
- Daily outage failures reach the existing no-forecast Issue; a failed Fingrid input is included in the deadline Issue (`s_forecast.py:210-211`, `:223-237`, `:296-306`). Refit adds the same facts to its existing single Issue (`:344-345`, `:360-365`), without an additional reporter.
- ImportReplay now invokes the reference-aware store-only `run_step()` (`orchestration/src/voltikka_orchestration/jobs.py:485-486`). Its failure path retains step/reference facts, and self-reporting command exits remain excluded from duplicate workflow reporting (`:183-207`).
- Exact one-Issue fact assertions cover daily fetch/store failures, refit store failure and replay store failure (`orchestration/tests/test_s_forecast.py:194-198`, `:303-307`; `orchestration/tests/test_jobs.py:547-558`).

### PLT-003 — Fixed under the recorded bounded-store design

The manager explicitly selected bounded activity lifetime plus an age guard instead of an open-reference registry (`tasks/staged-api-imports/decisions.md:107`; `design.md:137`). That decision is respected here.

- Every newly staged generic/replay store receives a schedule-to-close of at most one day (`orchestration/src/voltikka_orchestration/staged.py:29`, `:43-46`; `jobs.py:129-134`, `:187`, `:485-486`). S stores use the same cap while preserving their shorter cut/deadline budget (`s_forecast.py:163`, `:272-280`, `:338-344`). Stores remain noninterruptible with their existing drain-safe start-to-close limits.
- Before scheduling any of those stores, the workflow checks the payload directory date and rejects an unsafe age with `payload_expired` and one Issue (`staged.py:76-81`; `jobs.py:183-186`; `s_forecast.py:159-162`). Thus a workflow delayed after fetch cannot enqueue a store using an already expired payload.
- Cleanup still uses Helsinki directory dates, retaining the exact 14-day boundary rather than file modification time (`laravel/app/Services/ImportPayload/ImportPayloadCleanup.php:34-55`). A queued store expires within its schedule-to-close instead of remaining eligible indefinitely until cleanup deletes its input. Workflow-side timeout reporting retains the store/reference context (`jobs.py:191-207`; `s_forecast.py:164-170`).

### TST-005 — Fixed for that replacement safety mechanism

- Tests bind Python retention to PHP's constant and exercise the oldest-safe versus first-unsafe payload date (`orchestration/tests/test_jobs.py:568-586`).
- Workflow tests inspect actual scheduled activity timeouts for generic stores, contract import and ImportReplay, and assert expired payload refusal before any store/PHP activity (`:589-624`). S input tests inspect store bounds and independently reject an expired reference (`orchestration/tests/test_s_forecast.py:145-149`, `:210-220`).
- The real cleanup command tests preserve both payload and sidecar at the retained date boundary despite old modification times, and delete expired siblings (`laravel/tests/Feature/ImportPayload/ImportPayloadCleanupTest.php:27-77`, `:80-100`). A registry-specific open/closed-owner test is no longer applicable to the manager's selected implementation.
- These tests were inspected, not executed; this report does not independently establish a passing test run.

## Replay/regression check

The five pre-staging recorded histories remain unchanged in the fix range. `orchestration/tests/test_jobs.py:668-672` still replays each through `Replayer(workflows=PRODUCTION_WORKFLOWS)`, without swallowing nondeterminism through workflow failure configuration. The new staged contract-history replay also remains (`:675-685`). The old one-process patch branches remain in `jobs.py:168-171` and `s_forecast.py:148-152`; the refactor does not introduce an additional activity on that path. Replay execution was prohibited and was not attempted.

## Findings

Findings: none.

## Files inspected

- Root project guidance supplied in context; `orchestration/AGENTS.md`; Laravel `Orchestration`, `MorningFreshness` and `ImportPayload` service guidance; `.agents/skills/spec-review/SKILL.md`.
- `tasks/staged-api-imports/{spec.md,design.md,decisions.md,tasks.json}`; original platform/test reports; `tasks/temporal-orchestration/phase2-plan.md`.
- Fix diffs and relevant source: `orchestration/src/voltikka_orchestration/{jobs.py,s_forecast.py,staged.py,activities.py}`; `orchestration/tests/{test_jobs.py,test_s_forecast.py}`; five recorded-history file inventory and S history excerpts.
- `laravel/app/Services/ImportPayload/ImportPayloadCleanup.php`; `laravel/tests/Feature/ImportPayload/ImportPayloadCleanupTest.php`.
- Read-only Git status, range, history and `git diff --check` (clean).
