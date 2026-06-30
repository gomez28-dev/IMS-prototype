<?php

namespace App\Http\Controllers;

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

        Order::create($validated);

        return redirect()->route('dashboard')
            ->with('success', 'Order created successfully.');
    }

    /**
     * Show the form for editing the specified order.
     */
    public function edit(Order $order): View
    {
        return view('orders.form', [
            'title' => 'Edit Order',
            'order' => $order,
        ]);
    }

    /**
     * Update the specified order in storage.
     */
    public function update(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'account' => ['required', 'string', 'max:128'],
            'date' => ['required', 'date'],
            'qty_ordered' => ['required', 'integer', 'min:0'],
            'so_number' => ['required', 'string', 'max:64'],
        ]);

        $order->update($validated);

        return redirect()->route('dashboard')
            ->with('success', 'Order updated successfully.');
    }

    /**
     * Remove the specified order from storage.
     */
    public function destroy(Order $order): RedirectResponse
    {
        $order->delete();

        return redirect()->route('dashboard')
            ->with('success', 'Order deleted successfully.');
    }
}
