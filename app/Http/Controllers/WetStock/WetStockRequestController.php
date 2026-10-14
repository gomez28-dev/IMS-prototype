<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockRequest;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // Replenishment requests live in stock_requests. Legacy request rows that
          // predate the table are still listed alongside them, so nothing
          // disappears during the changeover.
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

        $legacyRequests = $requestsQuery->latest()->paginate(15);

        $stockRequestQuery = StockRequest::with(['warehouse', 'requester', 'lines'])
            ->open();

        if ($warehouseId) {
            $stockRequestQuery->where('warehouse_id', $warehouseId);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $stockRequestQuery->where(function ($q) use ($term) {
                $q->where('request_number', 'like', $term)
                  ->orWhere('remarks', 'like', $term);
            });
        }

        $stockRequests = $stockRequestQuery->latest()->paginate(15);

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
            'legacyRequests' => $legacyRequests,
            'incomingDeliveries' => $incomingDeliveries,
            'warehouses' => Warehouse::orderBy('name')->get(),
            'warehouseId' => $warehouseId,
            'stats' => $this->stats(),
            // Product-aware cards. Given the request's own builder so they total
            // exactly what the filters left on the page, search term included.
            'volumes' => StockRequest::volumeTotals($stockRequestQuery),
        ]);
    }

    /**
     * The ATLs raised against one replenishment request.
     *
     * Laid out like the M1 Deliveries page: an info card, product-aware totals,
     * the ATLs themselves, and an Add ATL button. Several ATLs may exist for one
     * request, each drawing on whichever PO still has volume.
     */
    public function atls(StockRequest $stockRequest): View
    {
        $stockRequest->load(['lines', 'warehouse', 'requester']);

        $atls = $stockRequest->deliveries()
            ->with(['purchaseOrder', 'allocations.purchaseOrder', 'lifter'])
            ->orderByDesc('id')
            ->get();

        return view('wetstock.wet-stock-requests.atls', [
            'request' => $stockRequest,
            'atls' => $atls,
            'volumes' => $stockRequest->atlVolumeTotals(),
        ]);
    }

    /**
     * Raise another ATL against this request, choosing the PO to draw on.
     */
    public function createAtl(StockRequest $stockRequest): View
    {
        if (! Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        $stockRequest->load(['lines', 'warehouse']);

        // Only POs that still have volume are offered, whichever product they
        // hold. A request does not need a PO of its own any more.
        $availablePos = PurchaseOrder::availableForAllocation();

        return view('wetstock.stock-orders.form', [
            'mode' => 'fuel_trade',
            'stockRequest' => $stockRequest,
            'order' => null,
            'purchaseOrder' => null,
            'availablePos' => $availablePos,
            'clients' => Client::orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
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