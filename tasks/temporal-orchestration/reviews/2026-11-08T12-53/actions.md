# Review actions — 2026-11-08T12-53
Verdict after fixes: ACCEPT (for R1, the collectors-only release)

## Fixed
| Finding(s) | Commit or local change | Change (one line) |
|---|---|---|
| PLT-001, PRC-001 | f6452f2 | Only the collectors group leaves `console.php`; queue and other groups stay in web until Redis; runbook R1–R5. |
| LAR-001 | f6452f2 | Readiness exports `scheduled_complete`; a manual subset `ready` is not done. |
| LAR-002, PLT-005, PRC-003 | f6452f2 | PHP 07:15/07:30 lower bound removed; cron windows stay until R4. |
| LAR-005, PRC-002 | f6452f2 | One concurrent lane per consumer. |
| LAR-003, PLT-002, PLT-003 | f6452f2 + local | Failed children and incomplete mornings fail with one Issue; separate noon check that waits for no producer or running command; late completion (`deadline_alerted`) counts as incomplete. |
| LAR-004, PLT-004 | f6452f2 | `contracts:complete-import` self-reports. |
| PLT-007 | f6452f2 | Lost/timed-out attempts of self-reporting steps are reported by the workflow. |
| PLT-006, PLT-V01 | f6452f2 + local | Worker drain: interruptible commands repeat on the new worker; claim-based steps finish; drain 65 min > every protected timeout (60 min), Railway 4000 s, test enforces the order. |
| PLT-008 | f6452f2 | `gas-storage:fetch --if-configured`. |
| PLT-V02 | local | Rollback order: pause Temporal and let runs end before the old web image returns. |
| TST-001 | f6452f2 | Independent schedule table test (inventory, args, calendars, SKIP, registry). |
| TST-002 (part) | f6452f2 | Real activity: non-zero exit runs once; shutdown kills and repeats; claim-based step finishes. |
| TST-004 | f6452f2 + local | One Sentry event per failure through real activity, interceptor and production `sentry.init` (transport replaced only). |
| REF-001 | f6452f2 | Context files describe the staged state. |

## Not done
| Finding(s) | Sev/Lik | Disposition | Reason | Decided by |
|---|---|---|---|---|
| TST-002 (rest): real heartbeat-loss test | Medium/Low | defer | The time-skipping test server does not skip a running activity's heartbeat timeout, and it does not time out a stopped worker's sticky queue; a SIGKILL repeat was verified manually on a local dev server (decisions.md 2026-11-08). Add with a dev-server based test if a crash-path change is made. | agent under delegated authority |
| TST-003 (rest): real PHP + Temporal + database morning test | High/Medium | defer to R4 acceptance | Needs a cross-language harness (worker + php artisan + test database). The morning chain is not scheduled until R4; run it as a required acceptance check before the R4 PR (pending completion, missing EEX proof, running claim, one deadline alert). | agent under delegated authority |
| S3: split `futures:fetch-eex` into units | — | out of scope | Recorded decision 2026-11-08. | agent (decision) |

## Still owed
| Item | Owner / next step |
|---|---|
| R1 production: worker variables (all application variables, `RAILWAY_DEPLOYMENT_DRAINING_SECONDS=4000`), deploy worker + web, apply collector Schedules | user approval |
| Check the Railway maximum for draining seconds before R3 | manager, read-only docs/support check |
| Real PHP + Temporal + DB morning acceptance test | before the R4 PR |
| `pi-pr-review` and `external-pr-review` on the PR | next stages |

## Codification
- Proposal (root `AGENTS.md`, Git workflow or a new orchestration rule): "A scheduler cut-over that moves cache writers needs the shared cache store first." Recorded now in `orchestration/AGENTS.md` (Cut-over order) and `phase2-plan.md`.
- Proposal (`.agents/skills/spec-review/SKILL.md` platform focus): "Check that every non-interruptible activity timeout is shorter than the worker drain, and that rollback pauses the new scheduler before the old one returns."
