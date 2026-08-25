# Implementation Plan v2: Transfer/Borrow/Return, Delivery Type Reflection, Status/Revised, Request to Modify

**Status:** Scope confirmed with supervisor across multiple rounds. Ready to build.
**Builds on top of:** `wetstock-transfer-fulfillment-roles-plan.md` (already implemented — Stock Transfer, fulfillment decoupling, roles, monthly reset all live in production)

---

## 0. Build Order (recommended)

1. **Request to Modify approval infrastructure** (Section 3) — foundational, several other pieces key off this
2. **Stock Transfer v2: Transfer/Borrow/Return** (Section 1) — independent of #1 except the new "modify" action
3. **Delivery Type column reflection** (Section 2) — quick win, fully independent, can slot in anytime
4. **Status column + Revised tagging** (Section 4) — build last, depends on #1's approval events firing the "Revised" flag

> ⚠️ Build the approval infrastructure (#1) before wiring the "-Revised" tag (#4) into the Status column — otherwise you'll have a status value with no way to actually trigger it yet.

---

## 1. Stock Transfer v2: Transfer / Borrow / Return

### 1.1 Schema change

Add a `type` column to the existing `stock_transfers` table:

```php
Schema::table('stock_transfers', function (Blueprint $table) {
    $table->string('type', 20)->default('transfer')->after('destination_warehouse_id');
    // values: 'transfer', 'borrow', 'return'
});
```

No linking table needed between Borrow and Return records — confirmed **net-total approach**, not per-transaction matching.

### 1.2 Tab → validation rules

| Tab | `type` value | Warehouse rule |
|---|---|---|
| **Transfer** | `transfer` | source & destination tank **must be same warehouse** (intra-site, depot↔tanker either direction) |
| **Borrow** | `borrow` | source & destination tank **must differ in warehouse** (cross-site) |
| **Return** | `return` | source & destination tank **must differ in warehouse** (cross-site) — functionally identical validation to Borrow, only the `type` tag differs |

Current `StockTransferController::store()` has **no warehouse validation at all** today (any tank can pair with any tank). Add explicit checks per tab before the existing contamination/capacity/availability checks.

### 1.3 Outstanding balance calculation (net total, per confirmed answer)

```
Outstanding borrowed FROM Warehouse X
  = SUM(quantity WHERE type='borrow' AND source_warehouse_id = X)
  − SUM(quantity WHERE type='return' AND destination_warehouse_id = X)
```

Compute this per warehouse. Display:
- **Borrow tab note box:** "Total Borrowed from San Simon: ___L · from Valenzuela: ___L" (outstanding, i.e. still owed back)
- **Return tab note box:** same underlying figures, framed as "Total Return Stocks for San Simon: ___L · Valenzuela: ___L" (what's left to return to each site)

Both boxes read the same two numbers — just labeled for their tab's context. Simple aggregate query, no new relationships required.

### 1.4 Legacy records (pre-restructure)

Existing `stock_transfers` rows (logged before this change — e.g. the ones in the current Transfers Log) have no `type` tag. Per confirmed answer, these need **manual review/tagging**, not an automatic default — this is an operational/data task for the ops team, not a blocking code dependency.

🔹 Suggestion: rather than a raw DB script, build a lightweight one-time "Tag Legacy Transfers" admin screen (list of untyped records with a type dropdown) so ops can review and tag them safely without SQL access. Optional — a `php artisan tinker` pass works too if the team prefers.

### 1.5 UI changes

- Replace the current "Load to Tanker / Offload to Depot / Custom Transfer" button row + in-form mode toggle with **3 top-level tabs**: Transfer, Borrow, Return.
- **Depot label display bug:** in the transfers log table, Tanker-category entries already show a badge + warehouse name; Depot-category entries are rendering blank in that same spot. Fix the partial blade so both categories render their badge/warehouse label consistently.

### 1.6 Modify action

New `edit()` / `update()` routes on `StockTransferController` (currently only has `index`/`create`/`store`). Per Section 3, this does **not** save directly — it submits a Modification Request requiring **Operations Manager** approval before the correction is applied.

---

## 2. Delivery Type Column Reflection (Module 2)

No schema or controller change needed — `deliveries.type` already exists and is captured today in the Module 1 DR form (`BIG TANKER` / `SMALL TANKER` / `PICK-UP`).

**Only change:** add a `type` badge column to the three Wet Stock assignment tables in `wetstock.deliveries.index` (Unassigned / Assigned / History) — the column header is already present but empty in all three. `$delivery->type` is already available on the eager-loaded object passed from `DeliveryAssignmentController::index()`.

---

## 3. Request to Modify — Approval Workflow

### 3.1 Schema — new table

```php
Schema::create('modification_requests', function (Blueprint $table) {
    $table->id();
    $table->morphs('requestable'); // requestable_type, requestable_id — Order, Delivery, or StockTransfer
    $table->foreignId('requested_by')->constrained('admins');
    $table->json('changes'); // { field: { old: ..., new: ... }, ... }
    $table->text('reason')->nullable();
    $table->string('status', 20)->default('PENDING'); // PENDING, APPROVED, REJECTED
    $table->foreignId('reviewed_by')->nullable()->constrained('admins');
    $table->timestamp('reviewed_at')->nullable();
    $table->timestamps();
});
```

### 3.2 Approver mapping (confirmed)

| Scope | Approver role(s) |
|---|---|
| Module 1 — any S.O. or D.R. modification (including cancellation) | `admin` (Portal Administrator) **or** `hod` |
| Module 2 — corrections to Wet Stock records (incl. Stock Transfers) | `ops_manager` only |

```php
Admin::can('module1.approveModification')  // → admin, hod
Admin::can('module2.approveModification')  // → ops_manager
```

### 3.3 Flow

1. User edits an Order, Delivery, or Stock Transfer as usual.
2. Instead of saving directly, the controller diffs old vs. new values and creates a `ModificationRequest` (status `PENDING`). The underlying record is **not** changed yet.
3. Eligible approver sees it in a new **"Pending Approvals"** queue (visible only to `admin`/`hod`/`ops_manager` depending on module).
4. On **Approve**: apply the diffed changes to the actual record, set the record's `revised_at` timestamp (drives the "-Revised" tag — see Section 4), and write an `AuditLog` entry (existing pattern).
5. On **Reject**: discard, no changes applied. (Notifying the requester is a nice-to-have, not required for MVP.)
6. **Cancelling a whole Order** routes through this same mechanism — a cancellation is just a `status` field change like any other, per confirmed scope.

### 3.4 Controllers affected

- `OrderController::update()` — becomes request-creation instead of direct save
- `DeliveryController::update()` — same
- `StockTransferController::update()` (new, per Section 1.6) — same, but routes to `ops_manager` approval instead

⚠️ **Open point — self-approval:** Since `admin`/`hod` are both requesters *and* approvers for Module 1, nothing currently stops one of them from approving their own request. Not explicitly restricted in any answer so far — flagging as a data-integrity consideration worth a quick gut-check with the team, not a blocker to ship v1.

---

## 4. Status Column + "-Revised" Tagging (Module 1 Orders Dashboard)

### 4.1 Values (confirmed)

`Pending` · `Pending - Revised` · `Fulfilled` · `Fulfilled - Revised` · `Cancelled` · `Cancelled - Revised`

### 4.2 Base status — computed, not manually selectable (confirmed)

```
Cancelled  → order.status == 'Cancelled' (set only via approved Request to Modify)
Fulfilled  → remaining_balance == 0 AND not cancelled
Pending    → remaining_balance > 0 AND not cancelled   (default / automatic — no dropdown)
```

This is consistent with the existing decoupled fulfillment design (Section 2 of the prior plan) — no new computation logic needed for the base three states.

### 4.3 "-Revised" suffix (confirmed trigger)

Appended automatically the moment a Modification Request tied to that Order (or one of its Deliveries) gets **approved** (Section 3.3, step 4). Implementation: add `revised_at` (nullable timestamp) to `orders` — and to `deliveries` if D.R.-level edits should also surface the tag on the parent order's row. Suffix shows whenever `revised_at` is not null, regardless of which base status it's paired with.

🔹 **Assumption flagged:** once tagged Revised, it's treated as **permanent** for that order (doesn't clear itself). Not explicitly discussed — reasonable default for an audit-trail feature, but worth a quick confirm if the team ever wants it to reset.

---

## 5. Roles — No Changes Needed

Confirmed: no new roles required for this batch. Reuse:
- `admin` (Portal Administrator — Roel, Mark) + `hod` (Joseph) → Module 1 approvals
- `ops_manager` (Lavin) → Module 2 approvals

The earlier question about separating Portal Administrator into an IT-only role is a **future consideration**, not part of this batch — noted here only so it isn't lost, not for action now.

---

## 6. Summary of All ⚠️ Warnings & Assumptions

1. **No warehouse validation exists today** on Stock Transfer — must be added per-tab (Transfer = same warehouse, Borrow/Return = different warehouse) since it's currently wide open.
2. **Legacy transfer records need manual tagging**, not an automatic default — operational task, plan time for it separately from the code work.
3. **Self-approval is currently unrestricted** — `admin`/`hod` could approve their own Module 1 requests. Flagged, not blocking.
4. **"Revised" is assumed permanent** once set — confirm with the team if a reset behavior is ever wanted.
5. Build the approval infrastructure (Section 3) **before** wiring the Revised tag (Section 4) — the tag has no trigger without it.
6. Modify action on Stock Transfers (Section 1.6) must route through the **same** Request to Modify system as Module 1 — don't build it as a quick direct-edit shortcut, or it'll bypass the whole point of the approval workflow.
