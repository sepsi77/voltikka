# Autoclaude Feedback

This file tracks issues, bugs, and suggestions for improving autoclaude during the Voltikka migration project.

---

## Issues Encountered

### 2026-01-19 - Timeout Configuration

**Problem:** The default 10-minute timeout when running `autoclaude run` via Bash tool may be too short for large migration tasks with many subtasks. The autoclaude process runs in the background but monitoring it requires polling.

**Suggestion:** Consider adding:
- A `--max-iterations` flag to limit how many tasks to process before stopping
- A `--timeout` flag for configuring session duration
- Better support for long-running sessions that can be monitored asynchronously

---

## Observations

### Task Processing (2026-01-19)

- Autoclaude successfully picked up tasks in priority order
- Git commits are atomic and well-documented
- Tests are being written (though not strictly TDD - tests written after implementation for some tasks)
- Progress is logged in PROGRESS.md

### Extended Session (2026-01-19)

- **11 of 16 tasks completed** in a single extended session
- After updating PROMPT.md with TDD guidance, subsequent tasks (Postcode model onwards) followed proper TDD
- Autoclaude handles complex debugging well (e.g., fixed SQLite vs PostgreSQL jsonb compatibility)
- Created missing database migrations when tests revealed gaps
- Total test count grew from 2 to 126 tests across the session
- User mentioned `autoclaude update` command is available - new version released during session

---

## Suggestions for Improvement

### 1. TDD Enforcement
Currently the agents write tests after implementation. Consider adding PROMPT.md instructions that are more strongly enforced to write tests FIRST.

### 2. Progress Visibility
Would be helpful to have a real-time progress stream or webhook for monitoring long-running sessions.

### 3. Session Recovery
If an autoclaude session is interrupted, it would be useful to have better resumption handling.

---

## Session Completion Summary (2026-01-19)

### Final Results

The `laravel-migration` session completed successfully with all **16 tasks finished**.

**Task Breakdown:**
- 6 Eloquent model tasks
- 2 Calculator service tasks
- 2 API route tasks
- 3 Scheduled job tasks
- 3 Frontend (Livewire) tasks

**Test Coverage:**
- Final test count: **137 tests, 392 assertions**
- All tests passing
- TDD was followed from task 5 onwards (after PROMPT.md update)

**Git Commits:** 16 atomic commits with comprehensive messages

### What Worked Well

1. **TDD Compliance** - After updating PROMPT.md, autoclaude consistently wrote tests first
2. **Autonomous Problem Solving** - Fixed multiple complex issues:
   - SQLite vs PostgreSQL compatibility (jsonb → json, ILIKE → LIKE)
   - Missing migrations detected via test failures
   - Finnish timezone handling for VAT rate calculations
3. **Code Quality** - Followed existing Laravel patterns consistently
4. **Progress Tracking** - PROGRESS.md provides excellent audit trail
5. **Task Decomposition** - Handled large tasks by breaking into subtasks internally

### Areas for Improvement

1. **No real-time progress visibility** - Had to poll output files repeatedly
2. **Session can't be paused/resumed mid-task** - Would be useful for very long tasks
3. **No estimated time remaining** - Hard to plan around autoclaude runs

---
