# Wet Stock Approvals UX — Design

**Date:** 2026-08-25
**Status:** Approved (conversation), implemented

## Problem

The Approvals Hub was only reachable from the Sales Documentation portal sidebar. A Wet Stock user submitting a stock-transfer modification request had to know to switch portals to find it. Additionally, transfer rows gave no indication a modification was pending, and users could stack duplicate pending requests on the same transfer.

## Approved Direction

User chose **Option C**: inline approve/reject for the common case + Approvals Hub link in the Wet Stock sidebar. Scope: **Wet Stock transfers only** (Module 1 friction is minimal since the Hub already lives in that portal). Inline actions are **one-click** (no review-notes prompt); review notes remain a Hub feature (mandatory on reject there).

## Design

### 1. Approvals link in Wet Stock sidebar

- Added to desktop sidebar and mobile drawer, gated by `canViewApprovals()` (admin, hod, ops_admin, ops_mgr).
- Deep-links to `route('approvals.index', ['tab' => 'module2'])` so Wet Stock users land on the Stock Transfers queue.
- Reuses the globally-computed `$__pendingApprovalsCount` badge (no extra queries).
- Sales Documentation sidebar links unchanged (default tab).

### 2. Inline approve/reject on transfers index

- `StockTransferController::index()` eager-loads PENDING modification requests (`modificationRequests` filtered by status, with `requestedBy:id,name`).
- Row states in the Action column:
  - **No pending request:** "Modify" button (unchanged).
  - **Pending request + approver** (`canApproveModule2Modification()`): "Pending Approval" badge (tooltip: requester + reason) + one-click Approve/Reject icon buttons with confirm dialogs. Modify hidden.
  - **Pending request + non-approver:** "Pending Approval" badge + "Awaiting Approval" lock hint. Modify hidden.
- Inline buttons POST to the **existing** `approvals.approve` / `approvals.reject` routes — no new routes or controller actions. Those endpoints already enforce permissions, apply changes atomically, tag `revised_at`, and write audit logs. Reject defaults review notes to "Rejected by reviewer."

### 3. Duplicate-pending guard

`StockTransferController::update()` now returns a warning redirect if a PENDING modification request already exists for the transfer, instead of creating a conflicting duplicate.

## Known Behavior (intentionally unchanged)

A requester who is also an approver (e.g., Portal Admin) can approve their own request. Revisit if self-approval needs blocking.

## Files Changed

- `app/Http/Controllers/WetStock/StockTransferController.php` — eager loading + duplicate guard
- `resources/views/wetstock/transfers/index.blade.php` — pending badge, inline approve/reject, conditional Modify
- `resources/views/layouts/app.blade.php` — Approvals link in Wet Stock desktop sidebar + mobile drawer

## Verification

- `php -l` on controller; full `view:cache` compile check passes.
- Manual: submit modification in Wet Stock → badge appears on row → approve inline (or via Hub) → row reverts to Modify, notes applied, `revised_at` tagged.
