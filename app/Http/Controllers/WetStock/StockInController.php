<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockInController extends Controller
{
    /**
     * Display listing of all Stock IN records.
     */
    public function index(): View
    {
        $stockIns = StockIn::with(['tank.warehouse', 'admin'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('wetstock.stock-in.index', [
            'stockIns' => $stockIns,
        ]);
    }

    /**
     * Show form to add fuel stock into a tank.
     */
    public function create(Request $request): View
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $warehouses = Warehouse::with(['tanks' => function ($q) {
            $q->where('is_active', true)->orderBy('name', 'asc');
        }])->orderBy('name', 'asc')->get();

        $selectedTankId = $request->query('tank_id');

        return view('wetstock.stock-in.form', [
            'warehouses' => $warehouses,
            'selectedTankId' => $selectedTankId,
        ]);
    }

    /**
     * Store a newly created Stock IN record.
     */
    public function store(Request $request): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $validated = $request->validate([
            'storage_tank_id' => ['required', 'exists:storage_tanks,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'],
        ]);

        $tank = StorageTank::findOrFail($validated['storage_tank_id']);

        if (!$tank->is_active) {
            return back()->withInput()->with('danger', 'Error: Cannot log stock IN to a deactivated tank!');
        }

        // HARD BLOCK: check if Stock IN exceeds remaining tank capacity
        if ($validated['quantity'] > $tank->remaining_capacity) {
            return back()->withInput()->with('danger', "Error: Adding {$validated['quantity']}L exceeds remaining capacity of {$tank->remaining_capacity}L for {$tank->name} (Max capacity: {$tank->max_capacity}L, Available: {$tank->stock_available}L)!");
        }

        $stockIn = StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => auth()->id(),
            'quantity' => $validated['quantity'],
            'date' => $validated['date'],
        ]);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'created',
            'description' => "Stock IN: Logged {$stockIn->quantity}L into {$tank->name} ({$tank->warehouse->name}) on {$stockIn->date->format('Y-m-d')}",
        ]);

        return redirect()->route('wetstock.stock-in.index')
            ->with('success', "Logged {$stockIn->quantity}L into {$tank->name} ({$tank->warehouse->name}) successfully.");
    }
}
