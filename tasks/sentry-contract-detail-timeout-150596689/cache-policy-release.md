# Retained-cache release constraints

## Release authorisation — 2026-10-01

The user explicitly authorised commit and push to deploy. Target: `main`, `git push origin main`, Voltikka project `6d8cae01-1006-409f-8108-1d51f1abc676`, production environment `9245cef8-41d0-486e-862f-193726511dba`, app service `700d0624-fa96-4266-876c-e37640d220ea`. This enables the scheduled cache producer. It does not authorise a separate manual production producer, cache flush, history rebuild or configuration switch.

Read-only Railway preflight confirms GitHub `sepsi77/voltikka`, branch `main`, one app replica, existing `/app/storage` volume, no staged platform changes, and a successful previous deployment. Local branch matches refreshed `origin/main` before the release commit. Stage only this cache task; retain unrelated annual-history task/context hunks in the worktree. Final deployment and public HTTP acceptance must be recorded separately; a successful push is not deployment proof.

Local implementation and final regression verification are complete: 2,953 PHP tests / 24,174 assertions, 12 JavaScript tests, production asset build, changed-file Pint, whitespace and 50 context mirrors pass. See `cache-policy-final-verification.md` for evidence and limits. That local checkpoint preceded release authorisation. The subsequently approved commit `6d43357` is deployed with Railway SUCCESS and passing public checks; see `cache-policy-production-release.md`. The automatic startup producer activated version 808. No separate manual production producer or history rebuild was run.

## Scope

User approves slightly old verified annual prices until a complete replacement is built. Pure GET/HEAD, including custom query strings, must not calculate annual prices or prepare broad donor/futures evidence. Explicit user actions may calculate exact custom values. ConsumptionCalculator's existing CompareContracts POST must prepare the exact profile before its redirect, so the destination GET remains cache-only. Do not interpolate or silently snap its financial total to a preset. Preparation uses the existing 1–150,000 integer-kWh comparison domain, with locked server-calculated result state. New nonpreset handoff profiles expire after 30 minutes, with at most 64 live profiles per active generation; capacity/missing prices are honestly unavailable. Shared preset prices remain retained until complete replacement.

The background pending warmer is a new production scheduled writer. Deploying it requires explicit release approval. A first production producer invocation is a separate mutation and requires explicit command/context approval. No manual rebuild runs automatically in this task. This cache replacement does not change annual calculator mathematics, activate a new stored statistics method, rewrite observations or rebuild financial history.

## Before any release

1. Complete all read/consumer/action units. Producer-only success is not complete policy compliance.
2. Review actual diffs, retained provenance/availability behavior, initialization versus explicit action permission and every public GET/HEAD annual consumer.
3. Run focused producer/race/storage tests, cache-only GET/HEAD tests, explicit custom/action handoff tests and relevant existing regression tests. Fix old cold-HTTP fixture assumptions by explicit producer setup, not bypassing policy in tests.
4. Run the full PHP suite and required frontend tests/build after all changes settle. Producer-stage full-suite evidence from before consumer changes is not final verification.
5. Repeat isolated read-only full-kernel city/detail GET checks with already produced shared caches. Record zero full-market/local/donor pricing calls and compare financial values/provenance. Test unavailable cold initial cache without a request build.
6. Verify context mirrors and git diff --check; preserve unrelated pre-existing dirty work.

## Production approval and first generation

- State the exact commit/file scope before any push. A push to origin/main starts a production deployment and needs separate explicit user approval for branch/command/Voltikka project/environment/app service and expected effects.
- The planned minute pending warmer runs per instance because FileStore data is local. Confirm current replica/store topology and scheduler runtime before release. Old active compatible annual keys can remain while the first new producer runs; company wrapper compatibility changes need review.
- If no compatible generation is available, expected HTTP behavior is an honest non-cacheable unavailable response/state, never a public market build. Do not present an unavailable state as successful price readiness.
- After approved Git release, poll the exact Railway deployment and verify relevant public pages only after SUCCESS. Any explicit manual producer, scheduler manipulation, cache flush or database write needs its own authorization. Do not flush active cache as a verification method.
- Production acceptance should prove retained GET prices during real demand, eventual complete promotion, exact user-action custom handoff and reduced crawler CPU/timeouts. Local PHP/SQLite timings alone cannot prove those production results.
