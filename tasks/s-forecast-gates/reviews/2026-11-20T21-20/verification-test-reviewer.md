# test-reviewer — s-forecast-gates verification
Range: `64bc535e7ea3..6369b5f3` · Assessed HEAD: `6369b5f339a491120cda087b420413f502cfbdbd` · Result: 2 findings (1 normal, 1 blocking)

No tracked local changes in scope. Reviewed only the fixes and affected callers.

## Finding verification

| ID | Status | Evidence and assessment |
|---|---|---|
| TST-001 | fixed | `orchestration/tests/test_s_forecast.py:489–551`: independent closed-gate table exercises all ten readiness gates, exact fetch commands/options, EEX recheck-only behavior, deadlines and missing outcomes; both outage windows are exercised. The new outage assertion has a timing flake, reported separately below. |
| TST-002 | fixed | `orchestration/tests/test_s_forecast.py:554–576`: two missed gates, changing fetch errors, a final store failure, and one Issue containing both latest failures. `orchestration/tests/test_jobs.py:530–542`: exhausted retry expects `down_12` and no store. |
| TST-003 | fixed | `orchestration/tests/test_s_forecast.py:294–316`: early-exit test now checks both report scheduling time and workflow close time at 11:30, with explicit jitter tolerance. |
| PLT-001 (tests) | fixed | `orchestration/tests/test_s_forecast.py:579–603` and `orchestration/tests/test_jobs.py:545–571`: store-side `fetch_incomplete` triggers new acquisition attempts, recovery stays quiet, exhaustion reports step `fetch` and the payload. Existing genuine-store-failure tests remain strong (`test_s_forecast.py:338–360`, `test_jobs.py:574–582`). Matches the manager's chosen exception rather than reopening the original proposed fix. |
| PLT-002 (tests) | partly | `orchestration/tests/test_s_forecast.py:606–622` proves a failing readiness call has a deadline-bounded budget and the run closes at the cut. However, `:625–632` does not exercise a post-cut ready result: its row appears at **11:29**, before the 11:30 cut, and every fake call returns immediately. See TST-V-002. |
| PLT-003 (tests) | fixed | `orchestration/tests/test_jobs.py:595–625` checks the revised Schedule inputs: daily/weekly/monthly and 12-hour collectors get 120 minutes; both hourly jobs and the four-hour Previous Runs job get zero; other groups remain zero and no collector self-reports. `orchestration/src/voltikka_orchestration/schedules.py:112–134` implements that decision. The 120-minute behavior is exercised through `ArtisanJob.run` at `test_jobs.py:514–542`. |

## New findings

### TST-V-001 — New outage-window assertion is intermittently red
- **Severity**: Low
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: none; over-specified timing precision, not the required five-minute cadence
- **Evidence**: `orchestration/tests/test_s_forecast.py:87–94,539–549`; the first targeted two-file run failed at line 548 with final fetch time `14:24` instead of `14:25`. The identical rerun passed all 122 tests; five subsequent outage-only runs also passed.
- **What is wrong**: Exact equality compares minute-truncated timestamps. A small early timer firing can become the previous whole minute, although the same file explicitly allows timer jitter in `near()` (`:288–291`). The intermittent failure is confirmed; the precise sub-minute offset of the failed attempt was not captured, so that root-cause detail remains **unconfirmed**.
- **User-impact scenario**: A correct gate run intermittently fails the verification/CI gate, delaying a release or encouraging developers to disregard a genuinely useful deadline test.
- **Fix**: Loosen only the new timestamp assertions: use the fixture's full scheduled instants and the existing small tolerance around each expected instant. Preserve attempt count/order, command, missing-gate and deadline assertions. Apply the same treatment to the new closed-gate timestamp equality where appropriate; no production-code change is needed.

