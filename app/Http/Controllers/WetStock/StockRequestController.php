<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
        $search = trim((string) $request->query('search', ''));

        // Replenishment requests live in stock_requests now. Lines are eager
        // loaded because the list reports Pending = Requested - Received per
        // product, and the stat cards total the same lines.
        $requestsQuery = StockRequest::with(['warehouse', 'requester', 'lines', 'purchasingReceiver'])
            ->latest();

        if ($warehouseId) {
            $requestsQuery->where('warehouse_id', $warehouseId);
        }

        if ($search !== '') {
            $term = '%'.$search.'%';
            $requestsQuery->where(function ($q) use ($term) {
                $q->where('request_number', 'like', $term)
                    ->orWhere('remarks', 'like', $term)
                    ->orWhereHas('requester', fn ($r) => $r->where('name', 'like', $term));
            });
        }

        // Computed before paginate(), which would otherwise leave a LIMIT on the
        // builder that the totals embed as a subquery.
        $stats = StockRequest::volumeTotals($requestsQuery);
        $stats['requests'] = (clone $requestsQuery)->toBase()->getCountForPagination();

        $stockRequests = $requestsQuery->paginate(15)->appends([
            'warehouse_id' => $warehouseId,
            'search' => $search,
        ]);

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

        // ?create=1 opens the request pop-up straight away, which is how the
        // Incoming Stock page and the dashboard reach "Request Replenishment".
        $openCreate = $request->boolean('create');

        return view('wetstock.stock-requests.index', compact(
            'stockRequests', 'incomingDeliveries', 'warehouses', 'warehouseId',
            'search', 'stats', 'openCreate'
        ));
    }

/**
 * The request form is a floating pop-up on the Stock Requests page, so the
 * standalone page route just sends people there with the pop-up open.
 */
public function create(): RedirectResponse
{
    return redirect()->route('wetstock.stock-requests.index', ['create' => 1]);
}

/**
 * Store a new replenishment request.
 *
 * Writes a StockRequest with one line per product, rather than a placeholder
 * purchase_orders row with no PO number and no value. The requestor stays in
 * Module 2 afterwards; sending them to Module 3 was never intended.
 */
public function store(Request $request): RedirectResponse
{
    if (!Auth::user()->canEditModule2()) {
        abort(403);
    }

    $validated = $request->validate([
        'warehouse_id' => ['required', 'exists:warehouses,id'],
        'request_type' => ['nullable', 'in:'.StockRequest::TYPE_PICKUP.','.StockRequest::TYPE_DELIVERY],
        'date_needed' => ['nullable', 'date'],
        'remarks' => ['nullable', 'string', 'max:500'],
        'lines' => ['required', 'array', 'min:1'],
        // Lenient on the rows themselves: removing a line in the pop-up can
        // leave a blank row behind, and that should not block sending. Rows
        // with no usable product or quantity are dropped below.
        'lines.*.id' => ['nullable', 'integer'],
        'lines.*.product' => ['nullable', 'string', 'max:64'],
        'lines.*.qty' => ['nullable', 'integer', 'min:0'],
    ]);

    $warehouse = Warehouse::findOrFail($validated['warehouse_id']);

    $lines = collect($validated['lines'])
        ->filter(fn ($line) => filled($line['product'] ?? null) && (int) ($line['qty'] ?? 0) > 0)
        ->unique('product')
        ->values();

    if ($lines->isEmpty()) {
        return back()
            ->withInput()
            ->withErrors(['lines' => 'Add at least one product with a quantity.']);
    }

    $stockRequest = StockRequest::create([
        'request_type' => $validated['request_type'] ?? StockRequest::TYPE_PICKUP,
        'warehouse_id' => $warehouse->id,
        'requested_by' => Auth::id(),
        'request_date' => now(),
        'date_needed' => $validated['date_needed'] ?? null,
        'remarks' => $validated['remarks'] ?? null,
        'status' => StockRequest::STATUS_REQUESTED,
    ]);

    foreach ($lines as $line) {
        StockRequestLine::create([
            'stock_request_id' => $stockRequest->id,
            'product' => $line['product'],
            'qty_requested' => (int) $line['qty'],
            'qty_received' => 0,
        ]);
    }

    $summary = $lines->map(fn ($line) => number_format($line['qty']).'L '.$line['product'])->implode(', ');

    AuditLog::create([
        'admin_id' => Auth::id(),
        'action' => 'CREATED',
        'description' => "Created Replenishment Request {$stockRequest->request_number} ({$warehouse->name}, {$summary}) for Purchasing",
    ]);

    return redirect()->route('wetstock.stock-requests.index')
        ->with('success', "Request {$stockRequest->request_number} sent to Purchasing ({$summary}).");
}

/**
 * Edit an existing request from the same floating pop-up.
 *
 * The quantity already received is never overwritten, so Pending can never
 * be pushed below zero by editing the requested figure.
 */
public function update(Request $request, StockRequest $stockRequest): RedirectResponse
{
    if (!Auth::user()->canEditModule2()) {
        abort(403);
    }

    $validated = $request->validate([
        'warehouse_id' => ['required', 'exists:warehouses,id'],
        'request_type' => ['required', 'in:'.StockRequest::TYPE_PICKUP.','.StockRequest::TYPE_DELIVERY],
        'date_needed' => ['nullable', 'date'],
        'remarks' => ['nullable', 'string', 'max:500'],
        'lines' => ['required', 'array', 'min:1'],
        'lines.*.id' => ['nullable', 'integer'],
        'lines.*.product' => ['nullable', 'string', 'max:64'],
        'lines.*.qty' => ['nullable', 'integer', 'min:0'],
    ]);

    $stockRequest->load('lines');

    $existing = $stockRequest->lines->keyBy('id');

    $keepIds = [];

    foreach ($validated['lines'] as $row) {
        if (blank($row['product'] ?? null) || (int) ($row['qty'] ?? 0) <= 0) {
            continue;
        }

        $qty = (int) $row['qty'];
        $line = $existing->get($row['id'] ?? null);

        if ($line) {
            // Never ask for less than has already arrived.
            $qty = max($qty, (int) $line->qty_received);
            $line->update([
                'product' => $row['product'],
                'qty_requested' => $qty,
            ]);
        } else {
            $line = StockRequestLine::create([
                'stock_request_id' => $stockRequest->id,
                'product' => $row['product'],
                'qty_requested' => $qty,
                'qty_received' => 0,
            ]);
        }

        $keepIds[] = $line->id;
    }

    // Lines the pop-up removed.
    $stockRequest->lines()->whereNotIn('id', $keepIds ?: [0])->delete();

    $stockRequest->update([
        'warehouse_id' => $validated['warehouse_id'],
        'request_type' => $validated['request_type'],
        'date_needed' => $validated['date_needed'] ?? null,
        'remarks' => $validated['remarks'] ?? null,
    ]);

    AuditLog::create([
        'admin_id' => Auth::id(),
        'action' => 'updated',
        'description' => "Updated stock request {$stockRequest->request_number}.",
    ]);

    return redirect()->route('wetstock.stock-requests.index')
        ->with('success', "Request {$stockRequest->request_number} updated.");
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
