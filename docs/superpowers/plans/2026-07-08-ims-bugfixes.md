# IMS Bug Fixes Implementation Plan

**Goal:** Fix three identified issues from local testing of the accounting features implementation.

## Fix 1: Accounting role in Manage Accounts page

**Files:**
- Modify: `app/Http/Controllers/AdminController.php`
- Modify: `resources/views/accounts/create.blade.php`
- Modify: `resources/views/accounts/edit.blade.php`

**What to change:**
1. AdminController `store()` line 34: change `in:admin,editor,viewer` to `in:admin,editor,viewer,accounting`
2. AdminController `update()` line 66: same change
3. Create view line 51: add `<option value="accounting">Accounting</option>` after Viewer option
4. Edit view line 50: same option added

## Fix 2: Reports Dashboard summary cards

**Files:**
- Modify: `app/Http/Controllers/ReportController.php`
- Modify: `resources/views/reports/index.blade.php`

**What to change:**
1. ReportController: compute 4 aggregate stats from the filtered query (before paginate)
2. Pass stats to view via compact()
3. Reports view: add 4-card HTML block between filter form and results, wrapped in `@if ($activeFilter)`

## Fix 3: Clearance dropdown on desktop dashboard

**Files:**
- Modify: `resources/views/dashboard.blade.php`

**What to change:**
1. Replace static badge in desktop clearance column with inline dropdown for admin/accounting
2. Show badge only for editor/viewer

## Bonus: DeliveryController return type fix

**Files:**
- Modify: `app/Http/Controllers/DeliveryController.php`

**What to change:**
1. `create()` return type: `View` → `\Illuminate\View\View|\Illuminate\Http\RedirectResponse`
