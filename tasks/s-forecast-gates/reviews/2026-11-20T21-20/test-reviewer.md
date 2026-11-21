# test-reviewer — s-forecast-gates
Range: b2754eb8d283..64bc535e7ea3 (+ local changes: none in scope) · Result: 3 findings

## Spec checklist

Test paths below are relative to `orchestration/`.

| # | Spec item | Test(s) | Verdict | Notes |
|---|---|---|---|---|
| S1 | One gate per readiness key and two outage proofs | `tests/test_s_forecast.py::test_one_gate_per_readiness_input_and_the_two_outage_proofs`, `test_every_gate_opens_and_the_model_runs_at_the_afternoon_cut` | ✅ Pass | PHP key/view policy check plus time-skipping workflow execution. |
| S2 | Check/fetch/repeat; failed fetch is not final | `tests/test_s_forecast.py::test_a_gate_opens_after_a_late_input`, `test_a_failed_fetch_is_repeated_and_a_later_success_opens_the_gate_without_an_issue`, `test_late_fingrid_capacity_rows_open_the_afternoon_gate` | ✅ Pass | Real workflow and staged-fetch orchestration, faked activities only. The next-pass check follows the recorded implementation decision. |
| S3 | Gate commands, starts, deadlines and outage intervals | `tests/test_s_forecast.py::test_every_gate_opens_and_the_model_runs_at_the_afternoon_cut`, `test_the_vintage_gates_never_fetch_after_their_cut`, outage and deadline tests | ⚠️ Partial | Several gate-specific command/deadline rules are never exercised with the key closed; TST-001. |
| S4 | Parallel in-workflow gates; no signals | `tests/test_s_forecast.py::test_every_gate_opens_and_the_model_runs_at_the_afternoon_cut`, `test_late_fingrid_capacity_rows_open_the_afternoon_gate` | ✅ Pass | Interleaved start times and independent delayed-input progression exercise concurrency. Source inspection confirms no cross-workflow signaling. |
| S5 | Predict/score after all gates, unchanged cuts | `tests/test_s_forecast.py::test_every_gate_opens_and_the_model_runs_at_the_afternoon_cut`, `test_model_failure_is_one_issue_with_its_error_key` | ✅ Pass | Predict cut arguments and score sequencing checked. |
| S6 | One Issue names every missed gate and its last failure; recovery quiet | `tests/test_s_forecast.py::test_a_missed_afternoon_deadline_names_the_gate_and_its_last_failure`, outage failure/recovery tests | ⚠️ Partial | All failure scenarios have one missed gate and invariant error facts; TST-002. |
| S7 | Morning miss ends at 11:30 | `tests/test_s_forecast.py::test_a_missed_morning_gate_ends_the_run_at_the_cut_with_one_issue` | ⚠️ Partial | Checks command timestamps, not Issue or workflow completion time; TST-003. |
| S8 | Patched replay and gate query | `tests/test_jobs.py::test_a_workflow_from_before_staged_imports_replays_on_the_new_code`, `tests/test_s_forecast.py::test_the_stage_query_reports_the_gates` | ✅ Pass | Both pre-gates daily histories replay; new gate history also replays. |
| S9 | Manual outage skip and already-passed deadlines | `tests/test_s_forecast.py::test_manual_start_after_the_morning_cut_needs_explicit_skip` | ✅ Pass | Covers ready and missing late-start evidence without fetches. |
| S10 | Bounded collector fetch retry; one store; final Issue | `tests/test_jobs.py::test_a_failed_collector_fetch_is_retried_and_recovers_without_an_issue`, `test_an_exhausted_collector_fetch_retry_sends_one_issue`, `test_a_failed_store_is_not_retried_by_the_collector_fetch_retry`, retry-table policy test | ⚠️ Partial | Recovery, intervals, budgets and single store covered; latest-error provenance unproved, TST-002. Accepted 25/45-minute caps follow decisions.md. |
| S11 | No self-reporting/other-group retries; collector policy guard | `tests/test_jobs.py::test_a_self_reporting_step_is_never_retried`, `test_only_staged_collector_steps_retry_a_failed_fetch_and_none_self_reports`, `test_failed_step_is_not_retried_skips_later_steps_but_runs_always_steps` | ✅ Pass | Policy guard is present and not weakened. |
| S12 | Deploy drain unchanged; durable retry timer | `tests/test_jobs.py::test_a_collector_fetch_retry_replays_deterministically`, `test_claim_based_steps_finish_during_a_deploy_drain`, both `test_a_deploy_*` tests | ✅ Pass | Retry history replays; existing real-process shutdown/drain tests pass. |
| S13 | Context/interface docs updated | Manual inspection of `AGENTS.md`, `orchestration/AGENTS.md`, workflow interface | ✅ Pass | Documentation-only acceptance item. |
| S14 | D1 time-skipping acceptance tests | Tests above and recorded-history replay parameterization | ⚠️ Partial | 104 targeted tests pass, with the coverage limitations above. |
| S15 | D2 replay check recorded | `test_late_fingrid_capacity_rows_open_the_afternoon_gate`; `decisions.md:138–161` | ✅ Pass | Measurement and analytical historical check recorded; synthetic regression executes the repeated-fetch pattern. |
| S16 | D3 Laravel unaffected | Range inspection | ➖ N/A | Only PHP change is a comment in `laravel/config/orchestration.php`; no Laravel tests permitted or run. |
| S17 | D4 five production issue dates after deploy | `tasks.json` pending item | ➖ N/A | Explicitly open and requires separately approved deployment; no production access attempted. |

