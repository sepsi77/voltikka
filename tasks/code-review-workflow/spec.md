# Code review workflow (ported from ecom-buddy)

## Goal

Give Voltikka the same three review stages that ecom-buddy uses:

1. **`spec-review`**: dedicated read-only reviewer agents audit finished work against its
   `tasks/<slug>/spec.md`. The manager triages, fixes, runs at most one verification pass and
   writes `actions.md`.
2. **`pi-pr-review`**: one headless Pi manager runs the PR Review Toolkit roles (code, tests,
   errors, types, comments, simplify) on the whole branch and saves a report. The manager
   triages it and fixes accepted small and medium findings.
3. **`external-pr-review`**: CodeRabbit (every push) and Greptile (once per PR) review the
   GitHub PR. The manager verifies, fixes, pushes in batches and loops until a round is clean.

Accepted findings of stages 2 and 3 improve the stage 1 instructions
(`.agents/skills/spec-review/missed-findings.md`).

Source: `/Users/seppo/code/ecom-buddy/.agents/skills/{spec-review,pi-pr-review,external-pr-review}`,
`.agents/prompts/evaluate-ai-code-review.md`, `.greptile/`, `.coderabbit.yaml` (read 2026-11-08).

## Voltikka adaptations

- The spec is `tasks/<slug>/spec.md`; rulings are `tasks/<slug>/decisions.md`; progress is
  `tasks/<slug>/tasks.json`. Review rounds go to `tasks/<slug>/reviews/<ts>/`.
- Since 2026-11-08 every change goes through a task branch and a PR (root `AGENTS.md`,
  "Git workflow"). The review range is the branch against `origin/main`, plus staged, unstaged
  and untracked changes of the task. The manager records it. Old work committed on `main` uses
  an explicit base commit.
- Stage 3 runs on every PR.
- Reviewer agents are tuned to Voltikka's risks: published prices and figures (canonical pricing,
  VAT basis, annual methods and history, unknown is not zero), production safety (dry-run
  defaults, no automatic rebuilds or retries, approvals), Laravel/Livewire, Temporal/Python, and
  Finnish public copy.

## Files

| Path | Purpose |
|---|---|
| `.agents/skills/spec-review/SKILL.md`, `missed-findings.md` | Stage 1 and the learning loop |
| `.agents/skills/pi-pr-review/` | Stage 2 runner (Python + Pi extension), bundled toolkit prompt, offline tests |
| `.agents/skills/external-pr-review/SKILL.md`, `coderabbit.sh`, `greptile.sh` | Stage 3 loop and GitHub helpers |
| `.agents/prompts/evaluate-ai-code-review.md` | Shared finding-triage prompt (`/evaluate-ai-code-review`) |
| `.pi/agents/<reviewer>.md` | Canonical reviewer definitions |
| `.claude/agents/<reviewer>.md` | Claude Code copies: identical plus one `model: opus` frontmatter line |
| `.claude/skills/<skill>` | Symlinks to `.agents/skills/<skill>` |
| `.claude/commands/evaluate-ai-code-review.md`, `.pi/prompts/evaluate-ai-code-review.md` | Symlinks to the shared prompt |
| `.greptile/config.json`, `rules.md`, `files.json`, `.coderabbit.yaml` | Shared external review standards |

## Reviewers

| Agent | ID prefix | Runs on | Selected when |
|---|---|---|---|
| `refactor-reviewer` | `REF` | Pi | Always |
| `laravel-reviewer` | `LAR` | Pi | Anything under `laravel/` except views/CSS/JS-only changes |
| `pricing-reviewer` | `PRC` | Pi | Any code that produces or stores a published price, figure, statistic, forecast or cache of them |
| `test-reviewer` | `TST` | Pi | Any behavior or test change |
| `platform-reviewer` | `PLT` | Pi | `orchestration/`, `forecast/`, `Dockerfile`, entrypoints, `supervisord.conf`, `scripts/`, `laravel/routes/console.php`, `laravel/config/` |
| `frontend-reviewer` | `FE` | Claude | `laravel/resources/views/`, `resources/css/`, `resources/js/`, Livewire components with UI state |
| `copy-reviewer` | `COPY` | Claude | Public Finnish text, market review editions, media material, or behavior that public copy describes |

Model routing follows ecom-buddy: Pi `openai-codex/gpt-6.1-sol --thinking high` (ChatGPT
subscription; never `openai/`), Claude for frontend and copy.

## Done when

- The three skills, the shared prompt, seven reviewer agents (both copies), and the external
  review configs exist and follow the conventions above.
- The ported `pi-pr-review` offline tests pass in this repository.
- Root `AGENTS.md` and `tasks/AGENTS.md` point at the workflow.
- Open user decisions are listed in `decisions.md`.
