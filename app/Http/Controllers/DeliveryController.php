<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
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
    public function create(Order $order): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
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
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        if (in_array($order->clearing_status, ['Declined', 'Hold'])) {
            return back()->with('warning', 'This order is awaiting Accounting clearance before delivery can be created.');
        }

        $validated = $request->validate([
            'dr_number' => ['required', 'string', 'max:64', 'unique:deliveries,dr_number'],
            'delivery_date' => ['required', 'date'],
            'qty_out' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:PENDING,FULFILLED,CANCELLED'],
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

        $delivery = $order->deliveries()->create($validated);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'created',
            'description' => "Created delivery {$delivery->dr_number} for order #{$order->id} - {$order->account}",
        ]);

        return redirect()->route('order.deliveries', $order->id)
            ->with('success', 'Delivery added successfully.');
    }

    /**
     * Show the form for editing the specified delivery.
     */
    public function edit(Delivery $delivery): View
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        return view('deliveries.form', [
            'title' => 'Edit Delivery',
            'order' => $delivery->order,
            'delivery' => $delivery,
        ]);
    }

    /**
     * Update the specified delivery in storage.
     */
    public function update(Request $request, Delivery $delivery): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }
        $order = $delivery->order;

        $validated = $request->validate([
            'dr_number' => ['required', 'string', 'max:64', 'unique:deliveries,dr_number,' . $delivery->id],
            'delivery_date' => ['required', 'date'],
            'qty_out' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:PENDING,FULFILLED,CANCELLED'],
            'type' => ['required', 'string', 'in:PICK-UP,BIG TANKER,SMALL TANKER,DELIVERY'],
            'remarks' => ['nullable', 'string'],
        ]);

        $committed = $order->committed_qty_out;
        $cancelled = $order->total_cancelled_qty;

        if ($delivery->status !== 'CANCELLED') {
            $committed -= $delivery->qty_out;
        } else {
            $cancelled -= $delivery->qty_out;
        }

        if ($validated['status'] !== 'CANCELLED') {
            $committed += $validated['qty_out'];
        } else {
            $cancelled += $validated['qty_out'];
        }

        if ($committed > $order->qty_ordered - $cancelled) {
            $available = $order->effective_qty_ordered - $order->committed_qty_out;
            if ($delivery->status !== 'CANCELLED') {
                $available += $delivery->qty_out;
            }
            return back()->withInput()->with('danger', 'Error: Delivery quantity would exceed the SO remaining quantity (Available: ' . max($available, 0) . 'L).');
        }

        $delivery->update($validated);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "Updated delivery {$delivery->dr_number} for order #{$order->id} - {$order->account}",
        ]);

        return redirect()->route('order.deliveries', $order->id)
            ->with('success', 'Delivery updated successfully.');
    }

    /**
     * Remove the specified delivery from storage.
     */
    public function destroy(Delivery $delivery): RedirectResponse
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $orderId = $delivery->order_id;
        $order = $delivery->order;

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'deleted',
            'description' => "Deleted delivery {$delivery->dr_number} for order #{$order->id} - {$order->account}",
        ]);

        $delivery->delete();

        return redirect()->route('order.deliveries', $orderId)
            ->with('success', 'Delivery deleted successfully.');
    }
}
