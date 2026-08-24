<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
     * Display a listing of stock transfers.
     */
    public function index(Request $request): View
    {
        $query = StockTransfer::with([
            'sourceTank.warehouse',
            'destinationTank.warehouse',
            'sourceWarehouse',
            'destinationWarehouse',
            'transferredBy',
        ])->orderBy('transfer_date', 'desc')->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transfer_number', 'like', "%{$search}%")
                  ->orWhereHas('sourceTank', fn($t) => $t->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('destinationTank', fn($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('warehouse_id')) {
            $whId = $request->warehouse_id;
            $query->where(function ($q) use ($whId) {
                $q->where('source_warehouse_id', $whId)
                  ->orWhere('destination_warehouse_id', $whId);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('transfer_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('transfer_date', '<=', $request->to);
        }

        $transfers = $query->paginate(20)->withQueryString();
        $warehouses = Warehouse::orderBy('name', 'asc')->get();

        return view('wetstock.transfers.index', [
            'transfers' => $transfers,
            'warehouses' => $warehouses,
            'currentWarehouse' => $request->warehouse_id,
            'searchQuery' => $request->search,
            'from' => $request->from,
            'to' => $request->to,
        ]);
    }

    /**
     * Show form for creating a new stock transfer.
     */
    public function create(Request $request): View
    {
        $mode = $request->get('mode', 'general'); // 'depot_to_tanker', 'tanker_to_depot', 'general'
        $warehouses = Warehouse::with(['activeTanks'])->orderBy('name', 'asc')->get();
        $allTanks = StorageTank::with('warehouse')->where('is_active', true)->orderBy('name', 'asc')->get();

        return view('wetstock.transfers.form', [
            'title' => match ($mode) {
                'depot_to_tanker' => 'Transfer: Depot → Tanker Truck',
                'tanker_to_depot' => 'Transfer: Tanker Truck → Depot',
                default => 'Record Stock Transfer',
            },
            'mode' => $mode,
            'warehouses' => $warehouses,
            'allTanks' => $allTanks,
            'sourceTankId' => $request->source_tank_id,
            'destinationTankId' => $request->destination_tank_id,
        ]);
    }

    /**
     * Store a newly executed stock transfer.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_tank_id' => ['required', 'exists:storage_tanks,id', 'different:destination_tank_id'],
            'destination_tank_id' => ['required', 'exists:storage_tanks,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $sourceTank = StorageTank::with('warehouse')->findOrFail($validated['source_tank_id']);
        $destinationTank = StorageTank::with('warehouse')->findOrFail($validated['destination_tank_id']);
        $quantity = (int) $validated['quantity'];

        // Safety Rule 1: Contamination check
        if ($sourceTank->is_contaminated) {
            return back()->withInput()->with('danger', "Transfer Blocked: Source tank '{$sourceTank->name}' is marked CONTAMINATED. Contaminated fuel cannot be transferred into other tanks/tankers.");
        }

        // Safety Rule 2: Source available volume check
        if ($quantity > $sourceTank->effective_available) {
            return back()->withInput()->with('danger', "Transfer Blocked: Quantity ({$quantity}L) exceeds available fuel in source tank '{$sourceTank->name}' ({$sourceTank->effective_available}L available).");
        }

        // Safety Rule 3: Destination remaining capacity check
        if ($quantity > $destinationTank->remaining_capacity) {
            return back()->withInput()->with('danger', "Transfer Blocked: Quantity ({$quantity}L) exceeds remaining capacity of destination tank '{$destinationTank->name}' (Capacity remaining: {$destinationTank->remaining_capacity}L).");
        }

        $transferNumber = 'ST-' . date('Ymd', strtotime($validated['transfer_date'])) . '-' . strtoupper(Str::random(5));

        DB::transaction(function () use ($validated, $sourceTank, $destinationTank, $quantity, $transferNumber) {
            $transfer = StockTransfer::create([
                'transfer_number' => $transferNumber,
                'source_tank_id' => $sourceTank->id,
                'destination_tank_id' => $destinationTank->id,
                'source_warehouse_id' => $sourceTank->warehouse_id,
                'destination_warehouse_id' => $destinationTank->warehouse_id,
                'quantity' => $quantity,
                'transfer_date' => $validated['transfer_date'],
                'notes' => $validated['notes'] ?? null,
                'transferred_by' => Auth::id(),
            ]);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'CREATED',
                'description' => "Executed Stock Transfer #{$transfer->transfer_number}: " . number_format($quantity) . "L transferred from {$sourceTank->name} ({$sourceTank->warehouse->name}) to {$destinationTank->name} ({$destinationTank->warehouse->name})",
            ]);
        });

        return redirect()->route('wetstock.transfers.index')
            ->with('success', "Stock Transfer {$transferNumber} (" . number_format($quantity) . "L) recorded successfully.");
    }
}
