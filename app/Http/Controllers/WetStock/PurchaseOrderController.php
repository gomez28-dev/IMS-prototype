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

        $hiddenIds = $this->rolledOffIds();
        $query->whereNotIn('purchase_orders.id', $hiddenIds);

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
        $recentPos = PurchaseOrder::createdPos()->whereNotIn('purchase_orders.id', $hiddenIds)
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

        // Every logged action for these POs, in one query, so History matches
        // the PO detail page's Activity history. POs from before the log existed
        // have no rows and fall back to their dates below.
        $activityByPo = \App\Models\PurchaseOrderActivity::with('actor')
            ->whereIn('purchase_order_id', $recentPos->pluck('id'))
            ->get()
            ->groupBy('purchase_order_id');
        $activityMap = [
            'submitted' => ['created', 'was submitted'],
            'resubmitted' => ['created', 'was resubmitted'],
            'edited' => ['created', 'was edited'],
            'approved' => ['approved', 'was approved'],
            'rejected' => ['returned', 'was returned for changes'],
            'cancelled' => ['cancelled', 'was cancelled'],
        ];
        $historyEvents = collect();

        foreach ($recentPos as $p) {
            if (isset($activityByPo[$p->id])) {
                foreach ($activityByPo[$p->id] as $a) {
                    [$type, $label] = $activityMap[$a->action] ?? ['created', 'had activity: '.$a->action];
                    $historyEvents->push([
                        'id' => $p->id,
                        'po' => $p->po_number,
                        'type' => $type,
                        'label' => $label,
                        'by' => $a->actor->name ?? null,
                        'at' => Carbon::parse($a->created_at),
                        // A rejection's note is the reason: requester only.
                        'note' => ($a->action !== 'rejected' || (int) $p->requested_by === (int) Auth::id()) ? $a->note : null,
                    ]);
                }
                continue;
            }
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
            'stats' => $this->stats($hiddenIds),
            'rejectionCount' => PurchaseOrder::where('requested_by', Auth::id())
                ->where('request_status', 'REQUESTED')
                ->whereNotNull('rejection_reason')
                ->whereNull('rejection_seen_at')
                ->count(),
        ]);
    }

    /**
     * IDs of POs that have rolled off the list: fully lifted, with the last
     * lift in an earlier month (Manila time). A PO that is pending, returned
     * or has any unlifted quantity is never in here, so it carries over from
     * month to month until it is fully lifted. Nothing is deleted: these POs
     * stay on the Reports page and their detail pages still open.
     *
     * @return array<int, int>
     */
    protected function rolledOffIds(): array
    {
        $monthStart = Carbon::now('Asia/Manila')->startOfMonth()->setTimezone('UTC');

        $ordered = [];
        $itemRows = \App\Models\PurchaseOrderItem::query()
            ->selectRaw('purchase_order_id, product, SUM(quantity_ordered) AS qty')
            ->groupBy('purchase_order_id', 'product')
            ->get();
        foreach ($itemRows as $row) {
            $ordered[$row->purchase_order_id][$row->product] = (int) $row->qty;
        }

        // lifted_at is stored in UTC. Legacy rows without it use updated_at.
        $lifted = [];
        $lastLift = [];
        $liftRows = AtlAllocation::query()
            ->join('purchase_order_deliveries', 'atl_allocations.purchase_order_delivery_id', '=', 'purchase_order_deliveries.id')
            ->where('purchase_order_deliveries.lift_status', PurchaseOrderDelivery::LIFT_LIFTED)
            ->where('purchase_order_deliveries.status', '!=', 'Cancelled')
            ->selectRaw('atl_allocations.purchase_order_id AS po_id, atl_allocations.product, SUM(atl_allocations.quantity) AS qty, MAX(COALESCE(purchase_order_deliveries.lifted_at, purchase_order_deliveries.updated_at)) AS last_lift')
            ->groupBy('atl_allocations.purchase_order_id', 'atl_allocations.product')
            ->get();
        foreach ($liftRows as $r) {
            $lifted[$r->po_id][$r->product] = (int) $r->qty;
            $at = Carbon::parse($r->last_lift, 'UTC');
            if (!isset($lastLift[$r->po_id]) || $at->gt($lastLift[$r->po_id])) {
                $lastLift[$r->po_id] = $at;
            }
        }

        $hidden = [];
        foreach ($ordered as $poId => $products) {
            if (array_sum($products) <= 0 || !isset($lastLift[$poId])) {
                continue;
            }
            foreach ($products as $product => $qty) {
                if ($qty > 0 && ($lifted[$poId][$product] ?? 0) < $qty) {
                    continue 2;
                }
            }
            if ($lastLift[$poId]->lt($monthStart)) {
                $hidden[] = (int) $poId;
            }
        }

        // A cancelled PO rolls off at month end too, judged by when it was
        // cancelled (the activity log's "cancelled" row).
        $cancelRows = \App\Models\PurchaseOrderActivity::query()
            ->where('action', 'cancelled')
            ->whereIn('purchase_order_id', PurchaseOrder::query()->where('request_status', 'CANCELLED')->select('id'))
            ->selectRaw('purchase_order_id, MAX(created_at) AS cancelled_at')
            ->groupBy('purchase_order_id')
            ->get();
        foreach ($cancelRows as $c) {
            if (Carbon::parse($c->cancelled_at, 'UTC')->lt($monthStart)) {
                $hidden[] = (int) $c->purchase_order_id;
            }
        }

        return array_values(array_unique($hidden));
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
            ->with(['order', 'lifter', 'receiver', 'allocations', 'corrections.admin']);

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
    protected function stats(array $hiddenIds = []): array
    {
        return [
            'total' => PurchaseOrder::createdPos()->notCancelled()->whereNotIn('purchase_orders.id', $hiddenIds)->count(),
            'volumes' => PurchaseOrder::volumeTotals(PurchaseOrder::createdPos()->notCancelled()->whereNotIn('purchase_orders.id', $hiddenIds)->pluck('id')->all()),
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