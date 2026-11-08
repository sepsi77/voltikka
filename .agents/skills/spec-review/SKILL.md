---
name: spec-review
description: Audit finished Voltikka work against its task spec (tasks/<slug>/spec.md) with dedicated read-only reviewer agents (refactor, Laravel, pricing, tests, platform, frontend, copy). The manager selects reviewers, triages findings against the spec, gets must-fix findings fixed, runs at most one verification pass, and records every finding in actions.md.
---

# Spec Review

Audit an implementation against its task spec with dedicated reviewer agents. Run it once
after the whole task has landed (every implementation item in `tasks.json` is done), not
after each step.

```
/spec-review tasks/<slug>
```

The caller is the **manager**. The manager selects reviewers, reads every report, triages,
gets fixes made, and writes `summary.md` and `actions.md`. Reviewers only audit.

This is stage 1 of three. Stage 2 is `pi-pr-review` (independent toolkit review of the whole
branch). Stage 3 is `external-pr-review` (CodeRabbit and Greptile on a GitHub PR). Accepted
findings of the later stages improve this skill through `missed-findings.md`.

## Inputs

The task folder is the whole spec (`tasks/AGENTS.md`):

| Review input | From the task folder |
|---|---|
| Spec items (`S1..Sn`) | `spec.md`: requirements, "Done when" or acceptance items, edge cases. Number them in order of appearance and write the list into `summary.md`, so every reviewer uses the same numbers. |
| Scope, non-goals | `spec.md` |
| Recorded rulings | `decisions.md` (apply the decision rule below) |
| Progress | `tasks.json` |

Also read the root `AGENTS.md`, the nested `AGENTS.md` files of the changed code, and earlier
rounds in `reviews/`.

**Decision rule.** Do not reopen a recorded trade-off without new evidence. A decision does not
exempt concrete wrong, stale, misleading or unsafe results from review: report the result and
cite the decision. Do not reverse an explicit user ruling without user authorization; record the
affected fix as decision-required and continue independent work.

## Workflow

### 1) Scope

Each task has its own branch (root `AGENTS.md`, "Git workflow"). The range is explicit:

- **Base**: `git merge-base origin/main HEAD` on the task branch. For old work that was committed
  directly on `main`, use the commit before the task's first commit (`git log --oneline --
  tasks/<slug>/`). Record the base in `summary.md`.
- **Head**: current `HEAD`, **plus** staged, unstaged and untracked changes that belong to the
  task. Other sessions can have unrelated local changes in the same checkout: list only the task's
  paths and tell reviewers which local paths are in scope.
- List the changed files: `git diff --name-only <base>..HEAD` and `git status --short`.

### 2) Select reviewers

| Condition | Reviewers |
|---|---|
| Always | `refactor-reviewer` |
| Any behavior change or any test change | `test-reviewer` |
| Anything under `laravel/` except only views, CSS or JS | `laravel-reviewer` |
| Code that produces, stores, caches or publishes a price, annual cost, statistic, forecast, premium or other public figure (`Services/CanonicalPricing`, `ContractStatistics`, `CompanyStatistics`, `PriceForecasting`, `RetailPremium`, `ContractCard`, `ContractInterpretation`, `BillComparison`, `Caching`, `ElectricityFutures`, spot imports and averages, `market-review/tools`, the readers of these) | `pricing-reviewer` |
| `orchestration/`, `forecast/`, `Dockerfile`, `docker-*.sh`, `supervisord.conf`, `scripts/`, `laravel/routes/console.php`, `laravel/config/`, queue or cache store changes | `platform-reviewer` |
| `laravel/resources/views/`, `resources/css/`, `resources/js/`, Livewire components with UI state, SEO output (titles, canonical, robots, sitemap, JSON-LD, og:image) | `frontend-reviewer` |
| Public Finnish text (Blade copy, `lang/`, articles, `market-review/editions/`, `media/`), or a behavior change that public copy describes | `copy-reviewer` |

Record the selection and each skip reason in `summary.md`.

### 3) Launch

`mkdir -p tasks/<slug>/reviews/<ts>/prompts` with `<ts>` = `YYYY-MM-DDTHH-MM` (Helsinki time).
Launch all selected reviewers **in parallel**. They are read-only and write distinct files.

**Model routing** (same as ecom-buddy, agent decision 2026-11-08, see
`tasks/code-review-workflow/decisions.md`): every reviewer except `frontend-reviewer` and
`copy-reviewer` runs on OpenAI through the Pi CLI. Those two run on Claude through the Agent
tool. Always use the `openai-codex/` provider (ChatGPT subscription), never `openai/` (API
prices).

Write the prompt below to `tasks/<slug>/reviews/<ts>/prompts/<reviewer-name>.md`, then launch
each Pi reviewer in the background from the repository root:

```bash
cd "$(git rev-parse --show-toplevel)" && \
.agents/skills/pi-pr-review/pi-run.sh <scratchpad>/logs/<slug>-<ts>-<reviewer-name>.log \
  -p @tasks/<slug>/reviews/<ts>/prompts/<reviewer-name>.md \
  --append-system-prompt .pi/agents/<reviewer-name>.md \
  --model openai-codex/gpt-6.1-sol --thinking high \
  --tools read,bash,find,ls,grep,write \
  --session-id <slug>-<ts>-<reviewer-name> --mode text