### TST-V-002 — The replacement late-input test never crosses the cut
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: requirements 3/5/7; manager's PLT-002 decision that no late readiness result opens a scheduled gate
- **Evidence**: `orchestration/tests/test_s_forecast.py:87,109–113,606–632`; `orchestration/src/voltikka_orchestration/s_forecast.py:324–330,342–352,362–379`.
- **What is wrong**: `test_a_row_that_appears_after_the_cut_never_opens_the_gate` sets availability to 11:29 and receives an immediate false response at the last 11:28 check. It only proves that the workflow does not check again. `test_a_readiness_check_failing_across_the_cut_misses_the_gate` covers repeated failures, never a recovered ready response. Neither exercises a slow final fetch/store followed by readiness at or after 11:30. This matters because `_key_ready()` bounds/rejects late results only when the **check started before** the deadline; a scheduled gate resuming after its previous activity exhausted the deadline can otherwise enter the unrestricted path intended for retrospective manual checks. The coverage gap is confirmed; the delayed-store runtime scenario below was not executed and remains **unconfirmed**.
- **User-impact scenario**: The final 11:25 store consumes its remaining budget and times out at 11:30. A subsequent readiness call encounters a short database outage and recovers after the required row first appears at 11:31. A regression treating that scheduled check like a late manual check can accept the input and eventually store a forecast instead of reporting the missed frozen cut; the current tests would not catch it.
- **Fix**: Add `test_a_scheduled_gate_cannot_open_after_its_last_store_exhausts_the_cut` through real `SDailyForecast.run` and the time-skipping server, faking only command/report activities. Make the final store exhaust its deadline; make readiness fail until a row becomes ready strictly after 11:30. Assert `no_forecast`, the missed gate, one `inputs_missing` Issue, no `predict`, and no fresh unrestricted post-cut readiness retry window. Keep the existing late-manual-start test to protect its explicit exception. Use the environment's actual advanced clock for this scenario, not the fake's scheduled-minute string. Blast radius: S workflow tests and any narrowly required scheduled/manual deadline distinction exposed by that test.

## Tests run

1. `cd orchestration && UV_OFFLINE=1 SENTRY_DSN= SENTRY_LARAVEL_DSN= uv run pytest -q tests/test_s_forecast.py tests/test_jobs.py`
   - First run: **1 failed, 121 passed in 13.82s** — TST-V-001.
   - Identical rerun: **122 passed in 13.80s**.
2. Additional targeted repetitions:
   ```bash
   cd orchestration && for attempt in 1 2 3 4 5; do printf '\nTargeted outage run %s\n' "$attempt"; printf 'p list(zip(fake.names(), fake.scheduled))\nc\n' | UV_OFFLINE=1 SENTRY_DSN= SENTRY_LARAVEL_DSN= uv run pytest -q tests/test_s_forecast.py::test_each_outage_gate_obeys_its_window --pdb --tb=short; done
   ```
   - **2 passed in each of five runs**; no debugger entry occurred.
3. `git diff --check 64bc535e7ea3..6369b5f3` — passed.

Used the cached local Temporal time-skipping server. No live API, Temporal Cloud, production, or Laravel test access. No source/test edits.

## Files inspected

- Repository `AGENTS.md` and `laravel/AGENTS.md` (Test isolation); `/AGENTS.md` is absent.
- `orchestration/AGENTS.md`; `tasks/AGENTS.md`; `.agents/skills/spec-review/SKILL.md`.
- `tasks/s-forecast-gates/{spec.md,decisions.md,tasks.json}`.
- First-round `test-reviewer.md` and `platform-reviewer.md` in this review directory.
- Fix diff for `orchestration/src/voltikka_orchestration/{jobs.py,s_forecast.py,schedules.py,staged.py}` and affected tests.
- Affected `run_steps`, `ImportReplay`, `SWeeklyRefit`, old daily replay/readiness callers, and `activities.py` error-key extraction.
- `orchestration/tests/{conftest.py,test_s_forecast.py,test_jobs.py}` and `orchestration/pyproject.toml`.
