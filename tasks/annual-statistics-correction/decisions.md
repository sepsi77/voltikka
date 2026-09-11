# Decisions

- The user approved preparing a correction, not changing production data. Prior commit/push request remains paused until correction and release approval.
- Calculated annual results can be corrected; observed seller prices and actual market observations remain evidence and must not be rewritten.
- Existing annual-only and aggregate tables already include method_version. Reuse them and preserve annual_cost_as_of_v1 for audit/rollback.
- Existing historical v1 deliberately holds recurring resets flat because its seasonal fallback is not date-bounded. Correcting historical methods requires fixing that evidence boundary, not simply calling today's calculator for old dates.
- Existing rebuild medianDeltas compares against legacy, even though production uses AsOf v1. Corrected preview must compare the requested baseline, normally the active public method, and distinguish lost coverage from numerical changes.
- Date-range application remains explicit, full-date, idempotent, and transactional. No automatic historic rebuild on deployment.

## Implementation design
- Add annual_cost_as_of_v2 to the existing method enum. Current corrected canonical results write v2 only; the public active-method setting remains unchanged until a separately approved switch. Never label the corrected current calculation as a new v1 result.
- Stored v1 is the baseline. The old calculator shares changed code, so recalculating v1 cannot reproduce every old result exactly. Preserve existing v1 rows; historical correction apply targets v2 only.
- Historical v2 uses canonical reset and supplier seasonal estimates after the seasonal provider is date-bounded. Keep the v1 held-price branches for explicit diagnostics/backward compatibility.
- Seasonal inputs use only complete months before the target date, with date-keyed memoization. Future announced reset periods retain their delivery period but cannot select a reference vintage after the calculation date.
- Active annual panels need their own dated endpoint while corrected v2 is prepared beside retained public v1. Do not falsely label retained older values as current. All public annual queries must filter to the active method.
- Preview compares stored baseline and candidate v2 before apply, reports matched/new/lost contract-consumption identities and aggregate medians, and preserves full-date transactional write guards. Partial previews compare the same selected identity universe.
- Keep the already-unreleased cache schema v16 bump. The stored annual method is independently versioned; no new tables or migrations are needed.
- Null legacy annual values are not proof that a price is uncalculable. Recover v2 canonical results only where dated consumption eligibility is proven; retain explicit ambiguity diagnostics where historical eligibility is unknown. Do not use today's contract limits to invent past eligibility.
- Current snapshots are unversioned compatibility records. The rollout must account for a same-day current overwrite changing retained v1 snapshot links; do not promise that old method rows alone replace a database backup.

## Completion
- Implementation, integrated review, regression tests, build, and read-only three-date local preview are complete. See `verification.md` for exact results and scope limits.
- The sampled preview exposed two valid Hybrid base estimates blocked by zero consumption-effect placeholders. The fix is limited to an explicit typed base effect in the annual base-only path. It preserves source objects, known phase changes, exact-period behavior, and opaque nonzero or conflicting-source exclusions.
- The local sample confirms numerical and coverage changes, but does not measure the full history or live production. Remaining conflicting-source and unknown-package cases require review before activation.
- No commit, push, production command, actual-data apply, or public method switch was performed during preparation. `rollout.md` remains the required approval and backup procedure.

## Release approval
- The user subsequently confirmed committing on `main` and running `git push origin main` to deploy to Railway Voltikka / production / voltikka. The user also confirmed that the required full database backup is verified; this was an operator confirmation, not an agent-run backup verification.
- The approved code release changes live estimates and makes future annual collection write v2. It leaves the public annual method on v1 and does not run a historical rebuild.
- The user then requested the historical v2 rollout after deployment. First inspect and preview production read-only. Exact historical apply dates, commands, expected changes, and public activation must still receive the required explicit production-operation approval.
