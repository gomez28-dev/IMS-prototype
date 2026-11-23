<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AtlAllocation;
use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Module 3 - Purchase Orders.
 *
 * Dedicated page for creating POs and listing every PO on the system,
 * regardless of which workflow raised it. Its detail view shows which
 * ATLs have drawn from the PO.
 *
 * The list logic is shared with the legacy StockOrderController@index so
 * both entry points show identical results.
 */
class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
    {
        // Only real purchase orders. Module 2 stock requests and the Module 1
        // Sales Order drawdown rows live on their own pages, and including
        // them here would also inflate the stat cards.
        $query = PurchaseOrder::createdPos()->with([
            'warehouse',
            'client',
            'requester',
            'approver',
            'linkedOrder',
            'items',
            'deliveries.preparer',
        ]);

        // The status dropdown and its Filter button are gone from the list, so
        // the query no longer honours a status parameter. Search stays.
        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('po_number', 'like', $term)
                  ->orWhere('supplier_name', 'like', $term);
            });
        }

        $purchaseOrders = $query->latest()->paginate(15);

        // Lifted volume per PO and product, for the whole page in one query.
        $liftedByPo = [];

        if ($purchaseOrders->isNotEmpty()) {
            $liftedRows = AtlAllocation::query()
                ->join('purchase_order_deliveries', 'atl_allocations.purchase_order_delivery_id', '=', 'purchase_order_deliveries.id')
                ->where('purchase_order_deliveries.lift_status', PurchaseOrderDelivery::LIFT_LIFTED)
                ->where('purchase_order_deliveries.status', '!=', 'Cancelled')
                ->whereIn('atl_allocations.purchase_order_id', $purchaseOrders->pluck('id'))
                ->selectRaw('atl_allocations.purchase_order_id AS po_id, atl_allocations.product, SUM(atl_allocations.quantity) AS qty')
                ->groupBy('atl_allocations.purchase_order_id', 'atl_allocations.product')
                ->get();

            foreach ($liftedRows as $row) {
                $liftedByPo[$row->po_id][$row->product] = (int) $row->qty;
            }
        }

        // Purchase orders returned to the signed-in requester (the bell). Only
        // the requester's own POs are ever read here, so a reason is never
        // shown to anyone else.
        $returnedPos = PurchaseOrder::query()
            ->where('requested_by', Auth::id())
            ->where('request_status', 'REQUESTED')
            ->whereNotNull('rejection_reason')
            ->orderByDesc('rejected_at')
            ->limit(20)
            ->get();

        // Recent created / approved / returned events (the history icon).
        // Reasons are deliberately left out of these.
        $recentPos = PurchaseOrder::createdPos()
            ->with(['requester', 'approver'])
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();

        $staffNames = Admin::whereIn(
            'id',
            $returnedPos->pluck('rejected_by')->merge($recentPos->pluck('rejected_by'))->filter()->unique()
        )->pluck('name', 'id');

        $returnedPos->each(function ($p) use ($staffNames) {
            $p->rejecter_name = $staffNames[$p->rejected_by] ?? null;
        });

        $historyEvents = collect();

        foreach ($recentPos as $p) {
            if ($p->created_at) {
                $historyEvents->push([
                    'id' => $p->id,
                    'po' => $p->po_number,
                    'type' => 'created',
                    'by' => $p->requester->name ?? null,
                    'at' => Carbon::parse($p->created_at),
                ]);
            }

            if ($p->approved_at) {
                $historyEvents->push([
                    'id' => $p->id,
                    'po' => $p->po_number,
                    'type' => 'approved',
                    'by' => $p->approver->name ?? null,
                    'at' => Carbon::parse($p->approved_at),
                ]);
            }

            if ($p->rejected_at) {
                $historyEvents->push([
                    'id' => $p->id,
                    'po' => $p->po_number,
                    'type' => 'returned',
                    'by' => $staffNames[$p->rejected_by] ?? null,
                    'at' => Carbon::parse($p->rejected_at),
                ]);
            }
        }

        $historyEvents = $historyEvents
            ->sortByDesc(fn ($e) => $e['at']->getTimestamp())
            ->take(25)
            ->values();

        return view('wetstock.purchase-orders.index', [
            'purchaseOrders' => $purchaseOrders,
            'liftedByPo' => $liftedByPo,
            'returnedPos' => $returnedPos,
            'historyEvents' => $historyEvents,
            'stats' => $this->stats(),
            'rejectionCount' => PurchaseOrder::where('requested_by', Auth::id())
                ->where('request_status', 'REQUESTED')
                ->whereNotNull('rejection_reason')
                ->whereNull('rejection_seen_at')
                ->count(),
        ]);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $atlSearch = $request->input('atl_search');

        // deliveries carries the ATL / DR numbers and lift status; items
        // carries the per-product drawdown lines.
        $purchaseOrder->load([
            'warehouse.tanks',
            'client',
            'supplier',
            'requester',
            'approver',
            'linkedOrder',
            'items',
            'deliveries.preparer',
            'deliveries.approver',
        ]);

        // The ATL table is filtered independently of the PO itself.
        $atlQuery = $purchaseOrder->deliveries()
            ->with(['order', 'lifter', 'receiver', 'allocations']);

        if ($request->filled('atl_search')) {
            $term = '%'.$request->atl_search.'%';
            $atlQuery->where(function ($q) use ($term) {
                $q->where('atl_number', 'like', $term)
                    ->orWhere('client_atl_number', 'like', $term)
                    ->orWhere('dr_number', 'like', $term)
                    ->orWhere('product', 'like', $term)
                    ->orWhere('driver_name', 'like', $term)
                    ->orWhere('status', 'like', $term)
                    ->orWhere('approval_status', 'like', $term)
                    ->orWhere('lift_status', 'like', $term);
            });
        }

        $deliveries = $atlQuery->orderByDesc('id')->get();

        // Card figures ignore the table's search, since they describe the PO
        // rather than the filtered ATL list.
        $volumes = PurchaseOrder::volumeTotals($purchaseOrder->id);

        // Price and amount are not stored on an ATL; they come from the PO
        // line the ATL drew against.
        $priceByProduct = $purchaseOrder->items
            ->mapWithKeys(fn ($item) => [$item->product => (float) $item->unit_price])
            ->all();

        return view('wetstock.purchase-orders.show', compact(
            'purchaseOrder',
            'deliveries',
            'volumes',
            'priceByProduct',
            'atlSearch'
        ));
    }

    /**
     * Headline figures shown as stat cards at the top of the list.
     *
     * The count is scoped to created POs so stock requests do not inflate it.
     * The volume cards read from PO lines and allocations, which request rows
     * never have, so they already ignore them.
     *
     * @return array<string, mixed>
     */
    protected function stats(): array
    {
        return [
            'total' => PurchaseOrder::createdPos()->count(),
            'volumes' => PurchaseOrder::volumeTotals(),
        ];
    }

    /**
     * The requester has opened the feedback on a returned PO, so its bell
     * badge clears. Only the requester can mark it.
     */
    public function markRejectionSeen(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless((int) $purchaseOrder->requested_by === (int) Auth::id(), 403);

        if ($purchaseOrder->rejection_reason !== null && $purchaseOrder->rejection_seen_at === null) {
            $purchaseOrder->forceFill(['rejection_seen_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }
}