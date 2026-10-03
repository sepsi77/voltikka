# Index migration release — 2026-10-03 UTC

## Approval and target

The user explicitly authorised commit, push and deployment. The manager stated `main`, `git push origin main`, Voltikka project `6d8cae01-1006-409f-8108-1d51f1abc676`, production environment `9245cef8-41d0-486e-862f-193726511dba`, and app service `700d0624-fa96-4266-876c-e37640d220ea`. The Docker entrypoint runs the tracked migration at startup. No direct SSH DDL was run.

## Preflight

- Final reviewed migration changes only the two named nonunique annual-table indexes. It preserves financial/history rows and existing keys. No method switch, recalculation or history rebuild is required by this performance-only change.
- MySQL requires `ALGORITHM=INPLACE, LOCK=NONE`; session metadata lock wait is five seconds, restored in `finally`. A blocked operation fails instead of silently falling back to a table-copy build. The build itself is not time-bounded.
- Combined manager test gate passed: 54 tests, 278 assertions. Focused cases cover repeat/partial migration states, full row/key preservation and session-timeout restoration, including failed DDL.
- Disposable local MySQL 9.4 gate passed, including a real five-second metadata lock failure and safe retry; both MAX queries were optimized away. The temporary container was removed.
- Pint `--test`, migration PHP syntax, task JSON, context mirrors and `git diff --check` passed.
- `npm run build` passed; only the nonblocking stale Browserslist warning remains.
- Read-only production SSH showed 44G free on the MySQL volume (46G total, 2.7G used).
- Pre-release Turku Energia page returned HTTP 200 in 1.035787 seconds. This is not a cold-cache or load test.
- Refreshed origin/main has no conflicting commits. Local main already includes the documentation-only parent commit `97d4c63` after prior production code `6d43357`.

## Release

Commit/push and exact-deployment verification are pending at this checkpoint. Post-release evidence will be added locally after verification; do not push a second documentation-only deployment to publish that evidence.
