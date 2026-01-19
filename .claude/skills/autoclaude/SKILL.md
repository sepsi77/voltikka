---
name: autoclaude
description: Manage autonomous development sessions with Autoclaude
allowed-tools:
  - Bash
---

# Autoclaude Skill

Autoclaude is a Python orchestrator for Claude Code CLI that enables autonomous development. Each iteration runs Claude with a fresh session, picks the highest-priority task from a session's task list, completes it, commits to git, and exits.

## Commands

### Initialize a Project

```bash
autoclaude init
```

Sets up the `.autoclaude/` directory structure with `PROMPT.md`, `sessions/`, and `archive/` directories.

### Session Management

**Start a new session:**
```bash
autoclaude session start <name>
```

Creates a new session with `TASKS.json`, `PROGRESS.md`, and `DECISIONS.md` files.

**Plan tasks interactively:**
```bash
autoclaude session plan
```

Launches interactive Claude to help plan and define tasks for the current session.

**List sessions:**
```bash
autoclaude session list
```

Shows all active and archived sessions.

**Show session status:**
```bash
autoclaude session status
```

Displays current session info and task summary (pending, in_progress, completed, blocked).

**End and archive a session:**
```bash
autoclaude session end
```

Moves the current session to the archive.

### Running the Development Loop

**Autonomous mode:**
```bash
autoclaude run
```

Runs the autonomous development loop. Claude picks tasks from `TASKS.json`, completes them, commits changes, and continues until tasks are done or limits are reached.

**Interactive mode:**
```bash
autoclaude run --interactive
```

Runs interactively, allowing you to chat with Claude while it works on tasks.

### Safety and Recovery

**Reset circuit breaker:**
```bash
autoclaude reset-circuit
```

Resets the circuit breaker if it tripped due to consecutive failures or stagnation.

### Installation and Updates

**Install system-wide:**
```bash
autoclaude install
```

Installs autoclaude as a system-wide command using `uv tool install` or `pipx install`.

**Update to latest version:**
```bash
autoclaude update
```

Pulls latest changes from git and reinstalls.

## Session Structure

Each session lives in `.autoclaude/sessions/<name>/` with:

- **TASKS.json** - Task list with priority, status, dependencies
- **PROGRESS.md** - Append-only iteration log
- **DECISIONS.md** - Design decision log
- **LAST_ITERATION.json** - Context passed to next iteration

## Task Format

Tasks in `TASKS.json` follow this structure:

```json
{
  "session": "session-name",
  "description": "What this session accomplishes",
  "tasks": [
    {
      "id": "unique-task-id",
      "title": "Short task title",
      "description": "Detailed description of what to do",
      "status": "pending",
      "priority": 1
    }
  ]
}
```

**Task statuses:** `pending`, `in_progress`, `completed`, `blocked`

**Priority:** Lower number = higher priority (1 is highest)
