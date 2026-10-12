<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockIn;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class StockRequestController extends Controller
{
    /**
     * Display listing of replenishment requests and incoming depot deliveries.
     */
    public function index(Request $request): View
    {
$warehouseId = $request->query('warehouse_id');

        // Replenishment requests live in stock_requests now. Lines are eager
        // loaded because the list reports Pending = Requested - Received per
        // product, and the warehouse filter narrows the same list.
        $requestsQuery = StockRequest::with(['warehouse', 'requester', 'lines', 'purchasingReceiver'])
            ->latest();

        if ($warehouseId) {
            $requestsQuery->where('warehouse_id', $warehouseId);
        }

        $stockRequests = $requestsQuery->paginate(15);

        // Incoming deliveries bound for depot tanks
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

        $incomingDeliveries = $deliveriesQuery->latest()->get();
        $warehouses = Warehouse::with('tanks')->orderBy('name')->get();

        // Buy Back orders raised in Sales Documentation that are delivered
        // rather than collected. They route here instead of to Module 3, so
        // they are listed alongside the depot's own replenishment requests.
        $buyBackDeliveries = Order::query()
            ->buyBackDelivery()
            ->where('status', '!=', 'Cancelled')
            ->with('items')
            ->latest()
            ->paginate(10);

        return view('wetstock.stock-requests.index', compact(
            'stockRequests', 'incomingDeliveries', 'buyBackDeliveries', 'warehouses', 'warehouseId'
        ));
    }

    /**
     * Show form to request depot stock replenishment.
     */
    public function create(): View
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $warehouses = Warehouse::with('tanks')->orderBy('name')->get();

        return view('wetstock.stock-requests.create', compact('warehouses'));
    }

    /**
     * Store a new replenishment request.
     *
     * Writes a StockRequest with its product lines, rather than a placeholder
     * purchase_orders row with no PO number and no value.
     */
    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $validated = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'po_type' => ['required', 'in:STANDARD_REPLENISHMENT,BUY_BACK'],
            'product' => ['required', 'string', 'max:64'],
            'qty_ordered' => ['required', 'integer', 'min:1'],
            'date_needed' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $warehouse = Warehouse::findOrFail($validated['warehouse_id']);

        $stockRequest = StockRequest::create([
            // Depot replenishment arrives at the tank, so fuel is picked up.
            'request_type' => StockRequest::TYPE_PICKUP,
            'warehouse_id' => $warehouse->id,
            'requested_by' => Auth::id(),
            'request_date' => now(),
            'date_needed' => $validated['date_needed'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'status' => StockRequest::STATUS_REQUESTED,
        ]);

        StockRequestLine::create([
            'stock_request_id' => $stockRequest->id,
            'product' => $validated['product'],
            'qty_requested' => $validated['qty_ordered'],
            'qty_received' => 0,
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Created Replenishment Request {$stockRequest->request_number} ({$warehouse->name}, " . number_format($validated['qty_ordered']) . "L {$validated['product']}) for Purchasing",
        ]);

        return redirect()->route('wetstock.stock-requests.index')
            ->with('success', "Replenishment request {$stockRequest->request_number} for {$warehouse->name} (" . number_format($validated['qty_ordered']) . "L) has been submitted to Purchasing (Module 3).");
    }

/**
 * Record that Purchasing has picked the request up.
 *
 * This is tracked separately from the fuel actually arriving, because the M2
 * list reports Purchasing and Qty Received as two different columns.
 */
public function markPickedUp(StockRequest $stockRequest): RedirectResponse
{
    if (!Auth::user()->canEditModule2()) {
        abort(403);
    }

    if (!$stockRequest->hasBeenPickedUpByPurchasing()) {
        $stockRequest->update([
            'purchasing_received_at' => now(),
            'purchasing_received_by' => Auth::id(),
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => "Purchasing picked up stock request {$stockRequest->request_number}.",
        ]);
    }

    return back()->with('success', "{$stockRequest->request_number} marked as picked up by Purchasing.");
}

/**
 * Record the quantity that actually arrived, per product line.
 *
 * Each line is set rather than incremented, so re-submitting corrects a typo
 * instead of double counting.
 */
