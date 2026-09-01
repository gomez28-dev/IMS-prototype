---
name: writing-plans
description: >-
  Creating comprehensive, structured, step-by-step implementation plans with
  explicit file targets, interfaces, constraints, and validation criteria.
---

# Writing Implementation Plans

Use this skill when a task involves multi-file changes, database migrations, security/role updates, or complex business logic.

## Plan Structure

A complete implementation plan must include:
1. **Goal & Architecture Summary**: High-level problem statement, design approach, and tech stack details.
2. **Global Constraints**: Unbreakable project rules (e.g. role boundaries, unique constraints, PHP 8.3/MySQL compatibility).
3. **File Responsibility Matrix**: Table mapping each target file to its specific role.
4. **Step-by-Step Task Breakdown**:
   - Numbered tasks with checkboxes (`- [ ] **Step N: ...**`).
   - Exact file paths (`database/migrations/...`, `app/Http/Controllers/...`, `resources/views/...`).
   - Interfaces: inputs consumed, outputs produced.
   - Exact code snippets or migration definitions.
   - Verification command for each step (e.g., `php -l <file>`, `php artisan route:list`).
