<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\ModificationRequestController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\WetStock;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/logout', [AuthController::class, 'logout']);

    // Portal selection screen
    Route::get('/', [PortalController::class, 'index'])->name('portal');

    // Sales Inventory Portal Routes
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Delivery index — accessible to all authenticated users
    Route::get('/order/{order}/deliveries', [DeliveryController::class, 'index'])->name('order.deliveries');

    // Reports — accessible to all authenticated users (view-only)
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    // Audit Log — accessible to all authenticated users (view-only)
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs');

    // Approvals — Module 1 (Sales Orders & DRs) — admin/hod only
    Route::get('/approvals', [ModificationRequestController::class, 'indexModule1'])
        ->middleware('role:admin,hod')->name('approvals.index');
    // Approve/reject actions are shared by both modules — per-module role
    // checks live inside the controller (canApproveModule1/2Modification).
    Route::post('/approvals/{modificationRequest}/approve', [ModificationRequestController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{modificationRequest}/reject', [ModificationRequestController::class, 'reject'])->name('approvals.reject');

    // Module 1 (Sales Inventory) Management Routes
    Route::middleware('role:admin,sales,hod,vp')->group(function () {
        Route::get('/order/new', [OrderController::class, 'create'])->name('order.create');
        Route::post('/order/new', [OrderController::class, 'store'])->name('order.store');
        Route::get('/order/{order}/edit', [OrderController::class, 'edit'])->name('order.edit');
        Route::post('/order/{order}/edit', [OrderController::class, 'update'])->name('order.update');

        Route::get('/order/{order}/delivery/new', [DeliveryController::class, 'create'])->name('delivery.create');
        Route::post('/order/{order}/delivery/new', [DeliveryController::class, 'store'])->name('delivery.store');
        Route::get('/delivery/{delivery}/edit', [DeliveryController::class, 'edit'])->name('delivery.edit');
        Route::post('/delivery/{delivery}/edit', [DeliveryController::class, 'update'])->name('delivery.update');

        Route::prefix('clients')->name('clients.')->group(function () {
            Route::get('/', [ClientController::class, 'index'])->name('index');
            Route::get('/create', [ClientController::class, 'create'])->name('create');
            Route::post('/', [ClientController::class, 'store'])->name('store');
            Route::get('/{client}/edit', [ClientController::class, 'edit'])->name('edit');
            Route::post('/{client}/edit', [ClientController::class, 'update'])->name('update');
            Route::post('/{client}/delete', [ClientController::class, 'destroy'])->name('destroy');
        });
    });

    // Admin-Only Routes
    Route::middleware('role:admin')->group(function () {
        Route::post('/order/{order}/delete', [OrderController::class, 'destroy'])->name('order.delete');
        Route::post('/delivery/{delivery}/delete', [DeliveryController::class, 'destroy'])->name('delivery.delete');

        Route::prefix('accounts')->name('accounts.')->group(function () {
            Route::get('/', [AdminController::class, 'index'])->name('index');
            Route::get('/create', [AdminController::class, 'create'])->name('create');
            Route::post('/', [AdminController::class, 'store'])->name('store');
            Route::get('/{admin}/edit', [AdminController::class, 'edit'])->name('edit');
            Route::post('/{admin}/edit', [AdminController::class, 'update'])->name('update');
            Route::post('/{admin}/toggle-active', [AdminController::class, 'toggleActive'])->name('toggle-active');
            Route::delete('/{admin}', [AdminController::class, 'destroy'])->name('destroy');
        });
    });

    // Accounting Clearance Routes
    Route::middleware('role:admin,accounting')->group(function () {
        Route::post('/order/{order}/clearance', [OrderController::class, 'updateClearance'])->name('order.clearance');
        Route::post('/orders/bulk-clearance', [OrderController::class, 'bulkUpdateClearance'])->name('orders.bulk-clearance');
    });

    // Wet Stock Module Routes
    Route::prefix('wetstock')->name('wetstock.')->group(function () {
        // Approvals — Module 2 (Stock Transfers) — admin/ops_admin/ops_mgr only
        Route::get('/approvals', [ModificationRequestController::class, 'indexModule2'])
            ->middleware('role:admin,ops_mgr,ops_admin')->name('approvals.index');

        Route::get('/', [WetStock\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/warehouses/{warehouse}', [WetStock\WarehouseController::class, 'show'])->name('warehouses.show');

        // Tank CRUD & Contamination
        Route::middleware('role:admin,ops_admin,ops_mgr,ops_wh,ops_log,hod,vp')->group(function () {
            Route::get('/warehouses/{warehouse}/tanks/create', [WetStock\StorageTankController::class, 'create'])->name('tanks.create');
            Route::post('/warehouses/{warehouse}/tanks', [WetStock\StorageTankController::class, 'store'])->name('tanks.store');
            Route::get('/tanks/{tank}/edit', [WetStock\StorageTankController::class, 'edit'])->name('tanks.edit');
            Route::post('/tanks/{tank}/edit', [WetStock\StorageTankController::class, 'update'])->name('tanks.update');
            Route::post('/tanks/{tank}/toggle-active', [WetStock\StorageTankController::class, 'toggleActive'])->name('tanks.toggle-active');
            Route::post('/tanks/{tank}/toggle-contamination', [WetStock\StorageTankController::class, 'toggleContamination'])->name('tanks.toggle-contamination');
        });

        // Stock IN
        Route::get('/stock-in', [WetStock\StockInController::class, 'index'])->name('stock-in.index');
        Route::middleware('role:admin,ops_admin,ops_mgr,ops_wh,ops_log,hod,vp')->group(function () {
            Route::get('/stock-in/create', [WetStock\StockInController::class, 'create'])->name('stock-in.create');
            Route::post('/stock-in', [WetStock\StockInController::class, 'store'])->name('stock-in.store');
            Route::get('/stock-in/{stockIn}/edit', [WetStock\StockInController::class, 'edit'])->name('stock-in.edit');
            Route::post('/stock-in/{stockIn}/edit', [WetStock\StockInController::class, 'update'])->name('stock-in.update');
            Route::post('/stock-in/{stockIn}/revert', [WetStock\StockInController::class, 'revert'])->name('stock-in.revert');
        });
        // Delete — Portal Administrator only
        Route::middleware('role:admin')->group(function () {
            Route::post('/stock-in/{stockIn}/delete', [WetStock\StockInController::class, 'destroy'])->name('stock-in.destroy');
        });

        // Stock Transfers (Depot <-> Tanker)
        Route::get('/transfers', [WetStock\StockTransferController::class, 'index'])->name('transfers.index');
        Route::middleware('role:admin,ops_admin,ops_mgr,ops_wh,ops_log,hod,vp')->group(function () {
            Route::get('/transfers/create', [WetStock\StockTransferController::class, 'create'])->name('transfers.create');
            Route::post('/transfers', [WetStock\StockTransferController::class, 'store'])->name('transfers.store');
            Route::get('/transfers/{transfer}/edit', [WetStock\StockTransferController::class, 'edit'])->name('transfers.edit');
            Route::post('/transfers/{transfer}/edit', [WetStock\StockTransferController::class, 'update'])->name('transfers.update');
        });
        // Delete — Portal Administrator only
        Route::middleware('role:admin')->group(function () {
            Route::post('/transfers/{transfer}/delete', [WetStock\StockTransferController::class, 'destroy'])->name('transfers.destroy');
        });

        // Delivery Assignment & Fulfillment
        Route::get('/deliveries', [WetStock\DeliveryAssignmentController::class, 'index'])->name('deliveries.index');
        Route::get('/deliveries/unassigned', function () {
            return redirect()->route('wetstock.deliveries.index', ['tab' => 'unassigned']);
        })->name('deliveries.unassigned');
        Route::get('/deliveries/assigned', function () {
            return redirect()->route('wetstock.deliveries.index', ['tab' => 'assigned']);
        })->name('deliveries.assigned');
        Route::get('/deliveries/assignment-history', function () {
            return redirect()->route('wetstock.deliveries.index', ['tab' => 'history']);
        })->name('deliveries.assignment-history');

        Route::middleware('role:admin,ops_admin,ops_mgr,ops_wh,ops_log,hod,vp')->group(function () {
            Route::post('/deliveries/{delivery}/allocate', [WetStock\DeliveryAssignmentController::class, 'allocate'])->name('deliveries.allocate');
            Route::post('/deliveries/allocations/{allocation}/unassign', [WetStock\DeliveryAssignmentController::class, 'unassign'])->name('deliveries.unassign');
        });

        // Fulfillment Actions (Restricted to canMarkFulfilled roles)
        Route::middleware('role:admin,ops_admin,ops_mgr')->group(function () {
            Route::post('/deliveries/{delivery}/fulfill', [WetStock\DeliveryAssignmentController::class, 'markFulfilled'])->name('deliveries.fulfill');
            Route::post('/deliveries/{delivery}/revert-fulfillment', [WetStock\DeliveryAssignmentController::class, 'revertFulfillment'])->name('deliveries.revert-fulfillment');
        });

        // Incoming Supplier Stock
        Route::get('/supplier-orders', [WetStock\SupplierOrderController::class, 'index'])->name('supplier-orders.index');
        Route::middleware('role:admin,ops_admin,ops_mgr,ops_wh,ops_log,hod,vp')->group(function () {
            Route::get('/supplier-orders/create', [WetStock\SupplierOrderController::class, 'create'])->name('supplier-orders.create');
            Route::post('/supplier-orders', [WetStock\SupplierOrderController::class, 'store'])->name('supplier-orders.store');
            Route::get('/supplier-orders/{supplierOrder}/edit', [WetStock\SupplierOrderController::class, 'edit'])->name('supplier-orders.edit');
            Route::post('/supplier-orders/{supplierOrder}/edit', [WetStock\SupplierOrderController::class, 'update'])->name('supplier-orders.update');
            Route::post('/supplier-orders/{supplierOrder}/complete', [WetStock\SupplierOrderController::class, 'complete'])->name('supplier-orders.complete');
            Route::post('/supplier-orders/{supplierOrder}/delete', [WetStock\SupplierOrderController::class, 'destroy'])->name('supplier-orders.destroy');

            // Module 2 Front-Door Stock Requests & Tank Receiving Hand-off
            Route::get('/stock-requests', [WetStock\StockRequestController::class, 'index'])->name('stock-requests.index');
            Route::get('/stock-requests/create', [WetStock\StockRequestController::class, 'create'])->name('stock-requests.create');
            Route::post('/stock-requests', [WetStock\StockRequestController::class, 'store'])->name('stock-requests.store');
            Route::post('/deliveries/{delivery}/receive-stock', [WetStock\StockRequestController::class, 'receiveDelivery'])->name('deliveries.receive-stock');
        });

        // Wet Stock Reports & Snapshots
        Route::get('/reports', [WetStock\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export/live', [WetStock\ReportController::class, 'exportLive'])->name('reports.export-live');
        Route::get('/reports/snapshot/{snapshot}', [WetStock\ReportController::class, 'showSnapshot'])->name('reports.show-snapshot');
        Route::get('/reports/export/snapshot/{snapshot}', [WetStock\ReportController::class, 'exportSnapshot'])->name('reports.export-snapshot');
        Route::middleware('role:admin,ops_admin,ops_mgr,ops_wh,ops_log,hod,vp')->group(function () {
            Route::post('/reports/snapshot', [WetStock\ReportController::class, 'storeSnapshot'])->name('reports.snapshot');
        });
    });

    // Module 3: Stock Orders & ATL Issuance (Gated to admin, purchasing, vp, audit, viewer)
    Route::prefix('stock-orders')->name('stock-orders.')->middleware('role:admin,purchasing,vp,audit,viewer')->group(function () {
        Route::get('/', [WetStock\StockOrderController::class, 'index'])->name('index');
        Route::get('/dashboard', [WetStock\StockOrderController::class, 'dashboard'])->name('dashboard');
        Route::get('/create-supplier-po', [WetStock\StockOrderController::class, 'createSupplierPo'])->name('create-supplier-po');
        Route::post('/store-supplier-po', [WetStock\StockOrderController::class, 'storeSupplierPo'])->name('store-supplier-po');
        Route::get('/fuel-trade/{order}/create-po', [WetStock\StockOrderController::class, 'createFromSalesOrder'])->name('create-fuel-trade-po');
        Route::post('/fuel-trade/{order}/store-po', [WetStock\StockOrderController::class, 'storeFuelTradePo'])->name('store-fuel-trade-po');
        Route::post('/deliveries/{delivery}/complete-fuel-trade', [WetStock\StockOrderController::class, 'completeFuelTrade'])->name('complete-fuel-trade');
        Route::post('/deliveries/{delivery}/update-docs', [WetStock\StockOrderController::class, 'updateDeliveryDocs'])->name('update-docs');
        Route::get('/{purchaseOrder}/prepare', [WetStock\StockOrderController::class, 'editRequest'])->name('edit-request');
        Route::post('/{purchaseOrder}/prepare', [WetStock\StockOrderController::class, 'updateRequest'])->name('update-request');
        Route::get('/approvals', [WetStock\StockOrderController::class, 'approvals'])->name('approvals');
        Route::prefix('suppliers')->name('suppliers.')->group(function () {
            Route::get('/', [WetStock\SupplierController::class, 'index'])->name('index');
            Route::get('/create', [WetStock\SupplierController::class, 'create'])->name('create');
            Route::post('/', [WetStock\SupplierController::class, 'store'])->name('store');
            Route::get('/{supplier}/edit', [WetStock\SupplierController::class, 'edit'])->name('edit');
            Route::post('/{supplier}/edit', [WetStock\SupplierController::class, 'update'])->name('update');
            Route::post('/{supplier}/toggle-active', [WetStock\SupplierController::class, 'toggleActive'])->name('toggle-active');
        });
        Route::post('/{purchaseOrder}/approve', [WetStock\StockOrderController::class, 'approve'])->name('approve');
        Route::post('/{purchaseOrder}/reject', [WetStock\StockOrderController::class, 'reject'])->name('reject');
        Route::get('/deliveries', [WetStock\StockOrderController::class, 'deliveries'])->name('deliveries');
        Route::post('/deliveries/{delivery}/dispatch', [WetStock\StockOrderController::class, 'dispatchDelivery'])->name('dispatch-delivery');
        Route::get('/deliveries/{delivery}/pdf', [WetStock\StockOrderController::class, 'downloadAtlPdf'])->name('pdf');
        Route::get('/deliveries/{delivery}/atl-pdf', [WetStock\StockOrderController::class, 'downloadAtlPdf'])->name('atl-pdf');

        // Module 3 redesign: Sales Order list and per-order ATL records.
        // These live alongside the current pages rather than replacing them,
        // so the existing Dashboard keeps working until these are signed off.
        Route::get('/atls', [WetStock\AtlController::class, 'index'])->name('atls.index');
        Route::get('/atls/{order}', [WetStock\AtlController::class, 'show'])->name('atls.show');
        Route::post('/atls/{delivery}/approve', [WetStock\StockOrderController::class, 'approveAtl'])->name('atl-approve');
        Route::post('/atls/{delivery}/reject', [WetStock\StockOrderController::class, 'rejectAtl'])->name('atl-reject');

        Route::get('/{purchaseOrder}', [WetStock\StockOrderController::class, 'show'])->name('show');
    });

    // Push subscription stub (real Web Push lands later; prevents 500 from layout snippet)
    Route::post('/push/subscribe', function () {
        return response()->noContent();
    })->name('push.subscribe');

});
