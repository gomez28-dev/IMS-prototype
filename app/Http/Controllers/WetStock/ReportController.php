<?php

namespace App\Http\Controllers\WetStock;

use App\Exports\WetStockReportExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\StorageTank;
use App\Models\SupplierOrder;
use App\Models\Warehouse;
use App\Models\WetStockReportSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    /**
     * Display the Wet Stock Report dashboard (Live Report & Saved Snapshots).
     */
    public function index(Request $request): View
    {
        $reportData = $this->compileLiveReport();
        $snapshots = WetStockReportSnapshot::with('creator')
            ->orderBy('snapshot_date', 'desc')
            ->paginate(15);

        return view('wetstock.reports.index', [
            'reportData' => $reportData,
            'snapshots' => $snapshots,
            'activeTab' => $request->get('tab', 'live'),
        ]);
    }

    /**
     * Lock and save the current live report as a historical snapshot.
     */
    public function storeSnapshot(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $liveData = $this->compileLiveReport();

        $snapshot = WetStockReportSnapshot::create([
            'title' => $validated['title'],
            'snapshot_date' => now(),
            'report_data' => $liveData,
            'created_by' => Auth::id(),
        ]);

        AuditLog::create([
            'admin_id' => Auth::id(),
            'action' => 'CREATED',
            'description' => "Saved Wet Stock Report Snapshot: '{$snapshot->title}'",
        ]);

        return redirect()->route('wetstock.reports.index', ['tab' => 'snapshots'])
            ->with('success', "Report snapshot '{$snapshot->title}' locked and saved successfully.");
    }

    /**
     * View an archived report snapshot.
     */
    public function showSnapshot(WetStockReportSnapshot $snapshot): View
    {
        return view('wetstock.reports.show_snapshot', [
            'snapshot' => $snapshot,
            'reportData' => $snapshot->report_data,
        ]);
    }

    /**
     * Export the live real-time report to Excel.
     */
    public function exportLive(): BinaryFileResponse
    {
        $liveData = $this->compileLiveReport();
        $filename = 'WET_STOCK_REPORT_LIVE_' . now()->format('Y_m_d_His') . '.xlsx';
        return Excel::download(new WetStockReportExport($liveData, 'Live Wet Stock Report'), $filename);
    }

    /**
     * Export a historical snapshot to Excel.
     */
    public function exportSnapshot(WetStockReportSnapshot $snapshot): BinaryFileResponse
    {
        $filename = 'WET_STOCK_REPORT_' . \Illuminate\Support\Str::slug($snapshot->title) . '_' . $snapshot->snapshot_date->format('Y_m_d') . '.xlsx';
        return Excel::download(new WetStockReportExport($snapshot->report_data, $snapshot->title), $filename);
    }

    /**
     * Helper method to compile live report metrics across all warehouses.
     */
    public function compileLiveReport(): array
    {
        $warehouses = Warehouse::orderBy('name', 'asc')->get();
        $reportBlocks = [];

        foreach ($warehouses as $warehouse) {
            // 2.1 Depot Tanks
            $depotTanks = StorageTank::where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->where('category', 'depot')
                ->get()
                ->each(fn ($tank) => $tank->setAttribute('stock_available', $tank->stock_available));
            $depotTotal = $depotTanks->sum(fn ($tank) => $tank->stock_available);

            // 2.2 Tanker Tanks
            $tankerTanks = StorageTank::where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->where('category', 'tanker')
                ->get()
                ->each(fn ($tank) => $tank->setAttribute('stock_available', $tank->stock_available));
            $tankerTotal = $tankerTanks->sum(fn ($tank) => $tank->stock_available);

            // Contaminated tanks & total
            $contaminatedTanks = StorageTank::where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->where('is_contaminated', true)
                ->get();
            $contaminatedTotal = $contaminatedTanks->sum('contaminated_liters');

            // 2.3 Unlifted Supplier Pick Up
            $unliftedSupplierOrders = SupplierOrder::where('warehouse_id', $warehouse->id)
                ->where('status', 'UNLIFTED_PICKUP')
                ->get();
            $unliftedSupplierTotal = $unliftedSupplierOrders->sum('liters');

            // 2.4 Pending Supplier Delivery
            $pendingSupplierOrders = SupplierOrder::where('warehouse_id', $warehouse->id)
                ->where('status', 'PENDING_DELIVERY')
                ->get();
            $pendingSupplierTotal = $pendingSupplierOrders->sum('liters');

            // Helper closure to check if a pending delivery matches this warehouse
            $matchDeliveryToWarehouse = function (Delivery $delivery) use ($warehouse) {
                if ($delivery->storage_tank_id && $delivery->storageTank) {
                    return $delivery->storageTank->warehouse_id == $warehouse->id;
                }
                if ($delivery->order && $delivery->order->location) {
                    return strtolower(trim($delivery->order->location)) === strtolower(trim($warehouse->name));
                }
                return false;
            };

            // Pending deliveries query
            $allPendingDeliveries = Delivery::with(['order', 'storageTank'])
                ->where('status', 'PENDING')
                ->get();

            // 2.5 Big Tanker Undelivered
            $bigTankerDeliveries = $allPendingDeliveries->filter(function ($d) use ($matchDeliveryToWarehouse) {
                return ($d->type === 'BIG TANKER' || $d->type === 'DELIVERY') && $matchDeliveryToWarehouse($d);
            });
            $bigTankerTotal = $bigTankerDeliveries->sum('qty_out');

            // 2.6 Small Tanker Undelivered
            $smallTankerDeliveries = $allPendingDeliveries->filter(function ($d) use ($matchDeliveryToWarehouse) {
                return $d->type === 'SMALL TANKER' && $matchDeliveryToWarehouse($d);
            });
            $smallTankerTotal = $smallTankerDeliveries->sum('qty_out');

            // 2.7 Client Pick Up
            $clientPickupDeliveries = $allPendingDeliveries->filter(function ($d) use ($matchDeliveryToWarehouse) {
                return $d->type === 'PICK-UP' && $matchDeliveryToWarehouse($d);
            });
            $clientPickupTotal = $clientPickupDeliveries->sum('qty_out');

            // 2.8 Sales Docs Pending Clearance (Hold for Clearing)
            $pendingClearanceOrders = \App\Models\Order::where('clearing_status', 'Pending')
                ->get()
                ->filter(fn ($order) => strtolower(trim($order->location)) === strtolower(trim($warehouse->name)));
            $pendingClearanceOrdersTotal = $pendingClearanceOrders->sum('qty_ordered');

            // Top Summary Math
            $totalCommitmentsIn = $depotTotal + $tankerTotal + $contaminatedTotal + $unliftedSupplierTotal + $pendingSupplierTotal;
            $clientPendingDeliveryTotal = $bigTankerTotal + $smallTankerTotal;
            $totalHoldForClearing = $pendingClearanceOrdersTotal + $clientPickupTotal + $clientPendingDeliveryTotal;
            $totalAvailableForSale = $totalCommitmentsIn + $totalHoldForClearing;
            $totalAvailableOnHandForSelling = ($depotTotal + $tankerTotal) - $clientPickupTotal;

            $reportBlocks[$warehouse->id] = [
                'warehouse' => $warehouse,
                'depot_tanks' => $depotTanks,
                'depot_total' => $depotTotal,
                'tanker_tanks' => $tankerTanks,
                'tankers_total' => $tankerTotal,
                'contaminated_tanks' => $contaminatedTanks,
                'contaminated_total' => $contaminatedTotal,
                'unlifted_supplier_orders' => $unliftedSupplierOrders,
                'unlifted_supplier_total' => $unliftedSupplierTotal,
                'pending_supplier_orders' => $pendingSupplierOrders,
                'pending_supplier_total' => $pendingSupplierTotal,
                'big_tanker_deliveries' => $bigTankerDeliveries,
                'big_tanker_total' => $bigTankerTotal,
                'small_tanker_deliveries' => $smallTankerDeliveries,
                'small_tanker_total' => $smallTankerTotal,
                'client_pickup_deliveries' => $clientPickupDeliveries,
                'client_pickup_total' => $clientPickupTotal,
                'pending_clearance_orders_total' => $pendingClearanceOrdersTotal,

                // Top Summary totals
                'total_commitments_in' => $totalCommitmentsIn,
                'client_pending_delivery_total' => $clientPendingDeliveryTotal,
                'total_hold_for_clearing' => $totalHoldForClearing,
                'total_available_for_sale' => $totalAvailableForSale,
                'total_available_on_hand_for_selling' => $totalAvailableOnHandForSelling,
            ];
        }

        return [
            'warehouses' => $warehouses,
            'blocks' => $reportBlocks,
            'compiled_at' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
