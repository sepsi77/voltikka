# Review actions — 2026-11-20T21-20
Verdict after fixes: ACCEPT

## Fixed
| Finding(s) | Commit or local change | Change (one line) |
|---|---|---|
| PLT-001 | 2d0645ff | A store that fails `fetch_incomplete` counts as an acquisition failure: the gate keeps fetching, the collector retry repeats fetch and store |
| PLT-002 | 2d0645ff, 2aa704bf | Last check at deadline − 2 min; every check ends by the deadline; a scheduled pass at/after the deadline is a miss; a late result never opens a gate |
| PLT-003 | 2d0645ff | Fetch retries only for collectors with ≥ 6 h between starts (120 min); hourly and 4-hourly collectors do not retry |
| TST-001 | 2d0645ff | Parameterized closed-gate test against an independent table |
| TST-002 | 2d0645ff | Two missed gates with changing errors; collector exhaustion with changing errors |
| TST-003 | 2d0645ff | Early-exit test asserts report and close time at 11:30 |
| TST-V-001 | 2aa704bf | New timestamp assertions use `near()` |
| TST-V-002 | 2aa704bf | Test for a fetch that runs to the cut; fails on the pre-fix code |

## Not done
| Finding(s) | Sev/Lik | Disposition | Reason | Decided by |
|---|---|---|---|---|
| PLT-001 (part) | — | ignore | A real-command HTTP failure test against the PHP Fingrid command: the contract is pinned at the activity boundary and the PHP side of `fetch_incomplete` is covered by the Laravel staged-import tests | agent under delegated authority |
| PLT-003 (original fix) | — | superseded | Budgeting the whole store against the next start replaced by the narrower retry scope (simpler, no store overlap possible for daily jobs) | agent under delegated authority |
| (implementation note) older minute-string assertions | Low/Low | defer | Same theoretical early-timer flake; none failed in 8 full runs | agent under delegated authority |

## Still owed
| Item | Owner / next step |
|---|---|
| External review (CodeRabbit, Greptile) | manager, PR |
| Merge | user request |
| Deploy, then `schedules --apply --group s-forecast` and `--group collectors` | user approval (each a production change) |
| D4: five issue dates in production | manager after the deploy |

## Codification
- A staged store that fails `fetch_incomplete` is an acquisition failure, not a store bug; any retry rule must read the closed error, not the activity that failed. Written in `orchestration/AGENTS.md` (staged imports).
- A gate deadline is a hard boundary for every readiness result, including retried and resumed ones. Written in `orchestration/AGENTS.md` (gates).
