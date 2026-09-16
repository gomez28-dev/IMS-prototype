<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ModificationRequest;
use App\Models\Order;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * Redirect back to wherever the user actually came from (e.g. Reports with its
     * filters intact), falling back to the Dashboard if none was captured or if the
     * given value isn't actually a page on this site (basic open-redirect guard).
     */
    private function redirectToOrigin(?string $returnTo): RedirectResponse
    {
        if ($returnTo && str_starts_with($returnTo, url('/'))) {
            return redirect($returnTo);
        }

        return redirect()->route('dashboard');
    }

    /**
     * Show the form for creating a new order.
     */
    public function create(): View
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        return view('orders.form', [
            'title' => 'New Order',
            'order' => null,
            'clients' => Client::orderBy('name', 'asc')->get(),
            'returnTo' => url()->previous(),
        ]);
    }

    /**
     * Store a newly created order in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        $validated = $request->validate([
            'account' => ['required', 'string', 'max:128'],
            'date' => ['required', 'date'],
            'qty_ordered' => ['required', 'integer', 'min:0'],
            // Column is NOT NULL with a 0.00 default, so an omitted/blank price
            // is valid and simply means "no price entered yet" — coerced below.
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'so_number' => ['required', 'string', 'max:64',
                Rule::unique('orders', 'so_number')->where(fn($q) => $q->where('location', $request->location)),
            ],
            'po_number' => ['nullable', 'string', 'max:64', 'unique:orders,po_number'],
            'location' => ['required', 'string', 'in:Valenzuela,San Simon'],
            'status' => ['required', 'string', 'in:Active,Cancelled'],
            'terms' => ['nullable', 'string', 'max:64'],
        ]);

        $validated['price'] = $validated['price'] ?? 0;
        $validated['created_by'] = Auth::id();

        $order = Order::create($validated);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Created order #{$order->id} - {$order->account} (SO# {$order->so_number})",
        ]);

        // Notify Accounting that a new sales order needs clearance review.
        // Wrapped so a push-delivery hiccup never blocks order creation itself.
        app(PushNotificationService::class)->sendToRole(
            'accounting',
            'New Sales Order Submitted',
            "SO# {$order->so_number} — {$order->account} ({$order->location}) needs clearance approval.",
            ['url' => route('dashboard')]
        );

        return $this->redirectToOrigin($request->input('return_to'))
            ->with('success', 'Order created successfully.');
    }

    /**
     * Show the form for editing the specified order.
     */
    public function edit(Order $order): View
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        return view('orders.form', [
            'title' => 'Edit Order',
            'order' => $order,
            'clients' => Client::orderBy('name', 'asc')->get(),
            'returnTo' => url()->previous(),
        ]);
    }

    /**
     * Update the specified order via Modification Request workflow.
     */
    public function update(Request $request, Order $order): RedirectResponse
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        $validated = $request->validate([
            'account' => ['required', 'string', 'max:128'],
            'date' => ['required', 'date'],
            'qty_ordered' => ['required', 'integer', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'so_number' => ['required', 'string', 'max:64',
                Rule::unique('orders', 'so_number')
                    ->where(fn($q) => $q->where('location', $request->location))
                    ->ignore($order->id),
            ],
            'po_number' => ['nullable', 'string', 'max:64', 'unique:orders,po_number,' . $order->id],
            'location' => ['required', 'string', 'in:Valenzuela,San Simon'],
            'status' => ['required', 'string', 'in:Active,Cancelled'],
            'terms' => ['nullable', 'string', 'max:64'],
            'modification_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['price'] = $validated['price'] ?? 0;

        $returnTo = $request->input('return_to');

        // Diff changes against existing model attributes
        $changes = [];
        $comparableFields = ['account', 'date', 'qty_ordered', 'price', 'so_number', 'po_number', 'location', 'status', 'terms'];

        foreach ($comparableFields as $field) {
            $oldVal = $order->{$field};
            if ($field === 'date' && $oldVal) {
                $oldVal = $oldVal->format('Y-m-d');
            }
            $newVal = $validated[$field] ?? null;

            if ((string)$oldVal !== (string)$newVal) {
                $changes[$field] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        if (empty($changes)) {
            return $this->redirectToOrigin($returnTo)
                ->with('info', "No changes detected on SO# {$order->so_number}.");
        }

        $modRequest = ModificationRequest::create([
            'requestable_type' => Order::class,
            'requestable_id' => $order->id,
            'requested_by' => Auth::id(),
            'changes' => $changes,
            'reason' => $validated['modification_reason'] ?? 'Sales Order modification submitted',
            'status' => 'PENDING',
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'REQUESTED',
            'description' => "Submitted Modification Request #{$modRequest->id} for SO# {$order->so_number} (" . count($changes) . " field(s) changed)",
        ]);

        return $this->redirectToOrigin($returnTo)
            ->with('success', "Modification request for SO# {$order->so_number} submitted to the Approvals Queue for HOD / Administrator review.");
    }

    /**
     * Remove the specified order from storage.
     *
     * Uses back() rather than a hardcoded route, since this is always a same-page
     * form submission (e.g. from the Reports table) — the referer header at the
     * moment of this POST genuinely is the page the button was clicked from.
     */
    public function destroy(Order $order): RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'DELETED',
            'description' => "Deleted order #{$order->id} - {$order->account}",
        ]);

        $order->delete();

        return back()->with('success', 'Order deleted successfully.');
    }

    public function updateClearance(Request $request, Order $order): RedirectResponse
    {
        if (!Auth::user()->canClearOrders()) {
            abort(403);
        }

        $validated = $request->validate([
            'clearing_status' => ['required', 'string', 'in:Pending,Declined,Hold,Approved'],
        ]);

        $order->update(['clearing_status' => $validated['clearing_status']]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Updated clearance status for order #{$order->id} ({$order->account}) to {$validated['clearing_status']}",
        ]);

        $this->notifySalesOfClearanceChange($order, $validated['clearing_status']);

        return redirect()->back()
            ->with('success', 'Clearance status updated successfully.');
    }

    /**
     * Bulk update clearance status for multiple selected orders.
     */
    public function bulkUpdateClearance(Request $request): RedirectResponse
    {
        if (!Auth::user()->canClearOrders()) {
            abort(403);
        }

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'clearing_status' => ['required', 'string', 'in:Pending,Declined,Hold,Approved'],
        ]);

        // Fetch the affected orders BEFORE updating, so we still know each
        // one's creator and SO# for the individual notifications below.
        $affectedOrders = Order::whereIn('id', $validated['order_ids'])->get();

        $count = Order::whereIn('id', $validated['order_ids'])
            ->update(['clearing_status' => $validated['clearing_status']]);

        if ($count === 0) {
            return redirect()->back()->with('danger', 'No matching orders were found to update.');
        }

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Bulk updated clearance status to {$validated['clearing_status']} for {$count} order(s): #" . implode(', #', $validated['order_ids']),
        ]);

        $this->notifySalesOfBulkClearanceChange($affectedOrders, $validated['clearing_status']);

        return redirect()->back()
            ->with('success', "Clearance status set to {$validated['clearing_status']} for {$count} order(s).");
    }

    /**
     * Notify the order's original creator (if known) plus every Sales-role
     * user that a single order's clearance status has changed.
     */
    private function notifySalesOfClearanceChange(Order $order, string $newStatus): void
    {
        $pushService = app(PushNotificationService::class);
        $title = 'Order Clearance Updated';
        $body = "SO# {$order->so_number} — {$order->account} was marked {$newStatus} by Accounting.";
        $data = ['url' => route('dashboard')];

        // The specific salesperson who created this order.
        if ($order->created_by) {
            $pushService->sendToAdmin($order->created_by, $title, $body, $data);
        }

        // Every Sales-role user, regardless of who created it.
        $pushService->sendToRole('sales', $title, $body, $data);
    }

    /**
     * Same as above, but for a bulk clearance update covering several orders
     * at once — each affected order's creator gets a personal notice, and
     * all Sales users get a single summary notice (rather than one push per
     * order, which would be noisy for a large batch).
     */
    private function notifySalesOfBulkClearanceChange($affectedOrders, string $newStatus): void
    {
        $pushService = app(PushNotificationService::class);
        $data = ['url' => route('dashboard')];

        // Individual notice per creator, for their own order(s) in this batch.
        foreach ($affectedOrders as $order) {
            if ($order->created_by) {
                $pushService->sendToAdmin(
                    $order->created_by,
                    'Order Clearance Updated',
                    "SO# {$order->so_number} — {$order->account} was marked {$newStatus} by Accounting.",
                    $data
                );
            }
        }

        // One aggregate notice to all Sales users for the whole batch.
        $count = $affectedOrders->count();
        $pushService->sendToRole(
            'sales',
            'Orders Clearance Updated',
            "{$count} order(s) were marked {$newStatus} by Accounting.",
            $data
        );
    }
}
