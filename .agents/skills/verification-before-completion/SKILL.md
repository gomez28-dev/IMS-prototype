---
name: verification-before-completion
description: >-
  Pre-completion verification checklist ensuring syntax validity, migration safety,
  route consistency, and zero regressions before declaring done.
---

# Verification Before Completion

Use this skill prior to concluding any task or claiming completion to ensure high reliability and zero broken deployments.

## Pre-Completion Checklist

1. **Syntax & Static Verification**:
   - Run `php -l` on all modified or newly created PHP files.
2. **Migration & DB Verification**:
   - Verify migrations are reversible (`down()` method defined) and do not drop unrecoverable production data.
   - Ensure foreign keys and unique indexes match MySQL requirements.
3. **Route & Controller Verification**:
   - Check middleware and role guards match intended access levels (e.g. `role:admin,accounting`).
4. **View & UI Integrity**:
   - Ensure Blade directives (`@if`, `@foreach`, `@can`, `@auth`) are properly closed.
   - Verify CSRF tokens are present on all forms (`@csrf`).
5. **Deployment Considerations**:
   - Verify changes adhere to Hostinger shared hosting environment (no root CLI assumptions, no node build steps).
