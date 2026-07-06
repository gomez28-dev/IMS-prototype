<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * Show the form for creating a new order.
     */
    public function create(): View
    {
        return view('orders.form', [
            'title' => 'New Order',
            'order' => null,
            'clients' => Client::orderBy('name', 'asc')->get(),
        ]);
    }

    /**
     * Store a newly created order in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'account' => ['required', 'string', 'max:128'],
            'date' => ['required', 'date'],
            'qty_ordered' => ['required', 'integer', 'min:0'],
            'so_number' => ['required', 'string', 'max:64'],
        ]);

        $order = Order::create($validated);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'created',
            'description' => "Created order #{$order->id} - {$order->account}",
        ]);

        return redirect()->route('dashboard')
            ->with('success', 'Order created successfully.');
    }

    /**
     * Show the form for editing the specified order.
     */
    public function edit(Order $order): View
    {
        if (auth()->user()->isViewer()) {
            abort(403);
        }

        return view('orders.form', [
            'title' => 'Edit Order',
            'order' => $order,
            'clients' => Client::orderBy('name', 'asc')->get(),
        ]);
    }

    /**
     * Update the specified order in storage.
     */
    public function update(Request $request, Order $order): RedirectResponse
    {
        if (auth()->user()->isViewer()) {
            abort(403);
        }

        $validated = $request->validate([
            'account' => ['required', 'string', 'max:128'],
            'date' => ['required', 'date'],
            'qty_ordered' => ['required', 'integer', 'min:0'],
            'so_number' => ['required', 'string', 'max:64'],
        ]);

        $order->update($validated);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "Updated order #{$order->id} - {$order->account}",
        ]);

        return redirect()->route('dashboard')
            ->with('success', 'Order updated successfully.');
    }

    /**
     * Remove the specified order from storage.
     */
    public function destroy(Order $order): RedirectResponse
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'deleted',
            'description' => "Deleted order #{$order->id} - {$order->account}",
        ]);

        $order->delete();

        return redirect()->route('dashboard')
            ->with('success', 'Order deleted successfully.');
    }
}
