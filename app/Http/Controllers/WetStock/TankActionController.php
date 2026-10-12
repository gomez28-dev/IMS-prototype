<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\StockAdjustment;
use App\Models\StockIn;
use App\Models\StorageTank;
use App\Models\TankCompartment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TankActionController extends Controller
{
    const REASONS = ['Sampling / test', 'Disposal', 'Spillage / loss', 'Count correction'];

    /** The thing being acted on: a compartment for trucks, the tank itself for depots. */
    private function unit(Request $request, StorageTank $tank)
    {
        abort_unless(Auth::user()->canEditModule2(), 403);

        if ($tank->compartments()->exists()) {
            return $tank->compartments()->findOrFail((int) $request->input('compartment_id'));
        }

        return $tank;
    }

    private function label($unit, StorageTank $tank): string
    {
        return $unit instanceof TankCompartment ? "{$tank->name} · C{$unit->compartment_no}" : $tank->name;
    }

    private function log(string $description): void
    {
        AuditLog::create(['admin_id' => Auth::id(), 'action' => 'UPDATED', 'description' => $description]);
    }

    private function isBlocked($unit): bool
    {
        return $unit->contaminated_liters > 0 && $unit->contaminated_liters >= $unit->stock_available;
    }

    /** Truck stock that is in the tank total but not yet placed in any compartment. */
    private function unassigned(StorageTank $tank): int
    {
        $inCompartments = (int) $tank->compartments()->get()->sum(fn ($c) => $c->stock_available);

        return max(0, $tank->stock_available - $inCompartments);
    }

    private function clearFlag($unit): void
    {
        if ($unit instanceof TankCompartment) {
            $unit->update(['contaminated_liters' => 0, 'contamination_note' => null, 'contaminated_date' => null, 'contaminated_by' => null]);
        } else {
            $unit->update(['is_contaminated' => false, 'contaminated_liters' => 0, 'contaminated_date' => null, 'contaminated_by' => null]);
        }
    }

    /** Keep the flagged liters from exceeding what is physically left. */
    private function capContamination($unit, StorageTank $tank): void
    {
        $level = $unit->stock_available;

        if ($unit->contaminated_liters > $level) {
            if ($level < 1) {
                $this->clearFlag($unit);
            } else {
                $unit->update(['contaminated_liters' => $level]);
            }
        }

        $this->syncTank($tank);
    }

    /** Trucks: the tank-level contaminated liters is the sum of its compartments. */
    private function syncTank(StorageTank $tank): void
    {
        if (!$tank->compartments()->exists()) {
            return;
        }

        $liters = (int) $tank->compartments()->sum('contaminated_liters');
        $tank->update([
            'is_contaminated' => $liters > 0,
            'contaminated_liters' => $liters,
            'contaminated_date' => $liters > 0 ? now() : null,
            'contaminated_by' => $liters > 0 ? Auth::id() : null,
        ]);
    }

    public function add(Request $request, StorageTank $tank): RedirectResponse
    {
        $unit = $this->unit($request, $tank);
        $v = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'product_type' => ['nullable', 'in:' . implode(',', array_keys(Order::PRODUCT_TYPES))],
            'source' => ['nullable', 'in:new,legacy'],
        ]);
        $name = $this->label($unit, $tank);
        $legacy = ($v['source'] ?? 'new') === 'legacy' && $unit instanceof TankCompartment;

        if ($this->isBlocked($unit)) {
            return back()->with('danger', "{$name} is flagged contaminated. Clear the flag before adding stock.");
        }

        // A tank holding stock keeps its product; an empty one takes the product being added.
        $product = $unit->stock_available > 0 ? $unit->product_type : ($v['product_type'] ?? $unit->product_type);
        if (!$product) {
            return back()->with('danger', 'Pick the product being added.');
        }

        if ($v['quantity'] > $unit->remaining_capacity) {
            return back()->with('danger', "Only " . number_format($unit->remaining_capacity) . "L of room left in {$name}.");
        }

        if ($legacy) {
            $unassigned = $this->unassigned($tank);
            if ($v['quantity'] > $unassigned) {
                return back()->with('danger', "Only " . number_format($unassigned) . "L of unassigned stock is left in {$tank->name}.");
            }
        }

        DB::transaction(function () use ($unit, $tank, $v, $product, $legacy) {
            if ($unit->product_type !== $product) {
                $unit->update(['product_type' => $product]);
            }

            if ($legacy) {
                // Stock already counted in the truck total: place it into this compartment.
                $unit->increment('opening_balance', $v['quantity']);
                return;
            }

            StockIn::create([
                'storage_tank_id' => $tank->id,
                'tank_compartment_id' => $unit instanceof TankCompartment ? $unit->id : null,
                'admin_id' => Auth::id(),
                'quantity' => $v['quantity'],
                'date' => now()->toDateString(),
            ]);
        });

        $productName = Order::PRODUCT_TYPES[$product] ?? $product;

        if ($legacy) {
            $this->log("Moved " . number_format($v['quantity']) . "L unassigned {$productName} into {$name}");

            return back()->with('success', number_format($v['quantity']) . "L of unassigned stock placed in {$name}.");
        }

        $this->log("Stock IN " . number_format($v['quantity']) . "L {$productName} into {$name}");

        return back()->with('success', number_format($v['quantity']) . "L added to {$name}.");
    }

    public function reduce(Request $request, StorageTank $tank): RedirectResponse
    {
        $unit = $this->unit($request, $tank);
        $v = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'in:' . implode(',', self::REASONS)],
        ]);
        $name = $this->label($unit, $tank);

        if ($v['quantity'] > $unit->stock_available) {
            return back()->with('danger', "{$name} only holds " . number_format($unit->stock_available) . "L.");
        }

        StockAdjustment::create([
            'storage_tank_id' => $tank->id,
            'tank_compartment_id' => $unit instanceof TankCompartment ? $unit->id : null,
            'quantity' => $v['quantity'],
            'reason' => $v['reason'],
            'admin_id' => Auth::id(),
        ]);

        $this->capContamination($unit, $tank);
        $this->log("Reduced " . number_format($v['quantity']) . "L from {$name} ({$v['reason']})");

        return back()->with('success', number_format($v['quantity']) . "L removed from {$name} ({$v['reason']}).");
    }

    public function contamination(Request $request, StorageTank $tank): RedirectResponse
    {
        $unit = $this->unit($request, $tank);
        $name = $this->label($unit, $tank);

        if ($unit->contaminated_liters > 0) {
            $this->clearFlag($unit);
            $this->syncTank($tank);
            $this->log("CLEARED Contamination flag on {$name}");

            return back()->with('success', "Contamination flag cleared for {$name}.");
        }

        $level = $unit->stock_available;
        if ($level < 1) {
            return back()->with('danger', "{$name} is empty, so there is nothing to flag.");
        }

        $v = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
            'liters' => ['nullable', 'integer', 'min:1', 'max:' . $level],
        ]);

        if ($unit instanceof TankCompartment) {
            $liters = $level; // the whole compartment is quarantined
            $unit->update([
                'contaminated_liters' => $liters,
                'contamination_note' => $v['remarks'] ?? null,
                'contaminated_date' => now(),
                'contaminated_by' => Auth::id(),
            ]);
        } else {
            $liters = (int) ($v['liters'] ?? $level);
            $unit->update([
                'is_contaminated' => true,
                'contaminated_liters' => $liters,
                'contaminated_date' => now(),
                'contaminated_by' => Auth::id(),
                'remarks' => $v['remarks'] ?? $unit->remarks,
            ]);
        }

        $this->syncTank($tank);
        $this->log("FLAGGED Contaminated on {$name}. Affected volume: " . number_format($liters) . "L of " . number_format($level) . "L. Remarks: " . ($v['remarks'] ?? 'None'));

        return back()->with('warning', "{$name} flagged contaminated (" . number_format($liters) . "L). It is blocked from sellable stock.");
    }
}