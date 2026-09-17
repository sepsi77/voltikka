# Production release — approval received, execution pending

## Approval and scope

The user replied **“Approved”** to the manager's request to commit the reviewed changes on
`main` and run **`git push origin main`**. This approves the normal Git auto-deploy to
**Voltikka / production / voltikka**, including changes to live comparison prices. Read-only
deployment status, bounded logs and live application checks are included.

**Deployment has NOT yet been performed.** Release SHA, new deployment ID, deployment status
and live results are pending manager execution. Approval is not proof of deployment success.
This record changes release authority only. Technical readiness and all evidence limits in
`release-readiness.md` remain unchanged. Earlier dated pre-approval notes remain historical.

## Exact production target

| Resource | Name | ID |
| --- | --- | --- |
| Project | Voltikka | `6d8cae01-1006-409f-8108-1d51f1abc676` |
| Environment | production | `9245cef8-41d0-486e-862f-193726511dba` |
| Service | voltikka | `700d0624-fa96-4266-876c-e37640d220ea` |

Existing deployment from the supplied read-only evidence, not a new check:

- Deployed SHA: `917de212fdc4ef4862903268c3d33ba5cd886e73`.
- Deployment ID: `13bfd4fa-c06f-4a27-b7a7-7e935823d903`.
- Recorded status: `SUCCESS`.

## Excluded actions

This approval does **not** authorize:

- V5 producer activation, five-key profile changes, pilot or reinterpretation/provider runs.
- Manual imports, manual cache flushes, history writes or historical rebuilds.
- Active SQLite replacement, data repair, restoration or queue changes.
- Direct Railway uploads, redeploys, restarts or rollbacks.
- Actual video posting.

Default V4 and Historical limits remain in force. These excluded actions need separate explicit
approval. On deployment failure or timeout, read bounded evidence and stop; do not restart,
redeploy or roll back without new approval.

## Accepted final technical gates

These are manager-supplied results, not new runs by the documentation executor:

- PHP: **2812 tests / 21214 assertions passed** (job 235).
- JavaScript: **12 tests / 0 failures** (job 228).
- Laravel production build and Remotion ESLint/TypeScript/build: **passed**.
- Pint: **140 changed/new Laravel PHP files passed**, plus all five preflight PHP files
  (job 237).
- Preflight/export smoke and final whitespace/context/hash checks: **passed**.

See `release-readiness.md` for logs, exact counts and evidence limits. Local checks do not prove
live HTTP, cache or ranking behavior. Policy conformity is not demonstrated forecast improvement.

## Manager execution record — pending

| Item | Result |
| --- | --- |
| Release commit SHA | Pending manager |
| `git push origin main` | Not yet performed; pending manager |
| New deployment ID | Pending manager |
| Deployment source SHA matches release commit | Pending manager |
| Exact deployment terminal status | Pending manager |
| Read-only live URLs, checks and results | Pending manager |
| Bounded build/runtime errors and follow-up decision | Pending manager |

The manager must match the deployment source SHA to the pushed release commit on the exact target
above. A successful push or an unrelated successful deployment is not release verification.
Record the live checks only after the matching deployment reports `SUCCESS`.

This documentation unit makes no code changes and runs no tests, staging, commit, push, network
request or production operation.
