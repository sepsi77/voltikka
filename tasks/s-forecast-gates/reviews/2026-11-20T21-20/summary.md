# S forecast gates — spec review 2026-11-20T21-20
Initial range: b2754eb8d283..64bc535e7ea3 (no local changes) · Reviewers run: refactor-reviewer, test-reviewer, platform-reviewer (Pi, openai-codex/gpt-6.1-sol) · Skipped: laravel-reviewer (only a PHP config comment changed), pricing-reviewer (no published figure), frontend-reviewer and copy-reviewer (no UI or public text)
Verification range: 64bc535e7ea3..6369b5f3 (platform-reviewer, test-reviewer)
Final assessed HEAD: ecadac47 (2aa704bf checked by manager inspection and the full pytest run)
Later source changes: 2aa704bf (PLT-002 remainder, TST-V-001, TST-V-002): manager read the diff; `uv run pytest` 137 passed (agent: five runs in a row, 137 each); the new test fails on the pre-fix code (agent reverted, ran, restored).

## Spec items
S1 one gate per readiness key plus the two outage proofs (req 1) · S2 gate loop (req 2) · S3 gate table and deadlines (req 3) · S4 parallel gates in the workflow, no signals (req 4) · S5 model run unchanged (req 5) · S6 one Issue naming missed gates and last failures (req 6) · S7 early exit (req 7) · S8 workflow.patched, stage query (req 8) · S9 manual starts (req 9) · S10 collector fetch retries (req 10, scope narrowed by manager decision after PLT-003) · S11 self-reporting and other groups not retried (req 11) · S12 deploy drain unchanged (req 12) · S13 docs (req 13) · S14 D1 tests · S15 D2 replay check · S16 D3 Laravel unaffected · S17 D4 production check

## Verdict: ACCEPT
Every must-fix finding is fixed with a test that fails without it. D4 is open by design: it needs the deploy and the Schedule applies, each with its own approval.

## Spec rollup
17 items: ✅ 15 · ⚠️ 0 · ❌ 0 · ➖ 2

| Item | State | Reason |
|---|---|---|
| S1–S9, S11–S15 | ✅ | Code and time-skipping tests (137 passed); PLT-001/002, TST-001..003 fixed |
| S10 | ✅ | Daily, weekly and monthly collectors retry for 120 min; hourly and 4-hourly do not (manager decision after PLT-003) |
| S16 | ➖ | No PHP behavior change (one comment) |
| S17 | ➖ | Production check after the approved deploy |

## Blocking findings
| ID(s) | Title | Sev/Lik | Spec rule | Owner |
|---|---|---|---|---|
| PLT-001 | Partial fetches looked like store failures, so neither loop retried them | High/Medium | S2, S6, S10 | executor (2d0645ff) |
| PLT-002 | A readiness check could open a gate after its cut | High/Medium | S3, S5, S7 | executor (2d0645ff, 2aa704bf) |
| PLT-003 | The collector retry cap did not bound the store before the next start | High/Medium | S10 | executor (2d0645ff, scope narrowed) |
| TST-001 | Gate-table rules untested for closed gates | Medium/Medium | S3, S14 | executor (2d0645ff) |
| TST-002 | Multiple misses and changing failure facts untested | Medium/Medium | S6, S10 | executor (2d0645ff) |
| TST-003 | Early-exit time not asserted | Medium/Low | S7, S14 | executor (2d0645ff) |
| TST-V-002 | No test for a pass that begins after the cut | Medium/Medium | S3, S7 | executor (2aa704bf) |

## Non-blocking findings
| ID(s) | Title | Sev/Lik | Class |
|---|---|---|---|
| TST-V-001 | New minute-string timestamp assertions flake | Low/Medium | normal (fixed, 2aa704bf) |

## Verified
- Patch: both recorded S histories fail replay without `workflow.patched("s-forecast-gates")` and pass with it (implementation report).
- `fetch_incomplete` contract: `StagedImport.php` fetch exits 0 with failed units; the store exits 1 with `step: fetch`, `error: fetch_incomplete`; `activities.py` carries the key (platform verification).
- Refactor review: clean.
