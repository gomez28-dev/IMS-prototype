<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\StockIn;
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

        // Replenishment requests originated from depots
        $requestsQuery = PurchaseOrder::with(['warehouse', 'requester', 'approver'])
            ->whereIn('po_type', ['STANDARD_REPLENISHMENT', 'BUY_BACK']);

        if ($warehouseId) {
            $requestsQuery->where('warehouse_id', $warehouseId);
        }

        $stockRequests = $requestsQuery->latest()->paginate(15);

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

        return view('wetstock.stock-requests.index', compact('stockRequests', 'incomingDeliveries', 'warehouses', 'warehouseId'));
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
     * Store a new replenishment request (creates a PurchaseOrder in REQUESTED / Pending status).
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

        $po = PurchaseOrder::create([
            'po_type' => $validated['po_type'],
            'warehouse_id' => $warehouse->id,
            'qty_ordered' => $validated['qty_ordered'],
            'request_status' => 'REQUESTED',
            'status' => 'Pending',
            'requested_by' => Auth::id(),
            'request_date' => now(),
            'date_needed' => $validated['date_needed'] ?? null,
            'requested_products' => [
                ['product' => $validated['product'], 'quantity' => $validated['qty_ordered']]
            ],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Created Replenishment Request #{$po->id} ({$warehouse->name}, " . number_format($po->qty_ordered) . "L {$validated['product']}) for Purchasing",
        ]);

        return redirect()->route('wetstock.supplier-orders.index')
            ->with('success', "Replenishment request for {$warehouse->name} (" . number_format($po->qty_ordered) . "L) has been submitted to Purchasing (Module 3).");
    }

    /**
     * Receive physical stock from an incoming PurchaseOrderDelivery into a storage tank.
     */
    public function receiveDelivery(Request $request, PurchaseOrderDelivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
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

        // Mark delivery completed
        $delivery->update([
            'status' => 'Completed',
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
