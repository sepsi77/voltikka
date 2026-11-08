---
name: pi-pr-review
description: Run the Pi PR Review Toolkit from Claude Code without a terminal UI. Use when the user asks for a Pi review, an independent branch review, or the code/tests/errors/types/comments/simplify review toolkit. Starts one headless Pi manager, waits for its reviewers, and saves a read-only Markdown report. The manager then triages it and fixes accepted small and medium findings itself. Does not replace a requested spec review.
compatibility: macOS or Linux; Python 3.10+, Git, authenticated Pi CLI and @tintinweb/pi-subagents. Node 22.19+ for Pi.
---

# Pi PR Review

Use Pi as the reviewer while Claude Code remains the manager. You can invoke this
skill from another authorized task; the user does not need to open Pi or type its
`/review-pr` command.

```text
/pi-pr-review tasks/<slug>
```

## 1. Choose the scope and output

- Work from the repository root. Read its instructions and the task files
  (`tasks/<slug>/spec.md`, `decisions.md`, `tasks.json`).
- Default scope: the **entire current branch against local `main`**, plus staged,
  unstaged and untracked source changes. Use the user's base if one was supplied.
- **Work happens on a task branch** (root `AGENTS.md`, "Git workflow"), so the default
  `--base main` covers the branch. Use `--base origin/main` when local `main` is behind.
  For old work that was committed directly on `main`, pass `--base <commit before the
  task's first commit>`; with no commits yet, pass `--base HEAD`: the runner then tells Pi
  that the scope is the local changes only. Record the base you used in the round's
  `actions.md`.
- Do not fetch, checkout, commit or change branches to prepare the review.
- Choose a new report path, for example
  `tasks/<slug>/reviews/<timestamp>/pi-pr-review-toolkit.md`.
  Do not overwrite an earlier report. If no task applies, use another user-authorized
  report location. Do not create a task folder just to hold a review.
- Keep the checkout stable during the run. Do not launch implementation work beside
  the review. A HEAD change makes the runner return an incomplete result. Local changes
  from other sessions are part of the reviewed tree: say so in the report triage.

## 2. Launch the runner

Resolve `run.py` relative to this skill directory. From the repository root:

```sh
python3 .agents/skills/pi-pr-review/run.py \
  --base main \
  --output tasks/<slug>/reviews/<timestamp>/pi-pr-review-toolkit.md
```

Use Claude Code's Bash tool with background execution for this long command.
Record its task ID. Wait for completion through Claude Code's task-output mechanism,
then read the report. Do not use fixed sleeps, detach the command with `&`, or report
completion merely because the launch returned a task ID.

Options:

| Option | Default | Meaning |
|---|---|---|
| `--base REF` | `main` | Existing local Git ref; resolved to a commit at start |
| `--output PATH` | required | New Markdown report; relative to the caller's directory |
| `--execution parallel\|sequential` | `parallel` | Reviewer execution order; simplify stays last |
| `--model PROVIDER/ID` | `openai-codex/gpt-6.1-sol` | Subscription-backed Pi model for manager and reviewers |
| `--run-tests` | off | Let only the tests reviewer run safe targeted checks |

The default is static review: the tests role analyzes coverage but does not execute
checks. Add `--run-tests` only when the task permits it and no other session is using
the same test database. No installs, service starts, Railway commands, production
access, live provider calls (ENTSO-E, EEX, Azure, Fingrid, FMI, Syke, Open-Meteo, LLM
providers) or customer data are authorized. Laravel tests must run from `laravel/` as
targeted `php artisan test --filter=...`; Python tests must run from `orchestration/`
or `forecast/` with that package's own test command.

All applicable roles are selected: code, tests, errors, types, comments and simplify.
**The reviewers only report.** The manager decides what to fix (section 4); selecting simplify
never lets a reviewer edit.
It runs last only if the other reviews leave no critical or important issues.

## 3. Read and report

- Exit **0** means Pi returned a completed report, **not** that the branch is clean.
- Exit **1** means setup, execution or completion failed. An incomplete report may
  exist. Read it and the retained log; do not call this a completed review.
- Argument errors return **2**.
- The runner prints the temporary log path at start and the report path when saved.
  Logs and the exact input prompt remain in that temporary directory for diagnosis.
  Do not publish raw logs without checking for sensitive content.
- Read every finding. Summarize confirmed critical and important issues, optional
  suggestions, strengths, skipped roles and failed/unavailable checks.
- Do not push, open a PR, post comments, or start another Pi review round unless the
  caller authorized that action. Do not silently retry a failed or incomplete review.
- **Provider outage** (exit 1, the log shows only provider errors such as "overloaded",
  and no review ran): retry with backoff (5, 10, 20, then 30 minutes), one run at a time,
  until a run completes (ecom-buddy user ruling, 2026-10-12). Tell the user that the
  outage happened.

## 4. Fix accepted findings

The manager verifies every finding against the code and sorts it: rejected (false premise,
with evidence), accepted, or backlog. Use the triage rules of `spec-review`
(`.agents/skills/spec-review/SKILL.md`; written rules are must-fix, small local UX fixes are
not deferred) and the verdict categories of `.agents/prompts/evaluate-ai-code-review.md`.
**Fix accepted small and medium findings at once, without waiting for the user**: tests that
cannot fail or miss a case, correctness bugs with a local fix, stale docs, comments and
`AGENTS.md` lines, brittle assertions, a change of one or a few units inside the spec. Ask the
user first only when the fix reverses a user ruling, changes a published price, figure or
public copy beyond the spec, needs a large refactor or a new concept, or is destructive or
touches production (root `AGENTS.md`, "Railway production operations").

