<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\ModificationRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    /**
     * Display a listing of deliveries for a specific order.
     */
    public function index(Order $order, Request $request): View
    {
        $deliveries = $order->deliveries()->orderBy('delivery_date', 'asc')->get();

        // Store report filter params in session for back navigation
        $filterKeys = ['from', 'to', 'month', 'year', 'type', 'account'];
        $filters = [];
        foreach ($filterKeys as $key) {
            if ($request->has($key)) {
                $filters[$key] = $request->input($key);
            }
        }
        if (!empty($filters)) {
            session(['report_filters' => $filters]);
        }

        return view('deliveries.index', [
            'order' => $order,
            'deliveries' => $deliveries,
        ]);
    }

    /**
     * Show the form for creating a new delivery.
     */
    public function create(Order $order): View|RedirectResponse
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        if (in_array($order->clearing_status, ['Declined', 'Hold'])) {
            return back()->with('warning', 'This order is awaiting Accounting clearance before delivery can be created.');
        }

        return view('deliveries.form', [
            'title' => 'New Delivery',
            'order' => $order,
            'delivery' => null,
        ]);
    }

    /**
     * Store a newly created delivery in storage.
     */
    public function store(Request $request, Order $order): RedirectResponse
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        if (in_array($order->clearing_status, ['Declined', 'Hold'])) {
            return back()->with('warning', 'This order is awaiting Accounting clearance before delivery can be created.');
        }

        $allowedStatuses = Auth::user()->canMarkFulfilled()
            ? 'PENDING,FULFILLED,CANCELLED'
            : 'PENDING,CANCELLED';

        $validated = $request->validate([
            'dr_number' => ['required', 'string', 'max:64', 'unique:deliveries,dr_number'],
            'delivery_date' => ['required', 'date'],
            'qty_out' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', "in:{$allowedStatuses}"],
            'type' => ['required', 'string', 'in:PICK-UP,BIG TANKER,SMALL TANKER,DELIVERY'],
            'remarks' => ['nullable', 'string'],
        ]);

        $committed = $order->committed_qty_out;
        $cancelled = $order->total_cancelled_qty;

        if ($validated['status'] !== 'CANCELLED') {
            $committed += $validated['qty_out'];
        } else {
            $cancelled += $validated['qty_out'];
        }

        if ($committed > $order->qty_ordered - $cancelled) {
            $available = max($order->effective_qty_ordered - $order->committed_qty_out, 0);
            return back()->withInput()->with('danger', 'Error: Delivery quantity would exceed the SO remaining quantity (Available: ' . $available . 'L).');
        }

        $delivery = $order->deliveries()->create(array_merge($validated, ['created_by' => Auth::id()]));

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Created delivery {$delivery->dr_number} ({$delivery->qty_out}L) for order #{$order->id} - {$order->account}",
        ]);

        return redirect()->route('order.deliveries', $order->id)
            ->with('success', 'Delivery added successfully.');
    }

    /**
     * Show the form for editing the specified delivery.
     */
    public function edit(Delivery $delivery): View
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }

        return view('deliveries.form', [
            'title' => 'Edit Delivery',
            'order' => $delivery->order,
            'delivery' => $delivery,
        ]);
    }

    /**
     * Update the specified delivery via Modification Request workflow.
     */
    public function update(Request $request, Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canEditModule1()) {
            abort(403);
        }
        $order = $delivery->order;

        $allowedStatuses = (Auth::user()->canMarkFulfilled() || $delivery->status === 'FULFILLED')
            ? 'PENDING,FULFILLED,CANCELLED'
            : 'PENDING,CANCELLED';

        $validated = $request->validate([
            'dr_number' => ['required', 'string', 'max:64', 'unique:deliveries,dr_number,' . $delivery->id],
            'delivery_date' => ['required', 'date'],
            'qty_out' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', "in:{$allowedStatuses}"],
            'type' => ['required', 'string', 'in:PICK-UP,BIG TANKER,SMALL TANKER,DELIVERY'],
            'remarks' => ['nullable', 'string'],
            'modification_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        // Diff changes against existing model attributes
        $changes = [];
        $comparableFields = ['dr_number', 'delivery_date', 'qty_out', 'status', 'type', 'remarks'];

        foreach ($comparableFields as $field) {
            $oldVal = $delivery->{$field};
            if ($field === 'delivery_date' && $oldVal) {
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
            return redirect()->route('order.deliveries', $order->id)
                ->with('info', "No changes detected on DR# {$delivery->dr_number}.");
        }

        $modRequest = ModificationRequest::create([
            'requestable_type' => Delivery::class,
            'requestable_id' => $delivery->id,
            'requested_by' => Auth::id(),
            'changes' => $changes,
            'reason' => $validated['modification_reason'] ?? 'Delivery modification submitted',
            'status' => 'PENDING',
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'REQUESTED',
            'description' => "Submitted Modification Request #{$modRequest->id} for DR# {$delivery->dr_number} (" . count($changes) . " field(s) changed)",
        ]);

        return redirect()->route('order.deliveries', $order->id)
            ->with('success', "Modification request for DR# {$delivery->dr_number} submitted to the Approvals Queue for HOD / Administrator review.");
    }

    /**
     * Remove the specified delivery from storage.
     */
    public function destroy(Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        $orderId = $delivery->order_id;
        $order = $delivery->order;

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'DELETED',
            'description' => "Deleted delivery {$delivery->dr_number} for order #{$order->id} - {$order->account}",
        ]);

        $delivery->delete();

        return redirect()->route('order.deliveries', $orderId)
            ->with('success', 'Delivery deleted successfully.');
    }
}
