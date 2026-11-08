# Pi PR Review Toolkit

## Scope

Reviewed the full committed range:

- Base: `5b1646a47d49eb08da5281988465fff67adb8ff6`
- Head: `4c22484da52b19b04d41916be559bae65b497bab`
- Branch: `temporal-orchestration`
- Changed scope: 55 files, including implementation, tests, review configuration and documentation.

No staged, unstaged or untracked changes were present at the start or end. HEAD did not change. GitHub reported no PR for this branch.

Secrets, dependencies, generated files and binaries were outside the source review scope.

## Review roles

| Role | State |
|---|---|
| Code | Completed |
| Tests | Completed |
| Errors | Completed |
| Types | Completed |
| Comments | Completed |
| Simplify | Skipped: important issues remain, as required by the review gate |

The five independent reviews ran in parallel. Reported findings were checked against the source and duplicates were combined.

## Critical issues

None confirmed.

## Important issues

### 1. Bare schedule apply enables groups that must remain on the web scheduler

**Roles:** Code, Errors, Tests  
**Location:** `orchestration/src/voltikka_orchestration/schedules.py:184–193`  
**Code confidence:** 98/100

Without `--group` or `--only`, `--apply` submits every schedule. This includes maintenance, Spot and morning jobs. Those jobs still run from `laravel/routes/console.php`; R1 removes only collectors.

**Impact:** The previously supported bare apply command can start duplicate writers. Before Redis, worker cache writes also go to a private store that the web service cannot read.

**Next step:** Require an explicit selector for `--apply`, before connecting to Temporal. Add CLI tests that verify rejection of bare apply and exact collectors-only selection. Keep the unfiltered read-only plan available.

### 2. Morning workflow dates do not constrain writer dates

**Roles:** Types, Tests  
**Location:** `orchestration/src/voltikka_orchestration/jobs.py:197–213,257–264,305–326`

`MorningInput.date` controls waits and checkpoint reads. The producer and consumer commands instead derive the current Helsinki date. `EexFutures` does not use the supplied date.

Starting yesterday’s workflow, or resuming a delayed activity after midnight, can therefore read yesterday’s checkpoints while writing today’s facts.

**Impact:** An old workflow can start unintended current-day work and replace current producer checkpoints.

**Next step:** Enforce the expected date at the activity or command boundary before writes. Reject expired runs. Add tests for a previous-date input and an activity resumed across Helsinki midnight.

**Release scope:** This concerns the R4 morning code, not collectors-only R1. Resolve it before morning activation.

### 3. An incomplete contract import can finish as succeeded

**Roles:** Errors, Types  
**Location:** `orchestration/src/voltikka_orchestration/jobs.py:213–234`

The workflow uses process success as its import outcome and checks the checkpoint only for `pending_completion`. However, `FetchContracts.php` can return success for partial acquisition while storing `contract_import=incomplete`.

**Impact:** Temporal shows a successful producer despite incomplete acquisition. PHP warnings and consumer freshness gates remain intact, so this is an operator-status defect, not a pricing-gate bypass.

**Next step:** Read the final checkpoint state. Report full success only for `ready`; preserve incomplete, failed and missing outcomes. Keep evaluation independent and do not retry acquisition automatically. Add a partial-acquisition workflow test.

**Release scope:** Resolve before R4 activation.

### 4. Tests do not exercise real signal and heartbeat-loss recovery

**Role:** Tests — criticality 6/10  
**Location:** `orchestration/tests/test_jobs.py:190–201,441–476`

The lost-attempt test raises an `ApplicationError` named `ActivityTimeout`; it does not cause a Temporal heartbeat timeout. Deploy tests call `worker.shutdown()` directly rather than sending SIGTERM through the production entrypoint.

**Impact:** These tests can pass if production signal handling or crash recovery breaks. R1 collectors already depend on that recovery.

**Next step:** Add process-level tests for SIGTERM and abrupt worker loss. Start a replacement worker and verify attempt counts, completion and reporting.

This is a coverage gap, not evidence that recovery currently fails.

## Suggestions

### Correct the retry-count comment

**Role:** Comments  
**Location:** `orchestration/src/voltikka_orchestration/jobs.py:62–63`

“Extra attempts” is incorrect: Temporal’s `maximum_attempts` includes the first attempt.

**Impact:** Maintainers can misunderstand the retry budget.

**Next step:** Use: “Total attempts, including the first. A failed command is never retried.”

### Correct the queue deployment effect

**Role:** Comments  
**Location:** `scripts/deploy-image.sh:77`

The new queue message promises that a running job is retried after `DB_QUEUE_RETRY_AFTER`. Laravel can instead finish the job after SIGTERM and then exit.

**Impact:** The approval summary gives an incorrect description of expected behavior.

**Next step:** State that work can finish during shutdown; interrupted work follows queue retry rules.

### Test an activity that remains active across noon

**Role:** Tests — criticality 4/10  
**Location:** `orchestration/tests/test_jobs.py:286–298`

The fake marks late completion during a new noon tick. It does not keep an earlier consumer activity active across noon.

**Impact:** This does not prove that the independent noon check runs while a consumer remains active.

**Next step:** Add that case before R4 acceptance.

## Type-design assessment

Scores are out of 10.

| Area | Encapsulation | Invariant expression | Usefulness | Enforcement |
|---|---:|---:|---:|---:|
| Step/job records | 7 | 6 | 9 | 7 |
| Command transport records | 7 | 6 | 9 | 7 |
| Morning inputs/results | 6 | 5 | 8 | 4 |
| PHP morning readiness | 8 | 8 | 9 | 8 |

Plain typed records are suitable. No additional abstraction layer is needed.

## Strengths

- Laravel removes only the collectors group.
- Nonzero command exits are non-retryable.
- Claim-based commands have explicit drain protection.
- PHP distinguishes manual subset success from scheduled completion.
- Tests cover step ordering, failure reporting, schedule times and graceful worker replacement.
- Documentation separates current R1 work from later releases.

## Recommended action

Fix the schedule-apply guard first. Add recovery coverage. Resolve the morning findings before R4 activation, then repeat the affected reviews.

## Checks and limits

- Used the exact requested Git range.
- Read repository instructions, task context, source, relevant callers and tests.
- No tests, builds, lint or type checks were run.
- No files, infrastructure or production data were changed.
- Live Railway drain limits and PHP–Temporal–database behavior were not verified.
- Pre-existing cancellation and deployment wording was not counted as a new regression.

No confirmed critical issue does not prove that the branch is correct.

<!-- pi-pr-review:complete -->
