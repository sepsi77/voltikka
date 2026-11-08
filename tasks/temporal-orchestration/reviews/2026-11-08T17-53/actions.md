# Pi PR review 2026-11-08T17-53: actions

Range: `--base main` (local `main` = 5b1646a; it holds 5b1646a and 323e6ce, which are not yet on
`origin/main`) to HEAD 4c22484 on `temporal-orchestration`, with no local changes. Static review
(no `--run-tests`). Roles: code, tests, errors, types, comments; simplify was skipped by the gate.
Report: `pi-pr-review-toolkit.md`. The manager made the fixes directly (each was a few lines).

## Triage

| # | Finding | Verdict | Action |
|---|---|---|---|
| 1 | Bare `schedules --apply` starts every group, also those still in `console.php` | Accepted (important) | `--apply` needs `--group` or `--only` (argparse error before the connection); `parse_args` and `select` extracted; test `test_apply_needs_an_explicit_group_and_selects_only_that_group`. |
| 2 | `MorningInput.date` does not constrain the date the commands write | Accepted (R4 code) | `MorningChain` refuses a past date before any command and waits until 04:00 of a later date. A run that starts on its own date cannot cross midnight (producer timeouts; consumers end at noon + at most 60 min), so no per-activity date check is added. Test `test_a_morning_for_a_past_date_is_refused_before_any_command`. |
| 3 | `contracts:fetch` exit 0 with `incomplete` counts as a succeeded import | Accepted (R4 code) | Verified: a partial acquisition stores `STATUS_INCOMPLETE` and returns `SUCCESS` (`FetchContracts.php`), and `DataFetchFailureReporter` already sends its Issue. `ContractImport` now reads the checkpoint after exit 0: `ready` is success; `incomplete` fails without a second Issue; any other state fails with one `import_not_ready` Issue. Evaluation still runs. Parametrized test `test_an_import_that_exits_0_without_a_ready_checkpoint_fails`. |
| 4 | No process-level SIGTERM or heartbeat-loss test | Accepted, deferred | Same item as the spec-review deferral (`2026-11-08T12-53/actions.md`): the time-skipping test server cannot drive it; SIGKILL and SIGTERM recovery were checked by hand on a local dev server. Stays a backlog item; the R4 acceptance test runs a real worker. |
| S1 | `max_attempts` comment says "extra attempts" | Accepted | Comment: total attempts, including the first. |
| S2 | Queue deploy effect promises a retry after `DB_QUEUE_RETRY_AFTER` | Accepted | Message corrected; the worker message was also stale ("running activities are retried") and now states the drain rule. |
| S3 | No test of a consumer activity that stays active across noon | Accepted, deferred | The time-skipping server does not skip time while an activity runs, so a fake cannot hold an activity across noon cheaply. Covered by the required real PHP + Temporal + database acceptance test before R4. |

Checks: `uv run pytest -q` in `orchestration/`: 40 passed. `bash -n scripts/deploy-image.sh` OK.
No PHP code changed in this round.

## Codification

| Finding | Owner | Cause | Instruction changed |
|---|---|---|---|
| 1 Bare apply | platform-reviewer | (d) cut-over rule written; the checklist pointed at `console.php` and `schedules.py`, not at the operator tool's default selection | `.pi/agents/platform-reviewer.md` item 4 (+ `.claude` copy) |
| 2 Workflow date vs written date | platform-reviewer | (c) no written rule | Rule in `orchestration/AGENTS.md` (MorningChain); checklist item 1 |
| 3 Exit 0 is not success | platform-reviewer | (d) "checkpoints stay the truth" is written in `orchestration/AGENTS.md`; checklist item 3 checked only Issue counts | Checklist item 3 |
| 4 Recovery test | test-reviewer | none: the spec review found it; the deferral stands | none |
| S1 Retry comment | refactor-reviewer | none: one-word comment error; the stale-comment focus already covers it | none |
| S2 Deploy effect text | platform-reviewer | (d) item 7 checked refusals, not the stated effect | Checklist item 7 |
| S3 Noon across an active consumer | test-reviewer | none: deferred to the R4 acceptance test | none |
