# Decisions

- Use root AGENTS.md because Railway production-operation rules are project-wide and cross-cutting.
- CLAUDE.md is a symlink to AGENTS.md, so editing AGENTS.md keeps both synchronized.
- Recorded Railway IDs from `railway list --json`: Breezily workspace, Voltikka project, production environment, app service, and MySQL service.
- Added explicit safety guidance: no destructive or production-mutating Railway commands without explicit user confirmation.
