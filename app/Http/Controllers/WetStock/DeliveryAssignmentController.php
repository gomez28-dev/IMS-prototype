<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\StorageTank;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeliveryAssignmentController extends Controller
{
    /**
     * Display delivery assignments — Unassigned & History in a single tabbed page.
     */
    public function index(Request $request): View
    {
        $unassignedDeliveries = Delivery::with(['order', 'allocations.tank.warehouse'])
            ->where('status', '!=', 'CANCELLED')
            ->whereHas('order', fn($q) => $q->where('status', '!=', 'Cancelled'))
            ->where(function ($q) {
                $q->whereDoesntHave('allocations')
                    ->orWhereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM delivery_allocations WHERE delivery_id = deliveries.id) < qty_out');
            })
            ->orderBy('delivery_date', 'desc')
            ->paginate(15);

        $assignments = DeliveryAllocation::with(['delivery.order', 'tank.warehouse', 'assignedBy'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $warehouses = Warehouse::with(['tanks' => function ($q) {
            $q->where('is_active', true)->orderBy('name', 'asc');
        }])->orderBy('name', 'asc')->get();

        $unassignedCount = Delivery::where('status', '!=', 'CANCELLED')
            ->whereHas('order', fn($q) => $q->where('status', '!=', 'Cancelled'))
            ->where(function ($q) {
                $q->whereDoesntHave('allocations')
                    ->orWhereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM delivery_allocations WHERE delivery_id = deliveries.id) < qty_out');
            })
            ->count();

        return view('wetstock.deliveries.index', [
            'deliveries' => $unassignedDeliveries,
            'assignments' => $assignments,
            'warehouses' => $warehouses,
            'unassignedCount' => $unassignedCount,
            'activeTab' => $request->get('tab', 'unassigned'),
        ]);
    }

    /**
     * Allocate a partial quantity of a delivery to a storage tank.
     * The delivery becomes FULFILLED once the sum of allocations equals its qty_out.
     */
    public function allocate(Request $request, Delivery $delivery): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $validated = $request->validate([
            'storage_tank_id' => ['required', 'exists:storage_tanks,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $delivery->load('allocations');
        $tank = StorageTank::findOrFail($validated['storage_tank_id']);
        $quantity = (int) $validated['quantity'];

        if ($delivery->order && $delivery->order->status === 'Cancelled') {
            return back()->with('danger', "Cannot allocate: the parent order of DR #{$delivery->dr_number} is cancelled.");
        }

        if ($delivery->remaining_to_allocate <= 0) {
            return back()->with('danger', "Cannot allocate: DR #{$delivery->dr_number} is already fully allocated.");
        }

        if ($quantity > $delivery->remaining_to_allocate) {
            return back()->with('danger', "Cannot allocate: {$quantity}L exceeds the remaining unallocated quantity of {$delivery->remaining_to_allocate}L for DR #{$delivery->dr_number}.");
        }

        if ($delivery->allocations->contains('storage_tank_id', $tank->id)) {
            return back()->with('danger', "Cannot allocate: DR #{$delivery->dr_number} already has an allocation on tank {$tank->name}.");
        }

        if ($quantity > $tank->effective_available) {
            return back()->with('danger', "Cannot allocate: {$quantity}L exceeds available stock in {$tank->name} ({$tank->effective_available}L available after accounting for pending deliveries).");
        }

        $preAllocated = (int) $delivery->allocations->sum('quantity');
        $newAllocated = $preAllocated + $quantity;
        $isFulfilled = $newAllocated >= (int) $delivery->qty_out;

        DeliveryAllocation::create([
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $tank->id,
            'quantity' => $quantity,
            'assigned_by' => auth()->id(),
        ]);

        if ($isFulfilled) {
            $delivery->update(['status' => 'FULFILLED']);
        }

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "Allocated {$quantity}L of DR #{$delivery->dr_number} ({$delivery->qty_out}L) to tank {$tank->name} ({$tank->warehouse->name})"
                . ($isFulfilled ? ' — DR fully allocated and marked FULFILLED.' : ''),
        ]);

        $message = $isFulfilled
            ? "DR #{$delivery->dr_number} fully allocated across tanks and marked FULFILLED."
            : "Allocated {$quantity}L of DR #{$delivery->dr_number} to tank {$tank->name}. Remaining: " . number_format($delivery->qty_out - $newAllocated) . "L.";

        return back()->with('success', $message);
    }

    /**
     * Remove a single tank allocation from a delivery.
     * If no allocations remain, the delivery returns to the unassigned list.
     */
    public function unassign(DeliveryAllocation $allocation): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $delivery = $allocation->delivery;
        $tankName = $allocation->tank->name;
        $quantity = $allocation->quantity;

        $allocation->delete();

        // A fully allocated (FULFILLED) delivery that loses an allocation reverts to PENDING.
        if ($delivery->status === 'FULFILLED' && $delivery->remaining_to_allocate > 0) {
            $delivery->update(['status' => 'PENDING']);
        }

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "Removed {$quantity}L allocation of DR #{$delivery->dr_number} ({$delivery->qty_out}L) from tank {$tankName}",
        ]);

        return back()->with('success', "Removed {$quantity}L allocation of DR #{$delivery->dr_number} from tank {$tankName}.");
    }
}