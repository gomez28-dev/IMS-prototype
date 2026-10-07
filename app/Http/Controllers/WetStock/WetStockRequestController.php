<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module 3 - Wet Stock Requests.
 *
 * Dedicated page for incoming wet stock replenishment only: the requests
 * a depot raises for fuel, plus the depot-bound deliveries those requests
 * turn into once Purchasing issues an ATL.
 *
 * A request is a PurchaseOrder with po_type STANDARD_REPLENISHMENT or
 * BUY_BACK, so this page filters on those two types.
 */
class WetStockRequestController extends Controller
{
    public function index(Request $request): View
    {
        $warehouseId = $request->query('warehouse_id');

        $requestsQuery = PurchaseOrder::with(['warehouse', 'requester', 'approver', 'deliveries'])
            ->whereIn('po_type', ['STANDARD_REPLENISHMENT', 'BUY_BACK']);

        if ($warehouseId) {
            $requestsQuery->where('warehouse_id', $warehouseId);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $requestsQuery->where(function ($q) use ($term) {
                $q->where('po_number', 'like', $term)
                  ->orWhere('remarks', 'like', $term);
            });
        }

        $stockRequests = $requestsQuery->latest()->paginate(15);

        // Depot-bound deliveries awaiting tank fill.
        $deliveriesQuery = PurchaseOrderDelivery::with(['purchaseOrder.warehouse', 'stockIns.tank'])
            ->whereIn('delivery_channel', [
                'SUPPLIER_STOCKS_DELIVERY',
                'SUPPLIER_DOYEN_PICKUP',
                'BUY_BACK_STOCKS_DELIVERY',
                'BUY_BACK_DOYEN_PICKUP',
            ])
            ->where('status', '!=', 'Cancelled');

        if ($warehouseId) {
            $deliveriesQuery->whereHas('purchaseOrder', fn ($q) => $q->where('warehouse_id', $warehouseId));
        }

        $incomingDeliveries = $deliveriesQuery->latest()->paginate(15);

        return view('wetstock.wet-stock-requests.index', [
            'stockRequests' => $stockRequests,
            'incomingDeliveries' => $incomingDeliveries,
            'warehouses' => \App\Models\Warehouse::orderBy('name')->get(),
            'warehouseId' => $warehouseId,
            'stats' => $this->stats(),
        ]);
    }

    /**
     * One request in detail: what was asked for, and the ATLs raised
     * against it. A request that Purchasing has not actioned yet has no
     * ATLs, so the view must cope with an empty set.
     */
    public function show(PurchaseOrder $purchaseOrder): View
    {
        abort_unless(
            in_array($purchaseOrder->po_type, ['STANDARD_REPLENISHMENT', 'BUY_BACK'], true),
            404,
            'This Purchase Order is not a wet stock replenishment request.'
        );

        $purchaseOrder->load([
            'warehouse.tanks',
            'requester',
            'approver',
            'items',
            'deliveries.preparer',
            'deliveries.approver',
        ]);

        $atls = $purchaseOrder->deliveries;

        return view('wetstock.wet-stock-requests.show', [
            'request' => $purchaseOrder,
            'atls' => $atls,
            'products' => $purchaseOrder->requested_products ?? [],
            'totals' => [
                'requested' => (int) $purchaseOrder->qty_ordered,
                'issued' => (int) $atls->sum('qty_to_receive'),
                'count' => $atls->count(),
            ],
        ]);
    }

    protected function stats(): array
    {
        $base = fn () => PurchaseOrder::whereIn('po_type', ['STANDARD_REPLENISHMENT', 'BUY_BACK']);

        return [
            'requested' => $base()->where('request_status', 'REQUESTED')->count(),
            'received' => $base()->where('request_status', 'RECEIVED')->count(),
            'for_delivery' => $base()->where('request_status', 'FOR_DELIVERY')->count(),
            'awaiting_fill' => PurchaseOrderDelivery::whereIn('status', ['Pending', 'Active'])->count(),
        ];
    }
}