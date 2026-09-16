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

        // Active tab: one of the 5 order-status buckets. These are mutually
        // exclusive and exhaustive — every order lands in exactly one (a
        // Cancelled order always lands in "cancelled" regardless of whatever
        // clearing_status it happens to carry).
        $activeTab = $request->input('tab', 'pending');
        $validTabs = ['pending', 'hold', 'approved', 'declined', 'cancelled'];
        if (!in_array($activeTab, $validTabs, true)) {
            $activeTab = 'pending';
        }

        $baseQuery = Order::query()->activeOrCurrentMonth($now);

        if ($searchQuery !== '') {
            $baseQuery->where(function ($q) use ($searchQuery) {
                $q->where('account', 'like', "%{$searchQuery}%")
                  ->orWhere('so_number', 'like', "%{$searchQuery}%");
            });
        }

        // Tab counts, computed from the same base/search scope before the
        // tab-specific filter is applied, so switching tabs updates correctly.
        $pendingCount = (clone $baseQuery)->where('status', '!=', 'Cancelled')->where('clearing_status', 'Pending')->count();
        $holdCount = (clone $baseQuery)->where('status', '!=', 'Cancelled')->where('clearing_status', 'Hold')->count();
        $approvedCount = (clone $baseQuery)->where('status', '!=', 'Cancelled')->where('clearing_status', 'Approved')->count();
        $declinedCount = (clone $baseQuery)->where('status', '!=', 'Cancelled')->where('clearing_status', 'Declined')->count();
        $cancelledCount = (clone $baseQuery)->where('status', 'Cancelled')->count();

        $query = clone $baseQuery;
        match ($activeTab) {
            'pending' => $query->where('status', '!=', 'Cancelled')->where('clearing_status', 'Pending'),
            'hold' => $query->where('status', '!=', 'Cancelled')->where('clearing_status', 'Hold'),
            'approved' => $query->where('status', '!=', 'Cancelled')->where('clearing_status', 'Approved'),
            'declined' => $query->where('status', '!=', 'Cancelled')->where('clearing_status', 'Declined'),
            'cancelled' => $query->where('status', 'Cancelled'),
        };

        // Summary cards count active current-month + carry-over orders (excluding cancelled) —
        // this stays an overall KPI view independent of whichever tab is active.
        $statsQuery = (clone $baseQuery)->where('status', '!=', 'Cancelled');

        $totalOrders = (clone $statsQuery)->count();
        $totalQtyOrdered = (clone $statsQuery)->get()->sum(fn($o) => $o->effective_qty_ordered);
        $totalQtyDelivered = (clone $statsQuery)->get()->sum(fn($o) => $o->total_qty_out);
        $totalRemaining = (clone $statsQuery)->get()->sum(fn($o) => $o->remaining_balance);

        $orders = $query->orderBy('so_number', 'desc')
            ->paginate(10, ['*'], 'page', $page)
            ->appends(['search' => $searchQuery, 'tab' => $activeTab]);

        return view('dashboard', compact(
            'orders', 'searchQuery', 'totalOrders', 'totalQtyOrdered', 'totalQtyDelivered', 'totalRemaining', 'now',
            'activeTab', 'pendingCount', 'holdCount', 'approvedCount', 'declinedCount', 'cancelledCount'
        ));
    }
}