```

Wait for each background task's completion notification; do not poll with sleeps.

```
Agent({subagent_type: "frontend-reviewer", prompt: "<prompt below>"})
Agent({subagent_type: "copy-reviewer", prompt: "<prompt below>"})
```

Only one reviewer may run Laravel tests, normally `test-reviewer`, and only targeted
`php artisan test --filter=...` runs from `laravel/`. Say in each prompt whether that reviewer may
run tests. No reviewer runs Railway commands, production database access, live API calls, or
`scripts/sync-production-database.sh`.

Prompt for each reviewer:

```
Review the implementation of tasks/<slug>/spec.md against that spec. Read-only: do not
edit source, commit, or write anything except your report.
Range: <base sha>..<head sha>, plus these local changes: <paths or "none">. Other local
changes in the checkout are out of scope.
Spec items: <S1..Sn list from summary.md>.
Read spec.md, decisions.md, tasks.json and earlier reviews/ for context. Do not reopen
recorded trade-offs without new evidence. Report concrete wrong, stale, misleading or
unsafe results even when a decision permits them. Cite the decision; a fix that reverses
an explicit user ruling needs user authorization.
Tests: <you may run targeted `php artisan test --filter=...` from laravel/ | do not run tests>.
Focus: <one line from the table below>.
Write your report to tasks/<slug>/reviews/<ts>/<reviewer-name>.md, using the report layout
in your agent definition. Give every finding: an ID <PREFIX>-NNN, Severity, Likelihood,
Class (blocking | normal | backlog), the spec rule it violates (or "none"), path:line
evidence, a user-impact scenario, and a fix with its blast radius. If you have no findings,
say "Findings: none". Reply with the path, finding count and a 3-line summary.
```

| Reviewer | Focus line |
|---|---|
| `refactor-reviewer` | Simplest solution, reuse of existing patterns, no parallel systems or unneeded flags/config/abstractions, dead residue, duplication, placement, context-file rules (AGENTS.md/CLAUDE.md mirror, decisions documented near the code). |
| `laravel-reviewer` | Correctness of Laravel behavior against every spec item: commands, imports, transactions, idempotency, migrations, Livewire state, failure reporting, fail-closed paths, production safety defaults. |
| `pricing-reviewer` | Every changed public number: canonical pricing as source of truth, VAT basis, missing is not zero, cache identity and schema versions, active annual method, history follows the current method, as-of evidence only, Helsinki dates. |
| `test-reviewer` | Over-specified tests first (reported `normal`), then coverage: each spec item has a real-chain test that fakes only external HTTP and time; policy tests are not weakened. |
| `platform-reviewer` | Temporal determinism and retry policy, schedule cut-over (never both schedulers), service variables and cache store, entrypoint speed, deploy-script guards, frozen forecast models and vintage-as-of-issue. |
| `frontend-reviewer` | Blade/Livewire/JS/CSS behavior against every spec item: honest states, form blur policy, redesign tokens and exact Paper values, accessibility, SEO output, no visitor data in shared cached HTML. |
| `copy-reviewer` | Public Finnish copy: writing rules in root AGENTS.md, facts true against the code and data, Finnish grammar, forbidden terms and mentions. |

### 4) Report contract (every reviewer)

The reviewer writes its own report to `reviews/<ts>/<reviewer-name>.md`. If it cannot write files,
it returns the report and the manager saves it verbatim under that name.

````markdown
# <reviewer-name> — <slug>
Range: <base>..<head> (+ local changes) · Result: clean | <n> findings

## Spec checklist  (reviewers that check spec items; others omit)
| # | Spec item | Verdict | Notes |
|---|---|---|---|
| S1 | … | ✅ Pass / ⚠️ Partial / ❌ Fail / ➖ N/A | … |

## Findings
### <ID> — <title>
- **Severity**: Critical | High | Medium | Low   (impact if hit)
- **Likelihood**: High | Medium | Low   (how often it is hit)
- **Class**: blocking | normal | backlog
- **Spec rule**: <S#, decisions.md entry, AGENTS.md rule, or "none">
- **Evidence**: `path:line`, …
- **What is wrong**: …
- **User-impact scenario**: the visitor, journalist or operator action and system state that
  trigger it, and what they see. Say so plainly if the trigger is rare or contrived.
- **Fix**: target pattern (a code snippet for code changes) and blast radius
  (`path:lines — what changes there`, including callers and tests).

## Files inspected
````

- One ID prefix per reviewer: `REF`, `LAR`, `PRC`, `TST`, `PLT`, `FE`, `COPY`. IDs are stable
  within the round. A clean report says `Findings: none` and applies only to its stated range.
- Severity: a wrong published price or figure, data loss, rewritten historical evidence, a
  production mutation without approval, or a secret leak → Critical/High; wrong but recoverable →
  Medium; cosmetic or rare edge → Low.
- A finding with no plausible impact scenario is dropped. An unconfirmed suspicion is marked
  `unconfirmed` with what was checked.

### 5) Read and summarize

Read every report yourself. Merge duplicates into one canonical item (keep all source IDs). Write
`reviews/<ts>/summary.md`:

```markdown
# <Task> — spec review <ts>
Initial range: <base>..<head> (+ local paths) · Reviewers run: … · Skipped: … (reason)
Verification range: <start>..<end> | not run
Final assessed HEAD: <sha>
Later source changes: <paths and manager verification results, unverified paths, or none>

