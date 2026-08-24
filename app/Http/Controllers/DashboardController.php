<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with orders list and optional search.
     * Incorporates unfulfilled carry-over orders from previous months alongside current month orders.
     */
    public function index(Request $request): View|RedirectResponse
    {
        // Clear persisted dashboard state and reset to page 1
        if ($request->has('clear')) {
            session()->forget(['dashboard_page', 'dashboard_search']);
            return redirect()->route('dashboard');
        }

        // Capture pagination + search state whenever they appear in the request
        if ($request->has('page') || $request->has('search')) {
            session([
                'dashboard_page' => (int) $request->input('page', 1),
                'dashboard_search' => trim($request->input('search', '')),
            ]);
        }

        // Restore from session when navigating back without params
        $searchQuery = $request->input('search', session('dashboard_search', ''));
        $page = (int) $request->input('page', session('dashboard_page', 1));
        $now = now('Asia/Manila');

        $query = Order::query()->activeOrCurrentMonth($now);

        if ($searchQuery !== '') {
            $query->where(function ($q) use ($searchQuery) {
                $q->where('account', 'like', "%{$searchQuery}%")
                  ->orWhere('so_number', 'like', "%{$searchQuery}%");
            });
        }

        // Summary cards count active current-month + carry-over orders (excluding cancelled)
        $statsQuery = (clone $query)->where('status', '!=', 'Cancelled');

        $totalOrders = (clone $statsQuery)->count();
        $totalQtyOrdered = (clone $statsQuery)->get()->sum(fn($o) => $o->effective_qty_ordered);
        $totalQtyDelivered = (clone $statsQuery)->get()->sum(fn($o) => $o->total_qty_out);
        $totalRemaining = (clone $statsQuery)->get()->sum(fn($o) => $o->remaining_balance);

        $orders = $query->orderBy('so_number', 'desc')
            ->paginate(10, ['*'], 'page', $page)
            ->appends(['search' => $searchQuery]);

        return view('dashboard', compact('orders', 'searchQuery', 'totalOrders', 'totalQtyOrdered', 'totalQtyDelivered', 'totalRemaining', 'now'));
    }
}
