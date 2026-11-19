Verification pass of the spec review of tasks/staged-api-imports. Read-only: do not edit source,
commit, or write anything except your report.
Working directory: /Users/seppo/code/voltikka-staged-api-imports (branch staged-api-imports).
Fix range: cdc93650e77e1db73f7f9067fee987a73de59c62..743f1c1 (HEAD). Check the fixes of these findings and their regressions only, not unrelated code: PRC-001 (contracts superseded refusal), PRC-002 (spot-forecast display), LAR-001 (spot averages in the transaction) and the SpotPriceAverageService row-lookup change: does any stored average identity or value change on MySQL?
The findings are in tasks/staged-api-imports/reviews/2026-11-19T12-19/<reviewer>.md; the manager's decisions are in tasks/staged-api-imports/decisions.md, section "Spec review round 2026-11-19T12-19", and design.md section 7a.
Tests: do not run tests.
No live API calls, no production access, no Artisan commands that write data.
Write your report to tasks/staged-api-imports/reviews/2026-11-19T12-19/verification-pricing-reviewer.md: per finding Fixed / Partially fixed / Not fixed with path:line evidence, then any new finding (ID <PREFIX>-1NN, Severity, Likelihood, Class, spec rule, evidence, impact scenario, fix). Reply with the path, counts and a 3-line summary.