## Findings

Pass 1 — over-specified tests: no actionable findings. Timing assertions predominantly encode the explicit gate table; the per-gate readiness pattern is also recorded in decisions.md. No policy-test weakening was found.

Pass 2 — behavioral coverage:

### TST-001 — Several gate-table rules have no closed-gate regression test
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S3 (requirement 3), S14
- **Evidence**: `orchestration/tests/test_s_forecast.py:169–193`, `orchestration/tests/test_s_forecast.py:425–433`; `orchestration/src/voltikka_orchestration/s_forecast.py:79–87`.
- **What is wrong**: The happy-path test initializes every readiness key as ready, so these gates never use their fetch/deadline rules. The table policy test compares keys/views and only checks `start < deadline`. No scenario closes `eex_futures`, `weather_ecmwf_00z`, `day_ahead_d`, or `jao_capacity_d`. Consequently changing EEX from recheck-only to fetching `futures:fetch-eex`, changing the ECMWF command's `--only` option, or moving one of those morning deadlines to 18:00 is not caught by the existing scenarios. The vintage/Fingrid/day-ahead-D+1 tests exercise different entries and do not protect these rules.
- **User-impact scenario**: A future gate-table edit starts a second EEX producer from the forecast workflow, or fetches the wrong weather run. Operators see competing imports or a forecast lost despite the intended input being obtainable. A wrong morning deadline can also delay the terminal missing-input outcome.
- **Fix**: Add a parameterized `test_each_gate_obeys_its_fetch_command_and_deadline` through `SDailyForecast.run` in the time-skipping environment. Use an independent expected table, fake only command/report activities, and keep each selected key unready in turn. Assert the permitted command/options, no fetch for EEX, the expected fetch window/deadline, and the missing-gate outcome with no prediction. Retain the existing key/view policy guard. Blast radius: workflow tests only; no change to the accepted gate design.

