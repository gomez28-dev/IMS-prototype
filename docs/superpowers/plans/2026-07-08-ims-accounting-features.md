# IMS Accounting Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add PO# tracking, clearance workflow, accounting role, duplicate-prevention constraints, and company filter to the IMS.

**Architecture:** Add columns to existing `orders` table (PO#, clearing_status) and unique constraints on SO#/DR# via Laravel migrations. Introduce an `accounting` role (view-only + clearance editing) by adding model helpers and updating middleware usage in routes. Extend controllers (OrderController, DeliveryController, ReportController) with new validation, a clearance endpoint, and a company filter. Update Blade views to show/hide fields per role.

**Tech Stack:** Laravel 11, PHP 8.3, MySQL, Bootstrap 5, Blade

## Global Constraints

- All roles: `admin`, `editor`, `viewer`, `accounting`
- `accounting` = view-only everywhere except clearance-status updates
- `viewer` gains access to `delivery.index()` but nothing else
- New PO# required on order create/edit, globally unique
- SO# and DR# get uniqueness constraints (DB + validation)
- Clearing status values: `Pending`, `Declined`, `Hold`, `Approved`
- Only `admin` and `accounting` may change clearing status

---
## File Structure

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_07_08_000001_add_po_number_and_clearing_status_to_orders_table.php` | Add `po_number`, `clearing_status` columns + unique on `so_number` |
| `database/migrations/2026_07_08_000002_add_unique_to_dr_number_in_deliveries_table.php` | Add unique constraint on `dr_number` |
| `app/Models/Admin.php` | Add `isAccounting()` helper |
| `app/Http/Middleware/CheckRole.php` | Ensure `accounting` is recognized (may already work if string-based) |
| `routes/web.php` | Add `role:accounting` to clearance endpoint; include `accounting` in view-access routes |
| `app/Http/Controllers/OrderController.php` | PO# validation in `store()`/`update()`, new `updateClearance()` endpoint |
| `app/Http/Controllers/DeliveryController.php` | Clearance gate on `create()`/`store()`, Viewer access on `index()` |
| `app/Http/Controllers/DashboardController.php` | Sorting change (pending confirmation) |
| `app/Http/Controllers/ReportController.php` | Company filter (`account`) |
| `resources/views/dashboard.blade.php` | PO# column, Clearance Status column + inline dropdown |
| `resources/views/orders/form.blade.php` | PO# input field |
| `resources/views/reports/index.blade.php` | Company filter dropdown |
| `resources/views/deliveries/index.blade.php` | Hide New Delivery button for accounting/viewer |

---

### Task 1: Migrations (po_number, clearing_status, unique constraints)

**Files:**
- Create: `database/migrations/2026_07_08_000001_add_po_number_and_clearing_status_to_orders_table.php`
- Create: `database/migrations/2026_07_08_000002_add_unique_to_dr_number_in_deliveries_table.php`

**Interfaces:**
- Consumes: existing `orders` and `deliveries` table schemas
- Produces: `orders.po_number` (string, unique), `orders.clearing_status` (string, default `Pending`), unique constraint on `orders.so_number`, unique constraint on `deliveries.dr_number`

- [ ] **Step 1: Create first migration for orders table**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('po_number', 64)->after('id')->unique()->nullable();
            $table->string('clearing_status', 20)->default('Pending')->after('po_number');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('so_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['so_number']);
            $table->dropColumn('clearing_status');
            $table->dropColumn('po_number');
        });
    }
};
```

Save to: `database/migrations/2026_07_08_000001_add_po_number_and_clearing_status_to_orders_table.php`

- [ ] **Step 2: Create second migration for deliveries unique constraint**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->unique('dr_number');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropUnique(['dr_number']);
        });
    }
};
```

Save to: `database/migrations/2026_07_08_000002_add_unique_to_dr_number_in_deliveries_table.php`

- [ ] **Step 3: Run syntax check**

Run: `php -l database/migrations/2026_07_08_000001_add_po_number_and_clearing_status_to_orders_table.php; php -l database/migrations/2026_07_08_000002_add_unique_to_dr_number_in_deliveries_table.php`

Expected: "No syntax errors detected" for both.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_08_000001_add_po_number_and_clearing_status_to_orders_table.php database/migrations/2026_07_08_000002_add_unique_to_dr_number_in_deliveries_table.php
git commit -m "feat: add po_number, clearing_status, uniqueness constraints"
```

---

### Task 2: Admin model + CheckRole/middleware updates for `accounting` role

**Files:**
- Modify: `app/Models/Admin.php`
- Modify: `app/Http/Middleware/CheckRole.php` (inspect to confirm)

**Interfaces:**
- Consumes: existing `Admin` model
- Produces: `Admin::isAccounting(): bool` method

- [ ] **Step 1: Add `isAccounting()` to Admin model**

