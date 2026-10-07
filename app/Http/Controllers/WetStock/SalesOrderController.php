<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PurchaseOrderDelivery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 3 - Sales Orders.
 *
 * Dedicated page for the orders Module 1 hands over for lift:
 * SO-FT (Fuel Trade) and SO-BUY BACK PICK UP. Nothing else appears here.
 *
 * The query and per-order detail are shared with AtlController, which
 * built this page before the module was split.
 */
class SalesOrderController extends Controller
{
    public function index(Request $request): View
    {
        $query = Order::query()
            ->with('items')
            ->where('status', '!=', 'Cancelled')
            ->where(function ($q) {
                $q->where('fulfillment_type', 'FUEL_TRADE')
                  ->orWhere(function ($buyBack) {
                      $buyBack->where('order_category', 'BUY_BACK')
                          ->where('fulfillment_type', 'DEPOT_PICKUP');
                  });
            });

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('so_number', 'like', $term)
                  ->orWhere('account', 'like', $term);
            });
        }

        $orders = $query->latest()->paginate(15);

        // ATLs already raised per order, for the "N Unlifted" badge.
        $atlsByOrder = PurchaseOrderDelivery::whereNotNull('atl_number')
            ->orWhereNotNull('client_atl_number')
            ->get(['order_id', 'atl_number', 'client_atl_number', 'lift_status', 'status'])
            ->groupBy('order_id');

        return view('wetstock.sales-orders.index', [
            'orders' => $orders,
            'atlsByOrder' => $atlsByOrder,
            'stats' => $this->stats($query),
            'search' => $request->search,
        ]);
    }

    public function show(Order $order): View
    {
        abort_unless(
            $order->status !== 'Cancelled',
            404
        );

        // atls: this order's ATLs. The byPurchaseOrderId fallback catches
        // ATLs issued before deliveries carried an order_id.
        $atls = PurchaseOrderDelivery::where(function ($q) use ($order) {
            $q->where('order_id', $order->id)
              ->orWhere(function ($legacy) use ($order) {
                  $legacy->whereNull('order_id')
                          ->whereHas('purchaseOrder', fn ($p) => $p->where('linked_order_id', $order->id));
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

        return view('wetstock.sales-orders.show', compact('order', 'atls', 'totals', 'atlByProduct'));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $baseQuery
     */
    protected function stats($baseQuery): array
    {
        // Keys match what sales-orders/index.blade.php renders.
        return [
            'orders' => (clone $baseQuery)->count(),
            'pending_clearance' => (clone $baseQuery)->where('clearing_status', 'Pending')->count(),
            'awaiting_approval' => PurchaseOrderDelivery::where('approval_status', 'FOR_APPROVAL')->count(),
            'unlifted' => PurchaseOrderDelivery::where('lift_status', 'UNLIFTED')->count(),
            'lifted' => PurchaseOrderDelivery::where('lift_status', 'LIFTED')->count(),
        ];
    }
}