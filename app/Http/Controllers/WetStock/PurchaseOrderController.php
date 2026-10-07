<?php

namespace App\Http\Controllers\WetStock;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\StorageTank;
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
        $query = PurchaseOrder::with(['warehouse', 'client', 'requester', 'approver', 'linkedOrder', 'deliveries.preparer']);

        if ($request->filled('po_type')) {
            $query->where('po_type', $request->po_type);
        }

        if ($request->filled('status')) {
            $query->where('request_status', $request->status);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('po_number', 'like', $term)
                  ->orWhere('supplier_name', 'like', $term);
            });
        }

        $purchaseOrders = $query->latest()->paginate(15);

        $warehouses = Warehouse::orderBy('name')->get();
        $clients = Client::orderBy('name')->get();

        // The dispatch modal offers the tanker a pickup can be received into.
        $mobileTankers = StorageTank::where('category', 'tanker')
            ->where('is_active', true)
            ->with('warehouse')
            ->get();

        return view('wetstock.purchase-orders.index', [
            'purchaseOrders' => $purchaseOrders,
            'warehouses' => $warehouses,
            'clients' => $clients,
            'mobileTankers' => $mobileTankers,
            'stats' => $this->stats(),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
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

        return view('wetstock.purchase-orders.show', compact('purchaseOrder'));
    }

    /**
     * Headline counts shown as stat cards at the top of the list, so the
     * page stands on its own without a separate Dashboard entry.
     */
    protected function stats(): array
    {
        return [
            'total' => PurchaseOrder::count(),
            'pending' => PurchaseOrder::where('request_status', 'REQUESTED')->count(),
            'for_delivery' => PurchaseOrder::where('request_status', 'FOR_DELIVERY')->count(),
            'fulfilled' => PurchaseOrder::where('request_status', 'COMPLETED')->count(),
        ];
    }
}