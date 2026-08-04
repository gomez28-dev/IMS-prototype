<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Delivery;
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
        $unassignedDeliveries = Delivery::with(['order', 'storageTank.warehouse'])
            ->whereNull('storage_tank_id')
            ->where('status', '!=', 'CANCELLED')
            ->orderBy('delivery_date', 'desc')
            ->paginate(15);

        $assignments = Delivery::with(['order', 'storageTank.warehouse', 'assignedBy'])
            ->whereNotNull('storage_tank_id')
            ->orderBy('updated_at', 'desc')
            ->paginate(20);

        $warehouses = Warehouse::with(['tanks' => function ($q) {
            $q->where('is_active', true)->orderBy('name', 'asc');
        }])->orderBy('name', 'asc')->get();

        $unassignedCount = Delivery::whereNull('storage_tank_id')->where('status', '!=', 'CANCELLED')->count();

        return view('wetstock.deliveries.index', [
            'deliveries' => $unassignedDeliveries,
            'assignments' => $assignments,
            'warehouses' => $warehouses,
            'unassignedCount' => $unassignedCount,
            'activeTab' => $request->get('tab', 'unassigned'),
        ]);
    }

    /**
     * Assign a storage tank to a delivery.
     */
    public function assign(Request $request, Delivery $delivery): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $validated = $request->validate([
            'storage_tank_id' => ['required', 'exists:storage_tanks,id'],
        ]);

        $tank = StorageTank::findOrFail($validated['storage_tank_id']);

        // Block assignment if delivery qty exceeds available stock (accounting for pending deliveries)
        if ($delivery->qty_out > $tank->effective_available) {
            return back()->with('danger', "Cannot assign: DR #{$delivery->dr_number} quantity ({$delivery->qty_out}L) exceeds available stock in {$tank->name} ({$tank->effective_available}L available after accounting for pending deliveries).");
        }

        $delivery->update([
            'storage_tank_id' => $validated['storage_tank_id'],
            'assigned_by' => auth()->id(),
        ]);

        $delivery->load('storageTank.warehouse', 'assignedBy');

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "Assigned DR #{$delivery->dr_number} ({$delivery->qty_out}L) to tank {$delivery->storageTank->name} ({$delivery->storageTank->warehouse->name})",
        ]);

        return back()->with('success', "Delivery DR #{$delivery->dr_number} assigned to tank {$delivery->storageTank->name} successfully.");
    }

    /**
     * Unassign a delivery from its storage tank.
     */
    public function unassign(Delivery $delivery): RedirectResponse
    {
        if (auth()->user()->isViewer() || auth()->user()->isAccounting()) {
            abort(403);
        }

        $tankName = $delivery->storageTank->name;

        $delivery->update([
            'storage_tank_id' => null,
            'assigned_by' => null,
        ]);

        AuditLog::create([
            'admin_id' => auth()->id(),
            'action' => 'updated',
            'description' => "Unassigned DR #{$delivery->dr_number} ({$delivery->qty_out}L) from tank {$tankName}",
        ]);

        return back()->with('success', "DR #{$delivery->dr_number} has been unassigned.");
    }
}