Add to `app/Models/Admin.php` after the existing `isViewer()` method:

```php
public function isAccounting(): bool
{
    return $this->role === 'accounting';
}
```

- [ ] **Step 2: Inspect CheckRole middleware**

Read `app/Http/Middleware/CheckRole.php` to verify it handles the `role` parameter as a comma-separated string (e.g., `role:admin,accounting`). If it uses `in_array($user->role, $roles)`, it already works. If it only checks exact match, update to support comma-separated roles.

- [ ] **Step 3: Update seeder to allow accounting role**

Read `database/seeders/DatabaseSeeder.php` and add `accounting` as a valid role in the seeder's role list.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Admin.php app/Http/Middleware/CheckRole.php database/seeders/DatabaseSeeder.php
git commit -m "feat: add accounting role support to model and middleware"
```

---

### Task 3: OrderController — PO# + clearance endpoint + validation

**Files:**
- Modify: `app/Http/Controllers/OrderController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `orders.po_number` and `orders.clearing_status` columns from Task 1, `Admin::isAccounting()` from Task 2
- Produces new route: `POST /order/{order}/clearance` → `OrderController@updateClearance`

- [ ] **Step 1: Read current OrderController**

Read `app/Http/Controllers/OrderController.php` to understand current `store()` and `update()` method signatures and validation rules.

- [ ] **Step 2: Add `po_number` to `store()` and `update()` validation**

Add `po_number` field to the validation rules array in both methods:

```php
'po_number' => ['required', 'string', 'max:64', 'unique:orders,po_number'],
```

Also add uniqueness check for `so_number`:

```php
'so_number' => ['required', 'string', 'max:64', 'unique:orders,so_number,' . ($order->id ?? 'NULL')],
```

(Use `$order->id ?? 'NULL'` in `store()` where `$order` is null, and the actual `$order->id` in `update()` for the ignore-self pattern.)

- [ ] **Step 3: Add `updateClearance()` method**

Add this method to OrderController:

```php
public function updateClearance(Request $request, Order $order): RedirectResponse
{
    if (!auth()->user()->isAdmin() && !auth()->user()->isAccounting()) {
        abort(403);
    }

    $validated = $request->validate([
        'clearing_status' => ['required', 'string', 'in:Pending,Declined,Hold,Approved'],
    ]);

    $order->update(['clearing_status' => $validated['clearing_status']]);

    AuditLog::create([
        'admin_id' => auth()->id(),
        'action' => 'updated',
        'description' => "Updated clearance status for order #{$order->id} ({$order->account}) to {$validated['clearing_status']}",
    ]);

    return redirect()->route('dashboard')
        ->with('success', 'Clearance status updated successfully.');
}
```

- [ ] **Step 4: Add route for clearance endpoint in `routes/web.php`**

Inside the `role:admin` middleware group, add:

```php
Route::post('/order/{order}/clearance', [OrderController::class, 'updateClearance'])->name('order.clearance');
```

Also add `accounting` to the route's middleware for this endpoint. The route should be accessible to both `admin` and `accounting`. Either place it in a separate group or use a middleware string like `role:admin,accounting`. Add this group inside the `auth` group:

```php
Route::middleware('role:admin,accounting')->group(function () {
    Route::post('/order/{order}/clearance', [OrderController::class, 'updateClearance'])->name('order.clearance');
});
```

- [ ] **Step 5: Syntax check**

Run: `php -l app/Http/Controllers/OrderController.php; php -l routes/web.php`

Expected: "No syntax errors detected" for both.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/OrderController.php routes/web.php
git commit -m "feat: add PO# validation and clearance endpoint"
```

---

### Task 4: DeliveryController — clearance gate + Viewer/Accounting access rules

**Files:**
- Modify: `app/Http/Controllers/DeliveryController.php`
- Modify: `routes/web.php` (if adding `accounting` role to delivery routes)

**Interfaces:**
- Consumes: `orders.clearing_status` from Task 1, `Admin::isAccounting()` from Task 2
- Produces: Clearance gate on delivery creation, Viewer access on `index()`

- [ ] **Step 1: Read current DeliveryController**

Read `app/Http/Controllers/DeliveryController.php`. Current state: `abort(403)` on `isViewer()` for `index()`, `create()`, `store()`, `edit()`, `update()`, `destroy()`.

- [ ] **Step 2: Remove `abort(403)` from `index()`**

Delete the `if (auth()->user()->isViewer()) { abort(403); }` block from the `index()` method only.

- [ ] **Step 3: Add clearance gate to `create()` and `store()`**

After the existing `isViewer()` check in `create()` and `store()`, add:

```php
if ($order->clearing_status !== 'Approved') {
    return back()->with('warning', 'This order is awaiting Accounting clearance before delivery can be created.');
}
```

For `store()`, `$order` is the route parameter, already available.

- [ ] **Step 4: Restrict accounting role on create/store/edit/update/destroy**

Wherever `isViewer()` is checked and `abort(403)` is used for viewer, also add `isAccounting()`:

Change checks like:
```php
if (auth()->user()->isViewer()) {
    abort(403);
}
```
to:
```php
if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
    abort(403);
}
```

This applies to `create()`, `store()`, `edit()`, `update()`, and `destroy()`.

- [ ] **Step 5: Syntax check**

Run: `php -l app/Http/Controllers/DeliveryController.php`

Expected: "No syntax errors detected"

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DeliveryController.php
git commit -m "feat: add clearance gate and viewer/accounting access rules to deliveries"
```