- Delegate code fixes to the Claude `executor` agent (`.claude/agents/executor.md`) with one
  self-contained brief per unit: path:line, the failure, the required behavior, the test.
  Finnish public copy is written by Claude only. A one-line doc or comment fix may be made by
  the manager directly.
- Verify each fix yourself (targeted tests from the owning directory, Pint for PHP), record
  the change in the round's `actions.md` and in `tasks/<slug>/decisions.md`. Commit only when
  the user asked for commits. Run DB-backed tests only when no other run uses the same test
  database.
- A fix that changes persisted identity or a write path (a migration, a unique or lookup
  key, an upsert target, which rows a writer replaces, a stored statistics method) gets one
  focused `laravel-reviewer` check (with `pricing-reviewer` when a published figure is
  affected), as in `spec-review`; otherwise no new review round.

## 5. Learn from accepted findings

The manager decides which Pi findings are real (accepted: fixed, or real and deferred). For each
accepted finding, find out why the spec review missed it and change the review instructions so
it catches that kind of defect next time: `.agents/skills/spec-review/missed-findings.md`. This
can mean a checklist item in a reviewer's agent file, a written rule, a selection change, or a
new reviewer subagent. Record the table it asks for in the round's `actions.md` and in
`tasks/<slug>/decisions.md`. It is part of handling the review, not an extra review round.

## Setup and boundaries

Pi must already be on `PATH` and authenticated for the selected `openai-codex/` model.
Never substitute `openai/`, which uses separate API billing. If setup is missing,
report the blocker; do not install packages or start an interactive login unattended.

The default subagent extension path is:

```text
${PI_CODING_AGENT_DIR:-$HOME/.pi/agent}/npm/node_modules/@tintinweb/pi-subagents
```

For a different installation, set `PI_SUBAGENTS_PATH` to the extension file or
package directory. The required package can be installed separately with
`pi install npm:@tintinweb/pi-subagents` when the user authorizes setup.

The runner loads only the subagent extension, `headless.ts`, and `pi-print-keepalive.ts`
from this directory. It uses `pi-process-supervisor.py` (also in this directory) for
cancellation and process-group cleanup. On cancellation, the runner gives Pi five seconds
to stop, then tells the supervisor to kill Pi's process group. A cancelled run never saves
a completed report. The runner does not change any global Pi configuration.

`pi-run.sh` in this directory is the general headless launcher for one Pi reviewer or
executor run (`pi-run.sh <logfile> <pi-args...>`). `spec-review` uses it to start its Pi
reviewers. It loads the keepalive from this directory and only the optional user
extensions that exist.

`headless.ts` forces immediate Agent calls to foreground mode. A batch of foreground
calls still runs in parallel, but Pi waits for their results before it can exit.
It also requests isolated subagents with no extension/MCP tools and no worktrees.
A custom `general-purpose` agent definition must not override these call settings
with conflicting frontmatter; the installed subagent package gives frontmatter
priority. Reviewers must not delegate further.

The parent has read/search/bash/Agent tools, not write/edit tools. Children are
instructed not to write. **This is not an OS sandbox:** Bash and a child's built-in
tools can still change files. Use an external sandbox if enforced isolation is needed.
No report is written by the models; the runner saves the final answer.

The runner passes bundled `review.md` directly, not the asynchronous `/review-pr`
command. JSON events are checked for a settled final response and an explicit report
completion marker, so a progress message or model error is not saved as success.
The marker is a model declaration, not an independent proof of review coverage.
Working-tree edits without a HEAD change are reported by the reviewers, not locked
or fully detected by the launcher.

## Maintenance and checks

- Edit this canonical directory. `.claude/skills/pi-pr-review` is a symlink to it.
- Ported from `/Users/seppo/code/ecom-buddy/.agents/skills/pi-pr-review` on 2026-11-08,
  with that repository's `feature-build-orchestration` support files copied in. The
  Voltikka changes are the test rule, the empty-range clause for work on `main`, and this
  file. See `tasks/code-review-workflow/`.
- `review.md` is the Pi adaptation of Anthropic's PR Review Toolkit. It is bundled so the
  skill does not depend on a personal extension. Preserve its attribution and the included
  Apache-2.0 `LICENSE`.
  Upstream: <https://github.com/anthropics/claude-plugins-official/tree/main/plugins/pr-review-toolkit>.
- Headless execution, output handling and read-only selection are repo-specific
  additions. They do not change the generic role instructions.
- `PI_PR_REVIEW_MODEL`: `run.py` passes the full `--model` ID and `headless.ts` forces
  it on every reviewer. Without it, the manager's bare `gpt-6.1-sol` resolved the
  reviewers to the API-billed `openai/` provider and all of them failed (found in the
  Clickbuddy copy, 2026-10-01).
- Run offline tests from this directory:

  ```sh
  PYTHONDONTWRITEBYTECODE=1 python3 -m unittest -v test_runner test_smoke
  node --test headless.test.ts
  ```

Runner tests use a fake Pi executable; hook tests call the extension directly.
`test_smoke` also starts the installed Pi CLI and two real subagents with the
bundled `smoke-provider.ts` fixture. It verifies concurrent starts and awaited
results without network requests or paid model calls. It skips if Pi or the
subagent package is absent. The production runner never loads this fixture.
These checks do not establish review quality or prove live-provider integration.
