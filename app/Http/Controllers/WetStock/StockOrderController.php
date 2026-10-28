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
use App\Models\StockRequest;
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

        // Only active suppliers are offered; deactivated ones stay on old POs.
        $suppliers = Supplier::active()->orderBy('company_name')->orderBy('location')->get();

        return view('wetstock.stock-orders.create-po', compact('suppliers'));
    }

    /**
     * Edit an existing supplier PO. Same form as create, prefilled.
     */
    public function editSupplierPo(PurchaseOrder $purchaseOrder): View
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        // An approved PO is a signed document the supplier is already working to,
        // and ATLs may already have been drawn against its quantities. The
        // supervisor's rule is that approval closes the PO to edits, and that is
        // what the detail page's Edit-or-PDF switch already assumes - without
        // this, a direct URL still reopened the form.
        if ($purchaseOrder->isVpApproved()) {
            abort(403, 'An approved purchase order can no longer be edited. Raise a new PO for any change.');
        }

        $purchaseOrder->load(['items', 'supplier', 'deliveries.preparer']);

        // Deactivated suppliers stay selectable on the PO they are already
        // attached to, but cannot be newly chosen.
        $suppliers = Supplier::active()->orderBy('company_name')->orderBy('location')->get();
        if ($purchaseOrder->supplier && ! $suppliers->contains('id', $purchaseOrder->supplier_id)) {
            $suppliers->push($purchaseOrder->supplier);
        }

        return view('wetstock.stock-orders.create-po', [
            'suppliers' => $suppliers,
            'purchaseOrder' => $purchaseOrder,
            'isEdit' => true,
        ]);
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
            // The manual form only raises Doyen Stock. SO-FT and SO-BUY BACK
            // originate from the Sales Orders page instead.
            'date_needed' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'attention' => ['nullable', 'string', 'max:128'],
            'terms' => ['nullable', 'string', 'max:128'],
            'po_date' => ['nullable', 'date'],
            'vatable_sales_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'less_w_tax' => ['nullable', 'numeric', 'min:0'],
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

        // Net Payable is always the gross less the creditable withholding tax.
        // VAT already sits inside the line total, so it is not subtracted. The
        // field is read-only in the browser and never trusted from the POST.
        $lessWTax = round((float) ($validated['less_w_tax'] ?? 0), 2);
        $netPayable = max(0, round($lineTotal - $lessWTax, 2));

        $po = PurchaseOrder::create([
            'po_number' => $validated['po_number'],
            'po_type' => 'STANDARD_REPLENISHMENT',
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
            // A new PO waits on the VP. RECEIVED is this codebase's "pending VP
            // approval" state and is what the Approvals queue filters on, so
            // saving here is what puts the PO in front of the VP.
            'request_status' => 'RECEIVED',
            'status' => 'Pending',
            'requested_by' => Auth::id(),
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

        return redirect()->route('stock-orders.purchase-orders.index')
            ->with('success', "Supplier PO #{$po->po_number} created and submitted for VP approval with " . count($validated['items']) . " product line(s).");
    }

    /**
     * Save an edit to an existing supplier PO.
     *
     * Three rules keep an edit from corrupting the record:
     *  - the PO type is fixed, because it also says which page raised the PO;
     *  - volume already drawn by ATLs can never be reduced or removed;
     *  - an edit to a VP-approved PO sends it back for re-approval rather than
     *    quietly changing an approved commitment.
     */
    public function updateSupplierPo(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        // The same lock as editSupplierPo, checked here too because this is the
        // route a crafted POST would hit directly. It used to deliberately allow
        // the edit and return the PO to RECEIVED for re-approval, which silently
        // un-approved a document ATLs had already been drawn from.
        if ($purchaseOrder->isVpApproved()) {
            abort(403, 'An approved purchase order can no longer be edited. Raise a new PO for any change.');
        }

        $drawnByProduct = $purchaseOrder->drawnByProduct();

        $rules = [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'date_needed' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'attention' => ['nullable', 'string', 'max:128'],
            'terms' => ['nullable', 'string', 'max:128'],
            'po_date' => ['nullable', 'date'],
            'vatable_sales_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'less_w_tax' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product' => ['required', 'string', 'in:Diesel,Premium,Unleaded'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['nullable', 'string', 'max:16'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            // The trailing id excludes the PO being edited, so re-saving it with
            // its own number still works.
            'po_number' => [
                'required', 'string', 'max:64',
                Rule::unique('purchase_orders', 'po_number')->ignore($purchaseOrder->id),
            ],
        ];

        $validated = $request->validate($rules);

        // Collapse the submitted lines to one row per product.
        $lines = [];
        foreach ($validated['items'] as $line) {
            $product = $line['product'];
            $quantity = (int) $line['quantity'];
            $lines[$product] = [
                'quantity' => ($lines[$product]['quantity'] ?? 0) + $quantity,
                'unit_price' => $line['unit_price'] ?? null,
            ];
        }

        // A line that has already been drawn against cannot shrink or vanish.
        foreach ($drawnByProduct as $product => $drawn) {
            if (! isset($lines[$product])) {
                return back()
                    ->withInput()
                    ->withErrors(["items" => "{$product} cannot be removed: {$drawn} L has already been drawn from this PO."])
                    ->with('error', "Cannot remove {$product} because ATLs have already drawn from it.");
            }

            if ($lines[$product]['quantity'] < $drawn) {
                return back()
                    ->withInput()
                    ->withErrors(["items" => "{$product} cannot drop below the {$drawn} L already drawn from this PO."])
                    ->with('error', "{$product} has {$drawn} L already drawn, so its quantity cannot go lower.");
            }
        }

        $totalQty = array_sum(array_column($lines, 'quantity'));

        $lineTotal = round(collect($lines)->sum(
            fn (array $l) => $l['quantity'] * (float) ($l['unit_price'] ?? 0)
        ), 2);

        $lessWTax = round((float) ($validated['less_w_tax'] ?? 0), 2);
        $netPayable = max(0, round($lineTotal - $lessWTax, 2));

        $supplier = Supplier::find($validated['supplier_id']);

        $attributes = [
            'supplier_id' => $supplier?->id,
            'supplier_name' => $supplier?->company_name,
            'attention' => $validated['attention'] ?? $supplier?->attention,
            'terms' => $validated['terms'] ?? null,
            'po_date' => $validated['po_date'] ?? $purchaseOrder->po_date,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'qty_ordered' => $totalQty,
            'total_amount' => $lineTotal,
            'vatable_sales_amount' => $validated['vatable_sales_amount'] ?? 0,
            'vat_amount' => $validated['vat_amount'] ?? 0,
            'less_w_tax' => $validated['less_w_tax'] ?? 0,
            'net_payable_amount' => $netPayable,
            'date_needed' => $validated['date_needed'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
        ];

        $attributes['po_number'] = $validated['po_number'];

        $purchaseOrder->update($attributes);

        $this->syncSupplierPoItems($purchaseOrder, $lines);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => "Purchasing edited PO #{$purchaseOrder->po_number}.",
        ]);

        return redirect()->route('stock-orders.purchase-orders.show', $purchaseOrder->id)
            ->with('success', "Supplier PO #{$purchaseOrder->po_number} updated.");
    }

    /**
     * Bring the PO's product lines in line with the submitted set.
     *
     * Items that ATLs have already drawn against are updated in place rather
     * than deleted, so their allocations stay attached to the same row.
     *
     * @param  array<string, array{quantity: int, unit_price: mixed}>  $lines
     */
    protected function syncSupplierPoItems(PurchaseOrder $purchaseOrder, array $lines): void
    {
        $existing = $purchaseOrder->items()->get()->keyBy('product');

        foreach ($lines as $product => $line) {
            $item = $existing->get($product);

            if ($item) {
                $item->update([
                    'quantity_ordered' => $line['quantity'],
                    'unit_price' => $line['unit_price'] ?? null,
                ]);
            } else {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'product' => $product,
                    'quantity_ordered' => $line['quantity'],
                    'unit_price' => $line['unit_price'] ?? null,
                ]);
            }

            $existing->forget($product);
        }

        // Whatever is left has already been checked as safe to remove.
        foreach ($existing as $item) {
            $item->delete();
        }
    }

    public function createFromSalesOrder(Order $order): View|RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        if (!$order->canBeIssuedAtl()) {
            return redirect()->route('stock-orders.purchase-orders.index')
                ->with('error', "Cannot issue ATL: Sales Order {$order->formatted_so_number} has not been cleared by Accounting (Status: {$order->clearing_status}).");
        }

        // The supervisor's rule: the dropdown lists POs that still have
        // remaining quantity. See PurchaseOrder::availableForAllocation().
        $availablePos = PurchaseOrder::availableForAllocation();

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

    /**
     * Refuse an ATL that would take the order past what it asked for.
     *
     * Several ATLs may be raised against one order, so the check runs against
     * this ATL plus every earlier one against the same order. It is one method
     * because every route that can attribute volume to an order has to apply
     * it; leaving a second route unchecked lets the order be over-drawn from
     * whichever screen a user happens to start from.
     *
     * @param  iterable<string, int>  $drawnNow  volume this ATL draws, per product
     * @param  array<string, int>  $requirements  what the order asks for, per product
     * @return RedirectResponse|null null when the ATL is within the order
     */
    private function guardOrderNotOverDrawn(
        Order $order,
        iterable $drawnNow,
        array $requirements,
        ?string $field = 'drawdowns'
    ): ?RedirectResponse {
        $alreadyDrawn = $order->drawnByProduct();

        foreach ($requirements as $product => $requiredQty) {
            $drawnQty = (int) ($drawnNow[$product] ?? 0);
            $previous = (int) ($alreadyDrawn[$product] ?? 0);
            $cumulative = $drawnQty + $previous;

            if ($cumulative <= $requiredQty) {
                continue;
            }

            return back()->withInput()->withErrors([
                $field => $previous > 0
                    ? 'This ATL draws '.number_format($drawnQty)." L of {$product}, which with the "
                        .number_format($previous).' L already ATL\'d would take the total to '
                        .number_format($cumulative).' L, over the Sales Order requirement ('
                        .number_format($requiredQty).' L).'
                    : 'This Sales Order only requires '.number_format($requiredQty)
                        ." L of {$product}, but ".number_format($cumulative).' L was drawn.',
            ]);
        }

        return null;
    }

    /**
     * Refuse a split ATL whose PO rows do not all pair on one supplier at one
     * loading location.
     *
     * A lifted tanker is loaded at a single refinery rack, so cross-allocation
     * - mixing suppliers, or one supplier across two terminals - is not
     * something the system may save and later print on a single ATL. Splitting
     * across POs of that same pairing stays legal: no single PO has to hold
     * the whole volume on its own.
     *
     * @param  \Illuminate\Support\Collection<int, array{purchase_order_id: mixed, product: mixed, quantity: mixed}>  $drawdowns
     */
    private function guardSameRackDrawdowns(\Illuminate\Support\Collection $drawdowns): ?RedirectResponse
    {
        $pos = PurchaseOrder::with('supplier')
            ->whereIn('id', $drawdowns->pluck('purchase_order_id'))
            ->get()
            ->keyBy('id');

        $primary = $pos->get($drawdowns->first()['purchase_order_id']);

        foreach ($drawdowns->skip(1) as $row) {
            $po = $pos->get($row['purchase_order_id']);

            if ($po && $primary && $po->allocationKey() !== $primary->allocationKey()) {
                return back()->withInput()->withErrors([
                    'drawdowns' => "Cross-allocation is not allowed: PO #{$po->po_number} is not the same supplier and "
                        ."loading location as PO #{$primary->po_number}. One lift draws at one rack - "
                        .'split this into one ATL per supplier visit.',
                ]);
            }
        }

        return null;
    }

    public function storeFuelTradePo(Request $request, Order $order): RedirectResponse
    {
        if (!Auth::user()->canEditStockOrders()) {
            abort(403);
        }

        $isClientProvided = $request->input('atl_source') === PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED;

        if (!$order->canPrepareAtl()) {
            return redirect()->route('stock-orders.purchase-orders.index')
                ->with('error', "Cannot issue ATL: Sales Order {$order->formatted_so_number} is not open for ATL preparation.");
        }

        // Every Doyen-issued ATL now goes straight to the VP queue, so there is no
        // longer a draft to fall back on. That makes Accounting's clearance the
        // gate on issuing an ATL at all rather than only on submitting one, and
        // the message names that blocker instead of pointing at a draft option
        // that no longer exists.
        if (!$isClientProvided && $order->clearing_status !== 'Approved') {
            return back()->withInput()->withErrors([
                'atl_source' => "Cannot issue ATL: Sales Order {$order->formatted_so_number} has not been cleared by Accounting (Status: {$order->clearing_status}). Every ATL now goes to the VP for approval, so the order must be cleared first.",
            ]);
        }

        // The product used when a drawdown row does not name one. Left to the
        // order when the form did not say, so a legacy order allocates against
        // the product it is really for rather than always Diesel.
        $defaultProduct = $request->input('product') ?: $order->resolvedProduct();

        if (! in_array($defaultProduct, PurchaseOrder::PRODUCTS, true)) {
            $defaultProduct = 'Diesel';
        }

        // What this Sales Order requires, per product. Orders created before
        // multi-product lines existed have no lines, so they fall back to a
        // single requirement equal to the order's whole ordered volume.
        //
        // Computed before the drawdown branch because the direct-PO fallback
        // below needs it too, and that branch runs precisely when the drawdown
        // block is skipped.
        $requirements = $this->salesOrderProductRequirements($order, $defaultProduct);

        // Drawdown engine submission (multi-PO or single-PO drawdown)
        if ($request->has('drawdowns') && is_array($request->input('drawdowns'))) {
            $validated = $request->validate([
                'product' => ['nullable', 'string', 'max:64'],
                'atl_source' => ['nullable', 'in:DOYEN_ISSUED,CLIENT_PROVIDED'],
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

            // 1b. Per product, this ATL plus every earlier one against the same
            // order must not overshoot what the order asked for.
            //
            // The rule used to demand that a single ATL exactly matched the
            // order, which made a second ATL impossible. The supervisor asked
            // for several per order, so the check runs against the running
            // total instead: an ATL may be a partial draw, but the order as a
            // whole can never be over-drawn.
            if ($refusal = $this->guardOrderNotOverDrawn($order, $drawnByProduct, $requirements)) {
                return $refusal;
            }

            // 2. One load, one rack: a lifted tanker is loaded at a single
            // refinery terminal, so every row must name the same supplier
            // AND the same loading location as the first row.
            if ($refusal = $this->guardSameRackDrawdowns($drawdowns)) {
                return $refusal;
            }

            // 3. Verify each PO has sufficient remaining balance for that product
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
                'atl_category' => $order->atlCategory(),
                'atl_source' => $isClientProvided
                    ? PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED
                    : PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
                // Client-provided ATLs are recorded only, so there is nothing on
                // them for the VP to approve. Every Doyen-issued ATL enters the
                // queue the moment it is created.
                'approval_status' => $isClientProvided
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

            $outcome = $isClientProvided
                ? 'recorded (client-provided, no VP approval needed)'
                : 'sent to the VP for approval';

            return redirect()->route('stock-orders.sales-orders.show', $order->id)
                ->with('success', "Authority to Load (ATL) #{$reference} {$outcome} and linked to Supplier PO(s).");
        }

        // Direct PO creation fallback
        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:64'],
            'supplier_name' => ['required', 'string', 'max:128'],
            'atl_source' => ['nullable', 'in:DOYEN_ISSUED,CLIENT_PROVIDED'],
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

        // This branch attributes volume to the order just as the drawdown branch
        // does, so it carries the same cap. Reusing an existing PO by typing its
        // number is also a way to reach it, so that PO's balance is checked too.
        if ($refusal = $this->guardOrderNotOverDrawn(
            $order,
            [$validated['product'] => (int) $validated['qty_to_receive']],
            $requirements
        )) {
            return $refusal;
        }

        if ($po) {
            $balance = $po->getAvailableBalanceForProduct($validated['product']);

            if ($balance < (int) $validated['qty_to_receive']) {
                return back()->withInput()->withErrors([
                    'po_number' => "PO {$po->po_number} only has ".number_format($balance)
                        ." L of {$validated['product']} left, not "
                        .number_format($validated['qty_to_receive']).' L.',
                ]);
            }
        }

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
            'atl_category' => $order->atlCategory(),
            'atl_source' => $isClientProvided
                ? PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED
                : PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            'approval_status' => $isClientProvided
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
            // Linked to the item so the PO's remaining balance for this product
            // accounts for the drawdown.
            'purchase_order_item_id' => $po->items()->where('product', $validated['product'])->value('id'),
            'product' => $validated['product'],
            'quantity' => $validated['qty_to_receive'],
        ]);

        $order->update(['linked_purchase_order_id' => $po->id]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'created',
            'description' => "Purchasing created Fuel Trade PO #{$po->po_number} linked to SO #{$order->so_number} for {$order->account}",
        ]);

        return redirect()->route('stock-orders.purchase-orders.index')
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

        return $this->performAtlLift(
            $delivery,
            $validated,
            isset($validated['storage_tank_id']) ? (int) $validated['storage_tank_id'] : null
        );
    }

    /**
     * The lift pipeline the two lift entrances share.
     *
     * complete-fuel-trade arrives from the confirm modal with the supplier
     * document serials and, for a tanker pick-up, the tanker to auto-stock
     * into; the records table's lift-status control arrives without either
     * for records that already carry their truck. Both landing here is what
     * keeps the modal's answer and the control's answer from drifting apart.
     *
     * @param  array<string, mixed>  $docFields
     */
    private function performAtlLift(PurchaseOrderDelivery $delivery, array $docFields, ?int $tankId): RedirectResponse
    {
        // Older Fuel Trade rows predate approval_status. Keep the existing
        // direct complete-fuel-trade endpoint compatible with those records;
        // explicit non-approved states still cannot be lifted.
        if (filled($delivery->approval_status) && ! $delivery->isApproved()) {
            return back()->with('error', 'Only an approved ATL can be marked Lifted.');
        }

        if ($delivery->received_at) {
            return back()->with('error', 'This ATL has already been received and cannot be lifted again.');
        }

        if (! in_array($delivery->status, ['Pending', 'Active', 'Cancelled'], true)) {
            return back()->with('error', 'Only a pending, active, or cancelled ATL can be marked Lifted.');
        }

        // A cancelled ATL is reversible. Restore its live state before running
        // the normal lift pipeline, so its allocation is counted again.
        if ($delivery->status === 'Cancelled') {
            $delivery->update([
                'status' => 'Active',
                'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            ]);
            $delivery->refresh();
        }

        $updateData = [];
        if (!empty($docFields['supplier_dr_number'])) {
            $updateData['supplier_dr_number'] = $docFields['supplier_dr_number'];
        }
        if (!empty($docFields['supplier_so_number'])) {
            $updateData['supplier_so_number'] = $docFields['supplier_so_number'];
        }
        if (!empty($docFields['scanned_doc_url'])) {
            $updateData['scanned_doc_url'] = $docFields['scanned_doc_url'];
        }

        $tankId = $tankId ?: ($delivery->storage_tank_id ? (int) $delivery->storage_tank_id : null);

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

    /**
     * Reverse a lift that has been recorded on an ATL.
     *
     * A lift is a journey: it completes the PO, fulfils the Sales Order,
     * may auto-stock a tanker, and writes the delivery mirror Module 1
     * derives its Delivered and Remaining figures from. A mistaken lift is
     * therefore wrong on four screens until it is unwound as a whole - this
     * method does the whole unwind rather than flipping one column, so
     * M3's records and M1's dashboards stay the same statement.
     */
    public function unliftAtl(PurchaseOrderDelivery $delivery): RedirectResponse
    {
        // Same allowlist as the lift: Purchasing and Admin only.
        if (!Auth::user()->canMarkAtlLifted()) {
            abort(403, 'Unauthorized to reverse an ATL lift.');
        }

        return $this->performAtlUnlift($delivery);
    }

    /**
     * The reversal pipeline the two unlift entrances share.
     *
     * The unlift route and the records table's lift-status control both land
     * here - the seam exists so the control's Unlifted choice and the legacy
     * route cannot reverse differently.
     */
    private function performAtlUnlift(PurchaseOrderDelivery $delivery): RedirectResponse
    {
        if ($delivery->lift_status !== PurchaseOrderDelivery::LIFT_LIFTED) {
            return back()->with('error', 'Only a lifted ATL can be marked Unlifted.');
        }

        // Stock that entered through the receive flow carries wet-stock
        // ledgers of its own; this route unwinds only what the lift writes.
        if ($delivery->received_at) {
            return back()->with('error', 'This ATL has been received into stock, so it cannot be unlifted here.');
        }

        $order = $delivery->order ?: $delivery->purchaseOrder?->linkedOrder;

        // An ATL that was lifted and then unwound is back in flight: approved
        // ones resume at Active, anything still awaiting approval resumes at
        // Pending. lifted_at/by were the record of the lift, so they go blank.
        $delivery->update([
            'status' => $delivery->approval_status === PurchaseOrderDelivery::APPROVAL_APPROVED
                ? 'Active'
                : 'Pending',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
            'lifted_at' => null,
            'lifted_by' => null,
        ]);

        // Any stock the lift stocked into a tanker is unrecorded with it. The
        // receive-into-stock path is refused above, so whatever is here was
        // written by the lift's auto StockIn.
        StockIn::where('purchase_order_delivery_id', $delivery->id)->delete();

        // The PO keeps its completed state while another lifted ATL still
        // draws from it. Otherwise it returns to in-flight - FOR_DELIVERY,
        // the state it sits in after dispatch, from where it can be lifted
        // again. It does not fall back to CONFIRMED: a lifted-and-reverted
        // ATL was dispatched, and pretending otherwise would hide it from the
        // boards that watch in-flight volume.
        $po = $delivery->purchaseOrder;
        if ($po && !$po->deliveries()
            ->where('lift_status', PurchaseOrderDelivery::LIFT_LIFTED)
            ->exists()
        ) {
            $po->update([
                'request_status' => 'FOR_DELIVERY',
                'status' => 'For Delivery',
            ]);
        }

        // The order only stops being Fulfilled when nothing on it is lifted
        // anymore - reversing one of two lifted ATLs must not un-fulfil the
        // half the client genuinely picked up.
        if ($order && ! $order->hasLiftedAtl()) {
            $order->update(['status' => 'Active']);
        }

        // Module 1 counts delivered from its own delivery rows, so the mirror
        // row the lift wrote is what makes its Remaining balance fall back.
        if ($order) {
            $mirror = $this->module1DeliveryFor($delivery, $order);
            $mirror?->delete();
        }

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => 'Reversed the lift of ATL '
                .($delivery->atl_number ?: ($delivery->client_atl_number ?: '#'.$delivery->id))
                .($order ? " for SO {$order->formatted_so_number}" : '')
                .': ATL back to Unlifted'
                .($po ? '; PO '.$po->po_number.' back to '.($po->fresh()->request_status) : '')
                .($order ? '; Sales Order now '.($order->fresh()->status).'; Module 1 mirror removed.' : '.'),
        ]);

        return back()->with('success', 'ATL '
            .($delivery->atl_number ?: ($delivery->client_atl_number ?: '#'.$delivery->id))
            .' is marked Unlifted. The lift records and the Module 1 mirror have been reversed.');
    }

    /**
     * The lift-status control on the ATL records table.
     *
     * One select per row replaces the round-5 Mark Picked Up / Mark Unlifted
     * buttons: Lifted runs the same pipeline the confirm modal's route runs,
     * Unlifted runs the same reversal the unlift route runs, and Cancelled
     * retires a record the supplier will never load - its draw releases back
     * to the order the same way a rejected ATL releases its volume.
     *
     * A tanker pick-up that has not been assigned a truck never lifts from
     * here: the control marks its Lifted choice with data-requires-modal and
     * the confirm modal (still posting to complete-fuel-trade) asks for the
     * tanker first.
     */
    public function updateLiftStatus(Request $request, PurchaseOrderDelivery $delivery): RedirectResponse
    {
        // Same allowlist as the two routes this one fronts for.
        if (!Auth::user()->canMarkAtlLifted()) {
            abort(403, 'Unauthorized to change an ATL lift status.');
        }

        $validated = $request->validate([
            'lift_status' => ['required', 'string', 'in:'
                .PurchaseOrderDelivery::LIFT_UNLIFTED.','
                .PurchaseOrderDelivery::LIFT_LIFTED.','
                .PurchaseOrderDelivery::LIFT_CANCELLED],
        ]);

        return match ($validated['lift_status']) {
            PurchaseOrderDelivery::LIFT_LIFTED => $delivery->lift_status === PurchaseOrderDelivery::LIFT_LIFTED
                ? back()->with('error', 'This ATL is already marked Lifted.')
                : $this->performAtlLift($delivery, [], $delivery->storage_tank_id ? (int) $delivery->storage_tank_id : null),
            PurchaseOrderDelivery::LIFT_UNLIFTED => $delivery->lift_status === PurchaseOrderDelivery::LIFT_CANCELLED
                ? $this->restoreCancelledAtl($delivery)
                : $this->performAtlUnlift($delivery),
            default => $this->performAtlCancel($delivery),
        };
    }

    /**
     * Restore a cancelled, not-yet-lifted ATL to its live pre-cancellation state.
     */
    private function restoreCancelledAtl(PurchaseOrderDelivery $delivery): RedirectResponse
    {
        if ($delivery->received_at) {
            return back()->with('error', 'This ATL has been received and cannot be restored.');
        }

        $delivery->update([
            'status' => $delivery->isApproved() ? 'Active' : 'Pending',
            'lift_status' => PurchaseOrderDelivery::LIFT_UNLIFTED,
        ]);

        $label = $delivery->atl_number ?: ($delivery->client_atl_number ?: '#'.$delivery->id);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => "Restored cancelled ATL {$label}: its volume counts against the Sales Order again.",
        ]);

        return back()->with('success', "ATL {$label} is restored to Unlifted. Its volume counts against the remaining balance again.");
    }

    /**
     * Retire an unlifted ATL - the record is not going to happen.
     *
     * The draw it made releases back to the order because the row stops
     * being an Active draw (the same release a rejected ATL gets on
     * Order::drawnByProduct). The PO is left in flight, free for another ATL
     * to be raised against. A lifted record must be reversed first, because
     * its lift already moved real stock; a received one carries ledgers, so
     * cancelling it is refused outright.
     */
    private function performAtlCancel(PurchaseOrderDelivery $delivery): RedirectResponse
    {
        $label = $delivery->atl_number ?: ($delivery->client_atl_number ?: '#'.$delivery->id);
        $order = $delivery->order ?: $delivery->purchaseOrder?->linkedOrder;

        if ($delivery->lift_status === PurchaseOrderDelivery::LIFT_LIFTED) {
            return back()->with('error', "ATL {$label} is lifted - mark it Unlifted before cancelling it.");
        }

        if ($delivery->received_at) {
            return back()->with('error', 'This ATL has been received into stock, so it cannot be cancelled.');
        }

        if ($delivery->lift_status === PurchaseOrderDelivery::LIFT_CANCELLED) {
            return back()->with('error', "ATL {$label} is already cancelled.");
        }

        $delivery->update([
            'lift_status' => PurchaseOrderDelivery::LIFT_CANCELLED,
            'status' => 'Cancelled',
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'updated',
            'description' => 'Cancelled ATL '.$label
                .($order ? ' for SO '.$order->formatted_so_number : '')
                .': its draw was released back to the Sales Order.',
        ]);

        return back()->with('success', "ATL {$label} is marked Cancelled. The volume it drew is back on the Sales Order's remaining balance.");
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
            // A PO number identifies one PO. The trailing id excludes the PO
            // being edited, so re-saving it with its own number still works.
            'po_number' => [
                'required',
                'string',
                'max:64',
                Rule::unique('purchase_orders', 'po_number')->ignore($purchaseOrder->id),
            ],
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

        return redirect()->route('stock-orders.purchase-orders.index')
            ->with('success', "Stock order {$purchaseOrder->po_number} prepared and submitted for VP approval.");
    }

