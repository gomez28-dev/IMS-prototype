<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StorageTankController extends Controller
{
    private function validated(Request $request): array
    {
        $codes = implode(',', array_keys(Order::PRODUCT_TYPES));

        return $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'category' => ['required', 'string', 'in:depot,tanker'],
            'max_capacity' => ['required_if:category,depot', 'nullable', 'integer', 'min:1'],
            'product_type' => ['required_if:category,depot', 'nullable', 'in:' . $codes],
            'compartments' => ['required_if:category,tanker', 'nullable', 'array', 'min:1'],
            'compartments.*.id' => ['nullable', 'integer'],
            'compartments.*.capacity' => ['required', 'integer', 'min:1'],
            'compartments.*.product_type' => ['nullable', 'in:' . $codes],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Create / update / remove a truck's compartments. Returns an error message or null.
     */
    private function syncCompartments(StorageTank $tank, array $rows): ?string
    {
        $keep = [];

        foreach ($rows as $r) {
            $product = $r['product_type'] ?: null;
            $c = !empty($r['id']) ? $tank->compartments()->find($r['id']) : null;

            if ($c) {
                $level = $c->stock_available;
                if ($level > 0 && $product !== $c->product_type) {
                    return "Compartment {$c->compartment_no} still holds stock. Empty it before changing its product.";
                }
                if ((int) $r['capacity'] < $level) {
                    return "Compartment {$c->compartment_no} holds {$level}L, which is more than the new capacity.";
                }
                $c->update(['capacity' => $r['capacity'], 'product_type' => $product]);
            } else {
                $c = $tank->compartments()->create([
                    'compartment_no' => ((int) $tank->compartments()->max('compartment_no')) + 1,
                    'capacity' => $r['capacity'],
                    'product_type' => $product,
                ]);
            }
            $keep[] = $c->id;
        }

        foreach ($tank->compartments()->whereNotIn('id', $keep)->get() as $c) {
            if ($c->stockIns()->exists() || $c->adjustments()->exists()) {
                return "Compartment {$c->compartment_no} has stock history and cannot be removed.";
            }
            $c->delete();
        }

        $tank->update(['max_capacity' => (int) $tank->compartments()->sum('capacity')]);

        return null;
    }

    /**
     * Saves the "Add tank / tanker" pop-up card. On success it goes back to the
     * dashboard, which reopens the Tanks & tankers pop-up with the new unit listed.
     */
    public function store(Request $request, Warehouse $warehouse): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $v = $this->validated($request);
        $isTanker = $v['category'] === 'tanker';

        DB::beginTransaction();

        $tank = $warehouse->tanks()->create([
            'name' => $v['name'],
            'category' => $v['category'],
            'product_type' => $isTanker ? null : $v['product_type'],
            'max_capacity' => $isTanker ? collect($v['compartments'])->sum('capacity') : $v['max_capacity'],
            'remarks' => $v['remarks'] ?? null,
            'is_active' => true,
        ]);

        if ($isTanker && ($err = $this->syncCompartments($tank, $v['compartments']))) {
            DB::rollBack();
            return back()->withInput()->with('danger', $err);
        }

        DB::commit();

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Created " . strtoupper($tank->category) . " tank '{$tank->name}' ({$tank->max_capacity}L capacity) in {$warehouse->name}",
        ]);

        return redirect()->route('wetstock.dashboard')
            ->with('success', strtoupper($tank->category) . " tank '{$tank->name}' added successfully.");
    }

    public function update(Request $request, StorageTank $tank): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $v = $this->validated($request);
        $isTanker = $v['category'] === 'tanker';
        $capacity = $isTanker ? (int) collect($v['compartments'])->sum('capacity') : (int) $v['max_capacity'];
        $level = $tank->stock_available;

        if ($capacity < $level) {
            return back()->withInput()->with('danger', "Error: Maximum capacity cannot be set lower than current available stock ({$level}L)!");
        }

        if (!$isTanker && $tank->product_type && $tank->product_type !== $v['product_type'] && $level > 0) {
            return back()->withInput()->with('danger', "This tank still holds {$level}L. Empty it before changing its product.");
        }

        DB::beginTransaction();

        $tank->update([
            'name' => $v['name'],
            'category' => $v['category'],
            'product_type' => $isTanker ? null : $v['product_type'],
            'max_capacity' => $capacity,
            'remarks' => $v['remarks'] ?? null,
        ]);

        if ($isTanker && ($err = $this->syncCompartments($tank, $v['compartments']))) {
            DB::rollBack();
            return back()->withInput()->with('danger', $err);
        }

        DB::commit();

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Updated " . strtoupper($tank->category) . " tank '{$tank->name}' ({$tank->max_capacity}L capacity) in {$tank->warehouse->name}",
        ]);

        return redirect()->route('wetstock.dashboard')
            ->with('success', ucfirst($tank->category) . " tank '{$tank->name}' updated successfully.");
    }

    public function toggleActive(StorageTank $tank): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $tank->update(['is_active' => !$tank->is_active]);
        $status = $tank->is_active ? 'reactivated' : 'deactivated';

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "{$status} " . strtoupper($tank->category) . " tank '{$tank->name}' in {$tank->warehouse->name}",
        ]);

        return redirect()->route('wetstock.dashboard')
            ->with('success', ucfirst($tank->category) . " tank '{$tank->name}' {$status} successfully.");
    }

    /**
     * Permanently delete a unit. Only allowed when it is empty and has no stock
     * history; otherwise it must be deactivated instead.
     */
    public function destroy(StorageTank $tank): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $name = $tank->name;
        $category = strtoupper($tank->category);
        $warehouseName = $tank->warehouse->name;

        if ((int) $tank->stock_available > 0 || (int) $tank->stock_for_delivery > 0) {
            return redirect()->back()->with('warning', "'{$name}' still holds stock. Empty it before deleting, or deactivate it instead.");
        }

        $hasHistory = false;
        foreach (['stockIns', 'adjustments'] as $rel) {
            if (method_exists($tank, $rel) && $tank->{$rel}()->exists()) {
                $hasHistory = true;
            }
        }
        foreach ($tank->compartments as $c) {
            if ($c->stockIns()->exists() || $c->adjustments()->exists()) {
                $hasHistory = true;
            }
        }

        if ($hasHistory) {
            return redirect()->back()->with('warning', "'{$name}' has stock history and can't be deleted. Deactivate it instead to hide it from the dashboard.");
        }

        try {
            DB::transaction(function () use ($tank) {
                $tank->compartments()->delete();
                $tank->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->back()->with('danger', "'{$name}' is linked to other records and can't be deleted. Deactivate it instead.");
        }

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'DELETED',
            'description' => "Deleted {$category} tank '{$name}' from {$warehouseName}",
        ]);

        return redirect()->route('wetstock.dashboard')
            ->with('success', ucfirst(strtolower($category)) . " tank '{$name}' deleted.");
    }

    /**
     * Legacy tank-level contamination toggle (depots). Trucks with compartments
     * are flagged per compartment from the Wet Stock dashboard.
     */
    public function toggleContamination(Request $request, StorageTank $tank): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        if ($tank->compartments()->exists()) {
            return redirect()->back()->with('warning', "'{$tank->name}' has compartments. Flag contamination per compartment from the Wet Stock dashboard.");
        }

        if ($tank->hasContamination()) {
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
        }

        $validated = $request->validate([
            'contaminated_liters' => ['required', 'integer', 'min:1', 'max:' . max(1, $tank->stock_available)],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $liters = (int) $validated['contaminated_liters'];
        $isFull = $liters >= $tank->stock_available;

        $tank->update([
            'is_contaminated' => true,
            'contaminated_liters' => $liters,
            'contaminated_date' => now(),
            'contaminated_by' => Auth::id(),
            'remarks' => $validated['remarks'] ?? $tank->remarks,
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => ($isFull ? "FLAGGED FULLY Contaminated" : "FLAGGED Partially Contaminated") . " on " . strtoupper($tank->category) . " tank '{$tank->name}' in {$tank->warehouse->name}. Affected Volume: " . number_format($liters) . "L of " . number_format($tank->stock_available) . "L. Remarks: " . ($validated['remarks'] ?? 'None'),
        ]);

        $scope = $isFull ? "FULLY contaminated" : "partially contaminated ({$liters}L of " . number_format($tank->stock_available) . "L)";
        return redirect()->back()->with('warning', "Contamination FLAGGED on '{$tank->name}' — " . $scope . ". Only the affected volume is blocked from sellable stock.");
    }
}