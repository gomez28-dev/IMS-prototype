<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\DeliveryController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/logout', [AuthController::class, 'logout']); // Fallback GET for simplicity (matching Flask app)

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/export/excel', [DashboardController::class, 'export'])->name('export');
    Route::get('/import/excel', [DashboardController::class, 'showImportForm'])->name('import.form');
    Route::post('/import/excel', [DashboardController::class, 'import'])->name('import');

    Route::get('/order/new', [OrderController::class, 'create'])->name('order.create');
    Route::post('/order/new', [OrderController::class, 'store'])->name('order.store');
    Route::get('/order/{order}/edit', [OrderController::class, 'edit'])->name('order.edit');
    Route::post('/order/{order}/edit', [OrderController::class, 'update'])->name('order.update');
    Route::post('/order/{order}/delete', [OrderController::class, 'destroy'])->name('order.delete');

    Route::get('/order/{order}/deliveries', [DeliveryController::class, 'index'])->name('order.deliveries');
    Route::get('/order/{order}/delivery/new', [DeliveryController::class, 'create'])->name('delivery.create');
    Route::post('/order/{order}/delivery/new', [DeliveryController::class, 'store'])->name('delivery.store');
    Route::get('/delivery/{delivery}/edit', [DeliveryController::class, 'edit'])->name('delivery.edit');
    Route::post('/delivery/{delivery}/edit', [DeliveryController::class, 'update'])->name('delivery.update');
    Route::post('/delivery/{delivery}/delete', [DeliveryController::class, 'destroy'])->name('delivery.delete');
});
