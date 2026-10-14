<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AtlAllocation;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockRequest;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
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

        // Replenishment requests live in stock_requests, and only there.
        //
        // This used to also list purchase orders filed as STANDARD_REPLENISHMENT
        // or BUY_BACK as "legacy requests". But storeSupplierPo files every
        // supplier PO as STANDARD_REPLENISHMENT, so the query pulled ordinary
        // purchase orders onto this page with a View Request button that led to a
        // PO, not a request. That was never a real legacy backlog: the rows were
        // ordinary POs, so they go.
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

        return view('wetstock.wet-stock-requests.index', [
            'stockRequests' => $stockRequests,
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

        // Only tanks at the request's own site. Booking a delivery against a tank
        // at another depot is possible if the list is the whole company's.
        $tanks = StorageTank::where('is_active', true)
            ->when(
                $stockRequest->warehouse_id,
                fn ($query) => $query->where('warehouse_id', $stockRequest->warehouse_id)
            )
            ->with('warehouse')
            ->orderBy('name')
            ->get();

        return view('wetstock.wet-stock-requests.create-atl', [
            'request' => $stockRequest,
            'availablePos' => $availablePos,
            'pending' => $stockRequest->pendingByProduct(),
            'tanks' => $tanks,
        ]);
    }

    /**
     * Store an ATL raised against a replenishment request.
     *
     * The request does not need a purchase order of its own: this draws on
     * whichever PO still has volume. Three things are checked before anything is
     * written - the PO is really allocatable and approved, it has that product's
     * volume left, and the request has that product's pending volume left.
     */
    public function storeAtl(Request $request, StockRequest $stockRequest): RedirectResponse
    {
        if (! Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        $validated = $request->validate([
            'purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'product' => ['required', 'string', 'in:Diesel,Premium,Unleaded'],
            'qty_to_receive' => ['required', 'integer', 'min:1'],
            'order_type' => ['required', 'in:DELIVERY,PICK_UP'],
            'delivery_channel' => ['required', 'string'],
            'atl_type' => ['required', 'in:DITC_ATL,CLIENT_ATL,NONE'],
            'atl_number' => ['nullable', 'string', 'max:64'],
            'dr_number' => ['nullable', 'string', 'max:64'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'receiving_date' => ['required', 'date'],
            'driver_name' => ['nullable', 'string', 'max:128'],
            'plate_number' => ['nullable', 'string', 'max:32'],
            'storage_tank_id' => ['nullable', 'exists:storage_tanks,id'],
            'additional_remarks' => ['nullable', 'string', 'max:500'],
        ]);

        // The PO has to be one the pool still offers. A crafted POST naming a
        // drained or cancelled PO is refused here rather than silently accepted.
        $po = PurchaseOrder::availableForAllocation()
            ->firstWhere('id', (int) $validated['purchase_order_id']);

        if (! $po) {
            return back()->withInput()->withErrors([
                'purchase_order_id' => 'That purchase order has no volume left to draw on.',
            ]);
        }

        if (! $po->isVpApproved()) {
            return back()->withInput()->withErrors([
                'purchase_order_id' => "PO {$po->po_number} has not been approved by the VP yet.",
            ]);
        }

        $product = $validated['product'];
        $qty = (int) $validated['qty_to_receive'];

        $balance = $po->getAvailableBalanceForProduct($product);

        if ($balance < $qty) {
            return back()->withInput()->withErrors([
                'qty_to_receive' => "PO {$po->po_number} has ".number_format($balance)
                    ." L of {$product} left, not ".number_format($qty).' L.',
            ]);
        }

        $pending = $stockRequest->pendingByProduct();

        if (! array_key_exists($product, $pending)) {
            return back()->withInput()->withErrors([
                'product' => "This request does not ask for {$product}.",
            ]);
        }

        if ($qty > $pending[$product]) {
            return back()->withInput()->withErrors([
                'qty_to_receive' => 'Only '.number_format($pending[$product])
                    ." L of {$product} is still outstanding on this request. "
                    .'Asked for '.number_format($stockRequest->lines->where('product', $product)->sum('qty_requested'))
                    .' L, less what has arrived and what an ATL already covers.',
            ]);
        }

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'request_id' => $stockRequest->id,
            'storage_tank_id' => $validated['storage_tank_id'] ?? null,
            'delivery_channel' => $validated['delivery_channel'],
            'order_type' => $validated['order_type'],
            'atl_type' => $validated['atl_type'],
            'atl_number' => $validated['atl_number'] ?? null,
            'dr_number' => $validated['dr_number'] ?? null,
            'reference_no' => $validated['reference_no'] ?? null,
            'product' => $product,
            'qty_to_receive' => $qty,
            'receiving_date' => $validated['receiving_date'],
            'driver_name' => $validated['driver_name'] ?? null,
            'plate_number' => $validated['plate_number'] ?? null,
            'additional_remarks' => $validated['additional_remarks'] ?? null,
            'atl_category' => PurchaseOrderDelivery::CATEGORY_DOYEN_STOCKS,
            // Every new ATL goes to the VP the moment it is created. A stock
            // request has no Accounting clearance gate of its own, so there is
            // nothing to hold it back.
            'approval_status' => PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
            'atl_source' => PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            'status' => 'Pending',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'issued_at' => now(),
        ]);

        // Linked to the purchase order item, as the other three allocation
        // writers do. Leaving it null hides this volume from the PO balance the
        // moment any other ATL has drawn from the same PO with a linked
        // allocation, because PurchaseOrderItem::getTotalDrawnAttribute() only
        // counts null-item allocations while it has no linked ones.
        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'purchase_order_item_id' => $po->items()->where('product', $product)->value('id'),
            'product' => $product,
            'quantity' => $qty,
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'created',
            'description' => "ATL {$delivery->atl_number} raised for request "
                .$stockRequest->request_number." against PO {$po->po_number} for "
                .number_format($qty)." L of {$product}.",
        ]);

        return redirect()->route('stock-orders.wet-stock-requests.atls', $stockRequest)
            ->with('success', "ATL {$delivery->atl_number} raised against PO {$po->po_number}.");
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