## Spec items
S1 … Sn (the numbered list given to reviewers)

## Verdict: ACCEPT | NEEDS-FIX | DECISION REQUIRED
1–3 sentences.

## Spec rollup
<n> items: ✅ n · ⚠️ n · ❌ n · ➖ n — table of item → state → reason (your call, not a sum of reviewer rows)

## Blocking findings
| ID(s) | Title | Sev/Lik | Spec rule | Owner |

## Non-blocking findings
| ID(s) | Title | Sev/Lik | Class |

## Verified
Spec items and surfaces confirmed correct, with the evidence.
```

**Acceptance gate.** "Zero findings" is not the bar; the refactor reviewer nearly always finds
something.

- **ACCEPT** when: no ❌ spec item; no High/Medium-likelihood correctness, pricing, data-integrity
  or platform finding; no blocking test finding; no written-rule breach left open.
- **NEEDS-FIX** when any of those fails.
- **DECISION REQUIRED** when a necessary fix would reverse an explicit user ruling, or a defect
  survived two fix attempts at the same site. Give the user the decision and options. Continue
  independent work.

**What blocks.** Only High and Medium likelihood findings can block. Exceptions that block
regardless: a wrong public figure, rewritten historical evidence, an unapproved production
mutation path, a missing required test, or a required test that fakes an internal layer.
Over-specified tests (pinned wording, call counts, internal payload equality) are `normal`, and
their fix only deletes assertions. Refactor findings and Low-likelihood residue are backlog.

**Test standards.** A Laravel spec item needs a test through the real chain (HTTP request or
Artisan command → service → database → response or stored rows) that fakes only external HTTP
(`Http::fake`) and time. Unit tests alone do not prove it. Items that describe the absence of
behavior are `➖`, not ❌.

### 6) Triage and fix (manager, under delegated authority)

Sort every finding into exactly one bucket:

- **Must-fix**: a spec item fails; a stated invariant or edge case is violated; or a real
  correctness, pricing, security or data-integrity bug with a plausible scenario. Give a narrow
  brief to the `executor` agent (path:line, the failure, required behavior, the test that must fail
  without the fix, a search for the same defect in sibling paths), then check the result yourself.
- **Written rules are must-fix.** A breach of a written repo rule (root or nested `AGENTS.md`,
  `.greptile/rules.md`, the scoped rules in `.greptile/config.json`) is must-fix. Only the user can
  waive a written rule.
- **Wrong public numbers are must-fix**, also at Low likelihood: a price, annual cost, statistic,
  forecast or market-review figure that a visitor or journalist can see. Do not defer them on your
  own likelihood estimate.
- **Derived results follow their inputs.** A deferral may leave a stale derived result (a cache, a
  statistic, a prepared page) only when the stale result is hidden or marked at once. "Until the next
  scheduled run" is not an acceptable disposition for a figure the visitor can see.
- **Small local UX fixes are not deferred.** A UX finding whose fix is local and small (a label, a
  guard, an empty state) is fixed in the round, also at Low likelihood.
- **Fixes without their test are not done.** When the verification pass reports "fixed, no test"
  for a behavior the spec or the fix brief asks to test, add the test in the same round.
- **Judgment call**: everything else (refactors, style, extra hardening, contrived edges, work the
  spec does not require). Decide `fix now` / `ignore` / `defer` with a reason, preferring deferral
  over scope growth. Label it an agent decision under delegated authority.

Inspect enough evidence to decide; reviewer labels and pseudocode are input, not authority. Never
drop a finding silently. Finnish public copy is fixed by Claude, never by a GPT-class model.

**Bounded rounds.** After the must-fix set is fixed, run **at most one** verification pass. Select
reviewers from both the findings being fixed and the actual fix diff, including materially affected
callers. Include a reviewer whose area changed even if its first report was clean. Do not rerun
unaffected reviewers. Point each one at the fix range; reports go to
`reviews/<ts>/verification-<reviewer-name>.md`. This pass checks the fixes and their regressions,
not unrelated code.

**Final coverage.** Before closing, compare the assessed commits with current HEAD and the task's
local changes. Record the final assessed commit and each later source change with its verification
status in `summary.md`. Do not carry an earlier ACCEPT onto unverified source changes. After the one
verification pass, check later fixes with focused code inspection and the affected tests. Exception:
a later fix that changes a stored identity or a write path (a migration, a unique key, an upsert
target, which rows a command writes or deletes) gets one focused `laravel-reviewer` check (with
`pricing-reviewer` when it writes prices or statistics) before the round closes.

**Stop** when the acceptance gate holds and every other finding has a recorded disposition.

### 7) Write `actions.md` (mandatory, one per round)

`reviews/<ts>/actions.md` is the human-facing record. Every finding from every reviewer in the round
appears in **exactly one** section.

```markdown
# Review actions — <ts>
Verdict after fixes: <ACCEPT | …>

