# Task tracking system for AI coding agents

A simple task tracking system for maintaining state on long-running agentic development tasks.

Components:
- Spec.md - Documents the overall task and requirements
- Tasks.json - Overall task broken into individual tasks. Each has (at most) name and status
- Decisions.md - Documents any decisions, reasoning and key points made during the development process. This helps future coding agents understand what was done and why.


Rules:
- Create a separate folder for each task and create empty spec.md, tasks.json and decisions.md files there
- ALWAYS read the context files for the task before you read or edit any code.
- ALWAYS update the relevant files after each task

Reviews:
- A review round writes its reports, `summary.md` and `actions.md` to `tasks/<slug>/reviews/<ts>/` (`<ts>` = `YYYY-MM-DDTHH-MM`, Helsinki time). See `## Code review workflow` in the root `AGENTS.md` and `.agents/skills/spec-review/SKILL.md`.
- Write `spec.md` so it can be reviewed: list the requirements and "Done when" checks as separate items. The spec review numbers them `S1..Sn`.