### TST-002 — Alerts are never tested with multiple misses or changing failure facts
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S6 and S10 (requirements 6 and 10)
- **Evidence**: `orchestration/tests/test_s_forecast.py:89–95`, `orchestration/tests/test_s_forecast.py:240–254`, `orchestration/tests/test_jobs.py:123–128`, `orchestration/tests/test_jobs.py:526–536`; `orchestration/src/voltikka_orchestration/s_forecast.py:283–290,325`.
- **What is wrong**: Each no-forecast scenario misses only one gate. Every failed daily-fetch attempt reports `test_error`, and every collector attempt reports `boom`. Thus `test_a_missed_afternoon_deadline_names_the_gate_and_its_last_failure` and `test_an_exhausted_collector_fetch_retry_sends_one_issue` cannot distinguish the first failure from the last. An implementation that retains first-attempt facts, or reports only the first missed gate, would satisfy those assertions. Recovery tests prove silence after success, not terminal diagnostic completeness.
- **User-impact scenario**: Two inputs remain missing at 18:00, but the operator receives only one gate or an obsolete transient error instead of the final failure. The other required repair is invisible, wasting another forecast date or prompting the wrong investigation.
- **Fix**: Add `test_all_missed_gates_report_their_latest_failures_once`: run the real daily workflow with two afternoon gates closed, distinct per-gate errors that change between attempts, and assert one Issue containing both gates and each final error/step. Add a changing-error collector case through `ArtisanJob.run` asserting one final Issue with the last error and no store. Use faked activities and Temporal time only. Blast radius: the two workflow test files and their fake activity fixtures; no production reporting changes required.

### TST-003 — The early-exit test does not assert when the run actually ends
- **Severity**: Medium
- **Likelihood**: Low
- **Class**: blocking
- **Spec rule**: S7 and S14 (requirement 7 and D1)
- **Evidence**: `orchestration/tests/test_s_forecast.py:112–114`, `orchestration/tests/test_s_forecast.py:268–280`; `orchestration/src/voltikka_orchestration/s_forecast.py:284–290`; `tasks/s-forecast-gates/decisions.md:52–65`.
- **What is wrong**: The early-exit test asserts that the latest `run_command` activity is at 11:30 and that afternoon fetches never start. The fake `report_issue` records no timestamp, and neither the workflow close time nor the environment time after completion is checked. A regression that stops all gate work at 11:30 but waits on dormant timers until 18:00 before reporting/returning still passes. This leaves the explicit early-exit acceptance item only partially proved.
- **User-impact scenario**: A morning input is irrecoverably missing, yet the operator sees an apparently running forecast and receives its failure Issue hours late, even though no further commands execute.
- **Fix**: Strengthen `test_a_missed_morning_gate_ends_the_run_at_the_cut_with_one_issue` to retain the workflow handle and assert its close time, plus the report activity's scheduled timestamp, is 11:30 Helsinki within a small scheduling tolerance. Keep activities instantaneous in this case and preserve the decision to drain active work without cancelling sleeping gate timers. Blast radius: test/fixture assertions only; no reversal of the recorded stop/drain trade-off.

## Tests run

- `cd orchestration && UV_OFFLINE=1 SENTRY_DSN= SENTRY_LARAVEL_DSN= uv run pytest -q tests/test_s_forecast.py tests/test_jobs.py`
  - **104 passed in 11.96s**. Used the already-cached local Temporal time-skipping server; command activities/Sentry transport are faked or local test processes. No live API or Temporal Cloud access.
- `git diff --check b2754eb8d283..64bc535e7ea3` — passed.
- No Laravel tests, production operations, or source/test edits.

## Files inspected

- `AGENTS.md` (project instructions); `/AGENTS.md` is absent in this checkout's filesystem.
- `laravel/AGENTS.md` (including Test isolation).
- `tasks/AGENTS.md`.
- `tasks/s-forecast-gates/spec.md`, `decisions.md`, `tasks.json`.
- `tasks/s-forecast-gates/reviews/` inventory (no earlier review reports present).
- `tasks/temporal-orchestration/s-workflow-interface.md`.
- `laravel/config/orchestration.php` (range diff).
- `orchestration/AGENTS.md`, `pyproject.toml`.
- `orchestration/src/voltikka_orchestration/s_forecast.py` (daily workflow), `jobs.py` (retry/staged-step path), `schedules.py`, `staged.py`.
- `orchestration/tests/conftest.py`, `test_s_forecast.py`, `test_jobs.py`, `test_sentry.py`.
- `orchestration/tests/histories/*.json` through the existing replay tests, including both daily pre-gates histories and the pre-retry collector history.