public function recordReceived(Request $request, StockRequest $stockRequest): RedirectResponse
{
    if (!Auth::user()->canReceiveStockIntoDepot()) {
        abort(403, 'Unauthorized to record received stock.');
    }

    $quantities = $request->input('quantities', []);

    $validated = [];
    foreach ($quantities as $lineId => $quantity) {
        $validated["quantities.{$lineId}"] = ['nullable', 'integer', 'min:0'];
    }

    $request->validate($validated);

    foreach ($stockRequest->lines as $line) {
        if (!array_key_exists($line->id, $quantities)) {
            continue;
        }

        $received = (int) $quantities[$line->id];
        $line->update(['qty_received' => $received]);
    }

    $stockRequest->load('lines');

    // The request is finished only once every product is fully received.
    $fullyReceived = $stockRequest->lines->isNotEmpty()
        && $stockRequest->lines->every(fn ($line) => $line->isFullyReceived());

    $stockRequest->update([
        'status' => $fullyReceived ? StockRequest::STATUS_COMPLETED : StockRequest::STATUS_RECEIVED,
    ]);

    return back()->with('success', "Received quantities recorded for {$stockRequest->request_number}.");
}

/**
 * Receive physical stock from an incoming PurchaseOrderDelivery into a storage tank.
 */
    public function receiveDelivery(Request $request, PurchaseOrderDelivery $delivery): RedirectResponse
    {
        // Explicit allowlist: confirming fuel physically arrived at a depot is
        // narrower than editing Module 2 generally, so the broader
        // canEditModule2() must not be reused here.
        if (!Auth::user()->canReceiveStockIntoDepot()) {
            abort(403, 'Unauthorized to mark stock as received.');
        }

        // HARD SAFETY CHECK: Fuel Trade deliveries strictly bypass depot wet stock!
        if ($delivery->bypassesDepotTanks()) {
            return back()->with('danger', 'Fuel Trade deliveries bypass depot wet stock and cannot be received into depot tanks.');
        }

        $validated = $request->validate([
            'storage_tank_id' => ['required', 'exists:storage_tanks,id'],
            'date' => ['required', 'date'],
        ]);

        $tank = StorageTank::findOrFail($validated['storage_tank_id']);

        if (!$tank->is_active) {
            return back()->with('danger', "Error: Storage Tank {$tank->name} is deactivated.");
        }

        if ($tank->is_contaminated) {
            return back()->with('danger', "Error: Storage Tank {$tank->name} is contaminated and cannot receive stock.");
        }

        // HARD CAPACITY CHECK: Cannot overfill tank
        if ($delivery->qty_to_receive > $tank->remaining_capacity) {
            return back()->with('danger', "Error: Receiving {$delivery->qty_to_receive}L exceeds remaining capacity of {$tank->remaining_capacity}L for {$tank->name} (Max: {$tank->max_capacity}L, Available: {$tank->stock_available}L)!");
        }

        // Create the StockIn record linked directly to this PurchaseOrderDelivery
        $stockIn = StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => Auth::id(),
            'quantity' => $delivery->qty_to_receive,
            'date' => $validated['date'],
            'purchase_order_delivery_id' => $delivery->id,
        ]);

        // Mark delivery completed, and record who confirmed receipt so the
        // reporting page can show it.
        $delivery->update([
            'status' => 'Completed',
            'received_at' => now(),
            'received_by' => Auth::id(),
        ]);

        // If all deliveries on the parent PO are completed, mark parent PO fulfilled
        $po = $delivery->purchaseOrder;
        if ($po) {
            $hasIncomplete = $po->deliveries()->whereNotIn('status', ['Completed', 'Cancelled'])->exists();
            if (!$hasIncomplete) {
                $po->update([
                    'status' => 'Fulfilled',
                    'request_status' => 'COMPLETED',
                ]);
            }
        }

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Stock Received: Logged " . number_format($delivery->qty_to_receive) . "L from PO Delivery #{$delivery->id} into Tank {$tank->name} ({$tank->warehouse->name})",
        ]);

        return redirect()->back()
            ->with('success', "Stock of " . number_format($delivery->qty_to_receive) . "L received successfully into {$tank->name} ({$tank->warehouse->name}).");
    }
}