/**
 * Prepare a Module 2 stock request into a purchase order.
 *
 * A request has no PO to allocate from, so the two steps are split: this page
 * creates the PO from the request, and the Allocate flow then draws the
 * delivery from that PO.
 *
 * Official PO Number is a dropdown of the requests still awaiting a number
 * rather than free text, so it can never be typed wrong or clash.
 */
public function editStockRequest(Request $request): View
{
    if (!Auth::user()->canEditStockOrders()) {
        abort(403);
    }

    $unnumbered = StockRequest::open()
        ->with(['lines', 'warehouse', 'requester'])
        ->latest()
        ->get();

    // Reaching the page from a request in the queue pre-selects it.
    $selected = null;
    if ($request->filled('stock_request')) {
        $selected = StockRequest::open()
            ->with(['lines', 'warehouse', 'requester'])
            ->findOrFail((int) $request->query('stock_request'));
    }

    return view('wetstock.stock-orders.prepare', [
        'unnumberedRequests' => $unnumbered,
        'selected' => $selected,
        'warehouses' => Warehouse::orderBy('name')->get(),
    ]);
}

/**
 * Create the purchase order for the chosen stock request.
 */
public function storeStockRequestPo(Request $request): RedirectResponse
{
    if (!Auth::user()->canEditStockOrders()) {
        abort(403);
    }

    $validated = $request->validate([
        'stock_request_id' => ['required', 'integer', 'exists:stock_requests,id'],
        // A PO number identifies one PO, and it is chosen from the dropdown
        // rather than typed.
        'po_number' => [
            'required',
            'string',
            'max:64',
            Rule::unique('purchase_orders', 'po_number'),
        ],
        'supplier_name' => ['required', 'string', 'max:128'],
        'attention' => ['nullable', 'string', 'max:128'],
        'terms' => ['nullable', 'string', 'max:128'],
        'po_date' => ['nullable', 'date'],
        'remarks' => ['nullable', 'string', 'max:500'],
    ]);

    $stockRequest = StockRequest::with('lines')->findOrFail($validated['stock_request_id']);

    // Guarded here as well as by the dropdown, so a stale page or a crafted
    // POST cannot raise a second PO against the same request.
    if ($stockRequest->isPrepared()) {
        return back()->with('danger', "Request {$stockRequest->request_number} already has a purchase order against it.");
    }

    $supplier = Supplier::where('company_name', $validated['supplier_name'])->first();

    $purchaseOrder = PurchaseOrder::create([
        'po_number' => $validated['po_number'],
        'po_type' => PurchaseOrder::TYPE_STANDARD_REPLENISHMENT,
        'supplier_id' => $supplier?->id,
        'supplier_name' => $validated['supplier_name'],
        'attention' => $validated['attention'] ?? null,
        'terms' => $validated['terms'] ?? null,
        'po_date' => $validated['po_date'] ?? now()->toDateString(),
        'warehouse_id' => $stockRequest->warehouse_id,
        'qty_ordered' => $stockRequest->qty_requested,
        'request_status' => 'RECEIVED',
        'status' => 'Pending',
        'requested_by' => $stockRequest->requested_by,
        'request_date' => $stockRequest->request_date,
        'date_needed' => $stockRequest->date_needed,
        'remarks' => $validated['remarks'] ?? $stockRequest->remarks,
        'requested_products' => $stockRequest->lines
            ->map(fn ($line) => ['product' => $line->product, 'quantity' => (int) $line->qty_requested])
            ->all(),
        'prepared_by' => Auth::id(),
    ]);

    foreach ($stockRequest->lines as $line) {
        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'product' => $line->product,
            'quantity_ordered' => (int) $line->qty_requested,
            'unit' => $line->unit,
        ]);
    }

    $stockRequest->update([
        'status' => StockRequest::STATUS_RECEIVED,
        'purchase_order_id' => $purchaseOrder->id,
    ]);

    AuditLog::create([
        'admin_id' => Auth::id(),
        'action' => 'updated',
        'description' => "Prepared stock request {$stockRequest->request_number} into purchase order {$purchaseOrder->po_number}.",
    ]);

    return redirect()->route('stock-orders.purchase-orders.show', $purchaseOrder->id)
        ->with('success', "Purchase order {$purchaseOrder->po_number} created from request {$stockRequest->request_number} and sent for VP approval.");
}

