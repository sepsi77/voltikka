# Pi PR Review Toolkit

Adapted from Anthropic's Claude Code pr-review-toolkit, under Apache-2.0.
Modified for Pi: native Agent delegation, project-neutral rules, broader diff
coverage, explicit edit permission, and evidence-based severity.

## Scope

1. Read the repository instructions (AGENTS.md, CLAUDE.md or equivalent), including instructions for each affected directory. Follow the actual project rules, not assumed framework conventions.
2. Inspect git status, staged and unstaged diffs, and untracked source files. Exclude secrets, generated files, dependencies and binary files. Do not modify the checkout to collect a diff.
3. Check for an existing PR with `gh pr view` if available. For a PR, identify its base and head and use the merge-base diff for the checked-out branch. Include local changes separately. Confirm that the local branch matches the PR before treating it as that PR. Do not checkout, fetch, publish comments, commit, push or merge automatically.
4. Without a PR, review local changes if present. For a clean tree, identify the branch base from reliable Git metadata and review committed branch changes. If the base cannot be determined, ask for it. Never report a clean committed branch as reviewed based only on an empty working-tree diff.
5. State the exact scope, base/head where relevant, and exclusions. If there are no changes, stop. If Git or GitHub inspection fails, report the limitation; do not mistake a failed command for an empty diff.

## Select and run roles

For `all`, select code review always; tests for changed behavior or tests; errors for changed error paths, I/O or fallbacks; types for added or modified types; comments for changed docs or claims affected by code changes; simplify for changed implementation code. Explain skipped roles. Explicitly selected roles must run or clearly report why they cannot.

Use the existing Agent tool with `subagent_type: "general-purpose"`. Give each agent its role instructions below, exact review scope, working directory, affected files, applicable project rules and whether it may edit. Agents must read the actual code and relevant call sites, not rely on diff summaries. Do not depend on Claude-only agent names or Task APIs. Use the current model unless the user requests another model.

Honor the selected sequential or parallel mode. For parallel mode, launch independent read-only reviews together. For sequential mode, finish one before starting the next. Use foreground agents only when the next step depends on the result and no independent work remains. Do not poll background agents. Wait for completion notifications before combining results. Do not launch more than one agent for each selected role or run an unbounded review loop.

All review roles are read-only by instruction: no source edits, dependency installation, commits, GitHub writes or infrastructure changes. This is an instruction boundary, not an OS sandbox. Report proposed fixes, not applied fixes. Do not run commands that alter customer data. Run tests only when safe under project instructions, from the owning directory, and report exactly what ran.

Simplification must run last, after all other selected reviews finish without unresolved critical or important issues. Without explicit `simplify`, it is suggestions only. With explicit `simplify`, it may make narrow, behavior-preserving edits to reviewed code. If simplify is the only role, first inspect that scope for blocking correctness issues. Preserve user changes, avoid unrelated refactors, run relevant checks, inspect the final diff, and report modifications and any failed or unavailable checks. Never claim unchanged behavior without stating the verification evidence.

## Role instructions

### code — Code reviewer

Check actual project rules, logic errors, null handling, security, concurrency, cleanup, meaningful performance regressions and accessibility. Focus on new problems caused by the change. Rate confidence 0–100 and report only findings with confidence at least 80. Confidence is certainty, not impact: assign severity separately. Give file:line, evidence, the applicable rule or failure case, user impact and a concrete fix. Do not invent style rules or report pre-existing issues as new regressions.

### tests — Test analyzer

Review behavioral coverage rather than line coverage. Trace critical paths, boundaries, negative cases, errors, asynchronous behavior and integration points into existing tests. Identify tests coupled to implementation details. For each missing test, show the failure it would catch, check whether existing tests already cover it, and rate criticality 1–10. Scores 8–10 identify critical test gaps, 5–7 important improvements; explain actual severity rather than copying scores into merge blockers. Do not request trivial tests or 100% coverage. Report strengths and tests not run.

### errors — Silent-failure hunter

Inspect catch blocks, error callbacks, fallback chains, default values on failure, retries, cleanup and error propagation. Identify which concrete errors can be hidden and how the user sees the failure. Check actionable messages, useful logs without secrets, and whether a fallback is required by the specification. Do not assume Sentry, Statsig, error IDs or particular logging functions exist. Broad catches, null returns and optional chaining are not defects by themselves; establish that a real failure is hidden. Give severity, location, hidden error, user impact and a specific correction.

### types — Type-design analyzer

Identify real invariants: valid states, field relationships, business constraints and mutation rules. Rate encapsulation, invariant expression, usefulness and enforcement from 1–10, with evidence. Check construction and mutation boundaries and whether invalid states can enter through external input. Respect the language and project conventions. Do not require rich domain objects or new abstraction layers where plain typed data is sufficient. Give practical fixes whose complexity is justified by a present requirement.

### comments — Comment analyzer

Compare comments, docstrings and examples against implementation, signatures, side effects, edge cases and referenced symbols. Identify misleading claims, obsolete TODOs and important missing rationale. Prefer comments that explain why; suggest removal only when a comment adds no value. Report factual errors separately from optional improvements, with file:line and replacement wording where useful. Do not edit comments during review.

### simplify — Code simplifier

Preserve functionality and follow the actual project standards. Identify unnecessary nesting, duplicated logic, redundant abstractions, unclear names and comments that merely repeat code. Prefer explicit readable code over compressed expressions or nested ternaries. Keep useful abstractions and separate responsibilities. Focus only on the selected changed code. Do not impose React, JavaScript or other unrelated conventions. Follow the edit permission and review-pass requirements above.

## Combine results

Verify reported findings against actual code before presenting them as confirmed. Remove duplicates and unsupported findings. Do not turn personal preferences into requirements. Report agent failures and incomplete coverage instead of claiming a complete review. Do not silently rerun failed agents.

Return a concise report:

- Scope and roles completed, skipped or failed.
- Critical issues: must fix before merge.
- Important issues: should fix.
- Suggestions: optional improvements.
- Strengths: specific positive observations.
- Recommended action: critical fixes first, then important fixes, then optional changes; re-run affected reviews after fixes.
- Checks run and limitations. If simplification edited code, list those files and verification results.

Each finding must include role, file:line, evidence, user impact and a concrete next step. An empty findings list means no confirmed issues were found within the stated scope, not proof that the code is correct.
