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
        $stockIns = StockIn::with(['tank.warehouse', 'admin', 'reversal', 'original'])
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
        if (!auth()->user()->canEditModule2()) {
            abort(403);
        }

        $warehouses = Warehouse::with(['tanks' => function ($q) {
            $q->where('is_active', true)->orderBy('name', 'asc');
        }])->orderBy('name', 'asc')->get();

        // Ensure computed stock attributes are present in the JSON payload for the form's JS.
        $warehouses->each(function ($wh) {
            $wh->tanks->each->append(['stock_available', 'remaining_capacity', 'effective_available', 'sellable_available']);
        });

        $selectedTankId = $request->query('tank_id');
        $returnTo = $request->query('return_to');
        $returnUrl = $returnTo === 'dashboard'
            ? route('wetstock.dashboard')
            : route('wetstock.stock-in.index');

        return view('wetstock.stock-in.form', [
            'warehouses' => $warehouses,
            'selectedTankId' => $selectedTankId,
            'returnTo' => $returnTo,
            'returnUrl' => $returnUrl,
        ]);
    }

    /**
     * Store a newly created Stock IN record.
     */
    public function store(Request $request): RedirectResponse
    {
        if (!auth()->user()->canEditModule2()) {
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
    public function edit(StockIn $stockIn): View|RedirectResponse
    {
        if (!auth()->user()->canEditModule2()) {
            abort(403);
        }

        $stockIn->load(['tank.warehouse', 'admin', 'reversal']);

        if ($stockIn->isReversal() || $stockIn->reversal) {
            return redirect()->route('wetstock.stock-in.index')
                ->with('warning', 'Reverted entries cannot be edited — revert preserves audit history.');
        }

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
        if (!auth()->user()->canEditModule2()) {
            abort(403);
        }

        $stockIn->load(['tank.warehouse', 'reversal']);

        if ($stockIn->isReversal() || $stockIn->reversal) {
            return back()->with('warning', 'Reverted entries cannot be edited — revert preserves audit history.');
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
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

    /**
     * Revert a Stock IN entry by logging an offsetting reversal entry.
     * Original row is preserved for audit; reversal carries -quantity.
     * Allowed for all Module 2 editors; blocked when fuel is on HOLD.
     */
    public function revert(StockIn $stockIn): RedirectResponse
    {
        if (!auth()->user()->canEditModule2()) {
            abort(403);
        }

        $stockIn->load(['tank.warehouse', 'reversal']);

        if ($stockIn->isReversal()) {
            return back()->with('warning', 'This entry is itself a reversal and cannot be reverted again.');
        }

        if ($stockIn->reversal) {
            return back()->with('warning', 'This Stock IN entry has already been reverted.');
        }

        $tank = $stockIn->tank;
        if (!$tank || !$tank->is_active) {
            return back()->with('danger', 'Error: Cannot revert a Stock IN entry for a deactivated tank!');
        }

        $quantity = (int) $stockIn->quantity;
        if ($quantity <= 0) {
            return back()->with('warning', 'Only positive Stock IN entries can be reverted.');
        }

        if ($tank->stock_available - $quantity < $tank->stock_for_delivery) {
            return back()->with('danger', "Error: Cannot revert {$quantity}L — {$tank->stock_for_delivery}L in {$tank->name} is on HOLD for pending deliveries. Unassign first.");
        }

        if ($tank->stock_available - $quantity < 0) {
            return back()->with('danger', "Error: Cannot revert {$quantity}L — tank {$tank->name} only holds {$tank->stock_available}L available.");
        }

        $reversal = StockIn::create([
            'storage_tank_id' => $tank->id,
            'admin_id' => auth()->id(),
            'quantity' => -$quantity,
            'date' => now('Asia/Manila')->toDateString(),
            'reverses_id' => $stockIn->id,
        ]);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'REVERTED',
            'description' => "Stock IN: Reverted +" . number_format($quantity) . "L entry for {$tank->name} ({$tank->warehouse->name}) via reversal " . number_format($reversal->quantity) . "L (orig #{$stockIn->id})",
        ]);

        return redirect()->route('wetstock.stock-in.index')
            ->with('success', "Reverted +" . number_format($quantity) . "L into {$tank->name} with an offsetting entry.");
    }

    public function destroy(StockIn $stockIn): RedirectResponse
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $stockIn->load(['tank.warehouse', 'reversal']);

        if ($stockIn->isReversal() || $stockIn->reversal) {
            return back()->with('warning', 'Reverted entries cannot be hard-deleted — history is preserved via reversal.');
        }
        $tankName = $stockIn->tank->name ?? '—';
        $warehouseName = $stockIn->tank->warehouse->name ?? '—';
        $quantity = (int) $stockIn->quantity;
        $date = $stockIn->date ? $stockIn->date->format('Y-m-d') : '—';

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'DELETED',
            'description' => "Stock IN: Deleted " . number_format($quantity) . "L entry for {$tankName} ({$warehouseName}) dated {$date}",
        ]);

        $stockIn->delete();

        return redirect()->route('wetstock.stock-in.index')
            ->with('success', "Deleted Stock IN entry (" . number_format($quantity) . "L into {$tankName}) permanently.");
    }
}
