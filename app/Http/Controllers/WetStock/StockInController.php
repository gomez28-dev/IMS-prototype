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

    /**
     * Show form to correct a Stock IN entry's quantity (typo fix).
     */
    public function edit(StockIn $stockIn): View
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $stockIn->load(['tank.warehouse', 'admin']);

        return view('wetstock.stock-in.form', [
            'stockIn' => $stockIn,
            'warehouses' => collect(),
            'selectedTankId' => null,
        ]);
    }

    /**
     * Update a Stock IN entry's quantity (typo correction).
     */
    public function update(Request $request, StockIn $stockIn): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $stockIn->load('tank.warehouse');

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $newQuantity = (int) $validated['quantity'];
        $oldQuantity = $stockIn->quantity;
        $tank = $stockIn->tank;

        if ($newQuantity === $oldQuantity) {
            return back()->with('info', 'No changes were made to this entry.');
        }

        if (!$tank->is_active) {
            return back()->withInput()->with('danger', 'Error: Cannot correct a Stock IN entry for a deactivated tank!');
        }

        // Effective remaining capacity accounts for the entry's own old quantity
        // still being part of stock_available (max_capacity - (stock_available - old_qty)).
        $effectiveRemaining = $tank->remaining_capacity + $oldQuantity;

        if ($newQuantity > $effectiveRemaining) {
            return back()->withInput()->with('danger', "Error: {$newQuantity}L exceeds the corrected capacity of {$effectiveRemaining}L for {$tank->name} (Max capacity: {$tank->max_capacity}L, Current stock: {$tank->stock_available}L)!");
        }

        $stockIn->update([
            'quantity' => $newQuantity,
        ]);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "Stock IN: Corrected quantity for {$tank->name} ({$tank->warehouse->name}) from " . number_format($oldQuantity) . "L to " . number_format($newQuantity) . "L",
        ]);

        return redirect()->route('wetstock.stock-in.index')
            ->with('success', "Corrected {$tank->name} Stock IN quantity from " . number_format($oldQuantity) . "L to " . number_format($newQuantity) . "L.");
    }
}
