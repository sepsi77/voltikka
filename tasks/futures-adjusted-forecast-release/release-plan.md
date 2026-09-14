# Proposed release plan — not authorization

## Target and approval boundary

- Branch for deployment: `main`.
- Exact proposed push: `git push origin main`.
- Railway project: `6d8cae01-1006-409f-8108-1d51f1abc676` (Voltikka).
- Environment: `9245cef8-41d0-486e-862f-193726511dba` (production).
- App service: `700d0624-fa96-4266-876c-e37640d220ea` (voltikka).
- Effect: the GitHub integration starts a new production deployment. Default generation/public reader identity changes to `fixed_term_futures_adjusted_v1`; forecast EEX freshness checks and the simpler Finnish copy become active.

The manager must review tests and the exact staged diff, then request explicit push approval with this command and target. Follow the branch-first rule before any requested commit. Do not stage the unrelated untracked research folder. Preserve all pinned research/export files. This implementation agent did not commit, push, or call production.

No new migration, backfill, environment variable, dependency, schedule or deployment-time generation is included. Existing nullable forecast columns suffice. Recheck model/minimum/horizon/threshold environment pins through metadata-only inspection before deployment; historical model generation pins fail before recovery. A configuration mutation would require separate approval.

## Deployment verification

After an approved push, use read-only Railway MCP to find the deployment with the exact pushed commit hash. Poll that deployment with `scripts/railway-poll-deployment.sh` and explicit target/deployment IDs. Verify its SUCCESS state and the public page. Do not use railway up, redeploy or restart for this Git release.

The new identity hides old public forecasts until new rows exist. This release gap is expected and must be stated before approval. Existing daily generation remains 07:30 and evaluation 07:45 Europe/Helsinki.

## Fresh dry run, then separate generation approval

Choose `YYYY-MM-DD` from the current Helsinki date and the newly deployed service's actual checkpoints; **do not assume 2026-09-13**. Use a read-only dry run with explicit context:

```bash
RAILWAY_CALLER=skill:use-railway@1.2.2 RAILWAY_AGENT_SESSION=futures-adjusted-release \
railway ssh --project 6d8cae01-1006-409f-8108-1d51f1abc676 \
  --environment 9245cef8-41d0-486e-862f-193726511dba \
  --service 700d0624-fa96-4266-876c-e37640d220ea \
  -- php artisan forecasting:run-fixed-contracts --as-of=YYYY-MM-DD --horizon=30 --require-freshness --dry-run
```

Review full and feature cohorts, current/lag trade dates, basket completeness, predicted changes and all gate failures. A dry run cannot recover statistics. Missing EEX readiness blocks generation and recovery. If publication order alone fails, state that the non-dry command can overwrite current-date statistics and recheck the complete gate.

Then request explicit approval for the same exact project/environment/service and this write command, with the **inspected literal date** substituted:

```bash
RAILWAY_CALLER=skill:use-railway@1.2.2 RAILWAY_AGENT_SESSION=futures-adjusted-release \
railway ssh --project 6d8cae01-1006-409f-8108-1d51f1abc676 \
  --environment 9245cef8-41d0-486e-862f-193726511dba \
  --service 700d0624-fa96-4266-876c-e37640d220ea \
  -- php artisan forecasting:run-fixed-contracts --as-of=YYYY-MM-DD --horizon=30 --require-freshness
```

Effect: create new-model forecast rows and, only if needed and permitted by the gate, recalculate current-date statistics first. No overwrite flag, old-forecast rewrite, evaluation rewrite or backfill is proposed. Do not execute this template before date-specific approval.

After approved generation, read back model/basis, all nine expected lanes or exact omission reasons, cohort counts, fixed-basket provenance and null legacy diagnostics. Verify the saved direction, current comparison, dates and simple copy on the public page. Compatible stored median evaluation remains available through the read-only report.

## Limits

The frozen export starts 2026-04-08. Production has retail history from 2026-01-21; exact production forecasts therefore differ from the isolated September 13 replay. The 123 median matches prove implementation parity, not future accuracy. The nine-lane checks prove the requested quantile arithmetic, not measured p20/p80 prediction accuracy. Frozen research has no untouched future holdout and has limited falling-market evidence.
