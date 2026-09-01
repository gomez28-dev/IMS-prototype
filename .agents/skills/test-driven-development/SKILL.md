---
name: test-driven-development
description: >-
  Test-driven development workflow: write failing tests first (Red), implement
  minimal passing code (Green), and refactor cleanly (Refactor).
---

# Test-Driven Development (TDD)

Use this skill when adding new models, business logic, endpoints, or calculation modules.

## Red-Green-Refactor Cycle

1. **Red (Failing Test First)**:
   - Create or update test file in `tests/Feature/` or `tests/Unit/`.
   - Define test cases covering expected inputs, outputs, and edge cases.
   - Run test runner (`php artisan test`) and verify the test fails for the expected reason.
2. **Green (Minimal Passing Code)**:
   - Write the simplest implementation code to satisfy the test.
   - Run test runner and verify all tests pass.
3. **Refactor (Clean & Optimize)**:
   - Clean up code structure, eliminate redundancy, and ensure formatting standards.
   - Re-run tests to ensure zero regressions.
