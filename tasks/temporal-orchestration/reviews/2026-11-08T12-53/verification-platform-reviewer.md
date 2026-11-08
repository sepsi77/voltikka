# platform-reviewer — phase 2 verification

Range: `12f5e21..f6452f2` · Earlier findings: 7 fixed, 1 partly fixed, 0 not fixed · New findings: 2

Static inspection only. No tests, Railway commands, production database access, or live API calls were run. Other local changes are excluded. These results do not certify production configuration or a production cut-over.

## Earlier finding → status → evidence

| Earlier finding | Status | Evidence |
|---|---|---|
| PLT-001 — R1 removes groups that it does not start | Fixed | `laravel/routes/console.php:11-14` states the collectors-only cut-over. The other jobs remain at `:17-181`, including imports, Spot, backups, consumers, and social publication. `supervisord.conf:25-33` retains the web queue worker. `tasks/temporal-orchestration/phase2-plan.md:79-102` now requires separate R3 and R4 PRs after Redis. |
| PLT-002 — Noon checks wait behind producers and consumer work | Partly fixed | `orchestration/src/voltikka_orchestration/jobs.py:267-269,285-293` gives each consumer a separate lane and a producer wait that ends at noon. `:300-304` preserves the noon tick for a failed claim. But `:306` still awaits that lane's own command, with a 120-minute timeout at `:293`. There is no separate deadline task while that command runs. A command started at 11:55 can therefore prevent its noon check until 13:55. PHP reserves the alert only when another invocation reaches `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:30-31`, or when the writer finishes at `:106-107`. The two-lane change removes cross-consumer blocking, but does not preserve the independent noon check during a live writer. |
| PLT-003 — Failed morning work appears completed | Fixed | `orchestration/src/voltikka_orchestration/jobs.py:195-198` fails EEX with `ReportedFailure`; `:224-230` carries completion and evaluation failures into the contract child result. `:275-282` reports an incomplete consumer outcome and fails the parent. Completion now uses the scheduled-scope fact at `:296-298`, supplied by `laravel/app/Services/Orchestration/MorningReadiness.php:50-58`, not raw `ready`. The remaining deadline-timing gap is PLT-002, not a second outcome-mapping finding. |
| PLT-004 — Completion sends two Issues | Fixed | `orchestration/src/voltikka_orchestration/jobs.py:171` sets `self_reports=True`. `:107-110,130-135` suppresses the wrapper Issue only for a self-reporting `CommandFailed`. Other exhausted activity failures still get a workflow Issue. |
| PLT-005 — Old lower clock gates override event chaining | Fixed | `laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:20-24` retains the current-date guard but removes the lower clock bound. Noon admission remains at `:30-31`. `laravel/routes/console.php:133-137,153-157` retains the old cron start windows until the later morning cut-over. |
| PLT-006 — Shutdown interrupts a live completion claim | Fixed for the reported completion path | `orchestration/src/voltikka_orchestration/jobs.py:171` makes the 35-minute completion activity non-interruptible. `orchestration/src/voltikka_orchestration/activities.py:93-105` then does not kill it on the shutdown notification. `orchestration/src/voltikka_orchestration/worker.py:21,48-51` allows a 38-minute drain. `tasks/temporal-orchestration/phase2-plan.md:81-83` requires a longer Railway drain. This corrects the original completion scenario, subject to that production setting. The new blanket protection claim for longer consumer/social steps has a separate defect, PLT-V01. |
| PLT-007 — Self-report suppression hides infrastructure failures | Fixed | `orchestration/src/voltikka_orchestration/jobs.py:107-110` requires an `ApplicationError` of type `CommandFailed` before suppression. `:130-135` reports all other activity failures, including timeouts and exhausted lost attempts. `:195-198` also makes failed EEX visible as a failed child. |
| PLT-008 — Optional gas-key filter is lost | Fixed | `orchestration/src/voltikka_orchestration/schedules.py:59` passes `--if-configured`. `laravel/app/Console/Commands/FetchGasStorage.php:38-46` returns success without fetching when the optional key is absent and that option is set. An ordinary manual call without the option still fails for a missing key. |

## New findings

