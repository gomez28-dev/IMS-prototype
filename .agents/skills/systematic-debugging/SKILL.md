---
name: systematic-debugging
description: >-
  Disciplined 4-phase root-cause debugging workflow for investigating errors,
  unexpected behaviors, and regressions.
---

# Systematic Debugging

Use this skill whenever investigating bugs, test failures, or runtime exceptions. Do not apply blind trial-and-error fixes.

## 4-Phase Protocol

1. **Phase 1: Reproduce & Observe**
   - Gather exact error traces, logs (`storage/logs/laravel.log`), and reproduction conditions.
   - Understand the expected behavior vs. actual behavior.
2. **Phase 2: Isolate Root Cause**
   - Trace the execution flow backwards from the failure point.
   - Inspect database state, query parameters, auth guards, and middleware.
   - Avoid guesswork; identify the exact line and mechanism causing failure.
3. **Phase 3: Formulate Targeted Fix**
   - Design the minimal fix addressing the root cause directly.
   - Check for secondary impacts or regressions across related controllers/views.
4. **Phase 4: Verify & Validate**
   - Verify the fix against the reproduction scenario.
   - Run full regression and syntax checks before committing.
