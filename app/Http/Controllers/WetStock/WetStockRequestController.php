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
     * request, each drawing on whichever PO still has volume. The Add ATL
     * button opens the same drawdown form the standalone page serves, embedded
     * in a collapsible card, so this action also carries the form's data.
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
            // The embed's form data - the same inputs createAtl() passes, so
            // both entrances post an identical payload.
            'availablePos' => PurchaseOrder::availableForAllocation(),
            'pending' => $stockRequest->pendingByProduct(),
            'tanks' => $this->tankOptions($stockRequest),
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

        $tanks = $this->tankOptions($stockRequest);

        return view('wetstock.wet-stock-requests.create-atl', [
            'request' => $stockRequest,
            'availablePos' => $availablePos,
            'pending' => $stockRequest->pendingByProduct(),
            'tanks' => $tanks,
        ]);
    }

    /**
     * The tanks the ATL form offers as its optional depot tank: the active
     * ones at the request's own site. Booking a delivery against a tank at
     * another depot is possible if the list is the whole company's, so the
     * query stays scoped to the request's warehouse.
     */
    private function tankOptions(StockRequest $stockRequest)
    {
        return StorageTank::where('is_active', true)
            ->when(
                $stockRequest->warehouse_id,
                fn ($query) => $query->where('warehouse_id', $stockRequest->warehouse_id)
            )
            ->with('warehouse')
            ->orderBy('name')
            ->get();
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
            'drawdowns' => ['required', 'array', 'min:1'],
            'drawdowns.*.purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'drawdowns.*.product' => ['required', 'string', 'in:Diesel,Premium,Unleaded'],
            'drawdowns.*.quantity' => ['required', 'integer', 'min:1'],
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

        $drawdowns = collect($validated['drawdowns'])->map(fn (array $dd): array => [
            'purchase_order_id' => (int) $dd['purchase_order_id'],
            'product' => $dd['product'],
            'quantity' => (int) $dd['quantity'],
        ]);

        // A single ATL may be split across several POs when no one of them holds
        // enough on its own, so the rows are summed per product before anything
        // is checked. Checking row by row would let two rows for the same product
        // each pass against the request's pending figure while together exceeding
        // it - which is the whole reason for the split.
        $drawnByProduct = $drawdowns
            ->groupBy('product')
            ->map(fn ($rows): int => (int) $rows->sum('quantity'));

        $pending = $stockRequest->pendingByProduct();

        // Every PO has to be one the pool still offers: VP-approved, not
        // cancelled, and with volume left. A crafted POST naming a drained or
        // unapproved PO is refused here rather than silently accepted.
        $pool = PurchaseOrder::availableForAllocation()->keyBy('id');

        foreach ($drawdowns as $index => $dd) {
            $po = $pool->get($dd['purchase_order_id']);

            if (! $po) {
                return back()->withInput()->withErrors([
                    "drawdowns.{$index}.purchase_order_id" => 'That purchase order has no volume left to draw on, or the VP has not approved it.',
                ]);
            }

            $balance = $po->getAvailableBalanceForProduct($dd['product']);

            if ($balance < $dd['quantity']) {
                return back()->withInput()->withErrors([
                    "drawdowns.{$index}.quantity" => "PO {$po->po_number} has ".number_format($balance)
                        ." L of {$dd['product']} left, not ".number_format($dd['quantity']).' L. '
                        .'Add another PO row to cover the rest.',
                ]);
            }
        }

        // One load, one rack - the same rule the Fuel Trade form enforces: two
        // rows of one ATL must not mix suppliers, or one supplier at two
        // loading locations. The lift happens at a single refinery terminal.
        $primaryKey = $pool->get($drawdowns->first()['purchase_order_id'])?->allocationKey();
        $primaryPo = $pool->get($drawdowns->first()['purchase_order_id']);

        foreach ($drawdowns->skip(1) as $dd) {
            $po = $pool->get($dd['purchase_order_id']);

            if ($po && $primaryKey !== null && $po->allocationKey() !== $primaryKey) {
                return back()->withInput()->withErrors([
                    'drawdowns' => "Cross-allocation is not allowed: PO #{$po->po_number} is not the same supplier and "
                        ."loading location as PO #{$primaryPo->po_number}. One lift draws at one rack - "
                        .'split this into one ATL per supplier visit.',
                ]);
            }
        }

        foreach ($drawnByProduct as $product => $qty) {
            if (! array_key_exists($product, $pending)) {
                return back()->withInput()->withErrors([
                    'drawdowns' => "This request does not ask for {$product}.",
                ]);
            }

            if ($qty > $pending[$product]) {
                return back()->withInput()->withErrors([
                    'drawdowns' => 'Only '.number_format($pending[$product])
                        ." L of {$product} is still outstanding on this request. "
                        .'Asked for '.number_format($stockRequest->lines->where('product', $product)->sum('qty_requested'))
                        .' L, less what has arrived and what an ATL already covers.',
                ]);
            }
        }

        $primaryPoId = $drawdowns->first()['purchase_order_id'];
        $totalDrawn = (int) $drawdowns->sum('quantity');
        $productSummary = $drawdowns->pluck('product')->unique()->implode(' + ');

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $primaryPoId,
            'request_id' => $stockRequest->id,
            'storage_tank_id' => $validated['storage_tank_id'] ?? null,
            'delivery_channel' => $validated['delivery_channel'],
            'order_type' => $validated['order_type'],
            'atl_type' => $validated['atl_type'],
            'atl_number' => $validated['atl_number'] ?? null,
            'dr_number' => $validated['dr_number'] ?? null,
            'reference_no' => $validated['reference_no'] ?? null,
            // The delivery row carries the summary: total litres and the products
            // it covers. Per-product quantities live on the allocations below.
            'product' => $productSummary,
            'qty_to_receive' => $totalDrawn,
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

        // One allocation per drawdown row, each linked to its own PO line.
        //
        // The item link matters: PurchaseOrderItem::getTotalDrawnAttribute() only
        // counts allocations with no item while the PO has no linked ones, so a
        // null here would hide this volume from the PO balance the moment any
        // other ATL drew from the same PO with a linked allocation.
        foreach ($drawdowns as $dd) {
            $po = $pool->get($dd['purchase_order_id']);

            AtlAllocation::create([
                'purchase_order_delivery_id' => $delivery->id,
                'purchase_order_id' => $po->id,
                'purchase_order_item_id' => $po->items()->where('product', $dd['product'])->value('id'),
                'product' => $dd['product'],
                'quantity' => $dd['quantity'],
            ]);
        }

        $poNumbers = $drawdowns->pluck('purchase_order_id')
            ->map(fn ($id) => $pool->get($id)->po_number)
            ->unique()
            ->implode(', ');

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'created',
            'description' => "ATL {$delivery->atl_number} raised for request "
                .$stockRequest->request_number.' against PO '.$poNumbers
                .' for '.number_format($totalDrawn)." L of {$productSummary}.",
        ]);

        return redirect()->route('stock-orders.wet-stock-requests.atls', $stockRequest)
            ->with('success', "ATL {$delivery->atl_number} raised against PO {$poNumbers}.");
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