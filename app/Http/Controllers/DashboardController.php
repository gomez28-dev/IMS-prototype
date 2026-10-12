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

        // Ordered / delivered / undelivered liters, split by product (D / U / P)
        $statsOrders = (clone $statsQuery)
            ->with(['items', 'deliveries.items', 'linkedPurchaseOrder'])
            ->get();
        $productStats = $this->productStats($statsOrders);

        $totalQtyOrdered = array_sum($productStats['ordered']);
        $totalQtyDelivered = array_sum($productStats['delivered']);
        $totalRemaining = array_sum($productStats['undelivered']);

        // Eager-load product lines (and deliveries, which the rows read anyway)
        // so the table doesn't run extra queries per row.
        $orders = $query->with(['items', 'deliveries'])
            ->orderBy('so_number', 'desc')
            ->paginate(10, ['*'], 'page', $page)
            ->appends(['search' => $searchQuery, 'tab' => $activeTab]);

        return view('dashboard', compact(
            'orders', 'searchQuery', 'totalOrders', 'totalQtyOrdered', 'totalQtyDelivered', 'totalRemaining', 'now',
            'activeTab', 'pendingCount', 'holdCount', 'approvedCount', 'declinedCount', 'cancelledCount',
            'productStats'
        ));
    }

    /**
     * Per-product totals for the summary cards.
     *
     * - Ordered: product lines, minus liters on cancelled DRs.
     * - Delivered: fulfilled DR lines. Fuel Trade orders (which count PO pick-ups) are
     *   spread across the order's products in proportion to what was ordered.
     * - Undelivered: ordered minus delivered.
     * Orders with no product lines count as Diesel, matching the rest of the app.
     */
    private function productStats($orders): array
    {
        $codes = ['D', 'U', 'P'];
        $ordered = array_fill_keys($codes, 0);
        $delivered = array_fill_keys($codes, 0);
        $undelivered = array_fill_keys($codes, 0);

        foreach ($orders as $o) {
            $lines = [];
            foreach ($o->items as $i) {
                $c = in_array($i->product_type, $codes, true) ? $i->product_type : 'D';
                $lines[$c] = ($lines[$c] ?? 0) + (int) $i->qty;
            }
            if (empty($lines)) {
                $legacy = $o->getAttribute('product_type');
                $c = in_array($legacy, $codes, true) ? $legacy : 'D';
                $lines[$c] = (int) $o->qty_ordered;
            }
            $fallback = array_key_first($lines);

            $done = [];
            $cancel = [];
            foreach ($o->deliveries as $d) {
                if (!in_array($d->status, ['FULFILLED', 'CANCELLED'], true)) {
                    continue;
                }

                $parts = $d->items->isNotEmpty()
                    ? $d->items->map(fn ($i) => [$i->product_type, (int) $i->qty_out])->all()
                    : [[$d->product_type, (int) $d->qty_out]];

                foreach ($parts as [$code, $qty]) {
                    $code = in_array($code, $codes, true) ? $code : $fallback;
                    if ($d->status === 'CANCELLED') {
                        $cancel[$code] = ($cancel[$code] ?? 0) + $qty;
                    } else {
                        $done[$code] = ($done[$code] ?? 0) + $qty;
                    }
                }
            }

            $out = (int) $o->total_qty_out;
            if ($out !== array_sum($done)) {
                $base = array_sum($lines) ?: 1;
                $done = [];
                foreach ($lines as $c => $q) {
                    $done[$c] = (int) round($out * $q / $base);
                }
            }

            foreach ($lines as $c => $q) {
                $ordered[$c] += max(0, $q - ($cancel[$c] ?? 0));
            }
            foreach ($done as $c => $q) {
                $delivered[$c] += $q;
            }
        }

        foreach ($codes as $c) {
            $undelivered[$c] = max(0, $ordered[$c] - $delivered[$c]);
        }

        return compact('ordered', 'delivered', 'undelivered');
    }
}