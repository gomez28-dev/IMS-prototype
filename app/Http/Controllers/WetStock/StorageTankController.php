<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class StorageTankController extends Controller
{
    /**
     * Show form to create a new storage tank or tanker.
     */
    public function create(Warehouse $warehouse): View
    {
        if (Auth::user()->isViewer() || Auth::user()->isAccounting()) {
            abort(403);
        }

        return view('wetstock.tanks.form', [
            'title' => 'Add Tank / Tanker',
            'warehouse' => $warehouse,
            'tank' => null,
        ]);
    }

    /**
     * Store a newly created storage tank or tanker.
     */
    public function store(Request $request, Warehouse $warehouse): RedirectResponse
    {
        if (Auth::user()->isViewer() || Auth::user()->isAccounting()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'category' => ['required', 'string', 'in:depot,tanker'],
            'max_capacity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $tank = $warehouse->tanks()->create([
            'name' => $validated['name'],
            'category' => $validated['category'],
            'max_capacity' => $validated['max_capacity'],
            'remarks' => $validated['remarks'] ?? null,
            'is_active' => true,
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Created " . strtoupper($tank->category) . " tank '{$tank->name}' ({$tank->max_capacity}L capacity) in {$warehouse->name}",
        ]);

        return redirect()->route('wetstock.warehouses.show', $warehouse->id)
            ->with('success', strtoupper($tank->category) . " tank '{$tank->name}' added successfully.");
    }

    /**
     * Show form to edit an existing storage tank or tanker.
     */
    public function edit(StorageTank $tank): View
    {
        if (Auth::user()->isViewer() || Auth::user()->isAccounting()) {
            abort(403);
        }

        return view('wetstock.tanks.form', [
            'title' => 'Edit ' . ucfirst($tank->category) . ' Tank',
            'warehouse' => $tank->warehouse,
            'tank' => $tank,
        ]);
    }

    /**
     * Update an existing storage tank or tanker.
     */
    public function update(Request $request, StorageTank $tank): RedirectResponse
    {
        if (Auth::user()->isViewer() || Auth::user()->isAccounting()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'category' => ['required', 'string', 'in:depot,tanker'],
            'max_capacity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        // If reducing capacity, ensure new capacity is not lower than currently used stock_available
        if ($validated['max_capacity'] < $tank->stock_available) {
            return back()->withInput()->with('danger', "Error: Maximum capacity cannot be set lower than current available stock ({$tank->stock_available}L)!");
        }

        $tank->update($validated);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Updated " . strtoupper($tank->category) . " tank '{$tank->name}' ({$tank->max_capacity}L capacity) in {$tank->warehouse->name}",
        ]);

        return redirect()->route('wetstock.warehouses.show', $tank->warehouse_id)
            ->with('success', ucfirst($tank->category) . " tank '{$tank->name}' updated successfully.");
    }

    /**
     * Toggle active status (soft delete / reactivate).
     */
    public function toggleActive(StorageTank $tank): RedirectResponse
    {
        if (Auth::user()->isViewer() || Auth::user()->isAccounting()) {
            abort(403);
        }

        $tank->update(['is_active' => !$tank->is_active]);
        $status = $tank->is_active ? 'reactivated' : 'deactivated';

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "{$status} " . strtoupper($tank->category) . " tank '{$tank->name}' in {$tank->warehouse->name}",
        ]);

        return redirect()->route('wetstock.warehouses.show', $tank->warehouse_id)
            ->with('success', ucfirst($tank->category) . " tank '{$tank->name}' {$status} successfully.");
    }

    /**
     * Toggle contaminated status for a tank or tanker.
     */
    public function toggleContamination(Request $request, StorageTank $tank): RedirectResponse
    {
        if (Auth::user()->isViewer() || Auth::user()->isAccounting()) {
            abort(403);
        }

        if ($tank->is_contaminated) {
            // Turn off contamination
            $tank->update([
                'is_contaminated' => false,
                'contaminated_liters' => 0,
                'contaminated_date' => null,
                'contaminated_by' => null,
            ]);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'UPDATED',
                'description' => "CLEARED Contamination flag on " . strtoupper($tank->category) . " tank '{$tank->name}' in {$tank->warehouse->name}",
            ]);

            return redirect()->back()->with('success', "Contamination flag CLEARED for '{$tank->name}'.");
        } else {
            // Turn on contamination
            $validated = $request->validate([
                'contaminated_liters' => ['required', 'integer', 'min:1'],
                'remarks' => ['nullable', 'string', 'max:1000'],
            ]);

            $tank->update([
                'is_contaminated' => true,
                'contaminated_liters' => $validated['contaminated_liters'],
                'contaminated_date' => now(),
                'contaminated_by' => Auth::id(),
                'remarks' => $validated['remarks'] ?? $tank->remarks,
            ]);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'UPDATED',
                'description' => "FLAGGED Contaminated on " . strtoupper($tank->category) . " tank '{$tank->name}' in {$tank->warehouse->name}. Affected Volume: " . number_format($tank->contaminated_liters) . "L. Remarks: " . ($validated['remarks'] ?? 'None'),
            ]);

            return redirect()->back()->with('warning', "Contamination FLAGGED on '{$tank->name}' for " . number_format($tank->contaminated_liters) . "L.");
        }
    }
}
