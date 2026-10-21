<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ModificationRequest;
use App\Models\Order;
use App\Models\StockTransfer;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StockTransferController extends Controller
{
    /**
     * Display a listing of stock transfers separated by 3 tabs: Transfer, Borrow, Return.
     */
    public function index(Request $request): View
    {
        $activeType = $request->get('type', 'transfer');
        if (!in_array($activeType, ['transfer', 'borrow', 'return'])) {
            $activeType = 'transfer';
        }

        $now = now('Asia/Manila');
        $filterMonth = $request->get('filter_month');
        $filterYear = $request->get('filter_year');
        $showAll = $request->boolean('show_all');
        $filterMonthInt = $filterMonth ? (int) $filterMonth : (int) $now->format('m');
        $filterYearInt = $filterYear ? (int) $filterYear : (int) $now->format('Y');

        $baseQuery = StockTransfer::with([
            'sourceTank.warehouse',
            'destinationTank.warehouse',
            'sourceCompartment',
            'destinationCompartment',
            'sourceWarehouse',
            'destinationWarehouse',
            'transferredBy',
            'modificationRequests' => fn ($q) => $q->where('status', 'PENDING')->with('requestedBy:id,name'),
        ])->orderBy('transfer_date', 'desc')->orderBy('created_at', 'desc');

        // Monthly reset: default to current month unless user filters by date or requests all
        $hasCustomDateRange = $request->filled('from') || $request->filled('to');
        if (!$showAll && !$hasCustomDateRange) {
            $baseQuery->whereYear('transfer_date', $filterYearInt)->whereMonth('transfer_date', $filterMonthInt);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $baseQuery->where(function ($q) use ($search) {
                $q->where('transfer_number', 'like', "%{$search}%")
                  ->orWhereHas('sourceTank', fn($t) => $t->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('destinationTank', fn($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('warehouse_id')) {
            $whId = $request->warehouse_id;
            $baseQuery->where(function ($q) use ($whId) {
                $q->where('source_warehouse_id', $whId)
                  ->orWhere('destination_warehouse_id', $whId);
            });
        }

        if ($request->filled('from')) {
            $baseQuery->whereDate('transfer_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $baseQuery->whereDate('transfer_date', '<=', $request->to);
        }

        // Tab counts
        $transferCount = (clone $baseQuery)->where('type', 'transfer')->count();
        $borrowCount = (clone $baseQuery)->where('type', 'borrow')->count();
        $returnCount = (clone $baseQuery)->where('type', 'return')->count();

        // Paginated results for active tab
        $transfers = (clone $baseQuery)->where('type', $activeType)->paginate(20)->withQueryString();
        $warehouses = Warehouse::orderBy('name', 'asc')->get();
        $outstandingBalances = StockTransfer::getOutstandingBalances();
        $availableMonths = StockTransfer::selectRaw("DATE_FORMAT(transfer_date, '%Y-%m') as ym")
            ->distinct()->orderByDesc('ym')->pluck('ym')->filter()->values();

        // Data for the transfer pop-up (only people who can record transfers need it).
        $formWarehouses = collect();
        $tankMeta = [];
        $compData = [];
        $editConfig = null;

        if (Auth::user()->canEditModule2()) {
            $formWarehouses = Warehouse::with(['activeTanks.compartments'])->orderBy('name', 'asc')->get();
            $tankMeta = $this->tankMeta($formWarehouses);
            $compData = $this->compartmentData($formWarehouses);

            // Old /transfers/{id}/edit links land here and open the pop-up for that record.
            if ($request->filled('edit') && ($editing = StockTransfer::find((int) $request->edit))) {
                $editConfig = [
                    'mode' => 'edit',
                    'id' => $editing->id,
                    'number' => $editing->transfer_number,
                    'action' => route('wetstock.transfers.update', $editing->id),
                    'type' => $editing->type,
                    'source_tank_id' => $editing->source_tank_id,
                    'source_comp' => $editing->source_compartment_id,
                    'destination_tank_id' => $editing->destination_tank_id,
                    'dest_comp' => $editing->destination_compartment_id,
                    'quantity' => $editing->quantity,
                    'date' => $editing->transfer_date ? $editing->transfer_date->format('Y-m-d') : null,
                    'notes' => $editing->notes,
                ];
            }
        }

        return view('wetstock.transfers.index', [
            'formWarehouses' => $formWarehouses,
            'tankMeta' => $tankMeta,
            'compData' => $compData,
            'editConfig' => $editConfig,
            'transfers' => $transfers,
            'warehouses' => $warehouses,
            'outstandingBalances' => $outstandingBalances,
            'activeType' => $activeType,
            'transferCount' => $transferCount,
            'borrowCount' => $borrowCount,
            'returnCount' => $returnCount,
            'currentWarehouse' => $request->warehouse_id,
            'searchQuery' => $request->search,
            'from' => $request->from,
            'to' => $request->to,
            'filterMonth' => $filterMonthInt,
            'filterYear' => $filterYearInt,
            'showAll' => $showAll,
            'availableMonths' => $availableMonths,
            'now' => $now,
        ]);
    }

    /**
     * Recording a transfer happens in the pop-up on the list page. Old links to
     * /transfers/create land there with the pop-up open.
     */
    public function create(Request $request): RedirectResponse
    {
        $type = $request->get('type', 'transfer');
        if (!in_array($type, ['transfer', 'borrow', 'return'])) {
            $type = 'transfer';
        }

        return redirect()->route('wetstock.transfers.index', array_filter([
            'type' => $type,
            'open' => $type,
            'source_tank_id' => $request->source_tank_id,
            'destination_tank_id' => $request->destination_tank_id,
        ]));
    }

    /**
     * Store a newly executed stock transfer / borrow / return.
     */
    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:transfer,borrow,return'],
            'source_tank_id' => ['required', 'exists:storage_tanks,id', 'different:destination_tank_id'],
            'destination_tank_id' => ['required', 'exists:storage_tanks,id'],
            'source_compartment_id' => ['nullable', 'integer'],
            'destination_compartment_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $sourceTank = StorageTank::with('warehouse')->findOrFail($validated['source_tank_id']);
        $destinationTank = StorageTank::with('warehouse')->findOrFail($validated['destination_tank_id']);
        $quantity = (int) $validated['quantity'];
        $type = $validated['type'];

        // Warehouse Rule Validation per Tab
        if ($type === 'transfer') {
            if ($sourceTank->warehouse_id !== $destinationTank->warehouse_id) {
                return back()->withInput()->with('danger', "Validation Error: 'Transfer' is strictly for intra-site movements within the same warehouse. Source ({$sourceTank->warehouse->name}) and Destination ({$destinationTank->warehouse->name}) must be the same site. For cross-site fuel movements, please use the 'Borrow' or 'Return' tab.");
            }
        } else {
            // borrow or return
            if ($sourceTank->warehouse_id === $destinationTank->warehouse_id) {
                return back()->withInput()->with('danger', "Validation Error: '" . ucfirst($type) . "' is strictly for cross-site movements between different warehouses. Source ({$sourceTank->warehouse->name}) and Destination ({$destinationTank->warehouse->name}) cannot be the same site. For movements within the same site, please use the 'Transfer' tab.");
            }
        }

        // Safety Rule 0 (returns): cannot repay more than the outstanding borrowed balance
        if ($type === 'return') {
            $outstanding = StockTransfer::getOutstandingBalanceFor($destinationTank->warehouse_id);
            if ($quantity > $outstanding) {
                return back()->withInput()->with('danger', "Return Blocked: The outstanding borrowed balance for {$destinationTank->warehouse->name} is " . number_format(max(0, $outstanding)) . "L — you cannot log a return of " . number_format($quantity) . "L. Borrow more fuel from this site first, or reduce the return quantity.");
            }
        }

        // Product & compartment check: what is moving, from which compartment, into which.
        $route = $this->resolveRoute($request, $sourceTank, $destinationTank);
        if (isset($route['error'])) {
            return back()->withInput()->with('danger', $route['error']);
        }
        $product = $route['product'];
        $srcComp = $route['src'];
        $dstComp = $route['dst'];
        $productLabel = Order::PRODUCT_TYPES[$product] ?? $product;

        // Safety Rules 1 + 2: the source must actually have the fuel.
        // Contaminated volume is already excluded from the available figures.
        if ($srcComp) {
            $srcAvail = $srcComp->effective_available;
            if ($quantity > $srcAvail) {
                return back()->withInput()->with('danger', "Transfer Blocked: Quantity ({$quantity}L) exceeds available {$productLabel} in compartment C{$srcComp->compartment_no} of '{$sourceTank->name}' (" . number_format($srcAvail) . "L available).");
            }
        } else {
            // Only FULLY contaminated tanks are blocked; partial contamination is already excluded from effective_available.
            if ($sourceTank->isFullyContaminated()) {
                return back()->withInput()->with('danger', "Transfer Blocked: Source tank '{$sourceTank->name}' is FULLY CONTAMINATED (" . number_format($sourceTank->contaminated_liters) . "L). Contaminated fuel cannot be transferred into other tanks/tankers.");
            }

            if ($quantity > $sourceTank->effective_available) {
                return back()->withInput()->with('danger', "Transfer Blocked: Quantity ({$quantity}L) exceeds available fuel in source tank '{$sourceTank->name}' ({$sourceTank->effective_available}L available).");
            }
        }

        // Safety Rule 3: destination remaining capacity (compartment first, then the whole tank / truck)
        if ($dstComp && $quantity > $dstComp->remaining_capacity) {
            return back()->withInput()->with('danger', "Transfer Blocked: Quantity ({$quantity}L) exceeds remaining capacity of compartment C{$dstComp->compartment_no} of '{$destinationTank->name}' (Capacity remaining: {$dstComp->remaining_capacity}L).");
        }

        if ($quantity > $destinationTank->remaining_capacity) {
            return back()->withInput()->with('danger', "Transfer Blocked: Quantity ({$quantity}L) exceeds remaining capacity of destination tank '{$destinationTank->name}' (Capacity remaining: {$destinationTank->remaining_capacity}L).");
        }

        $prefix = match ($type) {
            'borrow' => 'BR-',
            'return' => 'RT-',
            default => 'ST-',
        };
        $transferNumber = $prefix . date('Ymd', strtotime($validated['transfer_date'])) . '-' . strtoupper(Str::random(5));

        DB::transaction(function () use ($validated, $sourceTank, $destinationTank, $quantity, $transferNumber, $type, $product, $productLabel, $srcComp, $dstComp) {
            // An empty destination takes the product being moved in (same rule as Add on the dashboard).
            if ($dstComp) {
                if ($dstComp->product_type !== $product) {
                    $dstComp->update(['product_type' => $product]);
                }
            } elseif ($destinationTank->product_type !== $product) {
                $destinationTank->update(['product_type' => $product]);
            }

            $transfer = StockTransfer::create([
                'transfer_number' => $transferNumber,
                'source_tank_id' => $sourceTank->id,
                'destination_tank_id' => $destinationTank->id,
                'source_compartment_id' => $srcComp ? $srcComp->id : null,
                'destination_compartment_id' => $dstComp ? $dstComp->id : null,
                'source_warehouse_id' => $sourceTank->warehouse_id,
                'destination_warehouse_id' => $destinationTank->warehouse_id,
                'type' => $type,
                'product_type' => $product,
                'quantity' => $quantity,
                'transfer_date' => $validated['transfer_date'],
                'notes' => $validated['notes'] ?? null,
                'transferred_by' => Auth::id(),
            ]);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'CREATED',
                'description' => "Executed [" . strtoupper($type) . "] #{$transfer->transfer_number}: " . number_format($quantity) . "L {$productLabel} from {$sourceTank->name}" . ($srcComp ? " C{$srcComp->compartment_no}" : '') . " ({$sourceTank->warehouse->name}) to {$destinationTank->name}" . ($dstComp ? " C{$dstComp->compartment_no}" : '') . " ({$destinationTank->warehouse->name})",
            ]);
        });

        return redirect()->route('wetstock.transfers.index', ['type' => $type])
            ->with('success', ucfirst($type) . " record {$transferNumber} (" . number_format($quantity) . "L) saved successfully.");
    }

    /**
     * Modifying a transfer also happens in the pop-up on the list page.
     */
    public function edit(StockTransfer $transfer): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        return redirect()->route('wetstock.transfers.index', ['type' => $transfer->type, 'edit' => $transfer->id]);
    }

    /**
     * Update transfer via Modification Request (Module 2 approval).
     */
    public function update(Request $request, StockTransfer $transfer): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:transfer,borrow,return'],
            'source_tank_id' => ['required', 'exists:storage_tanks,id', 'different:destination_tank_id'],
            'destination_tank_id' => ['required', 'exists:storage_tanks,id'],
            'source_compartment_id' => ['nullable', 'integer'],
            'destination_compartment_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'modification_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $sourceTank = StorageTank::with('warehouse')->findOrFail($validated['source_tank_id']);
        $destinationTank = StorageTank::with('warehouse')->findOrFail($validated['destination_tank_id']);

        // Same product / compartment rules as a new record (liters are reviewed at approval, as before).
        $route = $this->resolveRoute($request, $sourceTank, $destinationTank);
        if (isset($route['error'])) {
            return back()->withInput()->with('danger', $route['error']);
        }

        $newValues = [
            'type' => $validated['type'],
            'source_tank_id' => $sourceTank->id,
            'destination_tank_id' => $destinationTank->id,
            // 0 = "no compartment": a null new value would be skipped when the request is approved.
            'source_compartment_id' => $route['src'] ? $route['src']->id : 0,
            'destination_compartment_id' => $route['dst'] ? $route['dst']->id : 0,
            'product_type' => $route['product'],
            'source_warehouse_id' => $sourceTank->warehouse_id,
            'destination_warehouse_id' => $destinationTank->warehouse_id,
            'quantity' => (int) $validated['quantity'],
            'transfer_date' => $validated['transfer_date'],
            'notes' => $validated['notes'] ?? null,
        ];

        // Diff changes
        $changes = [];
        foreach ($newValues as $field => $newVal) {
            $oldVal = $transfer->{$field};
            if ($field === 'transfer_date' && $oldVal) {
                $oldVal = $oldVal->format('Y-m-d');
            }
            if (in_array($field, ['source_compartment_id', 'destination_compartment_id'], true)) {
                $oldVal = (int) $oldVal;
            }

            if ((string)$oldVal !== (string)$newVal) {
                $changes[$field] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        if (empty($changes)) {
            return redirect()->route('wetstock.transfers.index', ['type' => $transfer->type])
                ->with('info', "No changes detected on Transfer #{$transfer->transfer_number}.");
        }

        // Return validation on the modification path: the requested new state must not
        // exceed the outstanding borrowed balance (excluding this record's own current return).
        if ($validated['type'] === 'return') {
            $outstanding = StockTransfer::getOutstandingBalanceFor($destinationTank->warehouse_id, $transfer->id);
            $newQty = (int) $validated['quantity'];
            if ($newQty > $outstanding) {
                return back()->withInput()->with('danger', "Return Blocked: The outstanding borrowed balance for {$destinationTank->warehouse->name} (excluding this record's current return) is " . number_format(max(0, $outstanding)) . "L — a return of " . number_format($newQty) . "L cannot be requested. Reduce the return quantity.");
            }
        }

        $existingPending = $transfer->modificationRequests()->where('status', 'PENDING')->first();
        if ($existingPending) {
            return redirect()->route('wetstock.transfers.index', ['type' => $transfer->type])
                ->with('warning', "Transfer #{$transfer->transfer_number} already has a Pending Modification Request (#{$existingPending->id}) awaiting review. Please wait for it to be approved or rejected before submitting another.");
        }

        $modRequest = ModificationRequest::create([
            'requestable_type' => StockTransfer::class,
            'requestable_id' => $transfer->id,
            'requested_by' => Auth::id(),
            'changes' => $changes,
            'reason' => $validated['modification_reason'] ?? 'Stock Transfer modification submitted',
            'status' => 'PENDING',
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'REQUESTED',
            'description' => "Submitted Modification Request #{$modRequest->id} for Transfer #{$transfer->transfer_number} (" . count($changes) . " field(s) changed)",
        ]);

        return redirect()->route('wetstock.transfers.index', ['type' => $transfer->type])
            ->with('success', "Modification request for Transfer #{$transfer->transfer_number} submitted to the Approvals Queue for Operations Manager review.");
    }

    /**
     * Permanently delete a stock transfer record.
     * Portal Administrator only — enforced both by route middleware
     * (role:admin) and here as a defense-in-depth check.
     *
     * Note: this deletes the transfer record itself but does not attempt
     * to reverse or recompute any tank quantities. If your StorageTank
     * balances are derived live from StockTransfer records, deleting is
     * self-correcting. If balances are instead stored/cached on the tank
     * itself, a manual correction may be needed after deletion.
     */
    public function destroy(StockTransfer $transfer): RedirectResponse
    {
        if (!Auth::user()->isAdmin()) {
            abort(403);
        }

        $transferNumber = $transfer->transfer_number;
        $type = $transfer->type;
        $sourceName = $transfer->sourceTank->name ?? '—';
        $destinationName = $transfer->destinationTank->name ?? '—';
        $quantity = $transfer->quantity;

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'DELETED',
            'description' => "Deleted [" . strtoupper($type) . "] record #{$transferNumber}: " . number_format($quantity) . "L from {$sourceName} to {$destinationName}",
        ]);

        $transfer->delete();

        return redirect()->route('wetstock.transfers.index', ['type' => $type])
            ->with('success', ucfirst($type) . " record {$transferNumber} was permanently deleted.");
    }

    /**
     * Work out which product moves and between which compartments, and check they fit.
     * Returns ['error' => message] or ['product' => code, 'src' => ?TankCompartment, 'dst' => ?TankCompartment].
     *
     * - A truck with compartments needs a compartment picked on that side.
     * - The product is whatever the source holds.
     * - A destination holding stock must hold the same product; an empty one takes the product.
     */
    private function resolveRoute(Request $request, StorageTank $sourceTank, StorageTank $destinationTank): array
    {
        $src = null;
        $dst = null;

        $srcComps = $sourceTank->compartments()->get();
        if ($srcComps->isNotEmpty()) {
            $src = $srcComps->firstWhere('id', (int) $request->input('source_compartment_id'));
            if (!$src) {
                return ['error' => "Pick the source compartment on '{$sourceTank->name}'."];
            }
            $product = $src->product_type;
        } else {
            $product = $sourceTank->product_type;
        }

        if (!$product) {
            return ['error' => "The product being moved is unknown: the source has no product set. Set the product on '{$sourceTank->name}' first."];
        }
        $productLabel = Order::PRODUCT_TYPES[$product] ?? $product;

        $dstComps = $destinationTank->compartments()->get();
        if ($dstComps->isNotEmpty()) {
            $dst = $dstComps->firstWhere('id', (int) $request->input('destination_compartment_id'));
            if (!$dst) {
                return ['error' => "Pick the destination compartment on '{$destinationTank->name}'."];
            }
            $holds = $dst->product_type;
            $level = $dst->stock_available;
            $where = "compartment C{$dst->compartment_no} of '{$destinationTank->name}'";
        } else {
            $holds = $destinationTank->product_type;
            $level = $destinationTank->stock_available;
            $where = "'{$destinationTank->name}'";
        }

        if ($level > 0 && $holds && $holds !== $product) {
            $heldLabel = Order::PRODUCT_TYPES[$holds] ?? $holds;

            return ['error' => "Product mismatch: {$where} holds {$heldLabel}, but this transfer moves {$productLabel}. Pick a destination with the same product (or an empty one)."];
        }

        return ['product' => $product, 'src' => $src, 'dst' => $dst];
    }

    /**
     * Per-tank numbers for the form's drop-downs (computed once, not per option).
     */
    private function tankMeta($warehouses): array
    {
        $meta = [];

        foreach ($warehouses as $warehouse) {
            foreach ($warehouse->activeTanks as $tank) {
                $truck = $tank->compartments->isNotEmpty();
                $stock = (int) $tank->stock_available;
                $bad = (int) $tank->contaminated_liters;

                $meta[$tank->id] = [
                    'truck' => $truck,
                    'product' => $truck ? null : $tank->product_type,
                    'product_name' => $truck ? null : $tank->product_name,
                    'stock' => $stock,
                    'avail' => $truck ? 0 : (int) $tank->effective_available,
                    'room' => max(0, (int) $tank->max_capacity - $stock),
                    'bad' => $bad,
                    'blocked' => !$truck && $bad > 0 && $bad >= $stock,
                ];
            }
        }

        return $meta;
    }

    /**
     * Compartments of every active truck, for the form's compartment pickers.
     */
    private function compartmentData($warehouses): array
    {
        $data = [];

        foreach ($warehouses as $warehouse) {
            foreach ($warehouse->activeTanks as $tank) {
                if ($tank->compartments->isEmpty()) {
                    continue;
                }

                $data[$tank->id] = $tank->compartments->map(fn ($c) => [
                    'id' => $c->id,
                    'no' => $c->compartment_no,
                    'product' => $c->product_type,
                    'stock' => (int) $c->stock_available,
                    'avail' => (int) $c->effective_available,
                    'room' => (int) $c->remaining_capacity,
                    'cap' => (int) $c->capacity,
                    'bad' => (int) $c->contaminated_liters,
                ])->values()->all();
            }
        }

        return $data;
    }
}