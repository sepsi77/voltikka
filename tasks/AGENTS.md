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