<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorageTankController extends Controller
{
    /**
     * Show form to create a new storage tank.
     */
    public function create(Warehouse $warehouse): View
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        return view('wetstock.tanks.form', [
            'title' => 'Add Storage Tank',
            'warehouse' => $warehouse,
            'tank' => null,
        ]);
    }

    /**
     * Store a newly created storage tank in storage.
     */
    public function store(Request $request, Warehouse $warehouse): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'max_capacity' => ['required', 'integer', 'min:1'],
        ]);

        $tank = $warehouse->tanks()->create([
            'name' => $validated['name'],
            'max_capacity' => $validated['max_capacity'],
            'is_active' => true,
        ]);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'created',
            'description' => "Created storage tank {$tank->name} ({$tank->max_capacity}L capacity) in {$warehouse->name}",
        ]);

        return redirect()->route('wetstock.warehouses.show', $warehouse->id)
            ->with('success', "Storage tank '{$tank->name}' added successfully.");
    }

    /**
     * Show form to edit an existing storage tank.
     */
    public function edit(StorageTank $tank): View
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        return view('wetstock.tanks.form', [
            'title' => 'Edit Storage Tank',
            'warehouse' => $tank->warehouse,
            'tank' => $tank,
        ]);
    }

    /**
     * Update an existing storage tank.
     */
    public function update(Request $request, StorageTank $tank): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'max_capacity' => ['required', 'integer', 'min:1'],
        ]);

        // If reducing capacity, ensure new capacity is not lower than currently used stock_available
        if ($validated['max_capacity'] < $tank->stock_available) {
            return back()->withInput()->with('danger', "Error: Maximum capacity cannot be set lower than current available stock ({$tank->stock_available}L)!");
        }

        $tank->update($validated);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "Updated storage tank {$tank->name} ({$tank->max_capacity}L capacity) in {$tank->warehouse->name}",
        ]);

        return redirect()->route('wetstock.warehouses.show', $tank->warehouse_id)
            ->with('success', "Storage tank '{$tank->name}' updated successfully.");
    }

    /**
     * Toggle active status (soft delete / reactivate).
     */
    public function toggleActive(StorageTank $tank): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $tank->update(['is_active' => !$tank->is_active]);
        $status = $tank->is_active ? 'reactivated' : 'deactivated';

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "{$status} storage tank {$tank->name} in {$tank->warehouse->name}",
        ]);

        return redirect()->route('wetstock.warehouses.show', $tank->warehouse_id)
            ->with('success', "Storage tank '{$tank->name}' {$status} successfully.");
    }
}
