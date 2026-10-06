<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\PurchaseOrderItem;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Supplier;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockOrderController extends Controller
{
    public function dashboard(): View
    {
        $stats = [
            'total_pos' => PurchaseOrder::count(),
            'pending_approval' => PurchaseOrder::where('request_status', 'RECEIVED')->count(),
            'for_delivery' => PurchaseOrderDelivery::where('status', 'Active')->count(),
            'completed' => PurchaseOrder::where('request_status', 'COMPLETED')->count(),
            'fuel_trade_queue' => Order::where('fulfillment_type', 'FUEL_TRADE')
                ->whereNull('linked_purchase_order_id')
                ->where('status', '!=', 'Cancelled')
                ->count(),
            'buy_back_count' => PurchaseOrder::where('po_type', 'BUY_BACK')->count(),
        ];

        $pendingFuelTradeOrders = Order::where('fulfillment_type', 'FUEL_TRADE')
            ->whereNull('linked_purchase_order_id')
            ->where('status', '!=', 'Cancelled')
            ->latest()
            ->take(5)
            ->get();

        $recentOrders = PurchaseOrder::with(['warehouse.tanks', 'client', 'requester', 'deliveries'])
            ->latest()
            ->take(10)
            ->get();

        $warehouses = Warehouse::with('tanks')->orderBy('name')->get();

        return view('wetstock.stock-orders.dashboard', compact('stats', 'pendingFuelTradeOrders', 'recentOrders', 'warehouses'));
    }

    public function index(Request $request): View
    {
        $query = PurchaseOrder::with(['warehouse', 'client', 'requester', 'approver', 'linkedOrder', 'deliveries.preparer']);

        if ($request->filled('po_type')) {
            $query->where('po_type', $request->po_type);
        }

        if ($request->filled('status')) {
            $query->where('request_status', $request->status);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('po_number', 'like', $term)
                  ->orWhere('supplier_name', 'like', $term);
            });
        }

        $purchaseOrders = $query->latest()->paginate(15);
        $warehouses = Warehouse::orderBy('name')->get();
        $clients = Client::orderBy('name')->get();

        // Queue of Fuel Trade SOs that still need a Supplier PO created
        $fuelTradeQueue = Order::where('fulfillment_type', 'FUEL_TRADE')
            ->whereNull('linked_purchase_order_id')
            ->where('status', '!=', 'Cancelled')
            ->latest()
            ->get();

        $mobileTankers = StorageTank::where('category', 'tanker')
            ->where('is_active', true)
            ->with('warehouse')
            ->get();

        return view('wetstock.stock-orders.index', compact('purchaseOrders', 'warehouses', 'clients', 'fuelTradeQueue', 'mobileTankers'));
    }

    public function createSupplierPo(): View
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        $warehouses = Warehouse::orderBy('name')->get();

        // Only active suppliers are offered; deactivated ones stay on old POs.
        $suppliers = Supplier::active()->orderBy('company_name')->orderBy('location')->get();

        return view('wetstock.stock-orders.create-po', compact('warehouses', 'suppliers'));
    }

    public function storeSupplierPo(Request $request): RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:64', 'unique:purchase_orders,po_number'],
            // Only active suppliers are selectable; deactivated ones remain on
            // existing Purchase Orders but cannot be chosen for new ones.
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'po_type' => ['required', 'in:STANDARD_REPLENISHMENT,BUY_BACK'],
            'date_needed' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'attention' => ['nullable', 'string', 'max:128'],
            'terms' => ['nullable', 'string', 'max:128'],
            'po_date' => ['nullable', 'date'],
            'vatable_sales_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'less_w_tax' => ['nullable', 'numeric', 'min:0'],
            'net_payable_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product' => ['required', 'string', 'in:Diesel,Premium,Unleaded'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['nullable', 'string', 'max:16'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $totalQty = (int) collect($validated['items'])->sum('quantity');

        // Line total is derived from the item rows so the stored totals never
        // drift from what was actually ordered. The browser computes the same
        // figure for display only; the server always recalculates it.
        $lineTotal = round(collect($validated['items'])->sum(
            fn ($i) => (int) $i['quantity'] * (float) ($i['unit_price'] ?? 0)
        ), 2);

        // supplier_name stays as a legacy mirror of the chosen supplier, so
        // existing screens that read it keep working.
        $supplier = Supplier::find($validated['supplier_id']);
        $supplierName = $supplier->company_name;

        $netPayable = $validated['net_payable_amount'] ?? $lineTotal;

        $po = PurchaseOrder::create([
            'po_number' => $validated['po_number'],
            'po_type' => $validated['po_type'],
            'supplier_id' => $supplier?->id,
            'supplier_name' => $supplierName,
            'attention' => $validated['attention'] ?? $supplier?->attention,
            'terms' => $validated['terms'] ?? null,
            'po_date' => $validated['po_date'] ?? now()->format('Y-m-d'),
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'qty_ordered' => $totalQty,
            'total_amount' => $lineTotal,
            'vatable_sales_amount' => $validated['vatable_sales_amount'] ?? 0,
            'vat_amount' => $validated['vat_amount'] ?? 0,
            'less_w_tax' => $validated['less_w_tax'] ?? 0,
            'net_payable_amount' => $netPayable,
            'prepared_by' => Auth::id(),
            'request_status' => 'CONFIRMED', // Supplier contract active
            'status' => 'Pending',
            'requested_by' => Auth::id(),
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'request_date' => now(),
            'date_needed' => $validated['date_needed'] ?? null,
            'requested_products' => $validated['items'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        foreach ($validated['items'] as $itemData) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $itemData['product'],
                // Fuel volumes are measured in liters unless stated otherwise.
                'unit' => $itemData['unit'] ?? 'LTRS',
                'quantity_ordered' => $itemData['quantity'],
                'unit_price' => $itemData['unit_price'] ?? null,
            ]);
        }

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'created',
            'description' => "Purchasing created Bulk Supplier PO #{$po->po_number} ({$po->supplier_name}) for total " . number_format($totalQty) . " L",
        ]);

        return redirect()->route('stock-orders.index')
            ->with('success', "Supplier PO #{$po->po_number} created successfully with " . count($validated['items']) . " product line(s).");
    }

    public function createFromSalesOrder(Order $order): View|RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        if (!$order->canBeIssuedAtl()) {
            return redirect()->route('stock-orders.index')
                ->with('error', "Cannot issue ATL: Sales Order {$order->formatted_so_number} has not been cleared by Accounting (Status: {$order->clearing_status}).");
        }

        $product = 'Diesel';
        $availablePos = PurchaseOrder::where('status', '!=', 'Cancelled')
            ->with(['items.allocations', 'allocations'])
            ->get()
            ->filter(function ($po) use ($product) {
                return $po->getAvailableBalanceForProduct($product) > 0;
            })
            ->values();

        $mobileTankers = StorageTank::where('category', 'tanker')
            ->where('is_active', true)
            ->with('warehouse')
            ->get();

        return view('wetstock.stock-orders.form', [
            'mode' => 'fuel_trade',
            'order' => $order,
            'purchaseOrder' => null,
            'availablePos' => $availablePos,
            'clients' => Client::orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
            'mobileTankers' => $mobileTankers,
        ]);
    }

    /**
     * What this Sales Order requires, per product (see Order::productRequirements).
     *
     * @return array<string, int>
     */
    private function salesOrderProductRequirements(Order $order, string $fallbackProduct): array
    {
        return $order->productRequirements($fallbackProduct);
    }

    public function storeFuelTradePo(Request $request, Order $order): RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        $isClientProvided = $request->input('atl_source') === PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED;
        $submitForApproval = (bool) $request->input('submit_for_approval');

        if (!$order->canPrepareAtl()) {
            return redirect()->route('stock-orders.index')
                ->with('error', "Cannot issue ATL: Sales Order {$order->formatted_so_number} is not open for ATL preparation.");
        }

        // Drafting is always allowed, even before Accounting clears the order.
        // Only submitting for VP approval is gated on clearance.
        if ($submitForApproval && !$isClientProvided && $order->clearing_status !== 'Approved') {
            return back()->withInput()->withErrors([
                'submit_for_approval' => "Cannot submit for approval: Sales Order {$order->formatted_so_number} has not been cleared by Accounting (Status: {$order->clearing_status}). Save it as a draft instead.",
            ]);
        }

        $defaultProduct = $request->input('product', 'Diesel');

        // Drawdown engine submission (multi-PO or single-PO drawdown)
        if ($request->has('drawdowns') && is_array($request->input('drawdowns'))) {
            $validated = $request->validate([
                'product' => ['nullable', 'string', 'max:64'],
                'atl_source' => ['nullable', 'in:DOYEN_ISSUED,CLIENT_PROVIDED'],
                'submit_for_approval' => ['nullable', 'boolean'],
                'delivery_channel' => ['nullable', 'string', 'in:SUPPLIER_CLIENT_PICKUP,SUPPLIER_DOYEN_PICKUP,BUY_BACK_CLIENT_PICKUP,BUY_BACK_DOYEN_PICKUP,SUPPLIER_STOCKS_DELIVERY,BUY_BACK_STOCKS_DELIVERY'],
                'storage_tank_id' => ['nullable', 'exists:storage_tanks,id'],
                'supplier_so_number' => ['nullable', 'string', 'max:64'],
                'supplier_dr_number' => ['nullable', 'string', 'max:64'],
                'scanned_doc_url' => ['nullable', 'string', 'max:1000'],
                'receiving_date' => ['required', 'date'],
                'driver_name' => ['nullable', 'string', 'max:128'],
                'plate_number' => ['nullable', 'string', 'max:64'],
                'location' => ['nullable', 'string', 'max:128'],
                'reference_no' => ['nullable', 'string', 'max:64'],
                'atl_type' => ['required', 'in:DITC_ATL,CLIENT_ATL,NONE'],
                'atl_number' => ['nullable', 'string', 'max:64'],
                'client_atl_number' => ['required_if:atl_source,CLIENT_PROVIDED', 'nullable', 'string', 'max:64'],
                'additional_remarks' => ['nullable', 'string', 'max:500'],
                'drawdowns' => ['required', 'array', 'min:1'],
                'drawdowns.*.purchase_order_id' => ['required', 'exists:purchase_orders,id'],
                'drawdowns.*.product' => ['nullable', 'string', 'in:Diesel,Premium,Unleaded'],
                'drawdowns.*.quantity' => ['required', 'integer', 'min:1'],
            ]);

            // What this Sales Order requires, per product. Orders created before
            // multi-product lines existed have no lines, so they fall back to a
            // single requirement equal to the order's whole ordered volume.
            $requirements = $this->salesOrderProductRequirements($order, $defaultProduct);

            // Each allocation row carries its own product.
            $drawdowns = collect($validated['drawdowns'])->map(fn (array $dd): array => [
                'purchase_order_id' => (int) $dd['purchase_order_id'],
                'product' => $dd['product'] ?? $defaultProduct,
                'quantity' => (int) $dd['quantity'],
            ]);

            $drawnByProduct = $drawdowns
                ->groupBy('product')
                ->map(fn ($rows) => (int) $rows->sum('quantity'));

            // 1a. Refuse any product the Sales Order never asked for.
            foreach ($drawnByProduct as $drawnProduct => $drawnQty) {
                if (!array_key_exists($drawnProduct, $requirements)) {
                    return back()->withInput()->withErrors([
                        'drawdowns' => "This Sales Order does not require {$drawnProduct}. Required: "
                            . implode(', ', array_keys($requirements)) . '.',
                    ]);
                }
            }

            // 1b. Per product, the volume drawn must exactly match the requirement.
            foreach ($requirements as $requiredProduct => $requiredQty) {
                $drawnQty = (int) ($drawnByProduct[$requiredProduct] ?? 0);
                if ($drawnQty !== $requiredQty) {
                    return back()->withInput()->withErrors([
                        'drawdowns' => "Total drawn volume for {$requiredProduct} ("
                            . number_format($drawnQty) . " L) must exactly match the Sales Order requirement ("
                            . number_format($requiredQty) . " L).",
                    ]);
                }
            }

            // 2. Verify each PO has sufficient remaining balance for that product
            foreach ($drawdowns as $dd) {
                $targetPo = PurchaseOrder::find($dd['purchase_order_id']);
                $avail = $targetPo->getAvailableBalanceForProduct($dd['product']);
                if ($dd['quantity'] > $avail) {
                    return back()->withInput()->withErrors([
                        'drawdowns' => "Insufficient {$dd['product']} balance on Supplier PO #{$targetPo->po_number}. Available: "
                            . number_format($avail) . " L, Requested: " . number_format($dd['quantity']) . " L.",
                    ]);
                }
            }

            $primaryPoId = $drawdowns->first()['purchase_order_id'];
            $totalDrawn = (int) $drawdowns->sum('quantity');

            // The ATL row keeps a summary: total liters, and the products it covers.
            // Per-product quantities live on the allocations below.
            $productSummary = $drawdowns->pluck('product')->unique()->implode(' + ');

            $delivery = PurchaseOrderDelivery::create([
                'purchase_order_id' => $primaryPoId,
                'order_id' => $order->id,
                'storage_tank_id' => $validated['storage_tank_id'] ?? null,
                'delivery_channel' => $validated['delivery_channel'] ?? 'SUPPLIER_CLIENT_PICKUP',
                'order_type' => 'PICK_UP',
                'atl_type' => $isClientProvided ? 'CLIENT_ATL' : $validated['atl_type'],
                'atl_number' => $isClientProvided ? null : ($validated['atl_number'] ?? null),
                'client_atl_number' => $isClientProvided
                    ? ($validated['client_atl_number'] ?? null)
                    : ($validated['client_atl_number'] ?? $order->client_atl_number),
                'atl_category' => $order->isBuyBack()
                    ? PurchaseOrderDelivery::CATEGORY_BUY_BACK
                    : PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
                'atl_source' => $isClientProvided
                    ? PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED
                    : PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
                // Client-provided ATLs are recorded only: no VP approval. A
                // Doyen-issued ATL enters the queue only when submitted.
                'approval_status' => ($isClientProvided || !$submitForApproval)
                    ? PurchaseOrderDelivery::APPROVAL_DRAFT
                    : PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
                'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
                'issued_at' => now(),
                'reference_no' => $validated['reference_no'] ?? null,
                'so_number' => $order->so_number,
                'supplier_so_number' => $validated['supplier_so_number'] ?? null,
                'supplier_dr_number' => $validated['supplier_dr_number'] ?? null,
                'scanned_doc_url' => $validated['scanned_doc_url'] ?? null,
                'product' => $productSummary,
                'qty_to_receive' => $totalDrawn,
                'receiving_date' => $validated['receiving_date'],
                'driver_name' => $validated['driver_name'] ?? null,
                'plate_number' => $validated['plate_number'] ?? null,
                'location' => $validated['location'] ?? null,
                'additional_remarks' => $validated['additional_remarks'] ?? null,
                'status' => 'Pending',
                'prepared_by' => Auth::id(),
            ]);

            foreach ($drawdowns as $dd) {
                $targetPo = PurchaseOrder::find($dd['purchase_order_id']);
                $item = $targetPo->items()->where('product', $dd['product'])->first();

                AtlAllocation::create([
                    'purchase_order_delivery_id' => $delivery->id,
                    'purchase_order_id' => $targetPo->id,
                    'purchase_order_item_id' => $item?->id,
                    'product' => $dd['product'],
                    'quantity' => $dd['quantity'],
                ]);
            }

            $order->update(['linked_purchase_order_id' => $primaryPoId]);

            $reference = $delivery->atl_number ?: $delivery->client_atl_number;

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'created',
                'description' => "Purchasing prepared ATL #{$reference} for SO {$order->formatted_so_number}, drawn from "
                    . count($validated['drawdowns']) . " PO(s) covering {$productSummary}.",
            ]);

            $outcome = match (true) {
                $isClientProvided => 'recorded (client-provided, no VP approval needed)',
                $submitForApproval => 'submitted for VP approval',
                default => 'saved as a draft',
            };

            return redirect()->route('stock-orders.atls.show', $order->id)
                ->with('success', "Authority to Load (ATL) #{$reference} {$outcome} and linked to Supplier PO(s).");
        }

        // Direct PO creation fallback
        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:64'],
            'supplier_name' => ['required', 'string', 'max:128'],
            'atl_source' => ['nullable', 'in:DOYEN_ISSUED,CLIENT_PROVIDED'],
            'submit_for_approval' => ['nullable', 'boolean'],
            'delivery_channel' => ['nullable', 'string', 'in:SUPPLIER_CLIENT_PICKUP,SUPPLIER_DOYEN_PICKUP,BUY_BACK_CLIENT_PICKUP,BUY_BACK_DOYEN_PICKUP,SUPPLIER_STOCKS_DELIVERY,BUY_BACK_STOCKS_DELIVERY'],
            'storage_tank_id' => ['nullable', 'exists:storage_tanks,id'],
            'supplier_so_number' => ['nullable', 'string', 'max:64'],
            'supplier_dr_number' => ['nullable', 'string', 'max:64'],
            'scanned_doc_url' => ['nullable', 'string', 'max:1000'],
            'product' => ['required', 'string', 'max:64'],
            'qty_to_receive' => ['required', 'integer', 'min:1'],
            'receiving_date' => ['required', 'date'],
            'driver_name' => ['nullable', 'string', 'max:128'],
            'plate_number' => ['nullable', 'string', 'max:64'],
            'location' => ['nullable', 'string', 'max:128'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'atl_type' => ['required', 'in:DITC_ATL,CLIENT_ATL,NONE'],
            'atl_number' => ['nullable', 'string', 'max:64'],
            'client_atl_number' => ['required_if:atl_source,CLIENT_PROVIDED', 'nullable', 'string', 'max:64'],
            'additional_remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $po = PurchaseOrder::where('po_number', $validated['po_number'])->first();
        if (!$po) {
            $po = PurchaseOrder::create([
                'po_number' => $validated['po_number'],
                'po_type' => 'FUEL_TRADE',
                'supplier_name' => $validated['supplier_name'],
                'linked_order_id' => $order->id,
                'qty_ordered' => $validated['qty_to_receive'],
                'request_status' => 'RECEIVED',
                'status' => 'Pending',
                'requested_by' => Auth::id(),
                'request_date' => now(),
                'date_needed' => $validated['receiving_date'],
                'requested_products' => [
                    ['product' => $validated['product'], 'quantity' => $validated['qty_to_receive']]
                ],
                'remarks' => "Fuel Trade for Client SO #{$order->so_number} ({$order->account})",
            ]);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product' => $validated['product'],
                'quantity_ordered' => $validated['qty_to_receive'],
            ]);
        }

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $po->id,
            'order_id' => $order->id,
            'storage_tank_id' => $validated['storage_tank_id'] ?? null,
            'delivery_channel' => $validated['delivery_channel'] ?? 'SUPPLIER_CLIENT_PICKUP',
            'order_type' => 'PICK_UP',
            'atl_type' => $isClientProvided ? 'CLIENT_ATL' : $validated['atl_type'],
            'atl_number' => $isClientProvided ? null : ($validated['atl_number'] ?? null),
            'client_atl_number' => $isClientProvided
                ? ($validated['client_atl_number'] ?? null)
                : ($validated['client_atl_number'] ?? $order->client_atl_number),
            'atl_category' => $order->isBuyBack()
                ? PurchaseOrderDelivery::CATEGORY_BUY_BACK
                : PurchaseOrderDelivery::CATEGORY_FUEL_TRADE,
            'atl_source' => $isClientProvided
                ? PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED
                : PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            'approval_status' => ($isClientProvided || !$submitForApproval)
                ? PurchaseOrderDelivery::APPROVAL_DRAFT
                : PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'issued_at' => now(),
            'reference_no' => $validated['reference_no'] ?? null,
            'so_number' => $order->so_number,
            'supplier_so_number' => $validated['supplier_so_number'] ?? null,
            'supplier_dr_number' => $validated['supplier_dr_number'] ?? null,
            'scanned_doc_url' => $validated['scanned_doc_url'] ?? null,
            'product' => $validated['product'],
            'qty_to_receive' => $validated['qty_to_receive'],
            'receiving_date' => $validated['receiving_date'],
            'driver_name' => $validated['driver_name'] ?? null,
            'plate_number' => $validated['plate_number'] ?? null,
            'location' => $validated['location'] ?? null,
            'additional_remarks' => $validated['additional_remarks'] ?? null,
            'status' => 'Pending',
            'prepared_by' => Auth::id(),
        ]);

        AtlAllocation::create([
            'purchase_order_delivery_id' => $delivery->id,
            'purchase_order_id' => $po->id,
            'product' => $validated['product'],
            'quantity' => $validated['qty_to_receive'],
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'created',
            'description' => "Purchasing created Fuel Trade PO #{$po->po_number} linked to SO #{$order->so_number} for {$order->account}",
        ]);

        return redirect()->route('stock-orders.index')
            ->with('success', "Fuel Trade PO #{$po->po_number} created and submitted for VP approval.");
    }

    public function completeFuelTrade(Request $request, PurchaseOrderDelivery $delivery): RedirectResponse
    {
        // Explicit allowlist rather than the broader canEditStockOrders(), so
        // lifting stays with Purchasing and Admin.
        if (!Auth::user()->canMarkAtlLifted()) {
            abort(403, 'Unauthorized to mark an ATL as lifted.');
        }

        $validated = $request->validate([
            'supplier_dr_number' => ['nullable', 'string', 'max:64'],
            'supplier_so_number' => ['nullable', 'string', 'max:64'],
            'scanned_doc_url' => ['nullable', 'string', 'max:1000'],
            'storage_tank_id' => ['nullable', 'exists:storage_tanks,id'],
        ]);

        $updateData = [];
        if (!empty($validated['supplier_dr_number'])) {
            $updateData['supplier_dr_number'] = $validated['supplier_dr_number'];
        }
        if (!empty($validated['supplier_so_number'])) {
            $updateData['supplier_so_number'] = $validated['supplier_so_number'];
        }
        if (!empty($validated['scanned_doc_url'])) {
            $updateData['scanned_doc_url'] = $validated['scanned_doc_url'];
        }

        $tankId = $validated['storage_tank_id'] ?? $delivery->storage_tank_id;

        // Fuel Trade and Buy Back pick-ups are lifted straight to the client at
        // the supplier, so they never stock into a tank. Tank auto-stocking
        // belongs to Doyen Stocks replenishment, which arrives via the wet
        // stock receive flow instead.
        $isTankerPickup = in_array($delivery->delivery_channel, ['SUPPLIER_DOYEN_PICKUP', 'BUY_BACK_DOYEN_PICKUP']);
        $stocksFromSupplier = $delivery->atl_category === PurchaseOrderDelivery::CATEGORY_FUEL_TRADE
            || $delivery->atl_category === PurchaseOrderDelivery::CATEGORY_BUY_BACK;

        if ($stocksFromSupplier && $isTankerPickup) {
            $isTankerPickup = false;
        }

        if ($isTankerPickup && $tankId) {
            $tank = StorageTank::with('warehouse')->findOrFail($tankId);

            if (!$tank->is_active) {
                return back()->with('danger', "Error: Mobile Tanker {$tank->name} is deactivated.");
            }

            if ($tank->is_contaminated) {
                return back()->with('danger', "Error: Mobile Tanker {$tank->name} is contaminated and cannot receive stock.");
            }

            if ($delivery->qty_to_receive > $tank->remaining_capacity) {
                return back()->with('danger', "Error: Receiving " . number_format($delivery->qty_to_receive) . "L exceeds remaining capacity of " . number_format($tank->remaining_capacity) . "L for {$tank->name} (Max: " . number_format($tank->max_capacity) . "L, Available: " . number_format($tank->stock_available) . "L)!");
            }

            // Create StockIn for the mobile tanker
            StockIn::create([
                'storage_tank_id' => $tank->id,
                'admin_id' => Auth::id(),
                'quantity' => $delivery->qty_to_receive,
                'date' => now()->toDateString(),
                'purchase_order_delivery_id' => $delivery->id,
            ]);

            $updateData['storage_tank_id'] = $tank->id;
        }

        $updateData['status'] = 'Completed';

        // Record the lift on the ATL itself, so the ATL screens can report
        // Lifted/Received without inferring it from the old status column.
        $updateData['lift_status'] = PurchaseOrderDelivery::LIFT_LIFTED;
        $updateData['lifted_at'] = now();
        $updateData['lifted_by'] = Auth::id();

        $delivery->update($updateData);

        $po = $delivery->purchaseOrder;
        if ($po) {
            $po->update([
                'request_status' => 'COMPLETED',
                'status' => 'Fulfilled',
            ]);

            if ($po->linkedOrder) {
                $this->recordLiftInModule1($delivery, $po->linkedOrder);
                $po->linkedOrder->update(['status' => 'Fulfilled']);
            }
        }

        $logMsg = $isTankerPickup && isset($tank)
            ? "Fuel Trade Pick-Up Lift Confirmed: Delivery #{$delivery->id} completed. Auto Stock-In " . number_format($delivery->qty_to_receive) . "L into Mobile Tanker {$tank->name} ({$tank->warehouse->name}). Linked Sales Order #{$po?->linkedOrder?->so_number} marked Fulfilled."
            : "Fuel Trade Lift Confirmed: Delivery #{$delivery->id} completed at refinery rack. Linked Sales Order #{$po?->linkedOrder?->so_number} marked Fulfilled.";

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => $logMsg,
        ]);

        $successMsg = $isTankerPickup && isset($tank)
            ? "Refinery lift confirmed! " . number_format($delivery->qty_to_receive) . "L successfully auto-stocked into Mobile Tanker {$tank->name}. Linked Sales Order marked Fulfilled."
            : "Fuel trade pick up confirmed! Linked Sales Order #{$po?->linkedOrder?->so_number} is now marked as Fulfilled.";

        return back()->with('success', $successMsg);
    }

    public function updateDeliveryDocs(Request $request, PurchaseOrderDelivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders() && !Auth::user()->canEditModule1() && !Auth::user()->canEditModule2()) {
            abort(403);
        }

        $validated = $request->validate([
            'supplier_dr_number' => ['nullable', 'string', 'max:64'],
            'supplier_so_number' => ['nullable', 'string', 'max:64'],
            'scanned_doc_url' => ['nullable', 'string', 'max:1000'],
            'storage_tank_id' => ['nullable', 'exists:storage_tanks,id'],
        ]);

        $data = [];
        if ($request->has('supplier_dr_number')) {
            $data['supplier_dr_number'] = $validated['supplier_dr_number'];
        }
        if ($request->has('supplier_so_number')) {
            $data['supplier_so_number'] = $validated['supplier_so_number'];
        }
        if ($request->has('scanned_doc_url')) {
            $data['scanned_doc_url'] = $validated['scanned_doc_url'];
        }
        if ($request->has('storage_tank_id')) {
            $data['storage_tank_id'] = $validated['storage_tank_id'];
        }

        $delivery->update($data);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => "Updated Documentation Details for Delivery #{$delivery->id} (ATL: " . ($delivery->atl_number ?: 'Pending') . ").",
        ]);

        return back()->with('success', "Documentation and serial numbers updated successfully.");
    }

    public function editRequest(PurchaseOrder $purchaseOrder): View
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        return view('wetstock.stock-orders.form', [
            'mode' => 'edit_request',
            'purchaseOrder' => $purchaseOrder,
            'order' => null,
            'clients' => Client::orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }

    public function updateRequest(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:64'],
            'supplier_name' => ['required', 'string', 'max:128'],
            'order_type' => ['required', 'in:DELIVERY,PICK_UP'],
            'delivery_channel' => ['required', 'string'],
            'dr_number' => ['nullable', 'string', 'max:64'],
            'atl_number' => ['nullable', 'string', 'max:64'],
            'client_atl_number' => ['nullable', 'string', 'max:64'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'so_number' => ['nullable', 'string', 'max:64'],
            'product' => ['required', 'string', 'max:64'],
            'qty_to_receive' => ['required', 'integer', 'min:1'],
            'receiving_date' => ['required', 'date'],
            'driver_name' => ['nullable', 'string', 'max:128'],
            'plate_number' => ['nullable', 'string', 'max:64'],
            'location' => ['nullable', 'string', 'max:128'],
            'additional_remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $purchaseOrder->update([
            'po_number' => $validated['po_number'],
            'supplier_name' => $validated['supplier_name'],
            'request_status' => 'RECEIVED',
        ]);

        PurchaseOrderDelivery::create([
            'purchase_order_id' => $purchaseOrder->id,
            'delivery_channel' => $validated['delivery_channel'],
            'order_type' => $validated['order_type'],
            'atl_type' => !empty($validated['client_atl_number']) ? 'CLIENT_ATL' : ($validated['order_type'] === 'PICK_UP' ? 'DITC_ATL' : 'NONE'),
            'dr_number' => $validated['dr_number'] ?? null,
            'atl_number' => $validated['atl_number'] ?? null,
            'client_atl_number' => $validated['client_atl_number'] ?? null,
            'reference_no' => $validated['reference_no'] ?? null,
            'so_number' => $validated['so_number'] ?? null,
            'product' => $validated['product'],
            'qty_to_receive' => $validated['qty_to_receive'],
            'receiving_date' => $validated['receiving_date'],
            'driver_name' => $validated['driver_name'] ?? null,
            'plate_number' => $validated['plate_number'] ?? null,
            'location' => $validated['location'] ?? null,
            'additional_remarks' => $validated['additional_remarks'] ?? null,
            'status' => 'Pending',
            'prepared_by' => Auth::id(),
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => "Purchasing processed stock order #{$purchaseOrder->po_number} with ATL/DR details, submitted for VP approval.",
        ]);

        return redirect()->route('stock-orders.index')
            ->with('success', "Stock order {$purchaseOrder->po_number} prepared and submitted for VP approval.");
    }

    public function approvals(Request $request): View
    {
        // Two queues: Purchase Orders waiting on the VP, and ATLs waiting on
        // the VP. The PO tab stays the default so the existing link is
        // unchanged.
        $tab = $request->input('tab', 'pos');
        if (!in_array($tab, ['pos', 'atls'], true)) {
            $tab = 'pos';
        }

        $pendingOrders = PurchaseOrder::with(['warehouse', 'client', 'requester', 'deliveries'])
            ->where('request_status', 'RECEIVED')
            ->latest()
            ->paginate(15);

        // Only Doyen-issued ATLs submitted for approval, and only those whose
        // sales order is actually cleared. Client-provided ATLs are recorded
        // only and never need approval.
        $pendingAtls = PurchaseOrderDelivery::query()
            ->where('approval_status', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL)
            ->where(function ($q) {
                $q->whereNull('atl_source')
                    ->orWhere('atl_source', PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED);
            })
            ->whereHas('order', function ($q) {
                $q->where('clearing_status', 'Approved');
            })
            ->with(['order', 'purchaseOrder', 'allocations'])
            ->latest()
            ->paginate(15);

        $counts = [
            'pos' => PurchaseOrder::where('request_status', 'RECEIVED')->count(),
            'atls' => PurchaseOrderDelivery::where('approval_status', PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL)
                ->where(function ($q) {
                    $q->whereNull('atl_source')
                        ->orWhere('atl_source', PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED);
                })
                ->whereHas('order', fn ($q) => $q->where('clearing_status', 'Approved'))
                ->count(),
        ];

        return view('wetstock.stock-orders.approvals', compact(
            'pendingOrders', 'pendingAtls', 'tab', 'counts'
        ));
    }

    /**
     * VP approval for a Doyen-issued ATL.
     */
    public function approveAtl(PurchaseOrderDelivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canApproveStockOrders()) {
            abort(403, 'Unauthorized to approve ATLs.');
        }

        if ($delivery->isClientProvided()) {
            return back()->with('error', 'Client-provided ATLs are recorded only and do not need approval.');
        }

        if ($delivery->approval_status !== PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL) {
            return back()->with('error', 'Only ATLs awaiting approval can be approved.');
        }

        $delivery->update([
            'approval_status' => PurchaseOrderDelivery::APPROVAL_APPROVED,
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'approved',
            'description' => "Approved ATL " . ($delivery->atl_number ?: $delivery->client_atl_number)
                . ($delivery->order ? " for SO {$delivery->order->formatted_so_number}" : ''),
        ]);

        return back()->with('success', 'ATL approved. The PDF is now available to download.');
    }

    /**
     * VP rejection for a Doyen-issued ATL.
     */
    public function rejectAtl(Request $request, PurchaseOrderDelivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canApproveStockOrders()) {
            abort(403, 'Unauthorized to reject ATLs.');
        }

        if ($delivery->isClientProvided()) {
            return back()->with('error', 'Client-provided ATLs are recorded only and cannot be rejected.');
        }

        if ($delivery->approval_status !== PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL) {
            return back()->with('error', 'Only ATLs awaiting approval can be rejected.');
        }

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $reason = trim($validated['rejection_reason'] ?? '');

        $delivery->update([
            'approval_status' => PurchaseOrderDelivery::APPROVAL_REJECTED,
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'REJECTED',
            'description' => "Rejected ATL " . ($delivery->atl_number ?: $delivery->client_atl_number)
                . ($delivery->order ? " for SO {$delivery->order->formatted_so_number}" : '')
                . ($reason !== '' ? " — Reason: {$reason}" : ''),
        ]);

        return back()->with('success', 'ATL rejected and returned to Purchasing.');
    }

    public function approve(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if (!Auth::user()->canApproveStockOrders()) {
            abort(403, 'Unauthorized to approve stock orders.');
        }

        $purchaseOrder->update([
            'request_status' => 'CONFIRMED',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        $purchaseOrder->deliveries()->whereNull('approved_at')->update([
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'approved',
            'description' => "Approved Stock Order {$purchaseOrder->po_number}",
        ]);

        return back()->with('success', "Stock Order {$purchaseOrder->po_number} has been approved successfully.");
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if (!Auth::user()->canApproveStockOrders()) {
            abort(403, 'Unauthorized to reject stock orders.');
        }

        if ($purchaseOrder->request_status !== 'RECEIVED') {
            return back()->with('info', "Only orders pending VP approval can be rejected.");
        }

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = trim($validated['rejection_reason'] ?? '');

        $purchaseOrder->update([
            'request_status' => 'REQUESTED',
            'approved_by' => null,
            'approved_at' => null,
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'REJECTED',
            'description' => "Rejected Stock Order {$purchaseOrder->po_number} back to purchasing"
                . ($reason !== '' ? " — Reason: {$reason}" : ""),
        ]);

        return back()->with('success', "Stock Order {$purchaseOrder->po_number} rejected and returned to purchasing for rework.");
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load(['warehouse.tanks', 'client', 'requester', 'approver', 'linkedOrder', 'deliveries.preparer', 'deliveries.approver', 'items']);

        return view('wetstock.stock-orders.show', compact('purchaseOrder'));
    }

    public function dispatchDelivery(PurchaseOrderDelivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        $delivery->update(['status' => 'Active']);
        $delivery->purchaseOrder->update(['request_status' => 'FOR_DELIVERY']);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => "Marked delivery #{$delivery->id} for PO {$delivery->purchaseOrder->po_number} as FOR DELIVERY (Active)",
        ]);

        return back()->with('success', "Delivery marked as FOR DELIVERY. Hauler and destination notified.");
    }

    /**
     * Reflect a lifted ATL into Module 1.
     *
     * Module 1 derives remaining balance from its Delivery rows, so setting
     * Order.status alone moves nothing. A Fuel Trade order additionally reads
     * its completed ATLs, but Buy Back pick-ups do not, so the Module 1
     * delivery row is written for both. It is keyed on the ATL so a repeated
     * lift updates the same row instead of double counting.
     */
    private function recordLiftInModule1(PurchaseOrderDelivery $delivery, Order $order): void
    {
        $atlNumber = $delivery->atl_number ?: $delivery->client_atl_number;

        $existing = Delivery::where('order_id', $order->id)
            ->where('atl_number', $atlNumber)
            ->first();

        $payload = [
            'order_id' => $order->id,
            'storage_tank_id' => $delivery->storage_tank_id,
            'dr_number' => $delivery->dr_number ?: ($atlNumber ?: 'ATL-' . $delivery->id),
            'atl_number' => $atlNumber,
            'delivery_date' => $delivery->receiving_date ?: now(),
            'qty_out' => (int) $delivery->qty_to_receive,
            'status' => 'FULFILLED',
            'fulfilled_by' => Auth::id(),
            'fulfilled_at' => now(),
            'created_by' => Auth::id(),
        ];

        if ($existing) {
            $existing->update($payload);
        } else {
            Delivery::create($payload);
        }
    }

    public function deliveries(): View
    {
        $deliveries = PurchaseOrderDelivery::with(['purchaseOrder.warehouse.tanks', 'purchaseOrder.client', 'preparer', 'approver', 'stockIns.tank'])
            ->latest()
            ->paginate(20);

        $warehouses = Warehouse::with('tanks')->orderBy('name')->get();

        return view('wetstock.stock-orders.deliveries', compact('deliveries', 'warehouses'));
    }

    /**
     * Printed Purchase Order, laid out to match the supplier's sample:
     * company and reference details, one row per product line, the money
     * summary, remarks, and prepared/approved printed names.
     */
    public function downloadPoPdf(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['items', 'supplier', 'preparer', 'approver', 'requester']);

        $supplierName = $purchaseOrder->supplier?->company_name
            ?: ($purchaseOrder->supplier_name ?: '—');
        $supplierAddress = $purchaseOrder->supplier?->address;
        $attention = $purchaseOrder->attention ?: $purchaseOrder->supplier?->attention;
        $poDate = $purchaseOrder->po_date
            ? $purchaseOrder->po_date->format('m/d/Y')
            : ($purchaseOrder->request_date ? $purchaseOrder->request_date->format('m/d/Y') : null);

        // Amount per line is derived, never stored, so it always matches the
        // quantity and unit price on the same row.
        $lines = $purchaseOrder->items->map(fn ($item) => [
            'particulars' => $item->product,
            'qty' => (int) $item->quantity_ordered,
            'unit' => $item->unit ?: 'LTRS',
            'price' => (float) ($item->unit_price ?? 0),
            'amount' => round((int) $item->quantity_ordered * (float) ($item->unit_price ?? 0), 2),
        ])->values()->all();

        $lineTotal = round(array_sum(array_column($lines, 'amount')), 2);

        // A PO with no item rows still prints, using the order's own volume.
        $storedTotal = (float) $purchaseOrder->total_amount;
        $totalAmount = $storedTotal > 0 ? $storedTotal : $lineTotal;

        $totals = [
            'total_amount' => $totalAmount,
            'vatable_sales_amount' => (float) $purchaseOrder->vatable_sales_amount,
            'vat_amount' => (float) $purchaseOrder->vat_amount,
            'less_w_tax' => (float) $purchaseOrder->less_w_tax,
            'net_payable_amount' => (float) $purchaseOrder->net_payable_amount > 0
                ? (float) $purchaseOrder->net_payable_amount
                : $totalAmount,
        ];

        $preparedBy = $purchaseOrder->preparer?->name ?: $purchaseOrder->requester?->name;
        $approvedBy = $purchaseOrder->approver?->name;

        $logoPath = public_path('images/logo_ims.png');
        $logoBase64 = file_exists($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
            : null;

        $pdf = Pdf::loadView('wetstock.stock-orders.po-pdf', [
            // The view reads $po, matching the ATL PDF's variable name.
            'po' => $purchaseOrder,
            'supplierName' => $supplierName,
            'supplierAddress' => $supplierAddress,
            'attention' => $attention,
            'poDate' => $poDate,
            'lines' => $lines,
            'totals' => $totals,
            'preparedBy' => $preparedBy,
            'approvedBy' => $approvedBy,
            'logoBase64' => $logoBase64,
        ]);        $pdf->setPaper('letter', 'portrait');

        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $purchaseOrder->po_number ?: ('PO-' . $purchaseOrder->id));

        return $pdf->download('PO_' . $sanitized . '.pdf');
    }

    public function downloadAtlPdf(PurchaseOrderDelivery $delivery)
    {
        // The printed ATL is a Doyen-issued document. A client-provided ATL is
        // only referenced, and a rejected one was never issued.
        if ($delivery->isClientProvided()) {
            abort(403, 'Client-provided ATLs do not have a Doyen ATL PDF.');
        }

        if ($delivery->approval_status === PurchaseOrderDelivery::APPROVAL_REJECTED) {
            abort(403, 'This ATL was rejected, so no ATL PDF is available.');
        }

        $po = $delivery->purchaseOrder;

        $logoPath = public_path('images/logo_ims.png');
        $logoBase64 = file_exists($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
            : null;

        $pdf = Pdf::loadView('wetstock.stock-orders.atl-pdf', compact('delivery', 'po', 'logoBase64'));
        $pdf->setPaper('letter', 'portrait');

        $sanitizedName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $delivery->atl_number ?: ($delivery->reference_no ?: ('DEL-' . $delivery->id)));
        $filename = 'ATL_' . $sanitizedName . '.pdf';

        return $pdf->download($filename);
    }
}
