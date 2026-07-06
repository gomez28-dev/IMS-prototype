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
    public function index(Order $order): View
    {
        if (auth()->user()->isViewer()) {
            abort(403);
        }

        $deliveries = $order->deliveries()->orderBy('delivery_date', 'asc')->get();

        return view('deliveries.index', [
            'order' => $order,
            'deliveries' => $deliveries,
        ]);
    }

    /**
     * Show the form for creating a new delivery.
     */
    public function create(Order $order): View
    {
        if (auth()->user()->isViewer()) {
            abort(403);
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
        if (auth()->user()->isViewer()) {
            abort(403);
        }
        $validated = $request->validate([
            'dr_number' => ['required', 'string', 'max:64'],
            'delivery_date' => ['required', 'date'],
            'qty_out' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:PENDING,FULFILLED,CANCELLED'],
            'type' => ['required', 'string', 'in:PICK-UP,DELIVERY'],
            'remarks' => ['nullable', 'string'],
        ]);

        if ($validated['status'] === 'FULFILLED') {
            if ($validated['qty_out'] > $order->remaining_balance) {
                return back()->withInput()->with('danger', 'Error: Delivery quantity exceeds the remaining order balance!');
            }
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
        if (auth()->user()->isViewer()) {
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
        if (auth()->user()->isViewer()) {
            abort(403);
        }
        $order = $delivery->order;

        $validated = $request->validate([
            'dr_number' => ['required', 'string', 'max:64'],
            'delivery_date' => ['required', 'date'],
            'qty_out' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:PENDING,FULFILLED,CANCELLED'],
            'type' => ['required', 'string', 'in:PICK-UP,DELIVERY'],
            'remarks' => ['nullable', 'string'],
        ]);

        if ($validated['status'] === 'FULFILLED') {
            $adjustedBalance = $order->remaining_balance;
            if ($delivery->status === 'FULFILLED') {
                $adjustedBalance += $delivery->qty_out;
            }
            if ($validated['qty_out'] > $adjustedBalance) {
                return back()->withInput()->with('danger', 'Error: Delivery quantity exceeds the remaining order balance!');
            }
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