### PLT-V01 — The new drain is shorter than protected consumer and social activities
- **Severity**: High
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S1, S6; phase2-plan.md, retry and deploy-drain rules.
- **Evidence**: `orchestration/src/voltikka_orchestration/worker.py:20-21,48-51`; `orchestration/src/voltikka_orchestration/jobs.py:293`; `orchestration/src/voltikka_orchestration/schedules.py:93`; `orchestration/src/voltikka_orchestration/activities.py:106-111`; `tasks/temporal-orchestration/phase2-plan.md:59-62`.
- **What is wrong**: The new protection marks morning consumers and weekly social publication as non-interruptible, but permits them to run for 120 and 60 minutes. The worker drains for only 38 minutes, and the proposed Railway limit is 40 minutes. The comment that completion is the longest protected step is therefore false. When the drain ends, activity cancellation still kills PHP. `interruptible=False` only bypasses the early shutdown notification; it does not prevent that later cancellation. No shorter command execution bound was found in the consumer entrypoints or execution service.
- **User-impact scenario**: After R4, a consumer claims its checkpoint and needs 50 minutes. A worker deploy starts one minute into that run. At about minute 39 the drain ends and kills the command. Its activity allows one attempt, and the durable running claim has no takeover (`laravel/app/Services/MorningFreshness/MorningConsumerExecution.php:33-36`). The replacement worker cannot finish that day's publication. This is the exact class of interruption that the new drain claims to prevent. The same drain mismatch applies to the protected 60-minute social step after R3.
- **Fix**: Before R3/R4 activation, align the supported drain with every protected activity's maximum remaining runtime, including margin, and verify that Railway supports it. If the platform limit is too short, retain a deploy guard for those live claims until an approved bounded/resumable implementation exists. Required shape: `platform_drain > graceful_shutdown > longest_protected_execution`. Do not add automatic claim takeover or retry social publication. Blast radius: worker shutdown configuration, protected-step limits, and the rollout instructions; no financial calculation change. This does not block the collectors-only cut-over by itself.

### PLT-V02 — The new Redis rollback sequence restores schedules before it pauses Temporal
- **Severity**: High
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S9; phase2-plan.md decision 4, one schedule owner per group.
- **Evidence**: `tasks/temporal-orchestration/phase2-plan.md:105-108`; restored scheduler entries in `laravel/routes/console.php:54-82,86-114,170-181`; Temporal jobs in `orchestration/src/voltikka_orchestration/schedules.py:78-104`.
- **What is wrong**: The new R2-after-R3 rollback instruction lists `deploy the R2-era web image` before `pause the maintenance/spot/morning Schedules`. This reverses the safe order stated in the preceding sentence. The restored image starts Laravel schedules while their Temporal Schedules can still start the same commands. Laravel's scheduler mutex is not acquired by `ArtisanJob`.
- **User-impact scenario**: After R3, an operator follows this rollback near an hourly boundary. The R2-era web image becomes ready before the Temporal spot group is paused. Both systems start the hourly Spot import. The rollback has introduced two active schedule owners, with duplicate import/cache work. A rollback near a maintenance trigger has the same conflict.
- **Fix**: Make the specific rollback order match the general rule: pause the moved groups first, inspect and finish their active runs, then restore the R2-era web scheduler and stop the separate queue service; only after all cache writers are back on the web may the store return to `file`. Example: `pause -> finish active runs -> restore web ownership -> switch cache store`. Blast radius: rollback runbook only. No production operation is authorized by this report.

## Files inspected

- The supplied verification prompt, `.pi/agents/platform-reviewer.md`, all five first-round reports, and `summary.md`.
- Task `spec.md`, `decisions.md`, `tasks.json`, and `phase2-plan.md`.
- Relevant root context sections; `orchestration/AGENTS.md`; Laravel orchestration, morning-freshness, and import context files.
- Fix diff and current `orchestration/src/voltikka_orchestration/{jobs,activities,worker,schedules,sentry}.py`; new scenarios in `orchestration/tests/test_jobs.py` (source inspection only).
- `laravel/routes/console.php`, `supervisord.conf`, `docker-entrypoint.sh`, `MorningReadiness.php`, `MorningConsumerExecution.php`, `FetchGasStorage.php`, and consumer entrypoint claim/execution paths.
- `git diff --check 12f5e21..f6452f2`: no whitespace errors.