## Fixed
| Finding(s) | Commit or local change | Change (one line) |

## Not done
| Finding(s) | Sev/Lik | Disposition (ignore / defer / pre-existing / out of scope) | Reason | Decided by (user / agent under delegated authority) |

## Still owed
| Item | Owner / next step |   (manual checks, browser checks, production steps that need approval)

## Codification
What the written rules were missing, and where it was (or should be) written.
```

A round with nothing under "Not done" is almost never real.

### 8) Codify and report

Ask: **"What did you know here that the written system did not?"** Write those rules with the user
into the root or nearest nested `AGENTS.md`. If the user is unavailable, list the proposals under
`## Codification` in `actions.md`.

Tell the user: where the reports are, the verdict, the spec rollup, what was fixed, what was not done
and why, and what is still owed. Record the round in the task's `decisions.md` and update
`tasks.json`.

## External findings

When a later review (`pi-pr-review`, `external-pr-review`, a human) finds a real defect that this
review missed, follow `missed-findings.md` in this directory.

## Rules

- **Reviewers are read-only.** They never edit source, commit, run migrations, run Artisan commands
  that write data, or touch Railway; they write only their report. They may run non-mutating checks
  (grep, `git`, targeted tests when the prompt allows).
- **Evidence required.** Every finding cites `path:line`.
- **Scope discipline.** Review what the spec covers plus materially affected callers and readers.
- **Earlier rounds are context, not verdicts.**
- **No production effect.** A review never deploys, changes Railway, or applies a dry-run command.
