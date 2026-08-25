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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DeliveryAssignmentController extends Controller
{
    /**
     * Display delivery assignments — 3 tabs: Unassigned, Assigned (Pending Fulfillment), and History (Fulfilled).
     */
    public function index(Request $request): View
    {
        $activeTab = $request->get('tab', 'unassigned');

        // 1. Unassigned: Deliveries needing tank allocation
        $unassignedQuery = Delivery::with(['order', 'allocations.tank.warehouse'])
            ->where('status', '!=', 'CANCELLED')
            ->whereHas('order', fn($q) => $q->where('status', '!=', 'Cancelled'))
            ->where(function ($q) {
                $q->whereDoesntHave('allocations')
                    ->orWhereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM delivery_allocations WHERE delivery_id = deliveries.id) < qty_out');
            })
            ->orderBy('delivery_date', 'desc');

        $unassignedCount = (clone $unassignedQuery)->count();
        $unassignedDeliveries = $unassignedQuery->paginate(15, ['*'], 'unassigned_page');

        // 2. Assigned: Fully or partially allocated deliveries still in PENDING status (awaiting fulfillment)
        $assignedQuery = Delivery::with(['order', 'allocations.tank.warehouse', 'allocations.assignedBy'])
            ->where('status', 'PENDING')
            ->whereHas('allocations')
            ->whereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM delivery_allocations WHERE delivery_id = deliveries.id) >= qty_out')
            ->orderBy('delivery_date', 'desc');

        $assignedCount = (clone $assignedQuery)->count();
        $assignedDeliveries = $assignedQuery->paginate(15, ['*'], 'assigned_page');

        // 3. History: Deliveries marked FULFILLED with their tank allocation audit trail
        $historyQuery = Delivery::with(['order', 'allocations.tank.warehouse', 'allocations.assignedBy'])
            ->where('status', 'FULFILLED')
            ->orderBy('updated_at', 'desc');

        $historyCount = (clone $historyQuery)->count();
        $historyDeliveries = $historyQuery->paginate(20, ['*'], 'history_page');

        $warehouses = Warehouse::with(['activeTanks'])->orderBy('name', 'asc')->get();

        return view('wetstock.deliveries.index', [
            'unassignedDeliveries' => $unassignedDeliveries,
            'assignedDeliveries' => $assignedDeliveries,
            'historyDeliveries' => $historyDeliveries,
            'unassignedCount' => $unassignedCount,
            'assignedCount' => $assignedCount,
            'historyCount' => $historyCount,
            'warehouses' => $warehouses,
            'activeTab' => $activeTab,
        ]);
    }

    /**
     * Allocate a partial or full quantity of a delivery to a storage tank.
     * The delivery remains PENDING with volume placed on hold (stock_for_delivery).
     */
    public function allocate(Request $request, Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
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

        // Site lock: the tank must belong to the same warehouse as the order's location.
        // Cross-site stock must be moved via a Borrow transfer first, then allocated locally.
        $tank->load('warehouse');
        if ($tank->warehouse->name !== $delivery->order->location) {
            return back()->with('danger', "Cannot allocate: tank {$tank->name} belongs to {$tank->warehouse->name}, but this order's site is " . ($delivery->order->location ?? '(no site)') . ". Cross-site stock must be moved via a Borrow transfer first, then allocated from the local tank.");
        }

        if ($quantity > $tank->effective_available) {
            return back()->with('danger', "Cannot allocate: {$quantity}L exceeds available stock in {$tank->name} ({$tank->effective_available}L available after accounting for pending deliveries).");
        }

        $preAllocated = (int) $delivery->allocations->sum('quantity');
        $newAllocated = $preAllocated + $quantity;
        $isFullyAllocated = $newAllocated >= (int) $delivery->qty_out;

        DeliveryAllocation::create([
            'delivery_id' => $delivery->id,
            'storage_tank_id' => $tank->id,
            'quantity' => $quantity,
            'assigned_by' => Auth::id(),
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Allocated {$quantity}L of DR #{$delivery->dr_number} ({$delivery->qty_out}L) to tank {$tank->name} ({$tank->warehouse->name})"
                . ($isFullyAllocated ? ' — DR is now fully allocated and ready for fulfillment in the Assigned tab.' : ''),
        ]);

        $message = $isFullyAllocated
            ? "DR #{$delivery->dr_number} fully allocated across tanks and moved to the Assigned tab."
            : "Allocated {$quantity}L of DR #{$delivery->dr_number} to tank {$tank->name}. Remaining unallocated: " . number_format($delivery->qty_out - $newAllocated) . "L.";

        return redirect()->route('wetstock.deliveries.index', ['tab' => $isFullyAllocated ? 'assigned' : 'unassigned'])
            ->with('success', $message);
    }

    /**
     * Mark an assigned delivery as FULFILLED (Transitions volume from stock_for_delivery to stock_out).
     */
    public function markFulfilled(Request $request, Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canMarkFulfilled()) {
            abort(403, 'Unauthorized: Only Portal Admin, Operations Admin, and Operations Manager can fulfill deliveries.');
        }

        if ($delivery->status === 'FULFILLED') {
            return back()->with('warning', "DR #{$delivery->dr_number} is already marked as FULFILLED.");
        }

        if ($delivery->status === 'CANCELLED') {
            return back()->with('danger', "Cannot fulfill a cancelled delivery.");
        }

        DB::transaction(function () use ($delivery) {
            $delivery->update(['status' => 'FULFILLED']);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'UPDATED',
                'description' => "Marked DR #{$delivery->dr_number} ({$delivery->qty_out}L) as FULFILLED. Fuel dispatched from assigned tanks.",
            ]);
        });

        return redirect()->route('wetstock.deliveries.index', ['tab' => 'history'])
            ->with('success', "DR #{$delivery->dr_number} successfully marked as FULFILLED.");
    }

    /**
     * Revert a FULFILLED delivery back to PENDING status for modifications.
     */
    public function revertFulfillment(Request $request, Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canMarkFulfilled()) {
            abort(403, 'Unauthorized: Only authorized Operations Managers and Admins can revert delivery fulfillment.');
        }

        if ($delivery->status !== 'FULFILLED') {
            return back()->with('warning', "DR #{$delivery->dr_number} is not in FULFILLED status.");
        }

        DB::transaction(function () use ($delivery) {
            $delivery->update(['status' => 'PENDING']);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'UPDATED',
                'description' => "Reverted fulfillment status of DR #{$delivery->dr_number} ({$delivery->qty_out}L) from FULFILLED back to PENDING.",
            ]);
        });

        return redirect()->route('wetstock.deliveries.index', ['tab' => 'assigned'])
            ->with('success', "DR #{$delivery->dr_number} reverted to PENDING status.");
    }

    /**
     * Remove a single tank allocation from a delivery.
     */
    public function unassign(DeliveryAllocation $allocation): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $delivery = $allocation->delivery;

        if ($delivery->status === 'FULFILLED') {
            return back()->with('danger', "Cannot modify allocations of a FULFILLED delivery. Please revert the delivery to PENDING first.");
        }

        $tankName = $allocation->tank->name;
        $quantity = $allocation->quantity;

        $allocation->delete();

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Removed {$quantity}L allocation of DR #{$delivery->dr_number} ({$delivery->qty_out}L) from tank {$tankName}",
        ]);

        return back()->with('success', "Removed {$quantity}L allocation of DR #{$delivery->dr_number} from tank {$tankName}.");
    }
}