---

### Task 5: Views — dashboard columns, order form PO# field, clearance dropdown

**Files:**
- Modify: `resources/views/dashboard.blade.php`
- Modify: `resources/views/orders/form.blade.php`
- Modify: `resources/views/deliveries/index.blade.php` (hide New Delivery for accounting)

**Interfaces:**
- Consumes: `$order->po_number`, `$order->clearing_status`, `Admin::isAccounting()`

- [ ] **Step 1: Read current dashboard.blade.php**

Read `resources/views/dashboard.blade.php` to check the current table and mobile card layout.

- [ ] **Step 2: Add PO# column to desktop table**

In the `<thead>` after the Account column, add:
```html
<th>PO#</th>
```

In each data `<tr>`, after the account `<td>`:
```html
<td>{{ $order->po_number }}</td>
```

- [ ] **Step 3: Add PO# to mobile cards**

In the mobile card section, after the account name line, add:
```html
<p class="text-muted small mb-1"><span class="fw-medium">PO#:</span> {{ $order->po_number }}</p>
```

- [ ] **Step 4: Add Clearance Status column with colored badge**

In the desktop table `<thead>`, add:
```html
<th class="text-center">Clearance</th>
```

In each data row, add:
```html
<td class="text-center">
    @php
        $cls = $order->clearing_status;
        $badgeClass = match($cls) {
            'Approved' => 'bg-success-subtle text-success border-success-subtle',
            'Declined' => 'bg-danger-subtle text-danger border-danger-subtle',
            'Hold' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
            default => 'bg-secondary-subtle text-secondary border-secondary-subtle',
        };
    @endphp
    <span class="badge rounded-pill px-3 py-1 border {{ $badgeClass }}">{{ $cls }}</span>
</td>
```

- [ ] **Step 5: Add inline Clearance Status dropdown for admin/accounting**

In the mobile card, add clearance status display below remaining balance section. After the remaining balance block, add:
```blade
<div class="d-flex justify-content-between align-items-center">
    <span class="text-muted small">Clearance:</span>
    @php
        $cls = $order->clearing_status;
        $badgeClass = match($cls) {
            'Approved' => 'bg-success-subtle text-success border-success-subtle',
            'Declined' => 'bg-danger-subtle text-danger border-danger-subtle',
            'Hold' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
            default => 'bg-secondary-subtle text-secondary border-secondary-subtle',
        };
    @endphp
    @if (auth()->user()->isAdmin() || auth()->user()->isAccounting())
        <form method="POST" action="{{ route('order.clearance', $order->id) }}" class="d-inline">
            @csrf
            <select name="clearing_status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                <option value="Pending" {{ $cls === 'Pending' ? 'selected' : '' }}>Pending</option>
                <option value="Declined" {{ $cls === 'Declined' ? 'selected' : '' }}>Declined</option>
                <option value="Hold" {{ $cls === 'Hold' ? 'selected' : '' }}>Hold</option>
                <option value="Approved" {{ $cls === 'Approved' ? 'selected' : '' }}>Approved</option>
            </select>
        </form>
    @else
        <span class="badge rounded-pill px-3 py-1 border {{ $badgeClass }}">{{ $cls }}</span>
    @endif
</div>
```

- [ ] **Step 6: Read and update `orders/form.blade.php`**

Read `resources/views/orders/form.blade.php`. Add PO# input field after the Account field (or wherever appropriate):

```html
<div class="mb-3">
    <label for="po_number" class="form-label fw-medium text-secondary small">PO Number</label>
    <input type="text" name="po_number" id="po_number" class="form-control @error('po_number') is-invalid @enderror" value="{{ old('po_number', $order->po_number ?? '') }}" required>
    @error('po_number')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
```

- [ ] **Step 7: Read and update `deliveries/index.blade.php`**

Read `resources/views/deliveries/index.blade.php`. Find the "Add Delivery" button and wrap it to hide for accounting and viewer:

```blade
@if (!Auth::user()->isViewer() && !Auth::user()->isAccounting())
```

- [ ] **Step 8: Commit**

