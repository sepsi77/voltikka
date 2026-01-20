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

## Long-Running Sessions

Autoclaude supports extended autonomous sessions with controls for duration and recovery.

### Limiting Session Duration

**By time:**
```bash
autoclaude run --timeout 60
```
Stops the session after 60 minutes. Useful for overnight runs or limiting resource usage.

**By iterations:**
```bash
autoclaude run --max-iterations 10
```
Stops after 10 iterations regardless of remaining tasks.

**Combined:**
```bash
autoclaude run --timeout 120 --max-iterations 20
```
Stops at whichever limit is reached first.

### Resuming Interrupted Sessions

If a session is interrupted (crash, Ctrl+C, timeout), tasks may be left with `in_progress` status. The `--resume` flag handles this:

```bash
autoclaude run --resume
```

Without `--resume`, autoclaude will warn you about interrupted tasks and exit. This prevents accidentally restarting work that may have partially completed.

### Example: Overnight Development Run

Set up a long-running session for overnight development:

```bash
# Start a session with your tasks
autoclaude session start feature-implementation
autoclaude session plan

# Run overnight with a 6-hour timeout
autoclaude run --timeout 360

# Check results in the morning
autoclaude session status
```

If something goes wrong overnight and the circuit breaker trips:
```bash
# Check what happened
cat .autoclaude/sessions/feature-implementation/PROGRESS.md

# Reset and continue if appropriate
autoclaude reset-circuit
autoclaude run --resume
```

## Monitoring Progress

Autoclaude provides multiple ways to monitor session progress, from human-readable logs to machine-parseable JSON.

### Session Files

Each session directory contains progress information:

**PROGRESS.md** - Human-readable iteration log
```bash
# Watch progress in real-time
tail -f .autoclaude/sessions/my-session/PROGRESS.md
```

**TASKS.json** - Current task statuses
```bash
# Check task completion
cat .autoclaude/sessions/my-session/TASKS.json | jq '.tasks[] | {id, status}'
```

**LAST_ITERATION.json** - Context from the most recent iteration
```bash
# See what happened in the last iteration
cat .autoclaude/sessions/my-session/LAST_ITERATION.json
```

### Progress File for External Tools

For integration with external monitoring tools, use `--progress-file`:

```bash
autoclaude run --progress-file /tmp/autoclaude-progress.json
```

This writes JSON status updates after each iteration:

```json
{
  "session": "my-session",
  "iteration": 3,
  "status": "running",
  "current_task": "implement-feature",
  "completed_tasks": 2,
  "pending_tasks": 5,
  "timestamp": "2026-01-19T12:30:00.000000"
}
```

**Status values:**
- `running` - Session is actively working on tasks
- `completed` - All tasks are done
- `stopped` - Session stopped (circuit breaker, timeout, or error)

### Quick Status Check

```bash
# One-liner to see current session status
autoclaude session status
```

## Task Definition Best Practices

Well-defined tasks are key to effective autonomous development. Here's how to write tasks that Claude can complete successfully.

### Keep Tasks Atomic and Focused

Each task should do one thing well. If a task description uses "and" multiple times, consider splitting it.

**Good:**
```json
{
  "id": "add-user-validation",
  "title": "Add email validation to user registration",
  "description": "Add email format validation to the user registration form. Return an error message if the email format is invalid. Use the existing validation utilities.",
  "status": "pending",
  "priority": 1
}
```

**Too broad:**
```json
{
  "id": "user-feature",
  "title": "Implement user registration",
  "description": "Add user registration with email validation, password strength checking, database storage, email verification, and login functionality.",
  "status": "pending",
  "priority": 1
}
```

### Write Clear Acceptance Criteria

The description should explain what "done" looks like. Include specific requirements and constraints.

**Good:**
```json
{
  "description": "Add pagination to the /api/users endpoint. Support page and limit query parameters. Default to page=1, limit=20. Return total count in response headers. Add tests for edge cases (empty results, invalid page numbers)."
}
```

**Vague:**
```json
{
  "description": "Add pagination to the API."
}
```

### Use Dependencies for Ordered Work

When tasks must be completed in sequence, use the `depends_on` field:

```json
{
  "tasks": [
    {
      "id": "create-user-model",
      "title": "Create User database model",
      "status": "pending",
      "priority": 1
    },
    {
      "id": "add-user-api",
      "title": "Add User CRUD API endpoints",
      "depends_on": ["create-user-model"],
      "status": "pending",
      "priority": 2
    }
  ]
}
```

### Priority Numbering Conventions

- **1-3**: Critical path items that block other work
- **4-6**: Important features or fixes
- **7-9**: Nice-to-haves and polish
- **10+**: Backlog items

Lower numbers are completed first. Tasks with the same priority are picked based on dependencies and order in the list.

## Common Workflows

Here are examples of common Autoclaude usage patterns.

### Quick Bug Fix

For a focused bug fix session:

```bash
# Start a targeted session
autoclaude session start fix-login-bug

# Plan the fix interactively
autoclaude session plan
# Claude will help you define: reproduce steps, identify root cause, implement fix, add regression test

# Run autonomously (usually 2-3 iterations)
autoclaude run --max-iterations 5

# Review and end
autoclaude session status
autoclaude session end
```

### Feature Development

For larger feature work:

```bash
# Start session
autoclaude session start add-search-feature

# Plan extensively with Claude
autoclaude session plan
# Define 5-10 atomic tasks covering: design, implementation, tests, documentation

# Run with timeout for safety
autoclaude run --timeout 60

# Check progress and continue if needed
autoclaude session status
autoclaude run --resume --timeout 60
```

### Refactoring with Tests

Ensure tests pass throughout refactoring:

```bash
autoclaude session start refactor-auth-module

# Plan tasks that alternate: refactor step, verify tests pass
autoclaude session plan

# Run and monitor - circuit breaker will trip if tests fail repeatedly
autoclaude run
```

### Documentation Updates

For documentation-focused work:

```bash
autoclaude session start update-api-docs

# Tasks: update each endpoint's documentation, add examples, fix typos
autoclaude session plan

# Usually quick iterations
autoclaude run --max-iterations 10
```

### Interactive Guidance Mode

When you want to guide Claude rather than fully autonomous:

```bash
autoclaude session start exploratory-work

# Run interactively - you can chat and course-correct
autoclaude run --interactive
```

In interactive mode, Claude still follows the session's TASKS.json but you can provide guidance, ask questions, and adjust direction as work progresses.
