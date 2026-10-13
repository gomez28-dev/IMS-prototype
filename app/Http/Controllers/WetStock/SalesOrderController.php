<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AtlAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseOrderDelivery;
use App\Models\StorageTank;
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
        // Module 3 handles every order category. Buy Back is no longer
        // special-cased: it raises an ATL here and its delivery is assigned
        // in Module 2's Assign Delivery.
        $query = Order::query()
            ->with('items')
            ->where('status', '!=', 'Cancelled')
            ->forModuleThree();

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('so_number', 'like', $term)
                  ->orWhere('account', 'like', $term);
            });
        }

        // Computed before paginate(), which would otherwise leave a LIMIT behind for
        // the totals to embed as a subquery.
        $stats = $this->stats($query);
        $stats['volumes'] = $this->volumeTotals($query);

        $orders = $query->paginate(15);

        // ATLs already raised per order, for the "N Unlifted" badge.
        $atlsByOrder = PurchaseOrderDelivery::whereNotNull('atl_number')
            ->orWhereNotNull('client_atl_number')
            ->get(['order_id', 'atl_number', 'client_atl_number', 'lift_status', 'status'])
            ->groupBy('order_id');

        return view('wetstock.sales-orders.index', [
            'orders' => $orders,
            'atlsByOrder' => $atlsByOrder,
            'stats' => $stats,
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

        // Per-product ATL totals are no longer rendered on this page; the ATL
        // table reads each ATL's own breakdown instead.
        $totals = [
            'ordered' => (int) $order->qty_ordered,
            'atl_issued' => (int) $atls->sum('qty_to_receive'),
            'lifted' => (int) $atls->where('lift_status', PurchaseOrderDelivery::LIFT_LIFTED)
                ->sum('qty_to_receive'),
        ];
        $totals['remaining'] = max(0, $totals['ordered'] - $totals['atl_issued']);

        $order->load('items');

        // Mark Picked Up lives here now, so the lift modal needs the tanker
        // list the destination of a tanker pick-up is chosen from.
        $mobileTankers = StorageTank::where('category', 'tanker')
            ->where('is_active', true)
            ->with('warehouse')
            ->orderBy('name')
            ->get();

        // The delivery that can still be lifted: active and not yet lifted.
        // Legacy rows carry a null lift_status, which counts as not lifted.
        $liftableDelivery = $atls->first(fn ($atl) => $atl->status === 'Active'
            && $atl->lift_status !== PurchaseOrderDelivery::LIFT_LIFTED);

        return view('wetstock.sales-orders.show', compact(
            'order', 'atls', 'totals', 'mobileTankers', 'liftableDelivery'
        ));
    }

    /**
     * Volume totals split by product, for the page's stat cards.
     *
     * Ordered volume comes from the order's own lines. Lifted and unlifted
     * come from ATL allocations, so a product with no ATL yet reads as zero
     * rather than missing. Passing the list's own builder keeps the cards in
     * step with the search filter.
     *
     * @return array{ordered: array<string, int>, lifted: array<string, int>, unlifted: array<string, int>}
     */
    protected function volumeTotals($baseQuery): array
    {
        $orderIds = (clone $baseQuery)->reorder()->select('id');

        // Grouped on product_type rather than the product_name accessor, which
        // is computed in PHP and so cannot be aggregated in SQL.
        $ordered = OrderItem::whereIn('order_id', $orderIds)
            ->selectRaw('product_type, SUM(qty) AS qty')
            ->groupBy('product_type');

        $byCode = $ordered->pluck('qty', 'product_type')->all();

        $orderedByName = [];
        foreach ($byCode as $code => $qty) {
            $orderedByName[Order::PRODUCT_TYPES[$code] ?? 'Unspecified'] = (int) $qty;
        }

        return [
            'ordered' => $this->normaliseVolumes($orderedByName),
            'lifted' => $this->normaliseVolumes(
                $this->allocationTotals($orderIds, PurchaseOrderDelivery::LIFT_LIFTED)
            ),
            'unlifted' => $this->normaliseVolumes(
                $this->allocationTotals($orderIds, PurchaseOrderDelivery::LIFT_UNLIFTED)
            ),
        ];
    }

    /**
     * ATL volume for the listed orders, by product, for one lift status.
     */
    protected function allocationTotals($orderIds, string $liftStatus): array
    {
        return AtlAllocation::query()
            ->join('purchase_order_deliveries', 'atl_allocations.purchase_order_delivery_id', '=', 'purchase_order_deliveries.id')
            ->whereIn('purchase_order_deliveries.order_id', $orderIds)
            ->where('purchase_order_deliveries.lift_status', $liftStatus)
            ->where('purchase_order_deliveries.status', '!=', 'Cancelled')
            ->selectRaw('atl_allocations.product, SUM(atl_allocations.quantity) AS qty')
            ->groupBy('atl_allocations.product')
            ->pluck('qty', 'product')
            ->all();
    }

    /**
     * Give every known product an entry so the cards always show all three,
     * even where a product has no rows at all.
     *
     * @param  array<string, mixed>  $totals
     * @return array<string, int>
     */
    protected function normaliseVolumes(array $totals): array
    {
        $out = [];

        foreach (array_values(Order::PRODUCT_TYPES) as $product) {
            $out[$product] = (int) ($totals[$product] ?? 0);
        }

        return $out;
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