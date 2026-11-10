<?php

use App\Http\Controllers\Logistics\JobOrderController;
use App\Http\Controllers\Logistics\PersonnelController;
use App\Http\Controllers\Logistics\TripScheduleController;
use App\Http\Controllers\Logistics\UnitController;
use Illuminate\Support\Facades\Route;

/*
 * Module 4 — Logistics.
 *
 * Same roles as Wet Stock; the controllers decide who may change anything
 * (hod and vp may look, not edit).
 */
Route::middleware('role:admin,ops_admin,ops_mgr,ops_wh,ops_log,hod,vp')
    ->prefix('logistics')->name('logistics.')->group(function () {

        Route::redirect('/', '/logistics/schedule')->name('index');

        // Trip schedule board (calendar, side panel, trips and stops)
        Route::get('/schedule', [TripScheduleController::class, 'index'])->name('schedule');
        Route::get('/schedule/detail', [TripScheduleController::class, 'detail'])->name('schedule.detail');
        Route::post('/schedule', [TripScheduleController::class, 'store'])->name('schedule.store');
        Route::post('/schedule/deliveries/{delivery}/date', [TripScheduleController::class, 'rescheduleDelivery'])
            ->whereNumber('delivery')->name('schedule.delivery-date');
        Route::post('/schedule/stops', [TripScheduleController::class, 'storeStop'])->name('schedule.stops.store');
        Route::post('/schedule/stops/{stop}/delete', [TripScheduleController::class, 'destroyStop'])
            ->whereNumber('stop')->name('schedule.stops.destroy');
        Route::post('/schedule/stops/{stop}/status', [TripScheduleController::class, 'statusStop'])
            ->whereNumber('stop')->name('schedule.stops.status');
        Route::post('/schedule/stops/{stop}', [TripScheduleController::class, 'updateStop'])
            ->whereNumber('stop')->name('schedule.stops.update');
        Route::post('/schedule/{trip}/delete', [TripScheduleController::class, 'destroy'])
            ->whereNumber('trip')->name('schedule.destroy');
        Route::post('/schedule/{trip}', [TripScheduleController::class, 'update'])
            ->whereNumber('trip')->name('schedule.update');

        // Job orders (deliveries waiting to be put on a trip)
        Route::get('/job-orders', [JobOrderController::class, 'index'])->name('job-orders.index');
        Route::post('/job-orders/schedule', [JobOrderController::class, 'schedule'])->name('job-orders.schedule');

        // Tanker units board
        Route::get('/units', [UnitController::class, 'index'])->name('units');
        Route::post('/units/{tank}/status', [UnitController::class, 'update'])
            ->whereNumber('tank')->name('units.status');

        // Personnel (drivers and helpers)
        Route::get('/personnel', [PersonnelController::class, 'index'])->name('personnel.index');
        Route::get('/personnel/create', [PersonnelController::class, 'create'])->name('personnel.create');
        Route::post('/personnel', [PersonnelController::class, 'store'])->name('personnel.store');
        Route::get('/personnel/{personnel}/edit', [PersonnelController::class, 'edit'])->name('personnel.edit');
        Route::post('/personnel/{personnel}', [PersonnelController::class, 'update'])->name('personnel.update');
        Route::post('/personnel/{personnel}/toggle', [PersonnelController::class, 'toggle'])->name('personnel.toggle');
    });
