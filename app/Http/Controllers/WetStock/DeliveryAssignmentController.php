<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\DeliveryAllocation;
use App\Models\Order;
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
     * Relations every list needs (compartments with their tank allocations).
     */
    private function listRelations(): array
    {
        return [
            'order',
            'items.allocations',
            'allocations.item',
            'allocations.tank.warehouse',
            'allocations.compartment',
            'allocations.assignedBy',
            'fulfilledBy',
            'createdBy',
            'modificationRequests.requestedBy',
            'modificationRequests.reviewedBy',
        ];
    }

    /**
     * Display delivery assignments — 3 tabs: Unassigned, Assigned (Pending Fulfillment), and History (Fulfilled).
     */
    public function index(Request $request): View
    {
        $activeTab = $request->get('tab', 'unassigned');
        $search = trim((string) $request->get('search', ''));
        $now = now('Asia/Manila');
        $historyMonth = $request->get('history_month');
        $historyYear = $request->get('history_year');
        $historyShowAll = $request->boolean('history_all');
        $filterMonth = $historyMonth ? (int) $historyMonth : (int) $now->format('m');
        $filterYear = $historyYear ? (int) $historyYear : (int) $now->format('Y');

        // 1. Unassigned: Deliveries needing tank allocation
        $unassignedQuery = Delivery::with($this->listRelations())
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('dr_number', 'like', "%{$search}%")
                        ->orWhere('atl_number', 'like', "%{$search}%");
                });
            })
            ->where('status', '!=', 'CANCELLED')
            ->whereHas('order', fn($q) => $q->where('status', '!=', 'Cancelled'))
            ->where(function ($q) {
                $q->whereDoesntHave('allocations')
                    ->orWhereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM delivery_allocations WHERE delivery_id = deliveries.id) < qty_out');
            })
            ->orderByRaw('CAST(dr_number AS UNSIGNED) DESC')
            ->orderBy('delivery_date', 'desc');

        $unassignedCount = (clone $unassignedQuery)->count();
        $unassignedDeliveries = $unassignedQuery->paginate(15, ['*'], 'unassigned_page');

        // 2. Assigned: Fully allocated deliveries still in PENDING status (awaiting fulfillment)
        $assignedQuery = Delivery::with($this->listRelations())
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('dr_number', 'like', "%{$search}%")
                        ->orWhere('atl_number', 'like', "%{$search}%");
                });
            })
            ->where('status', 'PENDING')
            ->whereHas('allocations')
            ->whereRaw('(SELECT COALESCE(SUM(quantity), 0) FROM delivery_allocations WHERE delivery_id = deliveries.id) >= qty_out')
            ->orderByRaw('CAST(dr_number AS UNSIGNED) DESC')
            ->orderBy('delivery_date', 'desc');

        $assignedCount = (clone $assignedQuery)->count();
        $assignedDeliveries = $assignedQuery->paginate(15, ['*'], 'assigned_page');

        // 3. History: Deliveries marked FULFILLED with their tank allocation audit trail (monthly reset)
        $historyQuery = Delivery::with($this->listRelations())
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('dr_number', 'like', "%{$search}%")
                        ->orWhere('atl_number', 'like', "%{$search}%");
                });
            })
            ->where('status', 'FULFILLED');
        if (!$historyShowAll) {
            $historyQuery->where(function ($q) use ($filterYear, $filterMonth) {
                $q->whereYear('fulfilled_at', $filterYear)->whereMonth('fulfilled_at', $filterMonth)
                  ->orWhere(function ($sub) use ($filterYear, $filterMonth) {
                      $sub->whereNull('fulfilled_at')->whereYear('updated_at', $filterYear)->whereMonth('updated_at', $filterMonth);
                  });
            });
        }
        $historyQuery->orderByRaw('CAST(dr_number AS UNSIGNED) DESC')
            ->orderByDesc('fulfilled_at')
            ->orderByDesc('updated_at');

        $historyCount = (clone $historyQuery)->count();
        $historyDeliveries = $historyQuery->paginate(20, ['*'], 'history_page');
        $historyMonths = Delivery::where('status', 'FULFILLED')
            ->selectRaw("COALESCE(DATE_FORMAT(fulfilled_at, '%Y-%m'), DATE_FORMAT(updated_at, '%Y-%m')) as ym")
            ->distinct()->orderByDesc('ym')->pluck('ym')->filter()->values();

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
            'search' => $search,
            'historyMonth' => $filterMonth,
            'historyYear' => $filterYear,
            'historyShowAll' => $historyShowAll,
            'historyMonths' => $historyMonths,
            'now' => $now,
        ]);
    }

    /**
     * Allocate a partial or full quantity of ONE compartment of a delivery to a storage tank.
     * The delivery remains PENDING with volume placed on hold (stock_for_delivery).
     *
     * Product-aware:
     *  - Tanker truck with compartments: only compartments holding the DR line's product are used,
     *    and the liters are split across them automatically.
     *  - Depot (or a truck without compartments): the tank's product must match the DR line.
     */
    public function allocate(Request $request, Delivery $delivery): RedirectResponse
    {
        if (!Auth::user()->canEditModule2()) {
            abort(403);
        }

        $validated = $request->validate([
            'delivery_item_id' => ['nullable', 'integer'],
            'storage_tank_id' => ['required', 'exists:storage_tanks,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $delivery->load('allocations', 'items.allocations');

        // Safety net: a delivery with no compartment lines gets one built from its header.
        if ($delivery->items->isEmpty() && (int) $delivery->qty_out > 0) {
            $delivery->items()->create([
                'product_type' => $delivery->product_type,
                'qty_out' => (int) $delivery->qty_out,
                'compartment_no' => 1,
            ]);
            $delivery->load('items.allocations');
        }

        $item = !empty($validated['delivery_item_id'])
            ? $delivery->items->firstWhere('id', (int) $validated['delivery_item_id'])
            : ($delivery->items->count() === 1 ? $delivery->items->first() : null);

        if (!$item) {
            return back()->with('danger', "Cannot allocate: select which product/compartment of DR #{$delivery->dr_number} you are allocating.");
        }

        $tank = StorageTank::findOrFail($validated['storage_tank_id']);
        $quantity = (int) $validated['quantity'];
        $code = $item->product_type;
        $productLabel = ($code ?: '-') . ' - ' . $item->product_name;
        $remaining = $item->remaining_to_allocate;

        if ($delivery->order && $delivery->order->status === 'Cancelled') {
            return back()->with('danger', "Cannot allocate: the parent order of DR #{$delivery->dr_number} is cancelled.");
        }

        if ($remaining <= 0) {
            return back()->with('danger', "Cannot allocate: the {$productLabel} compartment of DR #{$delivery->dr_number} is already fully allocated.");
        }

        if ($quantity > $remaining) {
            return back()->with('danger', "Cannot allocate: {$quantity}L exceeds the remaining unallocated {$productLabel} quantity of {$remaining}L for DR #{$delivery->dr_number}.");
        }

        // Site lock: the tank must belong to the same warehouse as the order's location.
        // Cross-site stock must be moved via a Borrow transfer first, then allocated locally.
        $tank->load('warehouse');
        if ($tank->warehouse->name !== $delivery->order->location) {
            return back()->with('danger', "Cannot allocate: tank {$tank->name} belongs to {$tank->warehouse->name}, but this order's site is " . ($delivery->order->location ?? '(no site)') . ". Cross-site stock must be moved via a Borrow transfer first, then allocated from the local tank.");
        }

        // Work out where the liters come from: [compartment|null, liters] rows.
        $rows = [];
        $compartments = $tank->compartments()->get();

        if ($compartments->isNotEmpty()) {
            if (!$code) {
                return back()->with('danger', "DR #{$delivery->dr_number} has no product set on this line, so a truck compartment cannot be matched. Edit the DR and set the product first.");
            }

            $matching = $compartments->filter(fn ($c) => $c->product_type === $code
                    && $c->effective_available > 0
                    && !$item->allocations->contains('tank_compartment_id', $c->id))
                ->values();

            if ($matching->isEmpty()) {
                return back()->with('danger', "Cannot allocate: no compartment on {$tank->name} has {$productLabel} available. Check the truck's compartments on the Wet Stock dashboard.");
            }

            $pool = (int) $matching->sum('effective_available');
            if ($quantity > $pool) {
                return back()->with('danger', "Cannot allocate: {$tank->name} only has " . number_format($pool) . "L of {$productLabel} available across its compartments (after pending deliveries).");
            }

            $left = $quantity;
            foreach ($matching as $c) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, $c->effective_available);
                $rows[] = [$c, $take];
                $left -= $take;
            }
        } else {
            if ($item->allocations->contains('storage_tank_id', $tank->id)) {
                return back()->with('danger', "Cannot allocate: the {$productLabel} compartment of DR #{$delivery->dr_number} already has an allocation on tank {$tank->name}.");
            }

            if ($tank->product_type && $code && $tank->product_type !== $code) {
                $held = Order::PRODUCT_TYPES[$tank->product_type] ?? $tank->product_type;
                return back()->with('danger', "Cannot allocate: {$tank->name} holds {$held}, but this DR line needs {$productLabel}. Pick a tank with the matching product.");
            }

            if ($quantity > $tank->effective_available) {
                return back()->with('danger', "Cannot allocate: {$quantity}L exceeds available stock in {$tank->name} ({$tank->effective_available}L available after accounting for pending deliveries).");
            }

            $rows[] = [null, $quantity];
        }

        $preAllocated = (int) $delivery->allocations->sum('quantity');
        $newAllocated = $preAllocated + $quantity;
        $isFullyAllocated = $newAllocated >= (int) $delivery->qty_out;

        DB::transaction(function () use ($rows, $delivery, $item, $tank) {
            foreach ($rows as [$c, $liters]) {
                DeliveryAllocation::create([
                    'delivery_id' => $delivery->id,
                    'delivery_item_id' => $item->id,
                    'storage_tank_id' => $tank->id,
                    'tank_compartment_id' => $c ? $c->id : null,
                    'quantity' => $liters,
                    'assigned_by' => Auth::id(),
                ]);
            }
        });

        $where = collect($rows)->map(fn ($r) => $r[0] ? 'C' . $r[0]->compartment_no . ' ' . number_format($r[1]) . 'L' : null)->filter()->implode(', ');

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Allocated {$quantity}L {$productLabel} (compartment #{$item->compartment_no}) of DR #{$delivery->dr_number} ({$delivery->qty_out}L) to tank {$tank->name} ({$tank->warehouse->name})"
                . ($where ? " [{$where}]" : '')
                . ($isFullyAllocated ? ' — DR is now fully allocated and ready for fulfillment in the Assigned tab.' : ''),
        ]);

        $message = $isFullyAllocated
            ? "DR #{$delivery->dr_number} fully allocated across tanks and moved to the Assigned tab."
            : "Allocated {$quantity}L {$productLabel} of DR #{$delivery->dr_number} to tank {$tank->name}. Remaining unallocated on this DR: " . number_format($delivery->qty_out - $newAllocated) . "L.";

        // After a partial allocation, reopen the pop-up so the next product can be assigned.
        return redirect()->route('wetstock.deliveries.index', [
            'tab' => $isFullyAllocated ? 'assigned' : 'unassigned',
            'open' => $isFullyAllocated ? null : $delivery->id,
        ])->with('success', $message);
    }

    /**
     * Mark an assigned delivery as FULFILLED (Transitions volume from stock_for_delivery to stock_out).
     * Tank and compartment stock are both computed from the allocations, so every product
     * is deducted from the tank (and truck compartment) it was allocated to.
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
            $delivery->update(['status' => 'FULFILLED', 'fulfilled_by' => Auth::id(), 'fulfilled_at' => now('Asia/Manila')]);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'UPDATED',
                'description' => "Marked DR #{$delivery->dr_number} ({$delivery->items_summary}) as FULFILLED. Fuel dispatched from assigned tanks.",
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
            $delivery->update(['status' => 'PENDING', 'fulfilled_at' => null]);

            AuditLog::create([
                'admin_id' => Auth::id(),
                'action' => 'UPDATED',
                'description' => "Reverted fulfillment status of DR #{$delivery->dr_number} ({$delivery->items_summary}) from FULFILLED back to PENDING.",
            ]);
        });

        return redirect()->route('wetstock.deliveries.index', ['tab' => 'assigned'])
            ->with('success', "DR #{$delivery->dr_number} reverted to PENDING status.");
    }

    /**
     * Remove a single tank allocation from a delivery compartment.
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
        $comp = $allocation->compartment ? ' C' . $allocation->compartment->compartment_no : '';
        $quantity = $allocation->quantity;
        $product = $allocation->item ? ($allocation->item->product_type ?: '-') . ' ' : '';

        $allocation->delete();

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'UPDATED',
            'description' => "Removed {$quantity}L {$product}allocation of DR #{$delivery->dr_number} ({$delivery->qty_out}L) from tank {$tankName}{$comp}",
        ]);

        return back()->with('success', "Removed {$quantity}L allocation of DR #{$delivery->dr_number} from tank {$tankName}{$comp}.");
    }
}