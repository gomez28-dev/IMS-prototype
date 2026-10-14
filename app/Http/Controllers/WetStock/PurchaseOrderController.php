<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDelivery;
use App\Models\Warehouse;
use Illuminate\Http\Request;
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

        return view('wetstock.purchase-orders.index', [
            'purchaseOrders' => $purchaseOrders,
            'stats' => $this->stats(),
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
}