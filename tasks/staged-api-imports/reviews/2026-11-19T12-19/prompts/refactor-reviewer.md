Review the implementation of tasks/staged-api-imports/spec.md against that spec. Read-only: do not
edit source, commit, or write anything except your report.
Working directory: /Users/seppo/code/voltikka-staged-api-imports (a git worktree, branch staged-api-imports).
Range: 3ed8b55f31b3f945c1c9211c5a63b7439942d921..cdc93650e77e1db73f7f9067fee987a73de59c62, plus these local changes: none. Other local
changes in the checkout are out of scope.
Spec items: S1–S21 and D1–D8 as numbered in spec.md. Out of scope here: S17 (shipped separately in PR #30) and D7 (a production check after deploy).
The binding implementation contract is tasks/staged-api-imports/design.md (including section 7a, additions during implementation).
Read spec.md, design.md, decisions.md, tasks.json and earlier reviews/ for context. Do not reopen
recorded trade-offs without new evidence. Report concrete wrong, stale, misleading or
unsafe results even when a decision permits them. Cite the decision; a fix that reverses
an explicit user ruling needs user authorization.
Tests: do not run tests.
Focus: Simplest solution, reuse of existing patterns, no parallel systems or unneeded flags/config/abstractions, dead residue, duplication, placement, context-file rules (AGENTS.md/CLAUDE.md mirror, decisions documented near the code). Pay attention to duplication across the 15 converted commands and whether the shared trait/payload classes are used consistently.
No live API calls, no production access, no Artisan commands that write data.
Write your report to tasks/staged-api-imports/reviews/2026-11-19T12-19/refactor-reviewer.md, using the report layout
in your agent definition. Give every finding: an ID <PREFIX>-NNN, Severity, Likelihood,
Class (blocking | normal | backlog), the spec rule it violates (or "none"), path:line
evidence, a user-impact scenario, and a fix with its blast radius. If you have no findings,
say "Findings: none". Reply with the path, finding count and a 3-line summary.
