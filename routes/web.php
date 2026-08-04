<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
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
    Route::get('/export/excel', [DashboardController::class, 'export'])->name('export');

    // Delivery index — accessible to all authenticated users
    Route::get('/order/{order}/deliveries', [DeliveryController::class, 'index'])->name('order.deliveries');

    // Reports — accessible to all authenticated users (view-only)
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    // Audit Log — accessible to all authenticated users (view-only)
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs');

    Route::middleware('role:admin,editor')->group(function () {
        Route::get('/import/excel', [DashboardController::class, 'showImportForm'])->name('import.form');
        Route::post('/import/excel', [DashboardController::class, 'import'])->name('import');

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
        });
    });

    Route::middleware('role:admin,accounting')->group(function () {
        Route::post('/order/{order}/clearance', [OrderController::class, 'updateClearance'])->name('order.clearance');
    });

    // Wet Stock Module Routes
    Route::prefix('wetstock')->name('wetstock.')->group(function () {
        Route::get('/', [WetStock\DashboardController::class, 'index'])->name('dashboard');
        Route::get('/warehouses/{warehouse}', [WetStock\WarehouseController::class, 'show'])->name('warehouses.show');

        // Tank CRUD & Contamination
        Route::middleware('role:admin,editor,warehouse')->group(function () {
            Route::get('/warehouses/{warehouse}/tanks/create', [WetStock\StorageTankController::class, 'create'])->name('tanks.create');
            Route::post('/warehouses/{warehouse}/tanks', [WetStock\StorageTankController::class, 'store'])->name('tanks.store');
            Route::get('/tanks/{tank}/edit', [WetStock\StorageTankController::class, 'edit'])->name('tanks.edit');
            Route::post('/tanks/{tank}/edit', [WetStock\StorageTankController::class, 'update'])->name('tanks.update');
            Route::post('/tanks/{tank}/toggle-active', [WetStock\StorageTankController::class, 'toggleActive'])->name('tanks.toggle-active');
            Route::post('/tanks/{tank}/toggle-contamination', [WetStock\StorageTankController::class, 'toggleContamination'])->name('tanks.toggle-contamination');
        });

        // Stock IN
        Route::get('/stock-in', [WetStock\StockInController::class, 'index'])->name('stock-in.index');
        Route::middleware('role:admin,editor,warehouse')->group(function () {
            Route::get('/stock-in/create', [WetStock\StockInController::class, 'create'])->name('stock-in.create');
            Route::post('/stock-in', [WetStock\StockInController::class, 'store'])->name('stock-in.store');
        });

        // Delivery Assignment
        Route::get('/deliveries', [WetStock\DeliveryAssignmentController::class, 'index'])->name('deliveries.index');
        Route::get('/deliveries/unassigned', function () {
            return redirect()->route('wetstock.deliveries.index', ['tab' => 'unassigned']);
        })->name('deliveries.unassigned');
        Route::get('/deliveries/assignment-history', function () {
            return redirect()->route('wetstock.deliveries.index', ['tab' => 'history']);
        })->name('deliveries.assignment-history');
        Route::middleware('role:admin,editor,warehouse')->group(function () {
            Route::post('/deliveries/{delivery}/assign', [WetStock\DeliveryAssignmentController::class, 'assign'])->name('deliveries.assign');
            Route::post('/deliveries/{delivery}/unassign', [WetStock\DeliveryAssignmentController::class, 'unassign'])->name('deliveries.unassign');
        });

        // Incoming Supplier Stock
        Route::get('/supplier-orders', [WetStock\SupplierOrderController::class, 'index'])->name('supplier-orders.index');
        Route::middleware('role:admin,editor,warehouse')->group(function () {
            Route::get('/supplier-orders/create', [WetStock\SupplierOrderController::class, 'create'])->name('supplier-orders.create');
            Route::post('/supplier-orders', [WetStock\SupplierOrderController::class, 'store'])->name('supplier-orders.store');
            Route::get('/supplier-orders/{supplierOrder}/edit', [WetStock\SupplierOrderController::class, 'edit'])->name('supplier-orders.edit');
            Route::post('/supplier-orders/{supplierOrder}/edit', [WetStock\SupplierOrderController::class, 'update'])->name('supplier-orders.update');
            Route::post('/supplier-orders/{supplierOrder}/complete', [WetStock\SupplierOrderController::class, 'complete'])->name('supplier-orders.complete');
        });

        // Wet Stock Reports & Snapshots
        Route::get('/reports', [WetStock\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export/live', [WetStock\ReportController::class, 'exportLive'])->name('reports.export-live');
        Route::get('/reports/snapshot/{snapshot}', [WetStock\ReportController::class, 'showSnapshot'])->name('reports.show-snapshot');
        Route::get('/reports/export/snapshot/{snapshot}', [WetStock\ReportController::class, 'exportSnapshot'])->name('reports.export-snapshot');
        Route::middleware('role:admin,editor,warehouse')->group(function () {
            Route::post('/reports/snapshot', [WetStock\ReportController::class, 'storeSnapshot'])->name('reports.snapshot');
        });
    });

});
