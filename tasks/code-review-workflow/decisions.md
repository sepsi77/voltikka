# Decisions

## 2026-11-08 — Port the ecom-buddy structure, slimmed for Voltikka (agent decision under delegated authority)

- Keep the three stages and the learning loop as in ecom-buddy. Their value comes from the loop:
  every accepted external finding improves the spec-review instructions.
- Do not port `feature-planning`, `feature-build-orchestration` or `merge-pr`. Voltikka plans in
  `tasks/` (see `tasks/AGENTS.md`) and deploys with its own documented steps. A merge is a plain
  `gh pr merge` after an explicit user request; deployment stays a separate approval.
- Do not port the accumulated ecom-buddy lessons ("learned from PR 17 …"). They describe Tallyroom
  code. Voltikka starts with general rules and grows its own lessons through `missed-findings.md`.
- Seven reviewers instead of ten. Voltikka has no tenant model, no separate FE/BE API contract and
  no LLM prompt layer of the same size, so `route-reviewer`, `contract-reviewer`,
  `model-boundary-reviewer` and `async-db-reviewer` are dropped. Voltikka-specific risk gets its own
  owners: `pricing-reviewer` (published numbers) and `platform-reviewer` (Temporal, scheduler,
  Docker, deploy scripts). `architecture-reviewer` is folded into `refactor-reviewer`, because
  Voltikka has no `docs/architecture.md` with size tripwires.
- Canonical agent files stay in `.pi/agents/` (the existing Voltikka pattern), with Claude copies
  that add only `model: opus`.
- Model routing copies ecom-buddy: Pi `openai-codex/gpt-6.1-sol` for most reviewers, Claude for
  `frontend-reviewer` and `copy-reviewer` (Claude writes better Finnish and design judgment). An
  independent model family is the main reason for a second review opinion.

## Resolved user decisions (2026-11-08)

The user installed CodeRabbit and Greptile on `sepsi77/voltikka` and chose the branch and PR way of
working for all changes. Root `AGENTS.md` now has `## Git workflow: branches and pull requests`
(one branch per task, worktrees when the main checkout is shared, merge only on user request, deploy
only from `origin/main`). The review skills now assume a task branch. Earlier text:

1. **GitHub apps.** CodeRabbit and Greptile have never commented on a Voltikka PR. The user must
   install both apps on `sepsi77/voltikka` before stage 3 can run. CodeRabbit costs one review of the
   plan allowance per push; Greptile costs credits (Base 1, Plus 3, Apex 10).
2. **When to use a PR.** Voltikka usually commits to `main` directly. Stage 3 needs a branch and a
   PR. Proposed default: feature tasks with a `spec.md` go through a branch and PR; small fixes stay
   on `main` with stage 1 or 2 only.

## 2026-11-08 — Build notes (agent decisions under delegated authority)

- `pi-pr-review` is self-contained: `pi-run.sh`, `pi-print-keepalive.ts` and
  `pi-process-supervisor.py` were copied into the skill directory, because Voltikka has no
  `feature-build-orchestration` skill. `spec-review` launches Pi reviewers with that `pi-run.sh`.
- `run.py` now tells Pi to review staged, unstaged and untracked changes when the committed range is
  empty (work directly on `main`, `--base HEAD`). Two new runner tests cover it. Offline tests:
  20 Python tests and 4 Node tests pass; the Pi smoke test started two real subagents offline.
- Fixes go to the existing `executor` agent; Voltikka has no separate backend/frontend executors
  or scout agents. Broad read-only verification uses the built-in `Explore` agent.
- `platform-reviewer` rule corrected after a check against the code: `--pull` is in
  `scripts/build-and-push-image.sh`, and only the queue worker `--timeout` must stay below
  `retry_after` (database connection: code minimum 1,050 s; redis default 90 s).
- Not changed: `.pi/agents/executor.md` and `.claude/agents/executor.md` already differ in wording,
  and the root `## Pi subagents` section still names the old `confirmProjectAgents` flag.