/**
 * Raise an extra ATL against a PO the VP has already approved.
 *
     * This is the repeatable per-truck action that moved off the Purchase
     * Orders list. Unlike updateRequest it never re-opens the approval flow,
     * so the PO's own request_status is left exactly as it was.
     */
    public function createAtl(PurchaseOrder $purchaseOrder): View
    {
        abort_unless(Auth::user()->canEditStockOrders(), 403);
        abort_unless(
            $purchaseOrder->isVpApproved(),
            403,
            'An ATL can only be raised against a purchase order the VP has approved.'
        );

        // No allocation pool here on purpose. This action raises another ATL
        // against the one PO in the route, and storeAtl() records a single
        // product and quantity against it. The multi-PO Allocate table belongs
        // to the Fuel Trade path, where the order chooses which POs to draw on.
        return view('wetstock.stock-orders.form', [
            'mode' => 'add_atl',
            'purchaseOrder' => $purchaseOrder,
            'order' => null,
            'clients' => Client::orderBy('name')->get(),
            'warehouses' => Warehouse::orderBy('name')->get(),
        ]);
    }

    public function storeAtl(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless(Auth::user()->canEditStockOrders(), 403);
        abort_unless(
            $purchaseOrder->isVpApproved(),
            403,
            'An ATL can only be raised against a purchase order the VP has approved.'
        );

        $validated = $request->validate([
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

        // The ATL cannot ask for more than the PO still owes.
        $remaining = $purchaseOrder->getAvailableBalanceForProduct($validated['product']);
        if ((int) $validated['qty_to_receive'] > $remaining) {
            return back()->withErrors([
                'qty_to_receive' => "Only {$remaining} L remain on this PO for {$validated['product']}.",
            ])->with('error', 'That ATL would draw more than the PO has remaining.');
        }

        // The form decides client-provided by whether a client ATL number was
        // typed, which is also what sets atl_type below. Recording the same
        // decision in atl_source is what keeps this ATL out of, or in, the VP's
        // queue on the same terms as every other ATL path.
        $isClientProvided = filled($validated['client_atl_number'] ?? null);

        $delivery = PurchaseOrderDelivery::create([
            'purchase_order_id' => $purchaseOrder->id,
            'delivery_channel' => $validated['delivery_channel'],
            'order_type' => $validated['order_type'],
            'atl_type' => $isClientProvided ? 'CLIENT_ATL' : ($validated['order_type'] === 'PICK_UP' ? 'DITC_ATL' : 'NONE'),
            'atl_source' => $isClientProvided
                ? PurchaseOrderDelivery::SOURCE_CLIENT_PROVIDED
                : PurchaseOrderDelivery::SOURCE_DOYEN_ISSUED,
            // Previously this method set neither column, so approval_status landed
            // null. The queue matches DRAFT or FOR_APPROVAL, so every ATL raised
            // this way was invisible to the VP and could never be signed off.
            'approval_status' => $isClientProvided
                ? PurchaseOrderDelivery::APPROVAL_DRAFT
                : PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
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

        // Record which PO line this ATL drew from, so the detail page can
        // report the ATL'd volume per product.
        if ($item = $purchaseOrder->items()->where('product', $validated['product'])->first()) {
            AtlAllocation::create([
                'purchase_order_delivery_id' => $delivery->id,
                'purchase_order_id' => $purchaseOrder->id,
                'purchase_order_item_id' => $item->id,
                'product' => $validated['product'],
                'quantity' => (int) $validated['qty_to_receive'],
            ]);
        }

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'created',
            'description' => "Purchasing raised ATL " . ($validated['atl_number'] ?? '(unnumbered)') . " against PO #{$purchaseOrder->po_number}.",
        ]);

        return redirect()->route('stock-orders.purchase-orders.show', $purchaseOrder->id)
            ->with('success', "ATL added to PO #{$purchaseOrder->po_number}.");
    }

    public function approvals(Request $request): View
    {
        // Anyone who can reach Module 3 may read the queue, because M2 is
        // connected to stock and its operators need to see what is waiting on
        // the VP. Approving is a separate question and stays with the VP: see
        // canApproveStockOrders() on approve() and reject().
        abort_unless(Auth::user()->canViewStockApprovals(), 403);

        // Two queues: Purchase Orders waiting on the VP, and ATLs waiting on
        // the VP. The PO tab stays the default so the existing link is
        // unchanged.
        $tab = $request->input('tab', 'pos');
        if (!in_array($tab, ['pos', 'atls'], true)) {
            $tab = 'pos';
        }

        $pendingOrders = PurchaseOrder::with(['warehouse', 'client', 'requester', 'deliveries'])
            ->pendingVpApproval()
            ->latest()
            ->paginate(15);

        // Every Doyen-issued ATL the VP has not yet ruled on: a draft still waiting
        // on its author is the VP's to see and to approve or send back. It used to
        // be the narrower FOR_APPROVAL only, and filtered on the sales order being
        // cleared by Accounting - which meant an ATL raised for an uncleared order,
        // or against a stock request rather than a sales order, never appeared at
        // all. Both queries below are shared with the sidebar badge so the number
        // on the badge is exactly what this page lists.
        $pendingAtls = PurchaseOrderDelivery::query()
            ->pendingVpApproval()
            ->with(['order', 'purchaseOrder', 'stockRequest', 'allocations'])
            ->latest()
            ->paginate(15);

        $counts = [
            'pos' => PurchaseOrder::pendingVpApproval()->count(),
            'atls' => PurchaseOrderDelivery::pendingVpApproval()->count(),
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

        // Anything the queue lists is the VP's to rule on, drafts included.
        if (! in_array($delivery->approval_status, [
            PurchaseOrderDelivery::APPROVAL_DRAFT,
            PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ], true)) {
            return back()->with('error', 'Only ATLs awaiting approval can be approved.');
        }

        // Approval is the moment the record becomes official, and the official
        // PDF prints the ATL number - a DITC-issued ATL may not be approved
        // while DITC has not yet given it one. The number is recorded on the
        // ATL itself the moment DITC issues it, and approving lifts then.
        // Client-provided rows carried their number at creation, NONE-type
        // rows never carry one.
        if ($delivery->atl_type === 'DITC_ATL' && trim((string) $delivery->atl_number) === '') {
            return back()->with('error', 'This DITC-issued ATL has no ATL Number yet - record it on the ATL (Pen) before approving.');
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

        if (! in_array($delivery->approval_status, [
            PurchaseOrderDelivery::APPROVAL_DRAFT,
            PurchaseOrderDelivery::APPROVAL_FOR_APPROVAL,
        ], true)) {
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
     * The Module 1 delivery row an ATL lift mirrors to.
     *
     * Keyed the way recordLiftInModule1 writes it and unliftAtl reverses it,
     * so both sides agree on which row belongs to which ATL: a repeated lift
     * updates one row instead of double counting, and an unlift deletes
     * exactly the row the lift wrote rather than any row with a similar
     * number. (An ATL with no number at all matches no row on either side -
     * the lift then writes fresh rows each pass, and the unlift has nothing
     * to remove; fixture rows and the real flow always set a number.)
     */
    private function module1DeliveryFor(PurchaseOrderDelivery $delivery, Order $order): ?Delivery
    {
        $atlNumber = $delivery->atl_number ?: $delivery->client_atl_number;

        return Delivery::where('order_id', $order->id)
            ->where('atl_number', $atlNumber)
            ->first();
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

        $existing = $this->module1DeliveryFor($delivery, $order);

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
        // The PO PDF is the signed commitment, so it is only released once
        // the VP has approved. Hiding the button is not enough -- this closes
        // the route itself for anyone who types the URL.
        abort_unless(
            $purchaseOrder->isVpApproved(),
            403,
            'The PO PDF is only available once the VP has approved this purchase order.'
        );

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
        ]);

        $this->useBrandStationery($pdf, 'portrait');

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

        // An ATL document is not released until the VP has approved the ATL. This
        // is its own check, not the PO's: an ATL raised against an approved PO was
        // still a document someone had to sign off, and the previous `elseif` let
        // any ATL on an approved PO skip the check entirely, so a draft or a
        // pending one could print itself.
        if ($delivery->approval_status !== PurchaseOrderDelivery::APPROVAL_APPROVED) {
            abort(403, 'The ATL PDF is released only after the VP has approved this ATL.');
        }

        // A PO that is still in the Approvals queue has not been signed off either,
        // so nothing may be drawn or printed from it while it waits there.
        if ($delivery->purchaseOrder && !$delivery->purchaseOrder->isVpApproved()) {
            abort(403, 'The ATL PDF is released only after the VP has approved this purchase order.');
        }

        $po = $delivery->purchaseOrder;

        $logoPath = public_path('images/logo_ims.png');
        $logoBase64 = file_exists($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
            : null;

        // The ATL follows the company's printed form, which is landscape.
        $pdf = Pdf::loadView('wetstock.stock-orders.atl-pdf', compact('delivery', 'po', 'logoBase64'));

        $this->useBrandStationery($pdf, 'landscape');

        $sanitizedName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $delivery->atl_number ?: ($delivery->reference_no ?: ('DEL-' . $delivery->id)));
        $filename = 'ATL_' . $sanitizedName . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * The paper both printed documents are set on: Montserrat, portrait for
     * the purchase order and landscape for the authority to load.
     *
     * dompdf only reads a font it can find inside its chroot, and it keeps the
     * copy it embeds plus the metrics it derives in the font cache. Both are
     * pointed at the project so the font files shipped in public/fonts are
     * usable and the cache lives in writable storage rather than in vendor.
     */
    private function useBrandStationery(\Barryvdh\DomPDF\PDF $pdf, string $orientation): void
    {
        $pdf->setOption('chroot', base_path());
        $pdf->setOption('fontDir', storage_path('fonts'));
        $pdf->setOption('fontCache', storage_path('fonts'));

        $faces = [
            ['400', 'normal', 'Montserrat-400.ttf'],
            ['400', 'italic', 'Montserrat-400-italic.ttf'],
            ['700', 'normal', 'Montserrat-700.ttf'],
            ['800', 'normal', 'Montserrat-800.ttf'],
        ];

        foreach ($faces as [$weight, $style, $file]) {
            $path = str_replace('\\', '/', public_path('fonts/montserrat/' . $file));

            $pdf->getFontMetrics()->registerFont(
                ['family' => 'Montserrat', 'style' => $style, 'weight' => $weight],
                'file://' . $path
            );
        }

        $pdf->setPaper('letter', $orientation);
    }
}
