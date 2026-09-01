---
name: subagent-driven-development
description: >-
  Plan execution using task briefs, isolated execution, diff reviews, and
  progress tracking in .superpowers/sdd/.
---

# Subagent-Driven Development (SDD)

Use this skill to execute structured implementation plans incrementally with isolated verification at each step.

## SDD Cycle per Task

For each task in the plan:
1. **Create Task Brief** (`.superpowers/sdd/task-N-brief.md`):
   - Outline target files, goals, constraints, and step-by-step instructions.
2. **Execute Changes**:
   - Make minimal, clean modifications matching the brief exactly.
3. **Validate & Capture Diff**:
   - Run syntax checks (`php -l`) and automated tests.
   - Record git diff in `.superpowers/sdd/task-N-diff.txt`.
4. **Generate Report** (`.superpowers/sdd/task-N-report.md`):
   - Summarize completed actions, test results, and any deviations.
5. **Update Progress** (`.superpowers/sdd/progress.md`):
   - Mark task complete with commit hashes or status note.