```bash
git add resources/views/dashboard.blade.php resources/views/orders/form.blade.php resources/views/deliveries/index.blade.php
git commit -m "feat: add PO#, clearance status columns, and inline dropdown to views"
```

---

### Task 6: ReportController + reports view — company filter

**Files:**
- Modify: `app/Http/Controllers/ReportController.php`
- Modify: `resources/views/reports/index.blade.php`

**Interfaces:**
- Consumes: `Client` model for dropdown data, `Request` input `account`
- Produces: Company filter on reports + export

- [ ] **Step 1: Read current ReportController**

Read `app/Http/Controllers/ReportController.php` to understand the existing filter pattern.

- [ ] **Step 2: Add company filter to `index()`**

After existing filter logic, add:
```php
$account = $request->input('account');
if ($account) {
    $query->where('account', $account);
}
```

Add to the `view` call:
```php
$clients = \App\Models\Client::orderBy('name')->get();
return view('reports.index', compact('orders', 'from', 'to', 'month', 'year', 'type', 'activeFilter', 'account', 'clients'));
```

- [ ] **Step 3: Add company filter to `export()`**

Same pattern: add `$account` input parsing and `where('account', $account)` filter to the export query before `->get()`.

- [ ] **Step 4: Add company filter dropdown to reports view**

Add between the `Type` and the filter buttons in `resources/views/reports/index.blade.php`:

```html
<div class="col-md-2">
    <label for="account" class="form-label fw-medium text-secondary small">Company</label>
    <select name="account" id="account" class="form-control form-select">
        <option value="">All Companies</option>
        @foreach ($clients as $client)
            <option value="{{ $client->name }}" {{ ($account ?? '') === $client->name ? 'selected' : '' }}>{{ $client->name }}</option>
        @endforeach
    </select>
</div>
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/views/reports/index.blade.php
git commit -m "feat: add company filter to reports and export"
```

---

### Task 7: Sorting change (pending confirmation)

**⚠️ PENDING CONFIRMATION — DO NOT IMPLEMENT UNTIL SUPERVISOR CONFIRMS**

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`

**Change (once confirmed):**
```php
$orders = $query->orderBy('so_number', 'desc')->paginate(10)->withQueryString();
```
(currently: `orderBy('date', 'desc')`)

- [ ] **Step 1: Wait for supervisor confirmation** — see Open Item #1 in the spec
- [ ] **Step 2: Apply the sort change in `DashboardController@index`** (once confirmed)

```bash
git add app/Http/Controllers/DashboardController.php
git commit -m "fix: change dashboard sort order to so_number desc"
```

---

### Task 8: Manual test pass covering all 4 roles

**Files:** (none — manual testing)

**Interfaces:**
- Consumes: the live application or a local test environment with the `db:seed` run

- [ ] **Step 1: Create test accounts for each role**
    - Admin (existing)
    - Editor (existing)
    - Viewer (existing)
    - Accounting (create via Admin → System Accounts)

- [ ] **Step 2: Test accounting role**
    - Login as accounting, confirm view-only access on dashboard, reports, deliveries index
    - Confirm "Create Order", "Edit Order", "Add Delivery", "Import Excel" buttons are hidden
    - Confirm clearance dropdown IS visible on dashboard (inline select works)
    - Confirm changing clearance status via dropdown works
    - Confirm "Download Excel" is accessible
    - Confirm "Delete" buttons are hidden

- [ ] **Step 3: Test viewer role**
    - Login as viewer, confirm can see dashboard + reports + deliveries index
    - Confirm clearance dropdown is NOT visible (shows badge only)
    - Confirm "Download Excel" is accessible

- [ ] **Step 4: Test editor role**
    - Login as editor, confirm can create and edit orders
    - Confirm PO# field is required on create/edit
    - Confirm cannot see or change clearance status
    - Confirm clearance gate blocks delivery creation when status !== Approved

- [ ] **Step 5: Test admin role**
    - Login as admin, confirm can do everything
    - Confirm uniqueness validation on PO#, SO#, DR#
    - Confirm clear, user-friendly error messages on duplicate submission

- [ ] **Step 6: Test migrations**
    - Run `php artisan migrate:fresh --seed` in a test environment
    - Confirm PO# and clearing_status columns exist on orders table
    - Confirm unique constraints work on so_number and dr_number

---

## Open Items (pending confirmation before acting)

1. **Sort order basis** — SO# descending or Order Date descending? (Task 7 is blocked until confirmed.)
2. **Default clearing_status for legacy orders** — Should existing orders after migration default to `Pending` or `Approved`? Currently defaulting to `Pending` in migration, but if changed to `Approved` the migration SQL needs updating.
3. **Accounting role on reports** — Does accounting see the Reports Dashboard? Current working assumption: yes (same view-only as Viewer). Confirm if this is correct.
