<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\PurchaseOrderDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AtlController extends Controller
{
    /**
     * Sales Orders that need an Authority to Load.
     *
     * Only Fuel Trade and Buy Back pick-up orders qualify: both are drawn from
     * the supplier at the client's request, unlike depot delivery which is
     * supplied from our own stock. Cancelled orders are excluded.
     */
    public function index(Request $request): View
    {
        $this->authorizeAccess();

        $query = Order::query()
            ->where('status', '!=', 'Cancelled')
            ->where(function ($q) {
                $q->where('fulfillment_type', 'FUEL_TRADE')
                    ->orWhere(function ($buyBack) {
                        // Buy Back qualifies only when the client picks it up.
                        $buyBack->where('order_category', 'BUY_BACK')
                            ->where('fulfillment_type', 'DEPOT_PICKUP');
                    });
            });

        $search = trim($request->input('search', ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('so_number', 'like', $term)
                    ->orWhere('account', 'like', $term);
            });
        }

        $orders = $query->with('items')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15)
            ->appends(['search' => $search]);

        // ATLs are linked by order_id, falling back to the legacy so_number for
        // records written before that column existed.
        $atls = PurchaseOrderDelivery::query()
            ->whereNotNull('atl_number')
            ->orWhereNotNull('client_atl_number')
            ->with('order')
            ->latest()
            ->get();

        $atlsByOrder = [];
        foreach ($atls as $atl) {
            $key = $atl->order_id ?? ($atl->so_number ?: null);
            if ($key === null) {
                continue;
            }
            $atlsByOrder[$key][] = $atl;
        }

        $stats = [
            'orders' => (clone $query)->count(),
            'pending_clearance' => (clone $query)->where('clearing_status', 'Pending')->count(),
            'awaiting_approval' => PurchaseOrderDelivery::where('approval_status', 'FOR_APPROVAL')->count(),
            'unlifted' => PurchaseOrderDelivery::where('lift_status', 'UNLIFTED')->count(),
            'lifted' => PurchaseOrderDelivery::where('lift_status', 'LIFTED')->count(),
        ];

        return view('wetstock.atl.index', compact('orders', 'atlsByOrder', 'stats', 'search'));
    }

    /**
     * ATL records for one Sales Order.
     */
    public function show(Order $order): View
    {
        $this->authorizeAccess();

        $atls = PurchaseOrderDelivery::query()
            ->where(function ($q) use ($order) {
                $q->where('order_id', $order->id)
                    // Legacy rows are matched on the sales order number.
                    ->orWhere(function ($legacy) use ($order) {
                        $legacy->whereNull('order_id')
                            ->where('so_number', $order->so_number);
                    });
            })
            ->with(['purchaseOrder', 'allocations.purchaseOrder', 'lifter', 'receiver'])
            ->orderByDesc('id')
            ->get();

        // Per-product totals come from allocations, which is where a
        // multi-product ATL records each product's volume.
        $atlByProduct = [];
        foreach ($atls as $atl) {
            foreach ($atl->allocations as $allocation) {
                $product = $allocation->product ?: 'Unspecified';
                $atlByProduct[$product] = ($atlByProduct[$product] ?? 0) + (int) $allocation->quantity;
            }
        }
        ksort($atlByProduct);

        $totals = [
            'ordered' => (int) $order->qty_ordered,
            'atl_issued' => (int) $atls->sum('qty_to_receive'),
            'lifted' => (int) $atls->where('lift_status', PurchaseOrderDelivery::LIFT_LIFTED)
                ->sum('qty_to_receive'),
        ];
        $totals['remaining'] = max(0, $totals['ordered'] - $totals['atl_issued']);

        $order->load('items');

        return view('wetstock.atl.show', compact('order', 'atls', 'totals', 'atlByProduct'));
    }

    private function authorizeAccess(): void
    {
        if (!Auth::user()->canAccessStockOrders()) {
            abort(403, 'Unauthorized to view ATL records.');
        }
    }
}
