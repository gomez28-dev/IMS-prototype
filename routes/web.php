<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/logout', [AuthController::class, 'logout']);

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/export/excel', [DashboardController::class, 'export'])->name('export');

    Route::middleware('role:admin,editor')->group(function () {
        Route::get('/import/excel', [DashboardController::class, 'showImportForm'])->name('import.form');
        Route::post('/import/excel', [DashboardController::class, 'import'])->name('import');

        Route::get('/order/new', [OrderController::class, 'create'])->name('order.create');
        Route::post('/order/new', [OrderController::class, 'store'])->name('order.store');
        Route::get('/order/{order}/edit', [OrderController::class, 'edit'])->name('order.edit');
        Route::post('/order/{order}/edit', [OrderController::class, 'update'])->name('order.update');

        Route::get('/order/{order}/deliveries', [DeliveryController::class, 'index'])->name('order.deliveries');
        Route::get('/order/{order}/delivery/new', [DeliveryController::class, 'create'])->name('delivery.create');
        Route::post('/order/{order}/delivery/new', [DeliveryController::class, 'store'])->name('delivery.store');
        Route::get('/delivery/{delivery}/edit', [DeliveryController::class, 'edit'])->name('delivery.edit');
        Route::post('/delivery/{delivery}/edit', [DeliveryController::class, 'update'])->name('delivery.update');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs');

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
});
