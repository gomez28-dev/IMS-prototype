<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ModificationRequest;
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

        return view('wetstock.transfers.index', [
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
     * Show form for creating a new stock transfer / borrow / return.
     */
    public function create(Request $request): View
    {
        $type = $request->get('type', 'transfer');
        if (!in_array($type, ['transfer', 'borrow', 'return'])) {
            $type = 'transfer';
        }

        $warehouses = Warehouse::with(['activeTanks'])->orderBy('name', 'asc')->get();
        $allTanks = StorageTank::with('warehouse')->where('is_active', true)->orderBy('name', 'asc')->get();
        $outstandingBalances = StockTransfer::getOutstandingBalances();

        return view('wetstock.transfers.form', [
            'title' => match ($type) {
                'borrow' => 'Record Cross-Site Borrowing',
                'return' => 'Record Cross-Site Stock Return',
                default => 'Record Intra-Site Stock Transfer',
            },
            'type' => $type,
            'transfer' => null,
            'warehouses' => $warehouses,
            'allTanks' => $allTanks,
            'outstandingBalances' => $outstandingBalances,
            'sourceTankId' => $request->source_tank_id,
            'destinationTankId' => $request->destination_tank_id,
        ]);
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

        // Safety Rule 1: Contamination check — only FULLY contaminated tanks are blocked.
        // Partially contaminated tanks may transfer up to effective_available (contaminated
        // volume is already excluded from that figure).
        if ($sourceTank->isFullyContaminated()) {
            return back()->withInput()->with('danger', "Transfer Blocked: Source tank '{$sourceTank->name}' is FULLY CONTAMINATED (" . number_format($sourceTank->contaminated_liters) . "L). Contaminated fuel cannot be transferred into other tanks/tankers.");
        }

        // Safety Rule 2: Source available volume check
        if ($quantity > $sourceTank->effective_available) {
            return back()->withInput()->with('danger', "Transfer Blocked: Quantity ({$quantity}L) exceeds available fuel in source tank '{$sourceTank->name}' ({$sourceTank->effective_available}L available).");
        }

        // Safety Rule 3: Destination remaining capacity check
        if ($quantity > $destinationTank->remaining_capacity) {
            return back()->withInput()->with('danger', "Transfer Blocked: Quantity ({$quantity}L) exceeds remaining capacity of destination tank '{$destinationTank->name}' (Capacity remaining: {$destinationTank->remaining_capacity}L).");
        }

        $prefix = match ($type) {
            'borrow' => 'BR-',
            'return' => 'RT-',
            default => 'ST-',
        };
        $transferNumber = $prefix . date('Ymd', strtotime($validated['transfer_date'])) . '-' . strtoupper(Str::random(5));

        DB::transaction(function () use ($validated, $sourceTank, $destinationTank, $quantity, $transferNumber, $type) {
            $transfer = StockTransfer::create([
                'transfer_number' => $transferNumber,
                'source_tank_id' => $sourceTank->id,
                'destination_tank_id' => $destinationTank->id,
                'source_warehouse_id' => $sourceTank->warehouse_id,
                'destination_warehouse_id' => $destinationTank->warehouse_id,
                'type' => $type,
                'quantity' => $quantity,
                'transfer_date' => $validated['transfer_date'],
                'notes' => $validated['notes'] ?? null,
                'transferred_by' => Auth::id(),
            ]);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'CREATED',
                'description' => "Executed [" . strtoupper($type) . "] #{$transfer->transfer_number}: " . number_format($quantity) . "L from {$sourceTank->name} ({$sourceTank->warehouse->name}) to {$destinationTank->name} ({$destinationTank->warehouse->name})",
            ]);
        });

        return redirect()->route('wetstock.transfers.index', ['type' => $type])
            ->with('success', ucfirst($type) . " record {$transferNumber} (" . number_format($quantity) . "L) saved successfully.");
    }

    /**
     * Show form for editing an existing transfer (Submits Modification Request).
     */
    public function edit(StockTransfer $transfer): View
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $warehouses = Warehouse::with(['activeTanks'])->orderBy('name', 'asc')->get();
        $allTanks = StorageTank::with('warehouse')->where('is_active', true)->orderBy('name', 'asc')->get();
        $outstandingBalances = StockTransfer::getOutstandingBalances();

        return view('wetstock.transfers.form', [
            'title' => "Request Modification: {$transfer->transfer_number}",
            'type' => $transfer->type,
            'transfer' => $transfer,
            'warehouses' => $warehouses,
            'allTanks' => $allTanks,
            'outstandingBalances' => $outstandingBalances,
            'sourceTankId' => $transfer->source_tank_id,
            'destinationTankId' => $transfer->destination_tank_id,
        ]);
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
            'quantity' => ['required', 'integer', 'min:1'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'modification_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $sourceTank = StorageTank::with('warehouse')->findOrFail($validated['source_tank_id']);
        $destinationTank = StorageTank::with('warehouse')->findOrFail($validated['destination_tank_id']);

        $newValues = [
            'type' => $validated['type'],
            'source_tank_id' => $sourceTank->id,
            'destination_tank_id' => $destinationTank->id,
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
}
